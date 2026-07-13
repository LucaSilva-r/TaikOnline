<?php

use App\Http\Controllers\Settings\AccessCodeController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\CabinetController;
use App\Http\Controllers\Settings\CostumeController;
use App\Http\Controllers\Settings\CustomizeController;
use App\Http\Controllers\Settings\GameSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings', fn () => redirect()->route('profile.edit'));

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::patch('settings/access-code', [AccessCodeController::class, 'update'])->name('access-code.update');
    Route::delete('settings/access-code', [AccessCodeController::class, 'destroy'])->name('access-code.destroy');

    Route::get('settings/customize', [CustomizeController::class, 'edit'])->name('customize.edit');
    Route::patch('settings/customize', [CustomizeController::class, 'update'])->name('customize.update');
    Route::patch('settings/donchan-name', [CustomizeController::class, 'updateName'])->name('donchan-name.update');
    Route::patch('settings/donchan-title', [CustomizeController::class, 'updateTitle'])->name('donchan-title.update');
    Route::patch('settings/donchan-official-title', [CustomizeController::class, 'updateOfficialTitle'])
        ->name('donchan-official-title.update');

    Route::get('settings/costumes', [CostumeController::class, 'edit'])->name('costumes.edit');
    Route::patch('settings/costumes', [CostumeController::class, 'update'])->name('costumes.update');

    Route::get('settings/avatar', [AvatarController::class, 'edit'])->name('avatar.edit');
    Route::post('settings/avatar', [AvatarController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('avatar.update');

    Route::get('settings/game', [GameSettingsController::class, 'edit'])->name('game-settings.edit');
    Route::patch('settings/game', [GameSettingsController::class, 'update'])->name('game-settings.update');

    Route::get('settings/cabinets', [CabinetController::class, 'index'])->name('cabinets.index');
    Route::post('settings/cabinets', [CabinetController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('cabinets.store');
    Route::get('settings/cabinets/{cabinet}', [CabinetController::class, 'show'])->name('cabinets.show');
    Route::patch('settings/cabinets/{cabinet}/config', [CabinetController::class, 'updateConfig'])->name('cabinets.config');
    Route::delete('settings/cabinets/{cabinet}', [CabinetController::class, 'destroy'])->name('cabinets.destroy');
    Route::get('settings/cabinets/{cabinet}/download', [CabinetController::class, 'download'])->name('cabinets.download');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
