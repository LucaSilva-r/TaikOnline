<?php

use App\Enums\TaikoGameVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_favorite_songs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('baid')->constrained('players', 'baid')->cascadeOnDelete();
            // Favourites are version-scoped because each version ships a different
            // song library; the same song_no can mean different songs per version.
            $table->string('game_version');
            $table->unsignedInteger('song_no');
            $table->timestampsTz();

            $table->unique(['baid', 'game_version', 'song_no']);
            $table->index(['baid', 'game_version']);
        });

        $this->backfillFromPlayerColumn();

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('favorite_song_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->json('favorite_song_numbers')->default('[]');
        });

        Schema::dropIfExists('player_favorite_songs');
    }

    /**
     * The legacy column held a single flat list with no version. Seed it into
     * every version whose library contains the song, capped at that version's
     * favourite limit so we never start a player over the in-game maximum.
     */
    private function backfillFromPlayerColumn(): void
    {
        $now = now();

        DB::table('players')
            ->select('baid', 'favorite_song_numbers')
            ->orderBy('baid')
            ->each(function (object $player) use ($now): void {
                $songNos = json_decode((string) $player->favorite_song_numbers, true);
                if (! is_array($songNos) || $songNos === []) {
                    return;
                }

                $songNos = array_values(array_unique(array_map('intval', $songNos)));

                foreach (TaikoGameVersion::cases() as $version) {
                    $existing = DB::table('songs')
                        ->where('version', $version->value)
                        ->whereIn('song_no', $songNos)
                        ->pluck('song_no')
                        ->map(fn ($no): int => (int) $no);

                    $ordered = collect($songNos)
                        ->filter(fn (int $no): bool => $existing->contains($no))
                        ->take($version->favoriteSongLimit());

                    foreach ($ordered as $songNo) {
                        DB::table('player_favorite_songs')->insert([
                            'baid' => $player->baid,
                            'game_version' => $version->value,
                            'song_no' => $songNo,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }
};
