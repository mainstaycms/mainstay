<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The formats every declared copy of an image is written in, comma
 | separated, the one an <img> shows last: AVIF and WebP where this PHP's GD
 | writes them, the original's own kind where it writes no WebP. A <picture>
 | must not offer a file that was never written, since a browser does not
 | fall back from a source that fails. Every image before this was written
 | in AVIF and WebP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mainstay_media', function (Blueprint $table) {
            $table->string('formats')->default('avif,webp');
        });
    }

    public function down(): void
    {
        Schema::table('mainstay_media', function (Blueprint $table) {
            $table->dropColumn('formats');
        });
    }
};
