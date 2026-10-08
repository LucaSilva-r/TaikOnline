<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the server last scored a Waddamburo play from its replay (Waddamburo --rescore). Null: its
     * numbers are still the client's (chart notes not uploaded yet, or the replay did not score), and
     * the boards leave it out.
     */
    public function up(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->timestampTz('rescored_at')->nullable()->after('seed');
        });
    }

    public function down(): void
    {
        Schema::table('wdb_plays', function (Blueprint $table) {
            $table->dropColumn('rescored_at');
        });
    }
};
