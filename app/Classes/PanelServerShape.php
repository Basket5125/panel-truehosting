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
     * The two fields a newly created server is useless without: the panel id to
     * act on it with, and the identifier its console link is built from.
     *
     * Read out of whichever envelope the panel used. Pterodactyl answers
     * {attributes: {...}}; the FeatherPanel-shaped routes answer
     * {success, data: {server: {...}}}; and a panel that speaks one dialect on
     * its read routes has still been seen to answer in the other on its
     * writes. Reading one fixed path is how a server came to be saved with no
     * identifier at all — a blank engine on its card and a manage link that
     * went to a 404.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{id: int|string, identifier: string}|null
     */
    public static function identity(?array $body): ?array
    {
        if (!$body) {
            return null;
        }

        foreach (self::candidates($body) as $record) {
            if (!is_array($record)) {
                continue;
            }

            $id = $record['id'] ?? null;
            // Pterodactyl's "identifier" is FeatherPanel's short uuid.
            $identifier = $record['identifier'] ?? ($record['uuidShort'] ?? null);

            if (!empty($id) && !empty($identifier)) {
                return ['id' => $id, 'identifier' => $identifier];
            }
        }

        return null;
    }

    /**
     * Every place a panel has been seen to put a server record, outermost
     * first.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, mixed>
     */
    private static function candidates(array $body): array
    {
        $attributes = $body['attributes'] ?? null;
        $data = $body['data'] ?? null;

        return [
            $attributes,
            is_array($attributes) ? ($attributes['attributes'] ?? null) : null,
            is_array($data) ? ($data['server'] ?? null) : null,
            is_array($data) ? ($data['attributes'] ?? null) : null,
            $data,
            $body['server'] ?? null,
            $body,
        ];
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
