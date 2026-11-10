<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\WdbCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A player's own Waddamburo cabinet tokens. Issuing one first needs the cabinet terms accepted (one
 * token per cabinet, never shared; staff can turn any token off). Banned players cannot issue one.
 */
class CabinetTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/Cabinets', [
            'acceptedTerms' => $user->cabinet_terms_accepted_at !== null,
            'banned' => $user->player?->activeBan() !== null,
            'cabinets' => WdbCabinet::query()->where('user_id', $user->id)->withCount('plays')->latest('id')->get()
                ->map(fn (WdbCabinet $cabinet): array => [
                    'id' => $cabinet->id,
                    'name' => $cabinet->name,
                    'plays' => (int) $cabinet->plays_count,
                    'last_seen_at' => $cabinet->last_seen_at?->toIso8601String(),
                    'disabled_at' => $cabinet->disabled_at?->toIso8601String(),
                    'revoked_at' => $cabinet->revoked_at?->toIso8601String(),
                    'created_at' => $cabinet->created_at?->toIso8601String(),
                ])->all(),
            // The token just issued, shown this once.
            'issued' => $request->session()->get('wdb_cabinet_issued'),
        ]);
    }

    public function acceptTerms(Request $request): RedirectResponse
    {
        $request->validate(['accept' => ['accepted']]);
        $request->user()->forceFill(['cabinet_terms_accepted_at' => now()])->save();

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->cabinet_terms_accepted_at === null, 403, 'Accept the cabinet terms first.');
        abort_if($user->player?->activeBan() !== null, 403);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        [$cabinet, $token] = WdbCabinet::issue($validated['name'], $user->id, $user->id);

        return back()->with('wdb_cabinet_issued', ['id' => $cabinet->id, 'name' => $cabinet->name, 'token' => $token]);
    }

    /** The owner cuts their own cabinet off for good. */
    public function revoke(Request $request, int $cabinet): RedirectResponse
    {
        $owned = WdbCabinet::query()->where('user_id', $request->user()->id)->whereKey($cabinet)->firstOrFail();
        $owned->update(['revoked_at' => $owned->revoked_at ?? now()]);

        return back();
    }
}
