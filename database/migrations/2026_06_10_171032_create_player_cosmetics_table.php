<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costume/tone/title state is version-scoped: the same id maps to a
        // different item (and the catalogs differ in size) between Taiko
        // versions, so it is keyed per (baid, game_version) rather than living
        // on the shared player row.
        Schema::create('player_cosmetics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            $table->string('game_version');

            // Equipped costume parts (kigurumi, head, body, face, puchi).
            $table->unsignedInteger('costume_1')->default(0);
            $table->unsignedInteger('costume_2')->default(0);
            $table->unsignedInteger('costume_3')->default(0);
            $table->unsignedInteger('costume_4')->default(0);
            $table->unsignedInteger('costume_5')->default(0);

            // Unlock id lists rendered into the cabinet's flag bitsets. Costumes
            // are a {slot => [ids]} map across the five costume_flg slots.
            $table->json('unlocked_costumes')->default('{}');
            $table->json('unlocked_tones')->default('[]');
            $table->json('unlocked_titles')->default('[]');

            $table->timestampsTz();

            $table->unique(['baid', 'game_version']);
        });

        // Cosmetic unlocks previously lived (incorrectly) on the shared player
        // row; they are now version-scoped above.
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('unlocked_costumes');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->json('unlocked_costumes')->default('{}');
        });

        Schema::dropIfExists('player_cosmetics');
    }
};
