<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dan dojo course catalog, imported per version from musicmedleyinfo.xml.
        // The cabinet evaluates pass/fail itself; the server only needs to serve
        // each dan slot's song list, so pass conditions are not stored.
        Schema::create('dan_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('dan');         // challengelv slot (1-25)
            $table->unsignedInteger('unique_id');   // course uniqueid from the datatable
            $table->string('name')->default('');
            $table->unsignedInteger('difficulty')->default(0);
            $table->unsignedInteger('verup_no')->default(1);
            $table->timestampsTz();

            $table->unique(['version', 'dan']);
        });

        Schema::create('dan_course_songs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dan_course_id')->constrained('dan_courses')->cascadeOnDelete();
            $table->unsignedInteger('song_no');
            $table->unsignedInteger('level')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dan_course_songs');
        Schema::dropIfExists('dan_courses');
    }
};
