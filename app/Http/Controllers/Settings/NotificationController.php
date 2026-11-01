<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The notification center: everything the account was told, by category, to read or clear. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $this->category($request);

        return Inertia::render('settings/Notifications', [
            'category' => $category?->value,
            'categories' => array_map(fn (NotificationCategory $tab): array => [
                'value' => $tab->value,
                'label' => $tab->label(),
                'unread' => tap($request->user()->unreadNotifications(), fn ($query) => $tab->apply($query))->count(),
            ], NotificationCategory::cases()),
            'items' => $request->user()->notifications()->when($category, fn ($query) => $category->apply($query))
                ->latest()->paginate(30)->withQueryString()
                ->through(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'message' => $notification->data['message'] ?? '',
                    'severity' => $notification->data['severity'] ?? 'info',
                    'url' => $notification->data['url'] ?? null,
                    'category' => NotificationCategory::of($notification->data['kind'] ?? null)->label(),
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Marks everything in the category read. */
    public function readAll(Request $request): RedirectResponse
    {
        $category = $this->category($request);
        $request->user()->unreadNotifications()->when($category, fn ($query) => $category->apply($query))->update(['read_at' => now()]);

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->delete();

        return back();
    }

    /** Deletes everything in the category. */
    public function clear(Request $request): RedirectResponse
    {
        $category = $this->category($request);
        $request->user()->notifications()->when($category, fn ($query) => $category->apply($query))->delete();

        return back();
    }

    private function category(Request $request): ?NotificationCategory
    {
        $validated = $request->validate(['category' => ['nullable', Rule::enum(NotificationCategory::class)]]);

        return isset($validated['category']) ? NotificationCategory::from($validated['category']) : null;
    }
}
