<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('prefecture_id')->default(0)->after('mydon_name_language');
            $table->boolean('is_publish')->default(true)->after('prefecture_id');
            $table->unsignedInteger('disp_score_type')->default(0)->after('is_publish');
            $table->unsignedInteger('disp_dan_type')->default(0)->after('disp_score_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['prefecture_id', 'is_publish', 'disp_score_type', 'disp_dan_type']);
        });
    }
};
