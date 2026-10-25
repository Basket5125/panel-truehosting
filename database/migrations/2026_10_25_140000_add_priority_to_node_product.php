<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which node a product is deployed on was decided by whatever came first out
 * of the database. This lets the operator say which node should be filled
 * first, the way configurable plans already can.
 *
 * Default 0 everywhere, so the order is unchanged until somebody sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_product', function (Blueprint $table) {
            $table->smallInteger('priority')->default(0)->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('node_product', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
