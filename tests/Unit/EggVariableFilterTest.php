<?php

namespace Tests\Unit;

use App\Classes\PterodactylClient;
use App\Models\Pterodactyl\Egg;
use App\Settings\PterodactylSettings;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Egg variables arrive from a form, so the list of what a customer may set
 * has to come from the egg and not from the request.
 */
class EggVariableFilterTest extends TestCase
{
    private function environment(): array
    {
        return [
            [
                'name' => 'Minecraft Version',
                'env_variable' => 'MINECRAFT_VERSION',
                'default_value' => 'latest',
                'rules' => 'nullable|string|max:20',
                'user_viewable' => true,
                'user_editable' => true,
            ],
            [
                // Pterodactyl's own Paper egg marks this one as off limits.
                'name' => 'Download Path',
                'env_variable' => 'DL_PATH',
                'default_value' => '',
                'rules' => 'nullable|string',
                'user_viewable' => false,
                'user_editable' => false,
            ],
        ];
    }

    private function build(array $submitted): array
    {
        $egg = new Egg(['environment' => $this->environment()]);

        $client = new PterodactylClient(new PterodactylSettings());
        $method = new ReflectionMethod($client, 'getEnvironmentVariables');
        $method->setAccessible(true);

        return $method->invoke($client, $egg, json_encode($submitted));
    }

    public function test_an_editable_variable_is_taken_from_the_request(): void
    {
        $out = $this->build(['MINECRAFT_VERSION' => '1.20.4']);

        $this->assertSame('1.20.4', $out['MINECRAFT_VERSION']);
    }

    public function test_a_variable_the_egg_hides_cannot_be_set(): void
    {
        $out = $this->build([
            'MINECRAFT_VERSION' => '1.20.4',
            'DL_PATH' => 'https://attacker.example/evil.jar',
        ]);

        $this->assertSame('1.20.4', $out['MINECRAFT_VERSION']);
        $this->assertArrayNotHasKey('DL_PATH', $out);
    }

    public function test_an_unknown_variable_is_dropped(): void
    {
        $out = $this->build(['TOTALLY_MADE_UP' => 'x']);

        $this->assertArrayNotHasKey('TOTALLY_MADE_UP', $out);
    }

    public function test_defaults_are_still_filled_in(): void
    {
        $out = $this->build([]);

        $this->assertSame('latest', $out['MINECRAFT_VERSION']);
    }
}
