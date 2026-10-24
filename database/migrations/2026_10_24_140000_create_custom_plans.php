<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable plans: the customer picks the resources, the price is derived
 * from per-unit rates. Everything here is additive, so a stock CtrlPanel
 * database keeps working and can be migrated in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_plans', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('disabled')->default(false);
            $table->string('billing_period')->default('monthly');

            // Unit prices, in the same 1000x integer scale as every other price.
            $table->bigInteger('price_cpu')->default(0);        // per 1 vCore (100%)
            $table->bigInteger('price_memory')->default(0);     // per 1 GB
            $table->bigInteger('price_disk')->default(0);       // per 1 GB
            $table->bigInteger('price_backup')->default(0);     // per backup slot
            $table->bigInteger('price_database')->default(0);   // per database
            $table->bigInteger('price_allocation')->default(0); // per port

            // Bounds the customer can choose between. CPU is a percentage,
            // memory and disk are MB, to match the product columns.
            $table->integer('cpu_min')->default(100);
            $table->integer('cpu_max')->default(800);
            $table->integer('cpu_step')->default(100);
            $table->integer('memory_min')->default(1024);
            $table->integer('memory_max')->default(16384);
            $table->integer('memory_step')->default(1024);
            $table->integer('disk_min')->default(5120);
            $table->integer('disk_max')->default(102400);
            $table->integer('disk_step')->default(5120);
            $table->integer('backups_min')->default(0);
            $table->integer('backups_max')->default(10);
            $table->integer('databases_min')->default(0);
            $table->integer('databases_max')->default(5);
            $table->integer('allocations_min')->default(1);
            $table->integer('allocations_max')->default(5);

            // Buying more of a resource makes each unit cheaper.
            $table->double('discount_per_unit')->default(0); // % off per extra unit
            $table->double('discount_max')->default(0);      // cap, in %

            // Above this much memory the plan may only be deployed on nodes
            // flagged for large orders.
            $table->integer('large_order_memory')->nullable();

            // Fixed values that are not customer-configurable.
            $table->integer('swap')->default(0);
            $table->integer('io')->default(500);
            $table->boolean('oom_killer')->default(false);
            $table->bigInteger('minimum_credits')->default(-1);

            $table->timestamps();
        });

        Schema::create('custom_plan_node', function (Blueprint $table) {
            $table->string('custom_plan_id', 32);
            $table->unsignedBigInteger('node_id');
            // Lower runs first; the next node is tried when one cannot take it.
            $table->integer('priority')->default(0);
            $table->boolean('allow_large')->default(true);

            $table->primary(['custom_plan_id', 'node_id']);
            $table->foreign('custom_plan_id')->references('id')->on('custom_plans')->onDelete('cascade');
        });

        Schema::create('custom_plan_egg', function (Blueprint $table) {
            $table->string('custom_plan_id', 32);
            $table->unsignedBigInteger('egg_id');

            $table->primary(['custom_plan_id', 'egg_id']);
            $table->foreign('custom_plan_id')->references('id')->on('custom_plans')->onDelete('cascade');
        });

        Schema::table('products', function (Blueprint $table) {
            // Set on the product generated for a configured order, so these can
            // be told apart from the catalogue products.
            $table->string('custom_plan_id', 32)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('custom_plan_id');
        });

        Schema::dropIfExists('custom_plan_egg');
        Schema::dropIfExists('custom_plan_node');
        Schema::dropIfExists('custom_plans');
    }
};
