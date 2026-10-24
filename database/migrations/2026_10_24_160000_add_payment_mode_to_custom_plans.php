<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A configured server can be billed from credits, as before, or paid for
 * directly with money each period. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_plans', function (Blueprint $table) {
            // credits | direct | both — what the customer may choose.
            $table->string('payment_modes')->default('credits');
            // What one credit costs in real money, used for the direct price.
            $table->double('credit_rate')->default(0);
            $table->string('currency_code', 3)->default('EUR');
            // Days after expiry before an unpaid server is suspended.
            $table->integer('grace_days')->default(3);
        });

        Schema::table('servers', function (Blueprint $table) {
            // null keeps the stock credit billing, so existing rows are untouched.
            $table->string('payment_mode')->nullable()->after('product_id');
            $table->dateTime('paid_until')->nullable()->after('last_billed');
        });

        // A configured order waiting for its first payment. The server is only
        // provisioned once the money has actually arrived.
        Schema::create('custom_orders', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('custom_plan_id', 32);
            $table->unsignedBigInteger('egg_id');
            $table->string('name');
            $table->json('resources');
            $table->bigInteger('price_credits');
            $table->bigInteger('price_money');
            $table->string('currency_code', 3)->default('EUR');
            $table->string('payment_id', 64)->nullable();
            // pending | paid | provisioned | failed | expired
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->string('server_id', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_orders');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'paid_until']);
        });

        Schema::table('custom_plans', function (Blueprint $table) {
            $table->dropColumn(['payment_modes', 'credit_rate', 'currency_code', 'grace_days']);
        });
    }
};
