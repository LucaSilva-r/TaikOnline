<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CabinetRegisterRequest;
use App\Models\Cabinet;
use App\Services\CabinetConfigArchive;
use App\Services\CabinetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CabinetController extends Controller
{
    public function index(Request $request): Response
    {
        $cabinets = Cabinet::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('registered_at')
            ->get()
            ->map(fn (Cabinet $cabinet): array => [
                'serial' => $cabinet->serial,
                'nickname' => $cabinet->nickname,
                'registered_at' => $cabinet->registered_at?->toIso8601String(),
                'last_heartbeat_at' => $cabinet->last_heartbeat_at?->toIso8601String(),
                'last_ip' => $cabinet->last_ip,
                'is_online' => $cabinet->isOnline(),
            ])
            ->values();

        return Inertia::render('settings/Cabinets', [
            'cabinets' => $cabinets,
        ]);
    }

    public function store(CabinetRegisterRequest $request, CabinetService $service): RedirectResponse
    {
        $cabinet = $service->allocate($request->user(), $request->validated('nickname'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cabinet :serial registered.', ['serial' => $cabinet->serial]),
        ]);

        return to_route('cabinets.index');
    }

    public function destroy(Request $request, Cabinet $cabinet, CabinetService $service): RedirectResponse
    {
        abort_unless($cabinet->user_id === $request->user()->id, 403);

        $service->revoke($cabinet);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cabinet revoked.')]);

        return to_route('cabinets.index');
    }

    public function download(Request $request, Cabinet $cabinet, CabinetConfigArchive $archive): StreamedResponse
    {
        abort_unless($cabinet->user_id === $request->user()->id, 403);

        return $archive->streamDownload($cabinet);
    }
}
