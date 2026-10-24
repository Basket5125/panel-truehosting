<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Off by default: the power buttons go through the game panel's client
        // API, which only works when the configured client key belongs to an
        // account allowed to touch customer servers. That is a deployment
        // detail, so nobody gets the buttons until they say so.
        $this->migrator->add('server.power_controls_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->delete('server.power_controls_enabled');
    }
};
