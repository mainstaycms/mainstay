<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Documents beside images, and who may be served either. `visibility` is
 | `public` -- served to anyone, for as long as the browser likes -- or
 | `restricted` -- served to a signed-in editor, or by a URL signed to
 | expire. A title and a description per locale, as alt text is, for the
 | link a document is offered as.
 |
 | A document has no size and no focal point: its width and height are 0,
 | which keeps both columns as they were rather than altering a NOT NULL
 | in place, which the four drivers each do their own way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mainstay_media', function (Blueprint $table) {
            $table->string('visibility')->default('public');
            $table->json('title')->nullable();
            $table->json('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mainstay_media', function (Blueprint $table) {
            $table->dropColumn(['visibility', 'title', 'description']);
        });
    }
};
