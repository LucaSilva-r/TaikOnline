<?php

namespace App\Http\Controllers\Mod;

use App\Enums\SanctionType;
use App\Http\Controllers\Controller;
use App\Models\ModerationAction;
use App\Models\Player;
use App\Models\PlayReport;
use App\Models\Sanction;
use App\Models\WdbPlay;
use App\Services\ModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The moderation pages (docs/features/moderation.md), for admins and moderators: player lookup, a
 * player's sanctions and Waddamburo plays, and the action log (whole for admins, per player otherwise).
 */
class ModerationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        return Inertia::render('mod/Index', [
            'filters' => ['q' => $search],
            'players' => $search === '' ? [] : Player::query()
                ->with('user')
                ->where(fn (Builder $query) => $query
                    ->when(ctype_digit($search), fn (Builder $baid) => $baid->where('baid', (int) $search))
                    ->orWhereLike('mydon_name', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user->whereLike('name', "%{$search}%")->orWhereLike('username', "%{$search}%")))
                ->orderBy('baid')->limit(50)->get()
                ->map(fn (Player $player): array => $this->playerRow($player))->all(),
            'activeSanctions' => Sanction::query()->active()->with(['player.user', 'creator'])->latest()->limit(100)->get()
                ->map(fn (Sanction $sanction): array => [...$this->sanction($sanction), 'player' => $this->playerRow($sanction->player)])->all(),
            // The whole log is the admins'; moderators see a player's own history on the player's page.
            'log' => $request->user()->isAdmin()
                ? ModerationAction::query()->with('actor')->latest('id')->limit(100)->get()->map(fn (ModerationAction $action): array => $this->action($action))->all()
                : null,
        ]);
    }

    public function player(Request $request, Player $player): Response
    {
        $player->load('user');

        return Inertia::render('mod/Player', [
            'player' => $this->playerRow($player),
            'canAct' => ModerationService::canActOn($request->user(), $player),
            'canBanPermanently' => $request->user()->isAdmin(),
            'sanctionTypes' => collect(SanctionType::cases())->map(fn (SanctionType $type): array => ['value' => $type->value, 'label' => $type->label()])->all(),
            'sanctions' => $player->sanctions()->with(['creator', 'revoker'])->latest('id')->get()
                ->map(fn (Sanction $sanction): array => $this->sanction($sanction))->all(),
            'plays' => WdbPlay::query()->where('baid', $player->baid)->with('chart:id,title,title_en,difficulty,course')
                ->withCount(['reports as open_reports' => fn (Builder $reports) => $reports->open()])
                ->latest('played_at')->limit(100)->get()
                ->map(fn (WdbPlay $play): array => [
                    'id' => $play->id,
                    'chart' => $play->chart?->title_en ?? $play->chart?->title ?? 'Unknown chart',
                    'difficulty' => $play->chart?->difficulty,
                    'course' => (int) $play->course,
                    'score' => (int) $play->score,
                    'great' => (int) $play->great,
                    'good' => (int) $play->good,
                    'miss' => (int) $play->miss,
                    'options' => WdbPlay::optionLabels((int) $play->options),
                    'played_at' => $play->played_at?->toIso8601String(),
                    'hidden_at' => $play->hidden_at?->toIso8601String(),
                    'hidden_reason' => $play->hidden_reason,
                    'open_reports' => (int) $play->open_reports,
                ])->all(),
            'log' => ModerationAction::query()->where('baid', $player->baid)->with('actor')->latest('id')->limit(100)->get()
                ->map(fn (ModerationAction $action): array => $this->action($action))->all(),
        ]);
    }

    /**
     * The review queue: open reports by play, the most reported first. Each reporter comes with how many
     * reports they filed and how many were dismissed, to spot who reports in bad faith (and block them).
     */
    public function reports(): Response
    {
        $reports = PlayReport::query()->open()->with(['play.chart', 'play.player.user', 'reporter.player'])->oldest()->limit(500)->get();
        $reporters = PlayReport::query()->whereIn('reporter_id', $reports->pluck('reporter_id')->unique())
            ->groupBy('reporter_id')->select('reporter_id')
            ->selectRaw('COUNT(*) AS filed')
            ->selectRaw("SUM(CASE WHEN resolution = 'dismissed' THEN 1 ELSE 0 END) AS dismissed")
            ->get()->keyBy('reporter_id');

        return Inertia::render('mod/Reports', [
            'plays' => $reports->groupBy('wdb_play_id')->sortByDesc(fn (Collection $group): int => $group->count())->values()
                ->map(function (Collection $group) use ($reporters): array {
                    $play = $group->first()->play;

                    return [
                        'id' => $play->id,
                        'chart' => $play->chart?->title_en ?? $play->chart?->title ?? 'Unknown chart',
                        'difficulty' => $play->chart?->difficulty,
                        'course' => (int) $play->course,
                        'score' => (int) $play->score,
                        'played_at' => $play->played_at?->toIso8601String(),
                        'hidden_at' => $play->hidden_at?->toIso8601String(),
                        'player' => $play->player === null ? null : $this->playerRow($play->player),
                        'reports' => $group->map(fn (PlayReport $report): array => [
                            'id' => $report->id,
                            'reason' => $report->reason->label(),
                            'comment' => $report->comment,
                            'created_at' => $report->created_at?->toIso8601String(),
                            'reporter' => [
                                'name' => $report->reporter?->name ?? 'Deleted account',
                                'baid' => $report->reporter?->player?->baid === null ? null : (int) $report->reporter->player->baid,
                                'filed' => (int) ($reporters->get($report->reporter_id)?->filed ?? 0),
                                'dismissed' => (int) ($reporters->get($report->reporter_id)?->dismissed ?? 0),
                            ],
                        ])->values()->all(),
                    ];
                })->all(),
        ]);
    }

    /** Closes a play's open reports with no action taken. */
    public function dismissReports(Request $request, WdbPlay $play, ModerationService $moderation): RedirectResponse
    {
        abort_unless($play->player !== null && ModerationService::canActOn($request->user(), $play->player), 403);
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $moderation->dismissReports($request->user(), $play, $validated['reason'] ?? null);

        return back();
    }

    public function storeSanction(Request $request, Player $player, ModerationService $moderation): RedirectResponse
    {
        abort_unless(ModerationService::canActOn($request->user(), $player), 403);
        $validated = $request->validate([
            'type' => ['required', Rule::enum(SanctionType::class)],
            'reason' => ['required', 'string', 'max:2000'],
            'note_to_player' => ['nullable', 'string', 'max:2000'],
            // Hours; empty for a permanent sanction.
            'duration_hours' => ['nullable', 'integer', 'min:1', 'max:87600'],
        ]);
        $type = SanctionType::from($validated['type']);
        $expiresAt = isset($validated['duration_hours']) ? now()->addHours((int) $validated['duration_hours']) : null;
        abort_unless(ModerationService::canIssue($request->user(), $type, $expiresAt), 403, 'Only an admin can ban permanently.');

        $moderation->sanction($request->user(), $player, $type, $validated['reason'], $validated['note_to_player'] ?? null, $expiresAt);

        return back();
    }

    public function revokeSanction(Request $request, Sanction $sanction, ModerationService $moderation): RedirectResponse
    {
        abort_unless(ModerationService::canActOn($request->user(), $sanction->player), 403);
        abort_unless($sanction->isActive(), 422, 'The sanction is no longer in force.');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $moderation->revoke($request->user(), $sanction, $validated['reason'] ?? null);

        return back();
    }

    public function hidePlay(Request $request, WdbPlay $play, ModerationService $moderation): RedirectResponse
    {
        abort_unless($play->player !== null && ModerationService::canActOn($request->user(), $play->player), 403);
        abort_if($play->hidden_at !== null, 422, 'The play is already hidden.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $moderation->hide($request->user(), $play, $validated['reason']);

        return back();
    }

    public function restorePlay(Request $request, WdbPlay $play, ModerationService $moderation): RedirectResponse
    {
        abort_unless($play->player !== null && ModerationService::canActOn($request->user(), $play->player), 403);
        abort_if($play->hidden_at === null, 422, 'The play is not hidden.');
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $moderation->restore($request->user(), $play, $validated['reason'] ?? null);

        return back();
    }

    /** @return array{baid: int, mydon_name: ?string, user: ?array{id: int, name: string, username: ?string, role: string}} */
    private function playerRow(Player $player): array
    {
        return [
            'baid' => (int) $player->baid,
            'mydon_name' => $player->mydon_name,
            'user' => $player->user === null ? null : [
                'id' => $player->user->id,
                'name' => $player->user->name,
                'username' => $player->user->username,
                'role' => $player->user->role->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sanction(Sanction $sanction): array
    {
        return [
            'id' => $sanction->id,
            'type' => $sanction->type->value,
            'reason' => $sanction->reason,
            'note_to_player' => $sanction->note_to_player,
            'expires_at' => $sanction->expires_at?->toIso8601String(),
            'created_at' => $sanction->created_at?->toIso8601String(),
            'created_by' => $sanction->creator?->name,
            'revoked_at' => $sanction->revoked_at?->toIso8601String(),
            'revoked_by' => $sanction->revoker?->name,
            'active' => $sanction->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    private function action(ModerationAction $action): array
    {
        return [
            'id' => $action->id,
            'actor' => $action->actor?->name,
            'action' => $action->action,
            'baid' => $action->baid,
            'wdb_play_id' => $action->wdb_play_id,
            'reason' => $action->reason,
            'created_at' => $action->created_at?->toIso8601String(),
        ];
    }
}
