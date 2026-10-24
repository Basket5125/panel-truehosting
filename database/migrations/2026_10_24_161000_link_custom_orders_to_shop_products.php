<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A direct payment goes through the ordinary checkout, which is built around
 * shop products. The generated product is recorded here so the completed
 * payment can be traced back to the order it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->string('shop_product_id', 32)->nullable()->after('payment_id');
            // first_period | renewal
            $table->string('kind')->default('first_period')->after('status');
            $table->index('shop_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('custom_orders', function (Blueprint $table) {
            $table->dropIndex(['shop_product_id']);
            $table->dropColumn(['shop_product_id', 'kind']);
        });
    }
};
