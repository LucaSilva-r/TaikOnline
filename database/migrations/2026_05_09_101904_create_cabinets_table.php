<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table): void {
            $table->string('serial', 12)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nickname')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestampsTz();

            $table->index('user_id');
            $table->index('last_heartbeat_at');
        });

        DB::table('cabinets')->insert([
            'serial' => '268410000000',
            'user_id' => null,
            'nickname' => 'Default (shared)',
            'registered_at' => null,
            'last_heartbeat_at' => null,
            'last_ip' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinets');
    }
};
