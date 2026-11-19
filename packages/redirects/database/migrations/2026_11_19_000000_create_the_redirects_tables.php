<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     | The redirects, and the 404s that could become some. A source is looked
     | up by the hash of its normalised form, so an exact redirect costs one
     | indexed read whatever its length and the same address cannot be held
     | twice. A regex pattern is hashed too, with its type in front, since
     | SQL Server allows one null in a unique index and these must be unique.
     | An automatic redirect points at the entry that caused it by type and
     | id, with no foreign key: each type has a table of its own, and a
     | deleted entry's redirect outlives it.
     */
    public function up(): void
    {
        Schema::create('mainstay_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source', 500);
            $table->char('source_hash', 40)->unique();
            $table->string('match_type', 5);
            $table->string('target', 2000)->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('origin', 10);
            $table->string('entry_type')->nullable();
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('locale')->nullable();
            $table->boolean('preserve_query')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->datetimes();

            $table->index(['match_type', 'enabled', 'priority']);
            $table->index(['entry_type', 'entry_id']);
        });

        Schema::create('mainstay_redirect_misses', function (Blueprint $table) {
            $table->id();
            $table->string('address', 500);
            $table->char('address_hash', 40)->unique();
            $table->unsignedBigInteger('hits')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->string('referrer', 500)->nullable();
            $table->boolean('ignored')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mainstay_redirect_misses');
        Schema::dropIfExists('mainstay_redirects');
    }
};
