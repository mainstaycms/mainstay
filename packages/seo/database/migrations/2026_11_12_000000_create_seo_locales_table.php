<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | What the SEO plugin keeps for each content locale: the template a page's
     | title is written into and the image a page with none of its own is
     | shared with. A table of its own rather than settings, which are one value
     | per key and would repeat a key for every locale a site adds.
     */
    public function up(): void
    {
        Schema::create('seo_locales', function (Blueprint $table) {
            $table->string('locale')->primary();
            $table->string('title_template')->nullable();
            $table->unsignedBigInteger('default_image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_locales');
    }
};
