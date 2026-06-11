<?php

use App\Http\Controllers\Admin\DanDojoController;
use App\Http\Controllers\Admin\SongController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Green\OperatorController;
use App\Http\Controllers\RankingController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Home', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::get('rankings', [RankingController::class, 'index'])->name('rankings');
Route::inertia('community', 'Community')->name('community');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::inertia('/', 'admin/Dashboard')->name('dashboard');

        Route::get('players', [OperatorController::class, 'players'])->name('players.index');
        Route::get('players/{player}', [OperatorController::class, 'player'])->name('players.show');
        Route::get('recent-plays', [OperatorController::class, 'recentPlays'])->name('recent-plays');
        Route::get('songs', [SongController::class, 'index'])->name('songs.index');
        Route::get('dan-dojo', [DanDojoController::class, 'index'])->name('dan-dojo.index');
        Route::post('dan-dojo/{version}/randomize', [DanDojoController::class, 'randomize'])->name('dan-dojo.randomize');
        Route::get('status', [OperatorController::class, 'status'])->name('status');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password');
        Route::post('users/{user}/access-code', [UserController::class, 'bindAccessCode'])->name('users.access-code.bind');
        Route::delete('users/{user}/access-code', [UserController::class, 'unbindAccessCode'])->name('users.access-code.unbind');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

require __DIR__.'/settings.php';
