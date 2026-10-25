<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Read from configuration rather than a hard-coded list, because which
     * proxy sits in front of the panel is a deployment detail.
     *
     * It has to come from config and not env() directly: once `config:cache`
     * has run, env() returns null outside the config files and the proxy would
     * silently stop being trusted.
     */
    public function __construct()
    {
        $proxies = config('app.trusted_proxies');

        if ($proxies === null || $proxies === '') {
            return;
        }

        $this->proxies = $proxies === '*' || $proxies === '**'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', (string) $proxies))));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
