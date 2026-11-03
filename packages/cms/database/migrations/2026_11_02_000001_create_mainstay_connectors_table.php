<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | What the Connectors screen keeps of an AI provider: the key saved there,
 | encrypted with APP_KEY since it is sent on every request and so cannot be
 | a hash, and the kinds of work the site chose it for. A provider the host's
 | config gives a key may have a row for its defaults alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_connectors', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('key')->nullable();
            $table->json('defaults');
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_connectors');
    }
};
