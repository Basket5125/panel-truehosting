<?php

namespace App\Services;

use App\Classes\FeatherPanelClient;
use App\Classes\PterodactylClient;
use App\Contracts\GamePanelClient;
use App\Settings\FeatherPanelSettings;
use App\Settings\PterodactylSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a panel call against the configured driver and, if that is refused,
 * against the other one when its credentials are filled in.
 *
 * The two panels overlap: a FeatherPanel instance answers a good deal of the
 * Pterodactyl-shaped API, but not all of it, and the parts it does not answer
 * come back as an authentication or validation error rather than a clear
 * "wrong panel". Rather than make the operator guess which driver a given
 * action needs, the second one is simply tried.
 */
class GamePanelFallback
{
    public function __construct(private GamePanelClient $primary)
    {
    }

    /**
     * The driver that is not currently selected, or null when it has no
     * credentials to work with.
     */
    public function alternate(): ?GamePanelClient
    {
        try {
            $feather = app(FeatherPanelSettings::class);

            if ($feather->isActive()) {
                $ptero = app(PterodactylSettings::class);

                return $this->configured($ptero->getUrl(), $ptero->admin_token)
                    ? new PterodactylClient($ptero)
                    : null;
            }

            return $this->configured($feather->getUrl(), $feather->admin_token)
                ? new FeatherPanelClient($feather)
                : null;
        } catch (Throwable $e) {
            Log::warning('Could not build the fallback game panel client', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Run $call against the active panel, then against the other one if the
     * first did not succeed.
     *
     * @param  callable(GamePanelClient): \Illuminate\Http\Client\Response|null  $call
     * @return array{response: \Illuminate\Http\Client\Response|null, client: GamePanelClient, fell_back: bool}
     */
    public function attempt(callable $call, string $what): array
    {
        $response = $this->run($call, $this->primary, $what);

        if ($response !== null && $response->successful()) {
            return ['response' => $response, 'client' => $this->primary, 'fell_back' => false];
        }

        $alternate = $this->alternate();

        if ($alternate === null) {
            Log::info('No fallback game panel is configured, so the refusal stands', [
                'what' => $what,
                'panel' => $this->primary->panelName(),
                'status' => $response?->status(),
            ]);

            return ['response' => $response, 'client' => $this->primary, 'fell_back' => false];
        }

        Log::info('Retrying on the other game panel', [
            'what' => $what,
            'from' => $this->primary->panelName(),
            'to' => $alternate->panelName(),
            'status' => $response?->status(),
        ]);

        $second = $this->run($call, $alternate, $what);

        // The first panel's answer is the one worth reporting when neither
        // worked: it is the one the operator configured.
        if ($second !== null && $second->successful()) {
            return ['response' => $second, 'client' => $alternate, 'fell_back' => true];
        }

        return ['response' => $response ?? $second, 'client' => $this->primary, 'fell_back' => false];
    }

    /** @return \Illuminate\Http\Client\Response|null */
    private function run(callable $call, GamePanelClient $client, string $what)
    {
        try {
            return $call($client);
        } catch (Throwable $e) {
            Log::warning('Game panel call threw', [
                'what' => $what,
                'panel' => $client->panelName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function configured(string $url, ?string $token): bool
    {
        return trim($url) !== '' && trim($url) !== '/' && !empty($token);
    }
}
