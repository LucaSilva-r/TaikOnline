<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WdbCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every cabinet token ever issued, by admins or by players for their own cabinet: disabled to turn one
 * off for a while, revoked to cut it off for good.
 */
class WaddamburoCabinetController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/WaddamburoCabinets', [
            'cabinets' => WdbCabinet::query()->with('user.player')->withCount('plays')->latest('id')->get()->map(fn (WdbCabinet $cabinet): array => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'owner' => $cabinet->user === null ? null : [
                    'id' => $cabinet->user->id,
                    'name' => $cabinet->user->name,
                    'banned' => $cabinet->ownerBanned(),
                ],
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [$cabinet, $token] = WdbCabinet::issue($validated['name'], $request->user()->id);

        return back()->with('wdb_cabinet_issued', ['id' => $cabinet->id, 'name' => $cabinet->name, 'token' => $token]);
    }

    /** Cuts a cabinet off for good: its token stops working at once (its plays stay). */
    public function revoke(WdbCabinet $cabinet): RedirectResponse
    {
        $cabinet->update(['revoked_at' => $cabinet->revoked_at ?? now()]);

        return back();
    }

    /** Turns a cabinet off until it is enabled again. */
    public function disable(WdbCabinet $cabinet): RedirectResponse
    {
        $cabinet->update(['disabled_at' => $cabinet->disabled_at ?? now()]);

        return back();
    }

    public function enable(WdbCabinet $cabinet): RedirectResponse
    {
        $cabinet->update(['disabled_at' => null]);

        return back();
    }
}
