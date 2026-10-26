<?php

namespace App\Classes;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

/**
 * A client response built here instead of received from a panel.
 *
 * FeatherPanel answers with {success, data}, while everything that consumes a
 * driver reads Pterodactyl's {object, attributes} envelope. Translating inside
 * the driver keeps that difference out of the rest of the application — and
 * since the callers also ask ->successful() and ->status(), the translation has
 * to come back as a real response rather than a bare array.
 */
class PanelResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function json(array $payload, int $status = 200): Response
    {
        return new Response(new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload)
        ));
    }

    /**
     * Pterodactyl's single resource envelope.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function attributes(array $attributes, string $object = 'server', int $status = 200): Response
    {
        return self::json([
            'object' => $object,
            'attributes' => $attributes,
        ], $status);
    }
}
