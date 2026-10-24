<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Adds the FeatherPanel driver settings. Purely additive: an untouched
 * CtrlPanel database keeps working, and the driver defaults to Pterodactyl
 * so behaviour does not change until it is switched over in the panel.
 */
class CreateFeatherPanelSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('featherpanel.driver', 'pterodactyl');
        $this->migrator->add('featherpanel.panel_url', '');
        $this->migrator->add('featherpanel.admin_token', '');
        $this->migrator->add('featherpanel.user_token', '');
        $this->migrator->add('featherpanel.per_page_limit', 50);
    }

    public function down(): void
    {
        $this->migrator->delete('featherpanel.driver');
        $this->migrator->delete('featherpanel.panel_url');
        $this->migrator->delete('featherpanel.admin_token');
        $this->migrator->delete('featherpanel.user_token');
        $this->migrator->delete('featherpanel.per_page_limit');
    }
}
