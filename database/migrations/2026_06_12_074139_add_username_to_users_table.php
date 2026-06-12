<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
        });

        $this->backfillUsernames();

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->unique()->change();
        });
    }

    /**
     * Generate a unique username for every existing user, derived from
     * their name (falling back to the local-part of their email).
     */
    private function backfillUsernames(): void
    {
        $taken = [];

        DB::table('users')->select('id', 'name', 'email')->orderBy('id')->each(function (object $user) use (&$taken) {
            $base = Str::slug($user->name ?: Str::before($user->email, '@'), '_');

            if ($base === '') {
                $base = 'user';
            }

            $username = $base;
            $suffix = 1;

            while (in_array($username, $taken, true)) {
                $username = $base.(++$suffix);
            }

            $taken[] = $username;

            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
