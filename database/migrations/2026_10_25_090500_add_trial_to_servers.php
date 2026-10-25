<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trial server is an ordinary server with an expiry and a counter for how
 * many times it has been extended. Both nullable, so a stock CtrlPanel
 * database stays valid and every existing server reads as "not a trial".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dateTime('trial_ends_at')->nullable()->after('paid_until');
            $table->unsignedSmallInteger('trial_extensions')->default(0)->after('trial_ends_at');
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['trial_ends_at']);
            $table->dropColumn(['trial_ends_at', 'trial_extensions']);
        });
    }
};
