<?php

namespace App\Http\Controllers;

use App\Enums\SanctionType;
use App\Enums\UploadWarningKind;
use App\Http\Middleware\AuthenticateWaddamburo;
use App\Http\Middleware\EnsureWaddamburoCabinet;
use App\Http\Middleware\ResolveTaikoVersion;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\Sanction;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Notifications\WaddamburoNotice;
use App\Services\CabinetPairingService;
use App\Services\WaddamburoAiBattles;
use App\Services\WaddamburoDanRank;
use App\Services\WaddamburoDeviceLoginService;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoReplay;
use App\Services\WaddamburoScorer;
use App\Services\WaddamburoSongs;
use App\Services\WaddamburoUploadWarnings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use RuntimeException;

/** The Waddamburo client API: login, card lookup, play and chart upload. */
class WaddamburoController extends Controller
{
    /** Chart metadata newer than the first imports; filled in on charts that lack it. */
    public const LATER_METADATA = ['title_en', 'subtitle_en', 'difficulty', 'osu_beatmap_id', 'osu_beatmapset_id'];

    /** Decompressed canonical chart size limit: about 9x the longest stored chart (55 KB). */
    public const MAX_CHART_BYTES = 512 * 1024;

    /** Home login: username or email + password (+ 2FA code) -> a long-lived 'wdb' token. */
    public function login(Request $request, TwoFactorAuthenticationProvider $twoFactor): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:16'],
            'device' => ['required', 'string', 'max:100'],
        ]);

        $login = Str::lower($validated['login']);
        $user = User::query()->where('username', $login)->orWhere('email', $login)->first();
        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => __('These credentials do not match our records.')]);
        }

        if ($user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null) {
            $code = (string) ($validated['code'] ?? '');
            if ($code === '') {
                return response()->json(['message' => 'Two-factor code required.', 'two_factor' => true], 422);
            }
            if (! $twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
                throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
            }
        }

        $player = $user->player;
        if (! $player instanceof Player) {
            throw ValidationException::withMessages(['login' => __('Your account does not have a Banapass yet.')]);
        }

        return response()->json([
            'token' => $user->createToken($validated['device'], ['wdb'])->plainTextToken,
            ...$this->profile($player),
        ]);
    }

    /** In-game login, step 1: a code for the player to enter on the website (see WaddamburoDeviceLoginService). */
    public function startDevice(Request $request, WaddamburoDeviceLoginService $devices): JsonResponse
    {
        $validated = $request->validate(['device' => ['required', 'string', 'max:100']]);

        try {
            $login = $devices->start($validated['device']);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unavailable, try again.'], 503);
        }

        // The site's one code page (it sends a sign-in code on to its approval).
        return response()->json([...$login, 'verification_url' => url('/'.ResolveTaikoVersion::DefaultScope.'/play')]);
    }

    /** In-game login, step 2 (polled): pending, denied, expired, or the token and profile once approved. */
    public function pollDevice(Request $request, WaddamburoDeviceLoginService $devices): JsonResponse
    {
        $validated = $request->validate(['device_code' => ['required', 'string', 'max:128']]);
        $result = $devices->poll($validated['device_code']);
        if ($result['status'] !== 'approved') {
            return response()->json(['status' => $result['status']]);
        }

        $player = $result['user']->player;
        if (! $player instanceof Player) {
            return response()->json(['status' => 'denied', 'message' => 'The account does not have a Banapass yet.']);
        }

        return response()->json([
            'status' => 'approved',
            'token' => $result['user']->createToken($result['device'], ['wdb'])->plainTextToken,
            ...$this->profile($player),
        ]);
    }

    /**
     * 6-digit pairing for a home PC (a logged-in player's token): a visiting friend enters the code on
     * the website. Instead of the card, the game receives a short-lived token for the friend, kept in
     * memory only, so their plays upload under their own account and nothing of theirs stays on the PC.
     */
    public function homePairing(Request $request, CabinetPairingService $pairings): JsonResponse
    {
        abort_if($this->isCabinet($request), 403);
        $validated = $request->validate([
            'accepting' => ['required', 'boolean'],
            'session' => ['nullable', 'string', 'max:96'],
            'ack' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $pairings->poll(
                cabinetId: 'wdb-home-'.$request->user()->currentAccessToken()->getKey(),
                state: 'attract',
                accepting: (bool) $validated['accepting'],
                sessionToken: $validated['session'] ?? null,
                ackCommandId: $validated['ack'] ?? null,
            );
        } catch (RuntimeException) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        if ($result['status'] !== 'claimed' || $result['access_code'] === null) {
            return response()->json(Arr::except($result, ['access_code']));
        }

        // Re-polled until acknowledged: one token per claim.
        $friend = Cache::remember('wdb-friend:'.$result['command_id'], 120, function () use ($result, $request): ?array {
            $player = GameCard::query()->whereKey($result['access_code'])->first()?->player;
            $user = $player?->user;
            if (! $player instanceof Player || ! $user instanceof User) {
                return null;
            }
            $device = 'Guest session on '.($request->user()->currentAccessToken()->name ?? 'Waddamburo');

            return [
                'token' => $user->createToken($device, ['wdb'], now()->addHours(12))->plainTextToken,
                ...$this->profile($player),
            ];
        });

        return response()->json([
            ...Arr::except($result, ['access_code']),
            'status' => $friend === null ? 'rejected' : 'claimed',
            'friend' => $friend,
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /** Home: who the token belongs to. */
    public function me(Request $request): JsonResponse
    {
        abort_if($this->isCabinet($request), 404);
        $player = $request->user()->player;
        abort_unless($player instanceof Player, 404);

        return response()->json($this->profile($player));
    }

    /**
     * Song select's score windows: the top players per chart (best score each), for the charts of
     * the song under the cursor. Unknown charts come back empty.
     */
    public function rankings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'charts' => ['required', 'array', 'max:10'],
            'charts.*' => ['string', 'regex:/\A[0-9a-f]{64}\z/'],
        ]);
        $charts = WdbChart::query()->whereIn('sha256', $validated['charts'])->pluck('sha256', 'id');
        $rankings = array_fill_keys($validated['charts'], []);
        $viewerBaid = $this->isCabinet($request) ? null : $request->user()->player?->baid;
        foreach ($charts as $chartId => $sha256) {
            // ponytail: every mode and scoring version counts; filter by ranked/min_scoring_version later.
            // Normal scores only: 真打 plays score on another scale. Only plays the server scored.
            $rankings[$sha256] = WdbPlay::query()
                ->where('wdb_chart_id', $chartId)
                ->whereNotNull('rescored_at')
                ->visibleTo($viewerBaid)
                ->whereRaw('(options & ?) = 0', [WdbPlay::SHINUCHI])
                ->selectRaw('baid, MAX(score) AS best')
                ->groupBy('baid')
                ->orderByDesc('best')
                ->limit(3)
                ->with('player:baid,mydon_name,user_id', 'player.user:id,name')
                ->get()
                ->map(fn (WdbPlay $best): array => [
                    'baid' => (int) $best->baid,
                    // The account's public name (as on the name boards), else the Don-chan's.
                    'name' => (string) ($best->player?->user?->name ?? $best->player?->mydon_name ?? ''),
                    'score' => (int) $best->best,
                ])
                ->all();
        }

        return response()->json(['rankings' => $rankings]);
    }

    /**
     * A player's best score and crown per chart (0 none, 1 clear, 2 full combo), so every machine
     * shows the same crowns. Home: the token's player; cabinet: ?baid= (a card it resolved).
     */
    public function bests(Request $request): JsonResponse
    {
        if ($this->isCabinet($request)) {
            $baid = (int) $request->validate(['baid' => ['required', 'integer']])['baid'];
        } else {
            $baid = $request->user()->player?->baid;
            abort_if($baid === null, 404);
        }

        $bests = WdbPlay::query()
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_plays.wdb_chart_id')
            ->where('wdb_plays.baid', $baid)
            ->whereNull('wdb_plays.hidden_at')
            ->whereNotNull('wdb_plays.rescored_at')
            ->groupBy('wdb_charts.sha256')
            // The best normal score (真打 ones are on another scale); a crown from any play.
            ->selectRaw('wdb_charts.sha256, MAX(CASE WHEN (wdb_plays.options & '.WdbPlay::SHINUCHI.') = 0 THEN wdb_plays.score END) AS score, '
                .'MAX(CASE WHEN wdb_plays.cleared AND wdb_plays.miss = 0 THEN 2 WHEN wdb_plays.cleared THEN 1 ELSE 0 END) AS crown')
            ->get()
            ->map(fn ($best): array => [
                'sha256' => (string) $best->sha256,
                'score' => (int) $best->score,
                'crown' => (int) $best->crown,
            ])
            ->all();

        // The last plays, newest first: the client's play history (Song Select's 最近あそんだ曲 folder and its
        // last-played difficulty), merged there with the plays kept on that PC only.
        $recent = WdbPlay::query()
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_plays.wdb_chart_id')
            ->where('wdb_plays.baid', $baid)
            ->whereNull('wdb_plays.hidden_at')
            ->orderByDesc('wdb_plays.played_at')
            ->limit(self::RECENT_PLAYS)
            ->get(['wdb_charts.sha256', 'wdb_plays.course', 'wdb_plays.played_at'])
            ->map(fn ($play): array => [
                'sha256' => (string) $play->sha256,
                'course' => (int) $play->course,
                'played_at' => $play->played_at->toIso8601String(),
            ])
            ->all();

        // Every AI battle (any machine): the client derives the rank, wins and AI-don's starting timing from them.
        $aiPlays = DB::table('wdb_ai_battles')
            ->join('wdb_charts', 'wdb_charts.id', '=', 'wdb_ai_battles.wdb_chart_id')
            ->join('wdb_plays', 'wdb_plays.id', '=', 'wdb_ai_battles.id')
            ->where('wdb_ai_battles.baid', $baid)
            ->whereNotNull('wdb_ai_battles.verified_at')->where('wdb_ai_battles.eligible', true)
            ->tap(fn ($query) => WdbPlay::visibility($query, $baid))
            ->orderByDesc('wdb_ai_battles.played_at')
            ->get(['wdb_ai_battles.id', 'wdb_charts.sha256', 'won', 'points', 'hit_median', 'hit_variance', 'wdb_ai_battles.played_at'])
            ->map(fn ($battle): array => [
                'id' => (string) $battle->id,
                'sha256' => (string) $battle->sha256,
                'won' => (bool) $battle->won,
                'points' => (int) $battle->points,
                'hit_median_ms' => $battle->hit_median === null ? null : (float) $battle->hit_median,
                'hit_variance' => $battle->hit_variance === null ? null : (float) $battle->hit_variance,
                'played_at' => Carbon::parse($battle->played_at)->toIso8601String(),
            ])
            ->all();

        return response()->json(['bests' => $bests, 'recent' => $recent, 'ai_plays' => $aiPlays]);
    }

    /** A seed and prior performance fixed before play, bound to this player's exact chart and options. */
    public function beginAiBattle(Request $request, WaddamburoAiBattles $battles): JsonResponse
    {
        $cabinet = $this->isCabinet($request);
        $setup = $request->validate([
            'baid' => [$cabinet ? 'required' : 'nullable', 'integer'],
            'chart_sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'base_sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'course' => ['required', 'integer', 'between:0,4'],
            'options' => ['required', 'integer', 'between:0,65535'],
            'support_level' => ['required', 'integer', 'between:0,12'],
            'version' => ['required', 'integer', 'between:1,'.WaddamburoAiBattles::VERSION],
        ]);
        $baid = $cabinet ? (int) $setup['baid'] : $request->user()->player?->baid;
        abort_if($baid === null, 403);
        if (! $cabinet && isset($setup['baid']) && (int) $setup['baid'] !== $baid) {
            abort(403);
        }
        if ((int) $setup['support_level'] === 0 && $setup['base_sha256'] !== $setup['chart_sha256']) {
            throw ValidationException::withMessages(['base_sha256' => 'A standard battle must use its base chart.']);
        }

        return response()->json($battles->begin($baid, Arr::only($setup, ['chart_sha256', 'base_sha256', 'course', 'options', 'support_level', 'version'])));
    }

    /** How many of a player's last plays the bests carry (the client keeps the newest 20 songs). */
    private const RECENT_PLAYS = 100;

    /** Cabinet: resolve the access code a 6-pin pairing delivered. */
    public function card(Request $request): JsonResponse
    {
        abort_unless($this->isCabinet($request), 403);
        $validated = $request->validate(['access_code' => ['required', 'string', 'regex:/\A[0-9]{20}\z/']]);
        $player = GameCard::query()->whereKey($validated['access_code'])->first()?->player;
        abort_unless($player instanceof Player, 404);

        return response()->json($this->profile($player));
    }

    /**
     * A batch of plays. Known ids are skipped (idempotent retries). New plays on charts the server has
     * notes for are scored from their replays (the client's numbers are not kept); the response lists
     * the charts it has no notes for yet, which the client then uploads (and those plays score then).
     */
    public function storePlays(Request $request, WaddamburoRankAggregateService $aggregates, WaddamburoScorer $scorer, WaddamburoAiBattles $battles): JsonResponse
    {
        $cabinet = $this->isCabinet($request);
        $validated = $request->validate([
            'plays' => ['required', 'array', 'min:1', 'max:50'],
            'plays.*.id' => ['required', 'uuid'],
            'plays.*.baid' => [$cabinet ? 'required' : 'nullable', 'integer'],
            'plays.*.chart_sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'plays.*.mode' => ['required', 'string', 'in:normal,ai_battle'],
            'plays.*.course' => ['required', 'integer', 'between:0,4'],
            'plays.*.score' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.great' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.good' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.miss' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.max_combo' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.rolls' => ['required', 'integer', 'between:0,2147483647'],
            'plays.*.gauge' => ['required', 'integer', 'between:0,255'],
            'plays.*.cleared' => ['required', 'boolean'],
            'plays.*.scoring_version' => ['required', 'integer', 'between:1,2147483647'],
            'plays.*.engine_version' => ['present', 'nullable', 'string', 'max:64'],
            'plays.*.played_at' => ['required', 'date'],
            'plays.*.replay' => ['required', 'string', 'max:2000000'],
            'plays.*.audio_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.input_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.options' => ['nullable', 'integer', 'between:0,65535'],
            'plays.*.seed' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            'plays.*.ai_session_id' => ['nullable', 'uuid'],
            'plays.*.ai_won' => ['required_if:plays.*.mode,ai_battle', 'nullable', 'boolean'],
            'plays.*.ai_sections' => ['required_if:plays.*.mode,ai_battle', 'nullable', 'string', 'regex:/\A[WL]{1,8}\z/'],
            'plays.*.ai_support' => ['nullable', 'integer', 'between:0,20'],
            'plays.*.ai_points' => ['nullable', 'integer', 'between:0,3'],
            'plays.*.hit_median_ms' => ['nullable', 'numeric', 'between:-1000,1000'],
            'plays.*.hit_variance' => ['nullable', 'numeric', 'between:0,1000000'],
        ]);

        $ownBaid = $cabinet ? null : $request->user()->player?->baid;
        abort_if(! $cabinet && $ownBaid === null, 403, 'Your account does not have a Banapass yet.');

        $accepted = [];
        $charts = [];
        $created = new EloquentCollection;
        foreach ($validated['plays'] as $play) {
            $baid = $ownBaid ?? (int) $play['baid'];
            if ($ownBaid !== null && isset($play['baid']) && (int) $play['baid'] !== $ownBaid) {
                continue;
            }
            if ($cabinet && ! Player::query()->whereKey($baid)->exists()) {
                continue;
            }
            // Every AI battle keeps its replay, including a failed clear: it can still be a battle win.
            // Normal failed plays remain local; acknowledge older clients that still send them.
            if (! filter_var($play['cleared'], FILTER_VALIDATE_BOOLEAN) && $play['mode'] !== 'ai_battle') {
                $accepted[] = $play['id'];

                continue;
            }
            $replay = WaddamburoReplay::canonical($play['replay']);
            if ($replay === null) {
                WaddamburoUploadWarnings::record($baid, UploadWarningKind::MalformedReplay, $play['id']);
                $refused[$baid] = ($refused[$baid] ?? 0) + 1;

                continue;
            }

            $sessionId = $battles->reserve($baid, $play);
            $chart = $charts[$play['chart_sha256']] ??= WdbChart::query()->firstOrCreate(['sha256' => $play['chart_sha256']]);
            $replayHash = WaddamburoReplay::hash($replay);
            // Another player's inputs: kept hidden (a moderator can restore it), and the uploader is not told.
            $original = WdbPlay::query()->where('wdb_chart_id', $chart->id)->where('replay_hash', $replayHash)
                ->where('baid', '!=', $baid)->oldest()->first(['id', 'baid']);
            $stored = WdbPlay::query()->firstOrCreate(['id' => $play['id']], [
                'baid' => $baid,
                'wdb_chart_id' => $chart->id,
                'mode' => $play['mode'],
                'course' => $play['course'],
                'score' => $play['score'],
                'great' => $play['great'],
                'good' => $play['good'],
                'miss' => $play['miss'],
                'max_combo' => $play['max_combo'],
                'rolls' => $play['rolls'],
                'gauge' => $play['gauge'],
                'cleared' => $play['cleared'],
                'scoring_version' => $play['scoring_version'],
                'engine_version' => (string) ($play['engine_version'] ?? ''),
                'played_at' => $play['played_at'],
                'replay' => $replay,
                'replay_hash' => $replayHash,
                'audio_offset_ms' => $play['audio_offset_ms'] ?? null,
                'input_offset_ms' => $play['input_offset_ms'] ?? null,
                'options' => $play['options'] ?? 0,
                'seed' => $play['seed'] ?? null,
                'wdb_cabinet_id' => $request->attributes->get(EnsureWaddamburoCabinet::CABINET_ID),
                ...($original !== null ? ['hidden_at' => now(), 'hidden_reason' => "Copy of play {$original->id}"] : []),
                // Played while restricted: kept (evidence), never counted, even after the ban ends.
                ...(($banned[$baid] ??= Sanction::activeFor($baid, SanctionType::Ban) !== null)
                    ? ['hidden_at' => now(), 'hidden_reason' => 'Played while restricted'] : []),
            ]);
            // Keep every owned battle summary beside its replay, including hidden evidence. Visibility controls awards.
            if ($play['mode'] === 'ai_battle' && $stored->mode === 'ai_battle' && (int) $stored->baid === $baid) {
                $chart = $charts[$play['chart_sha256']] ??= WdbChart::query()->firstOrCreate(['sha256' => $play['chart_sha256']]);
                DB::table('wdb_ai_battles')->insertOrIgnore([
                    'id' => $play['id'],
                    'baid' => $baid,
                    'session_id' => $sessionId,
                    'wdb_chart_id' => $stored->wdb_chart_id,
                    'won' => filter_var($play['ai_won'], FILTER_VALIDATE_BOOLEAN),
                    'sections' => $play['ai_sections'],
                    'support_level' => $play['ai_support'] ?? 0,
                    'points' => $play['ai_points'] ?? 0,
                    'hit_median' => $play['hit_median_ms'] ?? null,
                    'hit_variance' => $play['hit_variance'] ?? null,
                    'played_at' => Carbon::parse($play['played_at']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($stored->wasRecentlyCreated) {
                $created->push($stored);
                if ($original !== null) {
                    WaddamburoUploadWarnings::record($baid, UploadWarningKind::CopiedReplay, $stored->id, "Same inputs as play {$original->id} of player {$original->baid}");
                }
            }
            $accepted[] = $play['id'];
            $players[$baid] = true;
        }
        WaddamburoReplay::notifyRefused($refused ?? [], 'play');
        $scorer->rescore($created);
        // Restricted players hear their new plays won't count (once per upload, in game and on the site).
        $created->where('hidden_reason', 'Played while restricted')->groupBy('baid')->each(function (EloquentCollection $plays) use ($charts): void {
            $title = $plays->count() === 1 ? collect($charts)->firstWhere('id', $plays->first()->wdb_chart_id)?->title : null;
            $plays->first()->player?->user?->notify(new WaddamburoNotice(
                ($title !== null ? "Your play of {$title}" : ($plays->count() === 1 ? 'Your play' : "Your {$plays->count()} plays"))
                    .' will not count: your account is restricted.', 'warning', 'play_rejected'));
        });
        // The website's standings (ranked charts only) follow each upload.
        Player::query()->whereIn('baid', array_keys($players ?? []))->get()
            ->each(fn (Player $player) => $aggregates->recompute($player));

        $missing = WdbChart::query()
            ->whereIn('sha256', array_keys($charts))
            ->whereNull('notes')
            ->pluck('sha256');

        return response()->json(['accepted' => $accepted, 'missing_charts' => $missing]);
    }

    /** Imports a chart's canonical notes (gzipped, base64); the hash must match the decompressed bytes. */
    public function storeChart(Request $request, string $sha256, WaddamburoScorer $scorer, WaddamburoRankAggregateService $aggregates): Response
    {
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $sha256) === 1, 404);
        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:8000000'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:64'],
            'course' => ['nullable', 'integer', 'between:0,4'],
            'level' => ['nullable', 'integer', 'between:0,20'],
            'song_key' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'subtitle_en' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['nullable', 'string', 'max:255'],
            'osu_beatmap_id' => ['nullable', 'integer', 'min:1'],
            'osu_beatmapset_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $compressed = base64_decode($validated['notes'], true);
        $notes = $compressed === false ? false : @gzdecode($compressed, self::MAX_CHART_BYTES);
        $uploader = $this->isCabinet($request) ? null : $request->user()->player?->baid;
        if ($notes === false || strlen($notes) > self::MAX_CHART_BYTES || ! hash_equals($sha256, hash('sha256', $notes))) {
            WaddamburoUploadWarnings::record($uploader, UploadWarningKind::BadChart, detail: "Notes too big or not matching {$sha256}");
            throw ValidationException::withMessages(['notes' => 'The notes do not match the chart hash.']);
        }

        $chart = WdbChart::query()->firstOrCreate(['sha256' => $sha256]);
        if ($chart->notes === null) {
            $problem = $scorer->chartProblem(gzencode($notes), $validated['source'] ?? null);
            if ($problem !== null) {
                // Not kept, and no error: the game stops syncing a player's plays over a chart it cannot upload.
                WaddamburoUploadWarnings::record($uploader, UploadWarningKind::BadChart, detail: "{$sha256}: {$problem}");

                return response()->noContent();
            }
            $chart->update([
                // Compressed again: PHP read only the first gzip member, the scorer would read them all.
                'notes' => gzencode($notes),
                'title' => $validated['title'] ?? null,
                'subtitle' => $validated['subtitle'] ?? null,
                'source' => $validated['source'] ?? null,
                'course' => $validated['course'] ?? null,
                'level' => $validated['level'] ?? null,
            ]);
        }
        // Metadata added after a chart was imported fills in; nothing already set changes.
        foreach (self::LATER_METADATA as $column) {
            $chart->{$column} ??= $validated[$column] ?? null;
        }
        $chart->save();
        WaddamburoSongs::attach([['wdb_chart_id' => $chart->id, ...$validated]]);
        // The plays that came before their chart score now.
        $changed = $scorer->rescore($chart->plays()->whereNull('rescored_at')->get())['players'];
        Player::query()->whereIn('baid', $changed)->get()->each(fn (Player $player) => $aggregates->recompute($player));

        return response()->noContent();
    }

    private function isCabinet(Request $request): bool
    {
        return $request->attributes->get(AuthenticateWaddamburo::CABINET) === true;
    }

    /**
     * Don-chan's 63 colours by id, as the game numbers them (same table as the web Don editor,
     * resources/js/pages/settings/DonChanAvatar.svelte).
     */
    private const DON_COLORS = [
        '#f94729', '#68c0c1', '#dd1400', '#f8f1df', '#019587', '#00bf86', '#00ff99', '#65ffc3', '#ffffff',
        '#690001', '#fe0000', '#ff6a65', '#feb2b4', '#00bbc2', '#00f7ff', '#66fafe', '#b4feff', '#e4e4e4',
        '#993900', '#ff5f01', '#ff9e79', '#fecfb3', '#024f95', '#0088fe', '#68b8ff', '#b3dbff', '#b9b9b9',
        '#b37802', '#ffaa00', '#ffcc67', '#fee2b3', '#000d80', '#0119ff', '#6774ff', '#b3baff', '#858585',
        '#b49b01', '#ffdd00', '#ffff00', '#feff71', '#2b0181', '#5600ff', '#9966ff', '#ccb4ff', '#505050',
        '#39a102', '#77c800', '#b3ff00', '#ddff8c', '#62007e', '#c600ff', '#df69fe', '#edb3ff', '#232323',
        '#006600', '#02b900', '#00ff00', '#89ff9e', '#990158', '#ff0097', '#ff67be', '#ffb4df', '#000000',
    ];

    /**
     * The player and their Don's look, from Waddamburo's own loadout (Green's item ids, as it draws Green's models):
     * the equipped costume parts (kigurumi, head, body, face, puchi) and the face/body/limb colours.
     *
     * @return array{baid: int, name: string, title: ?string, title_plate: int, dan_rank: int, dan_gold: bool, look: array{costume: list<int>, presets: list<list<int>>, dan_rank: int, face: string, body: string, limb: string}}
     */
    public static function profile(Player $player): array
    {
        $cosmetics = $player->cosmetics()->where('game_version', PlayerCosmetic::WADDAMBURO)->first();
        $dan = app(WaddamburoDanRank::class)->selected($player, $cosmetics);
        $color = fn (?int $id, int $default): string => self::DON_COLORS[$id ?? $default] ?? self::DON_COLORS[$default];
        // The dan costume without a displayed rank (none chosen, or no longer verified) falls back to no costume.
        $kigurumi = fn (int $id): int => $id === PlayerCosmetic::DAN_COSTUME && $dan === null ? 0 : $id;

        return [
            'baid' => (int) $player->baid,
            'name' => (string) ($player->mydon_name ?? ''),
            // The website's public display name (not the login username), shown on Waddamburo's name boards.
            'account_name' => $player->user?->name,
            // The account's custom Don-chan (a transparent PNG), for the game's account picker.
            'avatar' => $player->user?->avatar,
            // Name-board title (RESTRICTED while banned, so every screen shows it) and plate (0 wood, 1 rainbow, 2 gold, 3 purple).
            'title' => $player->activeBan() !== null ? 'RESTRICTED' : $cosmetics?->title,
            'title_plate' => (int) ($cosmetics?->titleplate_id ?? 0),
            'dan_rank' => $dan['rank'] ?? 0,
            'dan_gold' => $dan['gold'] ?? false,
            'look' => [
                'costume' => array_map(fn (int $slot): int => $slot === 1 ? $kigurumi((int) ($cosmetics?->costume_1 ?? 0))
                    : (int) ($cosmetics?->{"costume_{$slot}"} ?? 0), [1, 2, 3, 4, 5]),
                // The entry's three costume sets: [kigurumi, head, body, puchi] each.
                'presets' => array_map(fn (array $set): array => [$kigurumi((int) $set['costume_1']), ...array_slice(array_values($set), 1)],
                    ($cosmetics ?? new PlayerCosmetic)->normalizedPresets()),
                // Which of the dan costume's per-rank models to draw (1-25; 0 none).
                'dan_rank' => $dan['rank'] ?? 0,
                'face' => $color($player->color_face, 0),
                'body' => $color($player->color_body, 1),
                'limb' => $color($player->color_limb, 3),
            ],
        ];
    }
}
