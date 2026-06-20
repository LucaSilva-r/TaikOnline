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
                'scope' => $request->attributes->get('taikoVersionScope', TaikoGameVersion::default()->value),
                'isAll' => (bool) $request->attributes->get('taikoVersionIsAll', false),
                'current' => $this->currentVersion($request),
                'versions' => collect(TaikoGameVersion::cases())
                    ->map(fn (TaikoGameVersion $version): array => $this->versionPayload($version))
                    ->values()
                    ->all(),
                'allowAll' => $request->routeIs('admin.*'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function currentVersion(Request $request): ?array
    {
        $version = $request->attributes->get('taikoGameVersion');

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
}
