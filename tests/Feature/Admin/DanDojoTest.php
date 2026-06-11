<?php

use App\Enums\SongGenre;
use App\Enums\SongPartsSet;
use App\Enums\SongWai2PartsSet;
use App\Models\DanCourse;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seed_version_songs(string $version, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        Song::query()->create([
            'version' => $version,
            'song_no' => $i,
            'music_id' => "{$version}-{$i}",
            'unique_id' => $i,
            'title' => "Song {$i}",
            'genre' => SongGenre::Jpop,
            'partsset' => SongPartsSet::Taiko,
            'wai2_partsset' => SongWai2PartsSet::Taiko,
            'flags' => [],
            'tags' => [],
        ]);
    }
}

test('admins can view the dan dojo page', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/dan-dojo')
        ->assertOk();
});

test('non-admins cannot view the dan dojo page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/dan-dojo')
        ->assertForbidden();
});

test('randomizing authors courses from the version song catalog', function (): void {
    seed_version_songs('green', 30);

    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/dan-dojo/green/randomize')
        ->assertRedirect();

    $courses = DanCourse::query()->where('version', 'green')->with('songs')->get();
    expect($courses)->toHaveCount(10)
        ->and($courses->first()->songs)->toHaveCount(3)
        ->and($courses->pluck('dan')->all())->toBe(range(1, 10));

    // Every authored song belongs to the version's catalog.
    $catalog = Song::query()->where('version', 'green')->pluck('song_no')->all();
    $courses->each(fn (DanCourse $course) => $course->songs->each(
        fn ($song) => expect($catalog)->toContain($song->song_no),
    ));
});

test('randomized courses only use chart difficulties the cabinet accepts (0-3)', function (): void {
    seed_version_songs('green', 30);

    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/dan-dojo/green/randomize');

    // Level 4 (ura) is not a valid dan chart difficulty and crashes the cabinet.
    $levels = App\Models\DanCourseSong::query()
        ->whereIn('dan_course_id', DanCourse::query()->where('version', 'green')->pluck('id'))
        ->pluck('level')
        ->unique();

    expect($levels->max())->toBeLessThanOrEqual(3)
        ->and($levels->min())->toBeGreaterThanOrEqual(0);
});

test('randomizing replaces the previous course set', function (): void {
    seed_version_songs('green', 30);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/dan-dojo/green/randomize');
    $this->actingAs($admin)->post('/admin/dan-dojo/green/randomize');

    // Still exactly one set, not stacked.
    expect(DanCourse::query()->where('version', 'green')->count())->toBe(10);
});

test('randomizing a version without songs creates nothing', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/dan-dojo/blue/randomize')
        ->assertRedirect();

    expect(DanCourse::query()->where('version', 'blue')->count())->toBe(0);
});
