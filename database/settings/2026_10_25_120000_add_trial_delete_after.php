<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // 0 keeps the existing behaviour: a suspended trial stays suspended.
        $this->migrator->add('trial.delete_after_hours', 0);
    }

    public function down(): void
    {
        $this->migrator->delete('trial.delete_after_hours');
    }
};
