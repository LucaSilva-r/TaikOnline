<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Green\OperatorController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('green/players', [OperatorController::class, 'players'])->name('green.players');
    Route::get('green/players/{player}', [OperatorController::class, 'player'])->name('green.players.show');
    Route::get('green/recent-plays', [OperatorController::class, 'recentPlays'])->name('green.recent-plays');
    Route::get('green/status', [OperatorController::class, 'status'])->name('green.status');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

require __DIR__.'/settings.php';
