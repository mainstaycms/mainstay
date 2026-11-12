<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | What the site's owner has set, one row per setting: its name, which a
 | plugin's handle leads (`chat.instructions`), and its value as JSON, so a
 | text, a flag and a number are each stored as what they are. A setting with
 | no row holds the default its plugin declared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mainstay_settings', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->json('value');
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_settings');
    }
};
