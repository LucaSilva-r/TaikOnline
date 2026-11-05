<?php

use App\Enums\TaikoGameVersion;
use App\Http\Controllers\Admin\DanDojoController;
use App\Http\Controllers\Admin\PlayerController;
use App\Http\Controllers\Admin\SongController;
use App\Http\Controllers\Admin\WaddamburoCabinetController;
use App\Http\Controllers\Admin\WaddamburoChartController;
use App\Http\Controllers\Admin\WaddamburoDanController as AdminWaddamburoDanController;
use App\Http\Controllers\Admin\WaddamburoNoticeController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\Green\OperatorController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\Mod\ModerationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\Settings\CabinetLoginController;
use App\Http\Controllers\Settings\DeviceLinkController;
use App\Http\Controllers\SongCatalogController;
use App\Http\Controllers\WaddamburoDanPlayController;
use App\Http\Controllers\WaddamburoDanRankController;
use App\Http\Controllers\WaddamburoPlayController;
use App\Http\Middleware\ResolveTaikoVersion;
use App\Services\WaddamburoStatus;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect('/'.ResolveTaikoVersion::DefaultScope));
Route::any('rankings', fn () => abort(404));
Route::any('community', fn () => abort(404));
Route::any('admin/{any?}', fn () => abort(404))->where('any', '.*');
Route::any('mod/{any?}', fn () => abort(404))->where('any', '.*');
Route::any('settings/{any?}', fn () => abort(404))->where('any', '.*');

$taikoVersionPattern = collect(TaikoGameVersion::cases())
    ->map(fn (TaikoGameVersion $version): string => $version->value)
    ->push('waddamburo')
    ->push('all')
    ->implode('|');

Route::prefix('{taikoVersion}')
    ->where(['taikoVersion' => $taikoVersionPattern])
    ->group(function (): void {
        Route::get('/', fn () => Inertia::render('Home', ['waddamburoOnline' => app(WaddamburoStatus::class)->online()]))->name('home');

        Route::get('rules', [LegalController::class, 'rules'])->name('legal.rules');
        Route::get('privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
        Route::post('rules/accept', [LegalController::class, 'accept'])->middleware('auth')->name('legal.accept');

        Route::get('rankings', [RankingController::class, 'index'])->name('rankings');
        Route::get('songs', [SongCatalogController::class, 'index'])->name('songs.index');
        Route::get('songs/{song}', [SongCatalogController::class, 'show'])->name('songs.show');
        Route::inertia('community', 'Community')->name('community');
        Route::get('users/{user}/board', [BoardController::class, 'show'])->name('board.show');
        Route::get('plays/{id}', [WaddamburoPlayController::class, 'show'])->name('plays.show');
        Route::get('dan-plays/{id}', [WaddamburoDanPlayController::class, 'show'])->name('dan-plays.show');
        Route::post('dan-plays/{id}/report', [WaddamburoDanPlayController::class, 'report'])
            ->middleware(['auth', 'throttle:play-reports'])->name('dan-plays.report');
        Route::post('plays/{id}/report', [WaddamburoPlayController::class, 'report'])
            ->middleware(['auth', 'throttle:play-reports'])
            ->name('plays.report');

        Route::middleware('auth')->group(function (): void {
            Route::patch('settings/dan-rank', [WaddamburoDanRankController::class, 'update'])->name('dan-rank.update');
            Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
            Route::get('play', [CabinetLoginController::class, 'create'])->name('play.create');
            Route::post('play', [CabinetLoginController::class, 'store'])
                ->middleware('throttle:cabinet-login')
                ->name('play.store');
            Route::get('link', [DeviceLinkController::class, 'create'])->name('link.create');
            Route::post('link', [DeviceLinkController::class, 'store'])
                ->middleware('throttle:cabinet-login')
                ->name('link.store');
        });

        Route::middleware(['auth', 'verified'])->group(function (): void {
            Route::post('songs/{song}/favorite', [SongCatalogController::class, 'toggleFavorite'])->name('songs.favorite');

            Route::middleware(['can:moderate', 'staff.2fa'])->prefix('mod')->name('mod.')->group(function (): void {
                Route::get('/', [ModerationController::class, 'index'])->name('index');
                Route::get('players/{player}', [ModerationController::class, 'player'])->name('players.show');
                Route::post('players/{player}/sanctions', [ModerationController::class, 'storeSanction'])->name('sanctions.store');
                Route::patch('sanctions/{sanction}/revoke', [ModerationController::class, 'revokeSanction'])->name('sanctions.revoke');
                Route::patch('plays/{play}/hide', [ModerationController::class, 'hidePlay'])->name('plays.hide');
                Route::patch('dan-plays/{play}/hide', [ModerationController::class, 'hideDanPlay'])->name('dan-plays.hide');
                Route::patch('dan-plays/{play}/restore', [ModerationController::class, 'restoreDanPlay'])->name('dan-plays.restore');
                Route::patch('dan-plays/{play}/dismiss-reports', [ModerationController::class, 'dismissDanReports'])->name('dan-plays.dismiss-reports');
                Route::patch('plays/{play}/restore', [ModerationController::class, 'restorePlay'])->name('plays.restore');
                Route::get('reports', [ModerationController::class, 'reports'])->name('reports.index');
                Route::get('upload-warnings', [ModerationController::class, 'uploadWarnings'])->name('upload-warnings.index');
                Route::patch('upload-warnings/dismiss-all', [ModerationController::class, 'dismissAllUploadWarnings'])->name('upload-warnings.dismiss-all');
                Route::patch('upload-warnings/{warning}/dismiss', [ModerationController::class, 'dismissUploadWarning'])->name('upload-warnings.dismiss');
                Route::patch('plays/{play}/dismiss-reports', [ModerationController::class, 'dismissReports'])->name('plays.dismiss-reports');
            });

            Route::middleware(['admin', 'staff.2fa'])->prefix('admin')->name('admin.')->group(function (): void {
                Route::inertia('/', 'admin/Dashboard')->name('dashboard');

                Route::get('baids', [OperatorController::class, 'baids'])->name('baids.index');
                Route::get('baids/{player}', [OperatorController::class, 'baid'])->name('baids.show');
                Route::delete('baids/{player}', [OperatorController::class, 'destroyBaid'])->name('baids.destroy');
                Route::patch('baids/{player}/access-code', [OperatorController::class, 'replaceAccessCode'])->name('baids.access-code.replace');
                Route::delete('baids/{player}/access-code', [OperatorController::class, 'unlinkAccessCode'])->name('baids.access-code.unlink');
                Route::delete('baids/{player}/plays/{result}', [OperatorController::class, 'destroyPlay'])->name('baids.plays.destroy');
                Route::delete('baids/{player}/bests/{best}', [OperatorController::class, 'destroyBest'])->name('baids.bests.destroy');
                Route::get('recent-plays', [OperatorController::class, 'recentPlays'])->name('recent-plays');
                Route::get('songs', [SongController::class, 'index'])->name('songs.index');
                Route::get('waddamburo-charts', [WaddamburoChartController::class, 'index'])->name('waddamburo-charts.index');
                Route::patch('waddamburo-charts/{chart}', [WaddamburoChartController::class, 'update'])->name('waddamburo-charts.update');
                Route::patch('waddamburo-songs/{song}', [WaddamburoChartController::class, 'updateSong'])->name('waddamburo-songs.update');
                Route::get('waddamburo-notices', [WaddamburoNoticeController::class, 'index'])->name('waddamburo-notices.index');
                Route::post('waddamburo-notices', [WaddamburoNoticeController::class, 'store'])->name('waddamburo-notices.store');
                Route::patch('waddamburo-notices/{notice}/end', [WaddamburoNoticeController::class, 'end'])->name('waddamburo-notices.end');
                Route::delete('waddamburo-notices/{notice}', [WaddamburoNoticeController::class, 'destroy'])->name('waddamburo-notices.destroy');
                Route::get('waddamburo-cabinets', [WaddamburoCabinetController::class, 'index'])->name('waddamburo-cabinets.index');
                Route::post('waddamburo-cabinets', [WaddamburoCabinetController::class, 'store'])->name('waddamburo-cabinets.store');
                Route::patch('waddamburo-cabinets/{cabinet}/revoke', [WaddamburoCabinetController::class, 'revoke'])->name('waddamburo-cabinets.revoke');
                Route::patch('waddamburo-cabinets/{cabinet}/restore', [WaddamburoCabinetController::class, 'restore'])->name('waddamburo-cabinets.restore');
                Route::get('waddamburo-dan', [AdminWaddamburoDanController::class, 'index'])->name('waddamburo-dan.index');
                Route::patch('waddamburo-dan/{series}', [AdminWaddamburoDanController::class, 'update'])->name('waddamburo-dan.update');
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
