<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wdb_dan_plays', function (Blueprint $table): void {
            $table->string('analysis_flag', 16)->nullable()->index();
            $table->json('analysis')->nullable();
            $table->timestampTz('analyzed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_dan_plays', function (Blueprint $table): void {
            $table->dropIndex(['analysis_flag']);
            $table->dropIndex(['analyzed_at']);
            $table->dropColumn(['analysis_flag', 'analysis', 'analyzed_at']);
        });
    }
};
