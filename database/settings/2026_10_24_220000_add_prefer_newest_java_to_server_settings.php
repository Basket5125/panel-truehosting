<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('server.prefer_newest_java', true);
    }

    public function down(): void
    {
        $this->migrator->delete('server.prefer_newest_java');
    }
};
