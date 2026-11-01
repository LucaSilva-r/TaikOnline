<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wdb_upload_warnings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('baid')->nullable();
            $table->string('kind', 32);
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->uuid('play_id')->nullable();
            $table->string('detail')->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->unique(['baid', 'kind', 'day']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_upload_warnings');
    }
};
