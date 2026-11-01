<?php

namespace App\Http\Middleware;

use App\Http\Controllers\LegalController;
use App\Models\User;
use App\Notifications\WaddamburoNotice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a home player's uploads until they accept the Rules on the website, telling them once per version
 * in the game. An error, not a refusal in the response: the game keeps its plays and sends them again later.
 * Cabinets are let through: their players have no account on the machine.
 */
class EnsureWaddamburoRulesAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get(AuthenticateWaddamburo::CABINET) === true ? null : $request->user();
        if (! $user instanceof User || ! LegalController::mustAccept($user)) {
            return $next($request);
        }
        $version = (string) config('legal.version');
        if (! $user->notifications()->where('data', 'like', '%"rules_version":"'.$version.'"%')->exists()) {
            $user->notify(new WaddamburoNotice(
                'Accept the Rules at '.url('/').' to upload scores. Your plays are saved and will upload afterwards.',
                'warning', 'rules_required', ['rules_version' => $version]));
        }

        return response()->json(['message' => 'Accept the Rules on the website first.', 'rules_required' => true], 403);
    }
}
