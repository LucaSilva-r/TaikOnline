<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateWaddamburo;
use App\Http\Middleware\ResolveTaikoVersion;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use App\Services\CabinetPairingService;
use App\Services\WaddamburoDeviceLoginService;
use App\Services\WaddamburoRankAggregateService;
use App\Services\WaddamburoSongs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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

    /** Decompressed canonical chart size limit (a long Oni chart is well under 100 KB). */
    public const MAX_CHART_BYTES = 4 * 1024 * 1024;

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

        return response()->json([...$login, 'verification_url' => url('/'.ResolveTaikoVersion::DefaultScope.'/link')]);
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
        foreach ($charts as $chartId => $sha256) {
            // ponytail: every mode and scoring version counts; filter by ranked/min_scoring_version later.
            // Normal scores only: 真打 plays score on another scale.
            $rankings[$sha256] = WdbPlay::query()
                ->where('wdb_chart_id', $chartId)
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

        return response()->json(['bests' => $bests]);
    }

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
     * A batch of plays. Known ids are skipped (idempotent retries). The response lists the charts
     * the server has no notes for yet, which the client then uploads.
     */
    public function storePlays(Request $request, WaddamburoRankAggregateService $aggregates): JsonResponse
    {
        $cabinet = $this->isCabinet($request);
        $validated = $request->validate([
            'plays' => ['required', 'array', 'min:1', 'max:50'],
            'plays.*.id' => ['required', 'uuid'],
            'plays.*.baid' => [$cabinet ? 'required' : 'nullable', 'integer'],
            'plays.*.chart_sha256' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'plays.*.mode' => ['required', 'string', 'in:normal'],
            'plays.*.course' => ['required', 'integer', 'between:0,4'],
            'plays.*.score' => ['required', 'integer', 'min:0'],
            'plays.*.great' => ['required', 'integer', 'min:0'],
            'plays.*.good' => ['required', 'integer', 'min:0'],
            'plays.*.miss' => ['required', 'integer', 'min:0'],
            'plays.*.max_combo' => ['required', 'integer', 'min:0'],
            'plays.*.rolls' => ['required', 'integer', 'min:0'],
            'plays.*.gauge' => ['required', 'integer', 'between:0,255'],
            'plays.*.cleared' => ['required', 'boolean'],
            'plays.*.scoring_version' => ['required', 'integer', 'min:1'],
            'plays.*.engine_version' => ['present', 'nullable', 'string', 'max:64'],
            'plays.*.played_at' => ['required', 'date'],
            'plays.*.replay' => ['required', 'string', 'max:2000000'],
            'plays.*.audio_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.input_offset_ms' => ['nullable', 'integer', 'between:-5000,5000'],
            'plays.*.options' => ['nullable', 'integer', 'between:0,65535'],
            'plays.*.seed' => ['nullable', 'integer'],
        ]);

        $ownBaid = $cabinet ? null : $request->user()->player?->baid;
        abort_if(! $cabinet && $ownBaid === null, 403, 'Your account does not have a Banapass yet.');

        $accepted = [];
        $charts = [];
        foreach ($validated['plays'] as $play) {
            $baid = $ownBaid ?? (int) $play['baid'];
            if ($ownBaid !== null && isset($play['baid']) && (int) $play['baid'] !== $ownBaid) {
                continue;
            }
            if ($cabinet && ! Player::query()->whereKey($baid)->exists()) {
                continue;
            }
            $replay = base64_decode($play['replay'], true);
            if ($replay === false) {
                continue;
            }

            $chart = $charts[$play['chart_sha256']] ??= WdbChart::query()->firstOrCreate(['sha256' => $play['chart_sha256']]);
            WdbPlay::query()->firstOrCreate(['id' => $play['id']], [
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
                'audio_offset_ms' => $play['audio_offset_ms'] ?? null,
                'input_offset_ms' => $play['input_offset_ms'] ?? null,
                'options' => $play['options'] ?? 0,
                'seed' => $play['seed'] ?? null,
            ]);
            $accepted[] = $play['id'];
            $players[$baid] = true;
        }
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
    public function storeChart(Request $request, string $sha256): Response
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
        if ($notes === false || ! hash_equals($sha256, hash('sha256', $notes))) {
            throw ValidationException::withMessages(['notes' => 'The notes do not match the chart hash.']);
        }

        $chart = WdbChart::query()->firstOrCreate(['sha256' => $sha256]);
        if ($chart->notes === null) {
            $chart->update([
                'notes' => $compressed,
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
     * @return array{baid: int, name: string, title: ?string, title_plate: int, look: array{costume: list<int>, presets: list<list<int>>, face: string, body: string, limb: string}}
     */
    public static function profile(Player $player): array
    {
        $cosmetics = $player->cosmetics()->where('game_version', PlayerCosmetic::WADDAMBURO)->first();
        $color = fn (?int $id, int $default): string => self::DON_COLORS[$id ?? $default] ?? self::DON_COLORS[$default];

        return [
            'baid' => (int) $player->baid,
            'name' => (string) ($player->mydon_name ?? ''),
            // The website's public display name (not the login username), shown on Waddamburo's name boards.
            'account_name' => $player->user?->name,
            // The account's custom Don-chan (a transparent PNG), for the game's account picker.
            'avatar' => $player->user?->avatar,
            // Name-board title and plate (0 wood, 1 rainbow, 2 gold, 3 purple).
            'title' => $cosmetics?->title,
            'title_plate' => (int) ($cosmetics?->titleplate_id ?? 0),
            'look' => [
                'costume' => array_map(fn (int $slot): int => (int) ($cosmetics?->{"costume_{$slot}"} ?? 0), [1, 2, 3, 4, 5]),
                // The entry's three costume sets: [kigurumi, head, body, puchi] each.
                'presets' => array_map(array_values(...), ($cosmetics ?? new PlayerCosmetic)->normalizedPresets()),
                'face' => $color($player->color_face, 0),
                'body' => $color($player->color_body, 1),
                'limb' => $color($player->color_limb, 3),
            ],
        ];
    }
}
