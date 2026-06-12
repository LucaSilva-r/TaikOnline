<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccessCodeBindRequest;
use App\Services\AccessCodeOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccessCodeController extends Controller
{
    public function update(AccessCodeBindRequest $request, AccessCodeOwnershipService $accessCodes): RedirectResponse
    {
        $accessCodes->claim($request->user(), $request->validated('access_code'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code linked.')]);

        return to_route('profile.edit');
    }

    public function destroy(Request $request, AccessCodeOwnershipService $accessCodes): RedirectResponse
    {
        $accessCodes->unclaim($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access code unlinked.')]);

        return to_route('profile.edit');
    }
}
