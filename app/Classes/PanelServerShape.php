<?php

namespace App\Classes;

/**
 * The shape every consumer of a server payload is allowed to assume.
 *
 * Controllers, services and Blade views all reach into these payloads, and the
 * panels disagree about which keys they send: Pterodactyl nests the location,
 * node, egg and nest under `relationships` and only when the relation was
 * included, while the FeatherPanel-shaped routes send flat ids and no
 * relationships at all. Every missing key was a 500 waiting for whoever read
 * it first — "Undefined array key node", then "allocation", then "location".
 *
 * Rather than guarding each of those reads for ever, the drivers push their
 * answer through here, so the keys always exist and are null when unknown.
 */
class PanelServerShape
{
    /**
     * Fill in whatever the panel left out, without touching what it sent.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    public static function normalise(array $server): array
    {
        $relation = fn (array $attributes = []) => ['attributes' => $attributes];

        $defaults = [
            'id' => null,
            'identifier' => null,
            'uuid' => null,
            'name' => null,
            'description' => null,
            'status' => null,
            'suspended' => null,
            'node' => null,
            'egg' => null,
            'nest' => null,
            'allocation' => null,
            'limits' => [
                'memory' => null, 'swap' => null, 'disk' => null,
                'io' => null, 'cpu' => null, 'threads' => null,
            ],
            'feature_limits' => [
                'databases' => null, 'backups' => null, 'allocations' => null,
            ],
            'relationships' => [
                'location' => $relation(['id' => null, 'short' => null, 'long' => null]),
                'node' => $relation(['id' => null, 'name' => null]),
                'egg' => $relation(['id' => null, 'name' => null]),
                'nest' => $relation(['id' => null, 'name' => null]),
                'allocations' => ['data' => []],
            ],
        ];

        return self::fill($server, $defaults);
    }

    /**
     * Recursive array_replace that only adds what is absent. A key the panel
     * sent is never overwritten, not even when it is null.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private static function fill(array $actual, array $defaults): array
    {
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $actual)) {
                $actual[$key] = $default;

                continue;
            }

            if (is_array($default) && is_array($actual[$key])) {
                $actual[$key] = self::fill($actual[$key], $default);
            }
        }

        return $actual;
    }
}
