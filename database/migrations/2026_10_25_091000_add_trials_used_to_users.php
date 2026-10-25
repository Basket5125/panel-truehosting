<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many trials an account has taken has to outlive the trial itself:
 * with "delete when the time is up", counting trial servers would hand the
 * same person a fresh one the moment theirs was removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('trials_used')->default(0)->after('server_limit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trials_used');
        });
    }
};
