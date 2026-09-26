<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateWaddamburo;
use App\Models\GameCard;
use App\Models\Player;
use App\Models\User;
use App\Models\WdbChart;
use App\Models\WdbPlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/** The Waddamburo client API: login, card lookup, play and chart upload. */
class WaddamburoController extends Controller
{
    /** Decompressed canonical chart size limit (a long Oni chart is well under 100 KB). */
    private const MAX_CHART_BYTES = 4 * 1024 * 1024;

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
    public function storePlays(Request $request): JsonResponse
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
            ]);
            $accepted[] = $play['id'];
        }

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

        return response()->noContent();
    }

    private function isCabinet(Request $request): bool
    {
        return $request->attributes->get(AuthenticateWaddamburo::CABINET) === true;
    }

    /**
     * @return array{baid: int, name: string}
     */
    private function profile(Player $player): array
    {
        return ['baid' => (int) $player->baid, 'name' => (string) ($player->mydon_name ?? '')];
    }
}
