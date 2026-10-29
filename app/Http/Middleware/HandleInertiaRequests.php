<?php

namespace App\Http\Middleware;

use App\Enums\TaikoGameVersion;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'taikoVersion' => [
                'scope' => $request->attributes->get('taikoVersionScope', ResolveTaikoVersion::DefaultScope),
                'isAll' => (bool) $request->attributes->get('taikoVersionIsAll', false),
                'current' => $this->currentVersion($request),
                // Waddamburo first: the site's own game, set apart in the switcher.
                'versions' => collect([$this->waddamburoPayload()])
                    ->concat(collect(TaikoGameVersion::cases())
                        ->map(fn (TaikoGameVersion $version): array => $this->versionPayload($version)))
                    ->values()
                    ->all(),
                'allowAll' => $request->routeIs('admin.*'),
            ],
            'notifications' => fn (): ?array => $request->user() === null ? null : [
                'unread' => $request->user()->unreadNotifications()->count(),
                // New ones are pushed here (Reverb, Pusher protocol); the bell reloads this prop when one arrives.
                'realtime' => $this->realtimeUrl() === null ? null : [
                    'url' => $this->realtimeUrl(),
                    'channel' => 'private-'.$request->user()->receivesBroadcastNotificationsOn(),
                ],
                'latest' => $request->user()->notifications()->latest()->limit(15)->get()
                    ->map(fn ($notification): array => [
                        'id' => $notification->id,
                        'message' => $notification->data['message'] ?? '',
                        'severity' => $notification->data['severity'] ?? 'info',
                        'url' => $notification->data['url'] ?? null,
                        'read' => $notification->read_at !== null,
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ])->all(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** Reverb's public WebSocket URL (as Waddamburo gets it), or null without one. */
    private function realtimeUrl(): ?string
    {
        $key = config('broadcasting.connections.reverb.key');
        $base = rtrim((string) config('broadcasting.connections.reverb.public_url'), '/');

        return $key === null || $base === '' ? null : "{$base}/app/{$key}";
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function currentVersion(Request $request): ?array
    {
        $version = $request->attributes->get('taikoGameVersion');

        if ((bool) $request->attributes->get('taikoVersionIsWaddamburo', false)) {
            return $this->waddamburoPayload();
        }

        return $version instanceof TaikoGameVersion ? $this->versionPayload($version) : null;
    }

    /**
     * @return array{value: string, label: string, supports: array<string, bool|int>}
     */
    private function versionPayload(TaikoGameVersion $version): array
    {
        return [
            'value' => $version->value,
            'label' => $version->label(),
            'supports' => $version->featureSupport(),
        ];
    }

    private function waddamburoPayload(): array
    {
        return [
            'value' => 'waddamburo',
            'label' => 'Waddamburo',
            'supports' => [
                'favoriteFolder' => false,
                'favoriteLimit' => 0,
                'costumeSlots' => TaikoGameVersion::Green->supportsCostumeSlots(), // edits Green's Don-chan
                'playOptionDefaults' => false,
                'toneDefault' => false,
                'rankingDifficulty' => false,
                'profilePublicity' => false,
                'difficultyFolderPresets' => false,
            ],
        ];
    }
}
