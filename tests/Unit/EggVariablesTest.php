<?php

namespace Tests\Unit;

use App\Models\Pterodactyl\Egg;
use App\Services\EggVariables;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The one place that decides what a customer may set on an egg. Every page
 * that creates a server goes through it, so the rules are pinned here.
 */
class EggVariablesTest extends TestCase
{
    private function egg(): Egg
    {
        return new Egg(['environment' => [
            [
                'name' => 'Minecraft Version',
                'env_variable' => 'MINECRAFT_VERSION',
                'default_value' => 'latest',
                'rules' => 'nullable|string|max:20',
                'user_viewable' => true,
                'user_editable' => true,
            ],
            [
                'name' => 'Edition',
                'env_variable' => 'EDITION',
                'default_value' => 'paper',
                'rules' => 'required|in:paper,folia',
                'user_viewable' => true,
                'user_editable' => true,
            ],
            [
                'name' => 'Download Path',
                'env_variable' => 'DL_PATH',
                'default_value' => '',
                'rules' => 'nullable|string',
                'user_viewable' => false,
                'user_editable' => false,
            ],
        ]]);
    }

    public function test_only_the_customer_facing_variables_are_offered(): void
    {
        $offered = array_column((new EggVariables())->forCustomer($this->egg()), 'env_variable');

        $this->assertSame(['MINECRAFT_VERSION', 'EDITION'], $offered);
    }

    public function test_a_fixed_set_of_values_becomes_a_list(): void
    {
        $offered = collect((new EggVariables())->forCustomer($this->egg()))->keyBy('env_variable');

        $this->assertSame(['paper', 'folia'], $offered['EDITION']['options']);
        $this->assertSame([], $offered['MINECRAFT_VERSION']['options']);
    }

    public function test_a_hidden_variable_cannot_be_set(): void
    {
        $values = (new EggVariables())->validate($this->egg(), [
            'MINECRAFT_VERSION' => '1.20.4',
            'DL_PATH' => 'https://attacker.example/evil.jar',
        ]);

        $this->assertSame('1.20.4', $values['MINECRAFT_VERSION']);
        $this->assertArrayNotHasKey('DL_PATH', $values);
    }

    public function test_the_eggs_own_rules_are_enforced(): void
    {
        $this->expectException(ValidationException::class);

        (new EggVariables())->validate($this->egg(), ['EDITION' => 'something-else']);
    }

    public function test_a_missing_variable_falls_back_to_its_default(): void
    {
        $values = (new EggVariables())->validate($this->egg(), []);

        $this->assertSame('latest', $values['MINECRAFT_VERSION']);
        $this->assertSame('paper', $values['EDITION']);
    }

    public function test_an_array_where_a_value_was_expected_is_ignored(): void
    {
        $values = (new EggVariables())->validate($this->egg(), [
            'MINECRAFT_VERSION' => ['not', 'a', 'string'],
        ]);

        $this->assertSame('latest', $values['MINECRAFT_VERSION']);
    }

    public function test_an_egg_with_nothing_to_set_produces_nothing(): void
    {
        $egg = new Egg(['environment' => []]);

        $this->assertSame([], (new EggVariables())->validate($egg, ['X' => 'y']));
        $this->assertNull((new EggVariables())->validatedJson($egg, []));
    }
}
