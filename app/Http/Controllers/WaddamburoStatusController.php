<?php

namespace App\Http\Controllers;

use App\Services\WaddamburoStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A home player's game reporting what they're doing. Cabinets aren't anyone in particular: they get a 404. */
class WaddamburoStatusController extends Controller
{
    public function update(Request $request, WaddamburoStatus $status): Response
    {
        abort_if($request->user() === null, 404);
        $validated = $request->validate([
            'state' => ['required', 'string', 'in:online,afk,playing'],
            'chart_sha256' => ['nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ]);
        $status->report($request->user(), $validated['state'], $validated['chart_sha256'] ?? null);

        return response()->noContent();
    }

    public function destroy(Request $request, WaddamburoStatus $status): Response
    {
        abort_if($request->user() === null, 404);
        $status->clear($request->user());

        return response()->noContent();
    }
}
