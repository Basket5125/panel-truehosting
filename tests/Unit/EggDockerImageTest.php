<?php

namespace Tests\Unit;

use App\Models\Pterodactyl\Egg;
use Tests\TestCase;

/**
 * Which image a server comes up on. Getting this wrong puts a server on the
 * wrong runtime, which looks like a broken egg rather than a wrong setting,
 * so each shape the panels send is pinned down here.
 */
class EggDockerImageTest extends TestCase
{
    /**
     * Real image maps, copied from the panel this fork runs against. Each of
     * these eggs was coming up on an older runtime than it had available.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function realEggs(): array
    {
        $yolks = 'ghcr.io/pterodactyl/yolks:';
        $ptero = 'ghcr.io/ptero-eggs/yolks:';
        $parker = 'ghcr.io/parkervcp/yolks:';
        $graal = 'ghcr.io/vanes430/java:graaljdk_';

        return [
            'Paper, which defaulted to Java 8' => [[
                'Java 8' => $yolks . 'java_8',
                'Java 11' => $yolks . 'java_11',
                'Java 16' => $yolks . 'java_16',
                'Java 17' => $yolks . 'java_17',
                'Java 18' => $yolks . 'java_18',
                'Java 21' => $yolks . 'java_21',
                'Java 25' => $parker . 'java_25',
                'Java 26' => $parker . 'java_26',
                'GraalJDK 24' => $graal . '24-debian',
                'GraalJDK 26' => $graal . '26-debian',
            ], $graal . '26-debian'],

            'Spigot, which defaulted to Java 11' => [[
                'Java 11' => $ptero . 'java_11',
                'Java 16' => $ptero . 'java_16',
                'Java 17' => $ptero . 'java_17',
                'Java 21' => $ptero . 'java_21',
                'Java 8' => $ptero . 'java_8',
                'Java 25' => $ptero . 'java_25',
                'Java 22' => $ptero . 'java_22',
            ], $ptero . 'java_25'],

            'NeoForge, with lowercase labels' => [[
                'Java 17' => $ptero . 'java_17',
                'java 21' => $ptero . 'java_21',
                'java 22' => $ptero . 'java_22',
                'java 25' => $ptero . 'java_25',
            ], $ptero . 'java_25'],

            'Purpur, already on GraalJDK' => [[
                'GraalJDK 26' => $graal . '26-debian',
                'Java 16' => $parker . 'java_16',
                'Java 25' => $parker . 'java_25',
                'Java 26' => $parker . 'java_26',
            ], $graal . '26-debian'],
        ];
    }

    /**
     * @dataProvider realEggs
     * @param  array<string, string>  $images
     */
    public function test_the_newest_java_is_chosen(array $images, string $expected): void
    {
        $this->enablePreference();

        $this->assertSame($expected, Egg::resolveDockerImage([
            // The panel's own choice is the stale one; it must not win.
            'docker_image' => array_values($images)[0],
            'docker_images' => $images,
        ]));
    }

    public function test_node_and_python_eggs_are_left_alone(): void
    {
        $this->enablePreference();

        $node = [
            'Nodejs 25' => 'ghcr.io/ptero-eggs/yolks:nodejs_25',
            'Nodejs 12' => 'ghcr.io/ptero-eggs/yolks:nodejs_12',
        ];

        // No Java anywhere, so the panel's own field still decides.
        $this->assertSame(
            'ghcr.io/ptero-eggs/yolks:nodejs_25',
            Egg::resolveDockerImage([
                'docker_image' => 'ghcr.io/ptero-eggs/yolks:nodejs_25',
                'docker_images' => $node,
            ])
        );

        $this->assertSame(
            'debian:stable-slim',
            Egg::resolveDockerImage([
                'docker_image' => 'debian:stable-slim',
                'docker_images' => [
                    'Latest Debian' => 'debian:stable-slim',
                    'Debian 13 Trixie' => 'debian:trixie',
                ],
            ])
        );
    }

    public function test_graaljdk_only_breaks_a_tie(): void
    {
        $this->enablePreference();

        // A newer plain Java still beats an older GraalJDK.
        $this->assertSame(
            'ghcr.io/example/java_27',
            Egg::resolveDockerImage(['docker_images' => [
                'GraalJDK 26' => 'ghcr.io/example/graaljdk_26',
                'Java 27' => 'ghcr.io/example/java_27',
            ]])
        );
    }

    public function test_the_preference_can_be_turned_off(): void
    {
        $this->preference(false);

        $this->assertSame(
            'ghcr.io/example/java_8',
            Egg::resolveDockerImage([
                'docker_image' => 'ghcr.io/example/java_8',
                'docker_images' => [
                    'Java 8' => 'ghcr.io/example/java_8',
                    'Java 21' => 'ghcr.io/example/java_21',
                ],
            ])
        );
    }

    /**
     * Settings normally come from the database, which a unit test has no
     * business touching; fake() binds the value into the container instead.
     */
    private function preference(bool $on): void
    {
        \App\Settings\ServerSettings::fake([
            'creation_enabled' => true,
            'enable_upgrade' => false,
            'location_description_enabled' => false,
            'power_controls_enabled' => false,
            'prefer_newest_java' => $on,
            'allocation_limit' => 0,
        ], false);
    }

    private function enablePreference(): void
    {
        $this->preference(true);
    }

    public function test_the_declared_default_wins_over_the_first_entry(): void
    {
        $this->preference(false);

        $image = Egg::resolveDockerImage([
            'docker_image' => 'ghcr.io/example/java_8',
            'default_docker_image' => 'ghcr.io/example/java_21',
            'docker_images' => [
                'Java 8' => 'ghcr.io/example/java_8',
                'Java 21' => 'ghcr.io/example/java_21',
            ],
        ]);

        $this->assertSame('ghcr.io/example/java_21', $image);
    }

    public function test_plain_pterodactyl_still_uses_its_own_field(): void
    {
        $this->preference(false);

        // Pterodactyl sends no default of its own, so nothing changes there.
        $image = Egg::resolveDockerImage([
            'docker_image' => 'ghcr.io/example/java_8',
            'docker_images' => [
                'Java 8' => 'ghcr.io/example/java_8',
                'Java 21' => 'ghcr.io/example/java_21',
            ],
        ]);

        $this->assertSame('ghcr.io/example/java_8', $image);
    }

    public function test_a_json_encoded_map_is_understood(): void
    {
        $this->preference(false);

        $image = Egg::resolveDockerImage([
            'default_docker_image' => '',
            'docker_images' => '{"Java 21":"ghcr.io/example/java_21"}',
        ]);

        $this->assertSame('ghcr.io/example/java_21', $image);
    }

    public function test_the_first_image_is_the_last_resort(): void
    {
        $this->preference(false);

        $image = Egg::resolveDockerImage([
            'docker_images' => ['Only' => 'ghcr.io/example/only'],
        ]);

        $this->assertSame('ghcr.io/example/only', $image);
    }

    public function test_an_egg_with_no_image_at_all_resolves_to_nothing(): void
    {
        $this->preference(false);

        $this->assertSame('', Egg::resolveDockerImage([]));
        $this->assertSame('', Egg::resolveDockerImage(['docker_image' => '  ', 'docker_images' => []]));
    }

    public function test_a_blank_default_does_not_win(): void
    {
        $this->preference(false);

        $image = Egg::resolveDockerImage([
            'default_docker_image' => '   ',
            'docker_image' => 'ghcr.io/example/fallback',
        ]);

        $this->assertSame('ghcr.io/example/fallback', $image);
    }
}
