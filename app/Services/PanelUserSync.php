<?php

namespace App\Services;

use App\Contracts\GamePanelClient;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Makes sure every account here also exists on the game panel.
 *
 * Registration creates both sides at once, so in the ordinary case there is
 * nothing to do. The two drift apart when the panel was unreachable at that
 * moment, when its reply was not understood, or when it was rebuilt or swapped
 * for another one. A customer in that state looks perfectly normal until they
 * try to buy a server, which then fails with nothing on screen to point at.
 *
 * This walks both lists and fills in what is missing. It never deletes and
 * never changes an account the panel already has: an account on the panel with
 * nobody here is the admin's business, not something to tidy away.
 */
class PanelUserSync
{
    public function __construct(private GamePanelClient $panel)
    {
    }

    /**
     * @return array{
     *     panel: string,
     *     checked: int,
     *     matched: int,
     *     relinked: int,
     *     created: int,
     *     failed: int,
     *     failures: array<int, string>,
     *     passwords_issued: bool,
     * }
     *
     * @throws \Exception if the panel cannot be read at all
     */
    public function run(bool $write = true): array
    {
        // Read before writing: a panel that cannot be listed must stop the
        // whole thing, or every account here looks missing and the sync
        // cheerfully creates a duplicate of each one.
        $panelUsers = collect($this->panel->getUsers())
            ->map(fn ($row) => is_array($row) ? ($row['attributes'] ?? $row) : [])
            ->filter(fn ($row) => is_array($row) && !empty($row['id']))
            ->values();

        $byId = $panelUsers->keyBy(fn (array $row) => (int) $row['id']);
        $byEmail = $panelUsers
            ->filter(fn (array $row) => filled($row['email'] ?? null))
            ->keyBy(fn (array $row) => Str::lower($row['email']));
        $takenNames = $panelUsers
            ->filter(fn (array $row) => filled($row['username'] ?? null))
            ->mapWithKeys(fn (array $row) => [Str::lower($row['username']) => true])
            ->all();

        $result = [
            'panel' => $this->panel->panelName(),
            'checked' => 0,
            'matched' => 0,
            'relinked' => 0,
            'created' => 0,
            'failed' => 0,
            'failures' => [],
            'passwords_issued' => false,
        ];

        foreach (User::query()->cursor() as $user) {
            $result['checked']++;

            $panelId = (int) $user->pterodactyl_id;

            if ($panelId && $byId->has($panelId)) {
                $result['matched']++;

                continue;
            }

            // The address is the one thing both sides agree on, so an account
            // that is already there is adopted rather than duplicated.
            $existing = filled($user->email) ? $byEmail->get(Str::lower($user->email)) : null;

            if ($existing && !empty($existing['root_admin'])) {
                // An administrator's panel account is not handed to whoever
                // holds the matching address here. Linking it would give them
                // that account's servers, so it is left for a human to do
                // deliberately in the user's own edit form.
                $result['failed']++;
                $result['failures'][] = $user->email . ' - ' . __('that address belongs to an administrator on the panel; link it by hand.');

                continue;
            }

            if ($existing) {
                if ($write) {
                    $user->update(['pterodactyl_id' => $existing['id']]);
                }

                $result['relinked']++;

                continue;
            }

            if (!$write) {
                $result['created']++;

                continue;
            }

            try {
                $created = $this->create($user, $takenNames);
            } catch (Throwable $e) {
                $result['failed']++;
                $result['failures'][] = $user->email . ' - ' . $e->getMessage();

                Log::warning('Could not create a panel account during the user sync', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Keep the new account out of the way of the next one in the loop.
            $takenNames[Str::lower($created['username'])] = true;
            $byId->put((int) $created['id'], $created);
            $byEmail->put(Str::lower($user->email), $created);

            $result['created']++;
            $result['passwords_issued'] = true;
        }

        return $result;
    }

    /**
     * Create the panel account for one user.
     *
     * The password is random and is deliberately not stored: nothing here
     * knows the customer's real one, it is only ever held hashed. Changing
     * their password in their profile pushes a new one to the panel, which is
     * how they get in afterwards.
     *
     * @param  array<string, bool>  $takenNames
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    private function create(User $user, array $takenNames): array
    {
        $username = $this->panelUsername($user, $takenNames);

        $response = $this->panel->createUser([
            'external_id' => (string) $user->id,
            'username' => $username,
            'email' => $user->email,
            'first_name' => $user->name,
            'last_name' => $user->name,
            'password' => Str::password(24),
            'root_admin' => false,
            'language' => 'en',
        ]);

        if ($response->failed()) {
            throw new \Exception($this->complaint($response));
        }

        $created = $response->json()['attributes'] ?? null;

        if (!is_array($created) || empty($created['id'])) {
            throw new \Exception(__('The panel accepted the account but did not say which one it created.'));
        }

        $user->update(['pterodactyl_id' => $created['id']]);

        Log::notice('Created a missing panel account for a user', [
            'user_id' => $user->id,
            'panel_user_id' => $created['id'],
        ]);

        return ['id' => $created['id'], 'username' => $username, 'email' => $user->email];
    }

    /**
     * Whatever the panel said went wrong, in the shortest readable form.
     */
    private function complaint($response): string
    {
        $body = $response->json();

        return (string) (
            $body['errors'][0]['detail']
            ?? $body['error_message']
            ?? $body['message']
            ?? ($response->status() . ' ' . Str::limit($response->body(), 120))
        );
    }

    /**
     * A username the panel will accept and does not already have.
     *
     * Panels are far stricter about this than this side is — letters, digits,
     * dots, dashes and underscores — so the display name is reduced to that
     * and the local id settles any clash.
     *
     * Public so the rules can be pinned by a test without a database.
     *
     * @param  array<string, bool>  $takenNames  lowercased usernames the panel has
     */
    public function panelUsername(User $user, array $takenNames = []): string
    {
        $base = Str::of((string) $user->name)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9._-]/', '')
            ->limit(20, '')
            ->toString();

        if (strlen($base) < 3) {
            $base = 'user' . $user->id;
        }

        $candidates = [
            $base,
            $base . '-' . $user->id,
            $base . '-' . $user->id . '-2',
        ];

        foreach ($candidates as $candidate) {
            if (!isset($takenNames[Str::lower($candidate)])) {
                return $candidate;
            }
        }

        return $base . '-' . Str::lower(Str::random(6));
    }
}
