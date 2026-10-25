<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Pterodactyl\Egg;
use App\Models\Server;
use App\Models\User;
use App\Settings\TrialSettings;
use App\Settings\UserSettings;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything a trial server is allowed to be.
 *
 * The wizard asks this what to show and the controller asks it whether a
 * request may go through, so there is one place that decides and no way for
 * the two to drift apart.
 */
class TrialService
{
    public function __construct(
        private TrialSettings $settings,
        private UserSettings $userSettings,
        private ServerCreationService $creator,
    ) {
    }

    /** Whether trials are on offer at all. */
    public function offered(): bool
    {
        return $this->settings->enabled && $this->products()->isNotEmpty();
    }

    /**
     * The packages a trial may be built from.
     *
     * Only ones the operator picked, and only ones that are still usable:
     * a deleted or disabled product must not keep showing up in the wizard.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        $ids = array_filter((array) $this->settings->products);

        if (empty($ids)) {
            return collect();
        }

        return Product::whereIn('id', $ids)
            ->where('disabled', false)
            ->with('eggs.nest')
            ->orderBy('price')
            ->get();
    }

    /**
     * The software a trial may run on a given package: the eggs attached to
     * that product, narrowed to the operator's list when they set one.
     *
     * @return Collection<int, Egg>
     */
    public function eggsFor(Product $product): Collection
    {
        $allowed = array_filter((array) $this->settings->eggs);
        $eggs = $product->eggs;

        if (!empty($allowed)) {
            $eggs = $eggs->filter(fn (Egg $egg) => in_array((string) $egg->id, array_map('strval', $allowed), true));
        }

        return $eggs->values();
    }

    /**
     * What kind of block this is, so the page can offer the right way out.
     *
     * 'profile' is something the customer can go and fix; 'used' and
     * 'closed' are not, and pointing them at their profile would only waste
     * a click.
     */
    public function blockedKind(User $user): ?string
    {
        if (!$this->offered()) {
            return 'closed';
        }

        if (($this->settings->require_email_verification && !$user->hasVerifiedEmail())
            || ($this->settings->require_discord_verification && !$user->discordUser)) {
            return 'profile';
        }

        if ($this->trialsTaken($user) >= max(1, $this->settings->per_user_limit)) {
            return 'used';
        }

        if ($user->servers()->count() >= $user->server_limit) {
            return 'limit';
        }

        return null;
    }

    /**
     * Why this user may not take a trial, or null when they may.
     *
     * Returned as a message rather than a boolean so the wizard can say what
     * is missing instead of just refusing.
     */
    public function blockedReason(User $user): ?string
    {
        if (!$this->offered()) {
            return __('Trial servers are not available right now.');
        }

        if ($this->settings->require_email_verification && !$user->hasVerifiedEmail()) {
            return __('Confirm your e-mail address first.');
        }

        if ($this->settings->require_discord_verification && !$user->discordUser) {
            return __('Link your Discord account first.');
        }

        if ($this->trialsTaken($user) >= max(1, $this->settings->per_user_limit)) {
            return __('You have already used your trial.');
        }

        if ($user->servers()->count() >= $user->server_limit) {
            return __('Server limit reached for this user.');
        }

        return null;
    }

    /**
     * How many trials this account has ever taken.
     *
     * Counted on the user and not by looking for trial servers, because with
     * "delete when the time is up" the evidence is gone and the same person
     * would be handed a fresh trial.
     */
    public function trialsTaken(User $user): int
    {
        return (int) ($user->trials_used ?? 0);
    }

    /** Whether the captcha has to be solved before a trial is created. */
    public function requiresCaptcha(): bool
    {
        return $this->settings->require_captcha;
    }

    public function durationHours(): int
    {
        return max(1, (int) $this->settings->duration_hours);
    }

    public function maxExtensions(): int
    {
        return max(0, (int) $this->settings->max_extensions);
    }

    public function endAction(): string
    {
        return $this->settings->end_action === 'delete' ? 'delete' : 'suspend';
    }

    /**
     * How long a suspended trial is kept before it is removed as well.
     * 0 means it is kept indefinitely, and it is irrelevant when the trial is
     * deleted outright.
     */
    public function deleteAfterHours(): int
    {
        return $this->endAction() === 'suspend'
            ? max(0, (int) $this->settings->delete_after_hours)
            : 0;
    }

    /**
     * Create the trial. Every choice is checked against the settings again
     * here; nothing is taken on trust from the wizard.
     *
     * @param  array<string, mixed>  $data  name, product, egg, egg_variables
     *
     * @throws Exception
     */
    public function create(User $user, array $data): Server
    {
        $reason = $this->blockedReason($user);

        if ($reason !== null) {
            throw new Exception($reason);
        }

        $product = $this->products()->firstWhere('id', $data['product'] ?? null);

        if (!$product) {
            throw new Exception(__('That package is not part of the trial offer.'));
        }

        $egg = $this->eggsFor($product)->firstWhere('id', (int) ($data['egg'] ?? 0));

        if (!$egg) {
            throw new Exception(__('That software is not part of the trial offer.'));
        }

        // One trial per account is enforced above, but two requests arriving
        // together would both pass that check.
        $lock = Cache::lock('trial_create_' . $user->id, 30);

        if (!$lock->get()) {
            throw new Exception(__('Please wait a moment before trying again.'));
        }

        try {
            if ($this->blockedReason($user) !== null) {
                throw new Exception(__('You have already used your trial.'));
            }

            $location = $this->locationFor($product);

            $server = $this->creator->handle($user, $product, [
                'name' => $data['name'],
                'egg_id' => $egg->id,
                'location_id' => $location,
                'billing_priority' => $product->default_billing_priority,
                'egg_variables' => $data['egg_variables'] ?? null,
                // A trial costs nothing, so no credits may be required or taken.
                'skip_credits' => true,
            ]);

            try {
                $server->forceFill([
                    'trial_ends_at' => now()->addHours($this->durationHours()),
                    'trial_extensions' => 0,
                ])->save();
            } catch (\Throwable $e) {
                // Without the expiry this would be a free server that never
                // ends, so it does not get to exist.
                try {
                    $server->delete();
                } catch (\Throwable $ignored) {
                    logger()->error('Trial stamp failed and the server could not be removed', [
                        'server_id' => $server->id,
                    ]);
                }

                throw $e;
            }

            // Counted once the server actually exists, so a failed attempt
            // does not burn the customer's one trial.
            User::query()->whereKey($user->id)->increment('trials_used');

            return $server;
        } finally {
            $lock->release();
        }
    }

    /** A node the product is attached to, by way of its location. */
    private function locationFor(Product $product): string
    {
        $node = $product->nodes()->where('nodes.disabled', false)->first();

        if (!$node) {
            throw new Exception(__('No node can take a trial server right now.'));
        }

        return (string) $node->location_id;
    }

    /**
     * Turn a trial into a paid server: take the credits and drop the expiry.
     *
     * @throws Exception
     */
    public function purchase(Server $server, User $user): void
    {
        if ($server->user_id !== $user->id) {
            throw new Exception(__('This is not your Server!'));
        }

        if (!$server->isTrial()) {
            throw new Exception(__('This server is not a trial.'));
        }

        $product = $server->product;

        if (!$product) {
            throw new Exception(__('This trial has no package behind it any more.'));
        }

        $price = (int) round($product->price);

        DB::transaction(function () use ($server, $user, $price) {
            // Lock the row so two clicks cannot both pass the balance check.
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (!$fresh || $fresh->credits < $price) {
                throw new Exception(__('You do not have enough credits to buy this server.'));
            }

            $locked = Server::query()->whereKey($server->id)->lockForUpdate()->first();

            if (!$locked || !$locked->isTrial()) {
                throw new Exception(__('This server is not a trial.'));
            }

            $fresh->decrement('credits', $price);

            $locked->forceFill([
                'trial_ends_at' => null,
                'trial_extensions' => 0,
                'last_billed' => now(),
            ])->save();
        });

        Cache::forget('user_credits_left:' . $user->id);

        // An expired trial was suspended on the game panel too, so clearing
        // the column alone would hand over a server that is still switched off
        // there. Done after the transaction: it is a network call.
        $this->wake($server->fresh());
    }

    /**
     * Give a trial one more period, if the operator allows it.
     *
     * @throws Exception
     */
    public function extend(Server $server, User $user): void
    {
        if ($server->user_id !== $user->id) {
            throw new Exception(__('This is not your Server!'));
        }

        if (!$server->isTrial()) {
            throw new Exception(__('This server is not a trial.'));
        }

        if ($server->trial_extensions >= $this->maxExtensions()) {
            throw new Exception(__('This trial cannot be extended any further.'));
        }

        // Extend from whichever is later, so an early click loses nothing and
        // an expired trial does not get a period that is already over.
        $from = $server->trial_ends_at->isPast() ? now() : $server->trial_ends_at;

        $server->forceFill([
            'trial_ends_at' => $from->copy()->addHours($this->durationHours()),
            'trial_extensions' => $server->trial_extensions + 1,
        ])->save();

        $this->wake($server);
    }

    /**
     * Lift a suspension that this feature put there, on the game panel as well
     * as in the database.
     *
     * A failure is logged rather than thrown: the customer has already paid,
     * or already earned the extension, and must not be told it did not happen.
     */
    private function wake(?Server $server): void
    {
        if (!$server || !$server->suspended) {
            return;
        }

        try {
            $server->unSuspend();
        } catch (\Throwable $e) {
            logger()->warning('Could not unsuspend a server after a trial change', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
