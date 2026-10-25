<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A configured server paid for with money is provisioned later, once the
 * payment arrives, so the egg settings the customer chose have to wait in the
 * order with the rest of the configuration. Without this they were silently
 * dropped and the server came up on the egg's defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->text('egg_variables')->nullable()->after('resources');
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropColumn('egg_variables');
        });
    }
};
