<?php

namespace App\Http\Controllers\Admin;

use App\Events\WdbNoticeEnded;
use App\Events\WdbNoticePosted;
use App\Http\Controllers\Controller;
use App\Models\WdbNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** System notices for every Waddamburo client: written here, pushed at once, ended early or deleted. */
class WaddamburoNoticeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/WaddamburoNotices', [
            'notices' => WdbNotice::query()->latest('id')->limit(50)->get()->map(fn (WdbNotice $notice): array => [
                ...$notice->toClient(),
                'active' => $notice->ends_at === null || $notice->ends_at->isFuture(),
            ])->all(),
            'severities' => WdbNotice::SEVERITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'severity' => ['required', Rule::in(WdbNotice::SEVERITIES)],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
        ]);
        $notice = WdbNotice::query()->create([
            'message' => $validated['message'],
            'severity' => $validated['severity'],
            'ends_at' => isset($validated['minutes']) ? now()->addMinutes((int) $validated['minutes']) : null,
            'created_by' => $request->user()->id,
        ]);
        WdbNoticePosted::dispatch($notice);

        return back();
    }

    /** Ends a notice now (it stays listed here as ended). */
    public function end(WdbNotice $notice): RedirectResponse
    {
        $notice->update(['ends_at' => now()]);
        WdbNoticeEnded::dispatch($notice->id);

        return back();
    }

    public function destroy(WdbNotice $notice): RedirectResponse
    {
        $id = $notice->id;
        $notice->delete();
        WdbNoticeEnded::dispatch($id);

        return back();
    }
}
