<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\DiscordUser;
use App\Models\User;
use App\Settings\DiscordSettings;
use App\Settings\UserSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Exception;

class SocialiteController extends Controller
{
    public function redirect(DiscordSettings $discord_settings)
    {
        $scopes = !empty($discord_settings->bot_token) && !empty($discord_settings->guild_id) ? ['guilds.join'] : [];

        return ( Socialite::driver('discord')
            ->scopes($scopes)
            ->redirect());
    }

    /**
     * What Discord's answer to "add this member" actually means.
     *
     * 201 is a member that was added; 204 is one that was in the server
     * already, which is the ordinary case for anyone re-linking and is not a
     * failure — there was simply nothing to do.
     *
     * @see https://discord.com/developers/docs/resources/guild#add-guild-member
     */
    public static function joinOutcome(int $status): string
    {
        if ($status === 204) {
            return 'already';
        }

        return $status >= 200 && $status < 300 ? 'joined' : 'failed';
    }

    public function callback(DiscordSettings $discord_settings, UserSettings $user_settings)
    {
        if (Auth::guest()) {
            return abort(500);
        }

        /** @var User $user */
        $user = Auth::user();
        $discord = Socialite::driver('discord')->user();
        $botToken = $discord_settings->bot_token;
        $guildId = $discord_settings->guild_id;
        $roleId = $discord_settings->role_id;

        //save / update discord_users

        //check if discord account is already linked to an cpgg account
        if (is_null($user->discordUser)) {
            $discordLinked = DiscordUser::where('id', '=', $discord->id)->first();
            if ($discordLinked !== null) {
                return redirect()->route('profile.index')->with(
                        'error',
                        __('That Discord account is already linked to another account here.')
                    );
            }

            //create discord user in db
            DiscordUser::create(array_merge($discord->user, ['user_id' => Auth::user()->id]));
            $user->refresh();

            //update user
            Auth::user()->increment('credits', $user_settings->credits_reward_after_verify_discord);
            Auth::user()->increment('server_limit', $user_settings->server_limit_increment_after_verify_discord);
            Auth::user()->update(['discord_verified_at' => now()]);
        } else {
            $user->discordUser->update($discord->user);
        }

        // Put the account into the Discord server. By this point the link
        // itself has already been made, so whatever happens here the answer is
        // never a bare "failed" — it says what was and was not done.
        $joined = 'skipped';
        $detail = '';

        if (! empty($guildId) && ! empty($botToken)) {
            try {
                $response = Http::withHeaders(
                    [
                        'Authorization' => 'Bot '. $botToken,
                        'Content-Type' => 'application/json',
                    ]
                )->put(
                    "https://discord.com/api/guilds/{$guildId}/members/{$discord->id}",
                    ['access_token' => $discord->token]
                );

                $joined = self::joinOutcome($response->status());

                if ($joined === 'failed') {
                    $detail = $response->status() . ' ' . (string) ($response->json('message') ?? '');

                    logger()->warning('Could not add the account to the Discord server', [
                        'user_id' => $user->id,
                        'discord_id' => $discord->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }

                // The role is still wanted when the account was already there;
                // addOrRemoveRole() reports its own problems and never throws.
                if ($joined !== 'failed' && !empty($roleId)) {
                    $user->refresh()->discordUser?->addOrRemoveRole('add', $roleId);
                }
            } catch (Exception $e) {
                $joined = 'failed';
                $detail = $e->getMessage();

                logger()->error('Discord join threw', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($joined === 'failed') {
            $message = __('Discord account linked, but we could not add you to our Discord server.');

            // The reason only helps whoever can act on it.
            if ($user->hasRole('Admin') && $detail !== '') {
                $message .= ' [' . trim($detail) . ']';
            }

            return redirect()->route('profile.index')->with('warning', $message);
        }

        if ($joined === 'already') {
            return redirect()->route('profile.index')->with(
                'success',
                __('Discord account linked. You are already on our Discord server, so there was nothing to add.')
            );
        }

        return redirect()->route('profile.index')->with(
            'success',
            __('Discord account linked!')
        );
    }
}
