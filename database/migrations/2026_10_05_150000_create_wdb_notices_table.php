<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** System notices for every Waddamburo client (maintenance, updates); shown between starts_at and ends_at. */
    public function up(): void
    {
        Schema::create('wdb_notices', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('severity', 16)->default('info');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wdb_notices');
    }
};
