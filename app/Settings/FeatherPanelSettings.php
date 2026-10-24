<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FeatherPanelSettings extends Settings
{
    /** Which panel the application provisions servers on. */
    public string $driver = 'pterodactyl';

    public string $panel_url = '';
    public string $admin_token = '';
    public string $user_token = '';
    public int $per_page_limit = 50;

    public static function group(): string
    {
        return 'featherpanel';
    }

    /**
     * Get url with ensured ending backslash
     *
     * @return string
     */
    public function getUrl(): string
    {
        return str_ends_with($this->panel_url, '/') ? $this->panel_url : $this->panel_url . '/';
    }

    public function isActive(): bool
    {
        return $this->driver === 'featherpanel';
    }

    /**
     * @return array<string, string>
     */
    public static function getDriverOptions(): array
    {
        return [
            'pterodactyl' => 'Pterodactyl',
            'featherpanel' => 'FeatherPanel',
        ];
    }

    /**
     * Summary of validations array
     * @return array<string, string>
     */
    public static function getValidations()
    {
        return [
            'driver' => 'required|string|in:pterodactyl,featherpanel',
            // Only the driver is mandatory; the credentials below are checked
            // when FeatherPanel is actually selected.
            'panel_url' => 'nullable|string|url',
            'admin_token' => 'nullable|string',
            'user_token' => 'nullable|string',
            'per_page_limit' => 'required|integer|min:1|max:10000',
        ];
    }

    /**
     * Summary of optionTypes
     * Only used for the settings page
     * @return array<array<'type'|'label'|'description'|'options', string|bool|float|int|array<string, string>>>
     */
    public static function getOptionInputData()
    {
        return [
            'category_icon' => 'fas fa-feather',
            'position' => 13,
            'driver' => [
                'label' => 'Game panel',
                'type' => 'select',
                'options' => self::getDriverOptions(),
                'description' => 'Which panel servers are created on. Switching this changes every API call the application makes.',
            ],
            'panel_url' => [
                'label' => 'Panel URL',
                'type' => 'string',
                'description' => 'The URL to your FeatherPanel instance, for example https://panel.example.com.',
            ],
            'admin_token' => [
                'label' => 'Admin API key',
                'type' => 'string',
                'description' => 'An API key of a staff account. It needs the admin permission nodes for users, servers, nodes, locations, realms, spells and allocations.',
            ],
            'user_token' => [
                'label' => 'User API key',
                'type' => 'string',
                'description' => 'Optional. Used for the client endpoints; leave empty to reuse the admin key.',
            ],
            'per_page_limit' => [
                'label' => 'Per Page Limit',
                'type' => 'number',
                'description' => 'How many records to request per page. Only change this when needed.',
            ],
        ];
    }
}
