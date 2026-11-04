<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | What a program reading or writing over the API signs in with, on behalf
 | of the account that made it: a CI build, an MCP client. Each holds
 | capabilities of its own, never more than its account's, and goes when
 | the account does. Only the secret's sha256 is kept, so a copy of the
 | table opens nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('mainstay_users')->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->json('capabilities');
            $table->dateTime('last_used_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_tokens');
    }
};
