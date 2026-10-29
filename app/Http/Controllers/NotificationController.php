<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The header's notification center: its list comes with every page (HandleInertiaRequests). */
class NotificationController extends Controller
{
    /** Marks the given notifications read, or all of them. */
    public function read(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['string', 'uuid']])['ids'] ?? null;
        $request->user()->unreadNotifications()->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))->update(['read_at' => now()]);

        return back();
    }
}
