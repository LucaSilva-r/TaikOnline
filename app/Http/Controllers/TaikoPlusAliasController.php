<?php

namespace App\Http\Controllers;

use App\Services\TaikoPlusAliasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues a view-only alias for a logged-in Taiko+ player's card, which the
 * client hands to its online opponent instead of the real access code.
 */
class TaikoPlusAliasController extends Controller
{
    public function __invoke(Request $request, TaikoPlusAliasService $aliases): JsonResponse
    {
        $validated = $request->validate([
            'access_code' => ['required', 'string', 'regex:/^[0-9]{20}$/'],
        ]);

        $alias = $aliases->issue($validated['access_code']);
        if ($alias === null) {
            return response()->json(['message' => 'Unknown card'], 404);
        }

        return response()->json(['alias' => $alias]);
    }
}
