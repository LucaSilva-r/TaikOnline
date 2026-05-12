<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('songs', function (Blueprint $table): void {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('song_no');
            $table->string('music_id');
            $table->unsignedInteger('unique_id');
            $table->string('title');
            $table->string('title_en')->nullable();
            $table->string('genre')->comment('Mapped from SongGenre enum string value');
            $table->string('partsset')->comment('Mapped from SongPartsSet enum string value');
            $table->string('wai2_partsset')->default('')->comment('Mapped from SongWai2PartsSet enum string value');
            $table->json('flags')->default('{}');
            $table->json('tags')->default('[]');
            $table->timestampsTz();

            $table->unique(['version', 'music_id']);
            $table->index(['version', 'song_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
