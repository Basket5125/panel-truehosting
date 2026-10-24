<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan now carries two price lists: one in credits and one in money. The
 * customer sees whichever matches the payment they picked.
 *
 * Plans created before this still work — when every money price is 0 the
 * money total is derived from `credit_rate`, exactly as it was.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'money_price_cpu',
        'money_price_memory',
        'money_price_disk',
        'money_price_backup',
        'money_price_database',
        'money_price_allocation',
    ];

    public function up(): void
    {
        Schema::table('custom_plans', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                // 1000x integer scale, like every other price in the panel.
                $table->bigInteger($column)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_plans', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
