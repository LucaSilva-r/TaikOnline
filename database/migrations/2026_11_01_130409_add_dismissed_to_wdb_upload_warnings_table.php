<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wdb_upload_warnings', function (Blueprint $table): void {
            $table->timestampTz('dismissed_at')->nullable()->index();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wdb_upload_warnings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dismissed_by');
            $table->dropColumn('dismissed_at');
        });
    }
};
