<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A big order is not always a memory-heavy one. A plan can now route on vCPU
 * as well, and either threshold on its own is enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_plans', function (Blueprint $table) {
            // Same unit as cpu_min/cpu_max: 100 = 1 vCore. Null = not used.
            $table->integer('large_order_cpu')->nullable()->after('large_order_memory');
        });
    }

    public function down(): void
    {
        Schema::table('custom_plans', function (Blueprint $table) {
            $table->dropColumn('large_order_cpu');
        });
    }
};
