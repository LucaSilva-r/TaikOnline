<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cabinet registration (serials, config zips, heartbeats) is retired. ponytail: down() restores the shape, not the data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cabinets');
    }

    public function down(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->string('serial', 12)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nickname')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->json('desired_config')->nullable();
            $table->json('reported_config')->nullable();
            $table->json('reported_meta')->nullable();
            $table->timestampTz('last_reported_at')->nullable();
            $table->timestampsTz();
        });
    }
};
