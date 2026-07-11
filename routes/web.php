<?php

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Admin\DanDojoController;
use App\Http\Controllers\Admin\ExtraSongController;
use App\Http\Controllers\Admin\PlayerController;
use App\Http\Controllers\Admin\SongController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\Green\OperatorController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\SongCatalogController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/', fn () => redirect('/'.TaikoGameVersion::default()->value));
Route::any('rankings', fn () => abort(404));
Route::any('community', fn () => abort(404));
Route::any('admin/{any?}', fn () => abort(404))->where('any', '.*');
Route::any('settings/{any?}', fn () => abort(404))->where('any', '.*');

$taikoVersionPattern = collect(TaikoGameVersion::cases())
    ->map(fn (TaikoGameVersion $version): string => $version->value)
    ->push('extra')
    ->push('all')
    ->implode('|');

Route::prefix('{taikoVersion}')
    ->where(['taikoVersion' => $taikoVersionPattern])
    ->group(function (): void {
        Route::inertia('/', 'Home', [
            'canRegister' => Features::enabled(Features::registration()),
        ])->name('home');

        Route::get('rankings', [RankingController::class, 'index'])->name('rankings');
        Route::get('songs', [SongCatalogController::class, 'index'])->name('songs.index');
        Route::get('songs/{song}', [SongCatalogController::class, 'show'])->name('songs.show');
        Route::inertia('community', 'Community')->name('community');
        Route::get('users/{user}/board', [BoardController::class, 'show'])->name('board.show');

        Route::middleware(['auth', 'verified'])->group(function (): void {
            Route::post('songs/{song}/favorite', [SongCatalogController::class, 'toggleFavorite'])->name('songs.favorite');

            Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
                Route::inertia('/', 'admin/Dashboard')->name('dashboard');

                Route::get('baids', [OperatorController::class, 'baids'])->name('baids.index');
                Route::get('baids/{player}', [OperatorController::class, 'baid'])->name('baids.show');
                Route::delete('baids/{player}', [OperatorController::class, 'destroyBaid'])->name('baids.destroy');
                Route::delete('baids/{player}/plays/{result}', [OperatorController::class, 'destroyPlay'])->name('baids.plays.destroy');
                Route::delete('baids/{player}/bests/{best}', [OperatorController::class, 'destroyBest'])->name('baids.bests.destroy');
                Route::get('recent-plays', [OperatorController::class, 'recentPlays'])->name('recent-plays');
                Route::get('songs', [SongController::class, 'index'])->name('songs.index');
                Route::get('extra-songs', [ExtraSongController::class, 'index'])->name('extra-songs.index');
                Route::post('extra-songs', [ExtraSongController::class, 'store'])->name('extra-songs.store');
                Route::get('dan-dojo', [DanDojoController::class, 'index'])->name('dan-dojo.index');
                Route::post('dan-dojo/{version}/randomize', [DanDojoController::class, 'randomize'])->name('dan-dojo.randomize');
                Route::get('status', [OperatorController::class, 'status'])->name('status');

                Route::get('players', [PlayerController::class, 'index'])->name('players.index');
                Route::get('players/{user}/edit', [PlayerController::class, 'edit'])->name('players.edit');
                Route::put('players/{user}', [PlayerController::class, 'update'])->name('players.update');
                Route::put('players/{user}/password', [PlayerController::class, 'updatePassword'])->name('players.password');
                Route::post('players/{user}/access-code', [PlayerController::class, 'bindAccessCode'])->name('players.access-code.bind');
                Route::patch('players/{user}/access-code', [PlayerController::class, 'rotateAccessCode'])->name('players.access-code.rotate');
                Route::delete('players/{user}/access-code', [PlayerController::class, 'unbindAccessCode'])->name('players.access-code.unbind');
                Route::patch('players/{user}/role', [PlayerController::class, 'updateRole'])->name('players.role');
                Route::delete('players/{user}', [PlayerController::class, 'destroy'])->name('players.destroy');
            });
        });

        require __DIR__.'/settings.php';
    });
