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
        // Checked on every signed-in request: the version in force is kept before anyone can edit it away.
        self::archive();
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

    /** The versions kept so far, oldest first, the current one last. */
    public static function versions(): array
    {
        self::archive();
        $folders = glob(rtrim((string) config('legal.history'), '/').'/*', GLOB_ONLYDIR) ?: [];
        usort($folders, fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        return array_map('basename', $folders);
    }

    /** Keeps the current version's documents as they are now, once: later versions are compared with them. */
    private static function archive(): void
    {
        $version = config('legal.version');
        if (! is_string($version) || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $version)) {
            return;
        }
        $folder = rtrim((string) config('legal.history'), '/')."/{$version}";
        if (is_dir($folder)) {
            return;
        }
        @mkdir($folder, 0775, true);
        foreach (['rules', 'privacy'] as $document) {
            if (is_file(self::documentPath($document))) {
                copy(self::documentPath($document), "{$folder}/{$document}.md");
            }
        }
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
        $mustAccept = $user instanceof User && self::mustAccept($user);
        $current = (string) file_get_contents(self::documentPath($document));
        $earlier = array_values(array_filter(self::versions(), fn (string $version): bool => $version !== config('legal.version')
            && is_file(self::historyPath($version, $document))));
        // What changed since: the version asked for, or the one a player behind on the rules last accepted.
        // An empty ?since= (null by now) asks for none.
        $since = $request->has('since') ? $request->query('since') : ($mustAccept ? $user->rules_accepted_version : null);
        $since = is_string($since) && in_array($since, $earlier, true) ? $since : null;

        return Inertia::render('Legal', [
            'title' => $title,
            'html' => self::html($current),
            'mustAccept' => $mustAccept,
            'version' => config('legal.version'),
            'versions' => $earlier,
            'since' => $since,
            'changes' => $since === null ? null : self::changes((string) file_get_contents(self::historyPath($since, $document)), $current),
        ]);
    }

    private static function historyPath(string $version, string $document): string
    {
        return rtrim((string) config('legal.history'), '/')."/{$version}/{$document}.md";
    }

    // Written by whoever runs the instance, never by a user: safe to show as HTML.
    private static function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false], [new TableExtension]);
    }

    /**
     * The document's paragraphs (blocks between blank lines) from the old text to the new, each kept, removed or added.
     *
     * @return list<array{change: 'same'|'removed'|'added', html: string}>
     */
    private static function changes(string $old, string $new): array
    {
        $blocks = fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', $text) ?: []), 'strlen'));
        [$a, $b] = [$blocks($old), $blocks($new)];
        // ponytail: plain O(n·m) longest common subsequence; a policy is a few dozen paragraphs.
        $lengths = array_fill(0, count($a) + 1, array_fill(0, count($b) + 1, 0));
        for ($i = count($a) - 1; $i >= 0; $i--) {
            for ($j = count($b) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $changes = [];
        [$i, $j] = [0, 0];
        while ($i < count($a) || $j < count($b)) {
            if ($i < count($a) && $j < count($b) && $a[$i] === $b[$j]) {
                $changes[] = ['change' => 'same', 'html' => self::html($a[$i++])];
                $j++;
            } elseif ($j < count($b) && ($i === count($a) || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $changes[] = ['change' => 'added', 'html' => self::html($b[$j++])];
            } else {
                $changes[] = ['change' => 'removed', 'html' => self::html($a[$i++])];
            }
        }

        return $changes;
    }
}
