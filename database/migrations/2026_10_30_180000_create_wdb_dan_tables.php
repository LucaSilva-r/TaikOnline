<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Waddamburo's dan dojo catalogue, apart from the recomp's dan_courses (its protocol, slots 1-25, no
     * requirements). A course is a list entry of one series (a game version's course list); level is its
     * challengelv, so 1-25 are ranks and the rest extras.
     */
    public function up(): void
    {
        Schema::create('wdb_dan_courses', function (Blueprint $table) {
            $table->id();
            $table->string('series', 32);
            $table->unsignedInteger('level');
            $table->string('name');
            $table->json('requirements');
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->unique(['series', 'level']);
        });
        Schema::create('wdb_dan_course_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wdb_dan_course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('song_key', 600);
            $table->unsignedTinyInteger('course');
            $table->unsignedInteger('notes');
            $table->timestamps();
            $table->unique(['wdb_dan_course_id', 'position']);
            $table->index('song_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_dan_course_songs');
        Schema::dropIfExists('wdb_dan_courses');
    }
};
