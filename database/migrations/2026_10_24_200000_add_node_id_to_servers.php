<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which node a server landed on was only ever known to the game panel — the
 * creation service worked it out and then threw it away, because the column
 * did not exist. Reporting per node needs it kept.
 *
 * Nullable, so rows from before this (and a stock CtrlPanel database) stay
 * valid; `cp:servers:syncnodes` fills them in from the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedBigInteger('node_id')->nullable()->after('product_id');
            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['node_id']);
            $table->dropColumn('node_id');
        });
    }
};
