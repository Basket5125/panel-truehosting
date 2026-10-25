<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('trial.enabled', false);
        $this->migrator->add('trial.require_email_verification', true);
        $this->migrator->add('trial.require_discord_verification', false);
        $this->migrator->add('trial.require_captcha', true);
        $this->migrator->add('trial.duration_hours', 24);
        $this->migrator->add('trial.max_extensions', 1);
        $this->migrator->add('trial.end_action', 'suspend');
        $this->migrator->add('trial.per_user_limit', 1);
        $this->migrator->add('trial.products', []);
        $this->migrator->add('trial.eggs', []);
    }

    public function down(): void
    {
        foreach ([
            'enabled', 'require_email_verification', 'require_discord_verification',
            'require_captcha', 'duration_hours', 'max_extensions', 'end_action',
            'per_user_limit', 'products', 'eggs',
        ] as $key) {
            $this->migrator->delete('trial.' . $key);
        }
    }
};
