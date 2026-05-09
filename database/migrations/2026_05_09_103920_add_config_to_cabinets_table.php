<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->jsonb('reported_config')->nullable();
            $table->jsonb('desired_config')->nullable();
            $table->timestampTz('last_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cabinets', function (Blueprint $table): void {
            $table->dropColumn(['reported_config', 'desired_config', 'last_reported_at']);
        });
    }
};
