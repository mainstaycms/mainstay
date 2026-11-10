<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | What each account chose for itself, as one JSON object read by dotted key:
 | which widgets its dashboard shows, and whatever a screen later lets it
 | change. Null until it chooses anything, so the accounts that exist are
 | untouched and every default is the code's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mainstay_users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mainstay_users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
