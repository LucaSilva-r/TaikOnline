<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CabinetRegisterRequest;
use App\Models\Cabinet;
use App\Models\CabinetBookkeepingLog;
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

    public function show(Request $request, Cabinet $cabinet): Response
    {
        abort_unless($cabinet->user_id === $request->user()->id, 403);

        $bookkeeping = CabinetBookkeepingLog::query()
            ->where('chassis_id', $cabinet->serial)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (CabinetBookkeepingLog $log): array => [
                'update_date' => $log->update_date,
                'shop_id' => $log->shop_id,
                'all_play_count' => $log->all_play_count,
                'service_switch_count' => $log->service_switch_count,
                'free_play_count' => $log->free_play_count,
                'payload' => $log->payload,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/CabinetDetail', [
            'cabinet' => [
                'serial' => $cabinet->serial,
                'nickname' => $cabinet->nickname,
                'registered_at' => $cabinet->registered_at?->toIso8601String(),
                'last_heartbeat_at' => $cabinet->last_heartbeat_at?->toIso8601String(),
                'last_reported_at' => $cabinet->last_reported_at?->toIso8601String(),
                'last_ip' => $cabinet->last_ip,
                'is_online' => $cabinet->isOnline(),
                'reported_config' => $cabinet->reported_config ?? [],
                'reported_meta' => $cabinet->reported_meta ?? [],
                'desired_config' => $cabinet->desired_config ?? [],
            ],
            'bookkeeping' => $bookkeeping,
        ]);
    }

    public function updateConfig(Request $request, Cabinet $cabinet): RedirectResponse
    {
        abort_unless($cabinet->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'desired_config' => ['nullable', 'array'],
            'desired_config.*.key' => ['required', 'integer', 'min:0'],
            'desired_config.*.value' => ['required', 'string'],
        ]);

        $cabinet->update([
            'desired_config' => $validated['desired_config'] ?? [],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cabinet config saved.')]);

        return to_route('cabinets.show', $cabinet);
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
