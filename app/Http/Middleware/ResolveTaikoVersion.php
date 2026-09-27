<?php

namespace App\Http\Middleware;

use App\Enums\TaikoGameVersion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ResolveTaikoVersion
{
    public const All = 'all';

    public const Waddamburo = 'waddamburo';

    /** The website's default scope (URLs without one, the home redirect): Waddamburo. */
    public const DefaultScope = self::Waddamburo;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $scope = $route?->parameter('taikoVersion');

        if ($scope === null) {
            URL::defaults(['taikoVersion' => self::DefaultScope]);

            return $next($request);
        }

        $scope = (string) $scope;
        $version = in_array($scope, [self::All, self::Waddamburo], true) ? null : TaikoGameVersion::tryFrom($scope);

        if (! in_array($scope, [self::All, self::Waddamburo], true) && ! $version instanceof TaikoGameVersion) {
            abort(404);
        }

        if ($scope === self::All && ! $request->routeIs('admin.*')) {
            abort(404);
        }

        URL::defaults(['taikoVersion' => $scope]);

        $request->attributes->set('taikoVersionScope', $scope);
        $request->attributes->set('taikoVersionIsAll', $scope === self::All);
        $request->attributes->set('taikoVersionIsWaddamburo', $scope === self::Waddamburo);
        $request->attributes->set('taikoGameVersion', $version);
        $route->forgetParameter('taikoVersion');

        return $next($request);
    }
}
