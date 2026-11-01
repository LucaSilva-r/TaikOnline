<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTaikoVersion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\Table\TableExtension;

/** This instance's Rules and Privacy Policy (config legal.path), and accepting the Rules. */
class LegalController extends Controller
{
    public static function mustAccept(User $user): bool
    {
        $version = config('legal.version');

        return is_string($version) && $version !== '' && $user->rules_accepted_version !== $version
            && is_file(self::documentPath('rules'));
    }

    private static function documentPath(string $document): string
    {
        return rtrim((string) config('legal.path'), '/')."/{$document}.md";
    }

    public static function scope(Request $request): string
    {
        return (string) $request->attributes->get('taikoVersionScope', ResolveTaikoVersion::DefaultScope);
    }

    public function rules(Request $request): Response
    {
        return $this->page($request, 'Rules', 'rules');
    }

    public function privacy(Request $request): Response
    {
        return $this->page($request, 'Privacy Policy', 'privacy');
    }

    public function accept(Request $request): RedirectResponse
    {
        abort_if(blank(config('legal.version')), 404);
        $request->validate(['accepted' => ['accepted']]);
        $request->user()->update(['rules_accepted_version' => config('legal.version'), 'rules_accepted_at' => now()]);

        return redirect()->intended(route('home', ['taikoVersion' => self::scope($request)]));
    }

    private function page(Request $request, string $title, string $document): Response
    {
        abort_if(blank(config('legal.version')) || ! is_file(self::documentPath($document)), 404);
        $user = $request->user();

        return Inertia::render('Legal', [
            'title' => $title,
            // Written by whoever runs the instance, never by a user: safe to show as HTML.
            'html' => Str::markdown((string) file_get_contents(self::documentPath($document)), ['html_input' => 'strip', 'allow_unsafe_links' => false], [new TableExtension]),
            'mustAccept' => $user instanceof User && self::mustAccept($user),
            'version' => config('legal.version'),
        ]);
    }
}
