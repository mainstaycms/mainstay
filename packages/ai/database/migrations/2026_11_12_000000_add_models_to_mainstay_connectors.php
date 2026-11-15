<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The model the site chose for each kind of work a provider is the default
 | for, as one JSON object by kind: `{"text": "claude-haiku-5-5"}`. Null for
 | the rows that exist, and a kind with none is the provider's own default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mainstay_connectors', function (Blueprint $table) {
            $table->json('models')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mainstay_connectors', function (Blueprint $table) {
            $table->dropColumn('models');
        });
    }
};
