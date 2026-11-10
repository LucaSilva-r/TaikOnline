<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Table\TableExtension;

class WikiController extends Controller
{
    /** @var array<string, array{title: string, summary: string, group: string, keywords: string}> */
    private const ARTICLES = [
        'getting-started' => [
            'title' => 'Getting started',
            'summary' => 'Download Waddamburo, set up your game folder, and play your first song.',
            'group' => 'Start playing',
            'keywords' => 'install Windows Linux AppImage Green font update',
        ],
        'controls' => [
            'title' => 'Controls and drums',
            'summary' => 'Keyboard shortcuts, controller and MIDI bindings, two players, and drum debounce.',
            'group' => 'Start playing',
            'keywords' => 'keys gamepad buttons mouse input double hits',
        ],
        'song-libraries' => [
            'title' => 'Songs and libraries',
            'summary' => 'Add TJA, osu!lazer, and official song libraries, then search and browse them.',
            'group' => 'Start playing',
            'keywords' => 'custom songs Nijiiro audio WAVE vgmstream difficulties Oni Ura',
        ],
        'home-and-arcade' => [
            'title' => 'Home and Arcade modes',
            'summary' => 'Compare play, settings, accounts, cabinet pairing, and online features in each mode.',
            'group' => 'Accounts and online',
            'keywords' => 'mode config free play coins cabinet token card service F2 F3',
        ],
        'online-play' => [
            'title' => 'Accounts and online play',
            'summary' => 'Sign in, save scores, read rankings, and play together in online rooms.',
            'group' => 'Accounts and online',
            'keywords' => 'TaikOnline Guest friend code login multiplayer Quick Play privacy sharing',
        ],
        'practice-and-replays' => [
            'title' => 'Practice and replays',
            'summary' => 'Watch a recorded play, practise a section, set loops, and change playback speed.',
            'group' => 'Personalise and practise',
            'keywords' => 'training autoplay seek scrub A B score unranked',
        ],
        'layout-editor' => [
            'title' => 'Layout editor',
            'summary' => 'Create named gameplay layouts, arrange both player lanes, and fit a 4:3 screen.',
            'group' => 'Personalise and practise',
            'keywords' => 'move rotate scale Don depth presets reset layouts-2p',
        ],
        'modchart-editor' => [
            'title' => 'Modchart editor',
            'summary' => 'Animate the gameplay screen with keyframes, edit curves, and save or share effects.',
            'group' => 'Personalise and practise',
            'keywords' => 'effects F8 wddmod TJA timeline graph easing Bezier auto-key import export',
        ],
        'settings-and-timing' => [
            'title' => 'Settings and timing',
            'summary' => 'Calibrate audio and input, adjust graphics and volume, and choose cache settings.',
            'group' => 'Settings and help',
            'keywords' => 'offset early late latency buffer exclusive upscale performance FPS fullscreen',
        ],
        'troubleshooting' => [
            'title' => 'Troubleshooting',
            'summary' => 'Fix startup, audio, songs, sign-in, scores, and editor problems; find useful logs.',
            'group' => 'Settings and help',
            'keywords' => 'crash NG missing songs no sound error report logs backup config',
        ],
    ];

    public function show(Request $request, ?string $article = null): Response
    {
        abort_if($article !== null && ! array_key_exists($article, self::ARTICLES), 404);

        $scope = $request->attributes->get('taikoVersionScope');
        $guides = collect(self::ARTICLES)->map(fn (array $guide, string $slug): array => [
            ...$guide,
            'slug' => $slug,
            'url' => route('wiki.show', ['taikoVersion' => $scope, 'article' => $slug], false),
        ])->values()->all();
        $selected = null;
        $sections = [];

        if ($article !== null) {
            $markdown = file_get_contents(resource_path("wiki/{$article}.md"));
            $html = Str::markdown($markdown, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
                'heading_permalink' => [
                    'apply_id_to_heading' => true,
                    'id_prefix' => '',
                    'insert' => 'none',
                ],
            ], [new TableExtension, new HeadingPermalinkExtension]);
            $html = str_replace('<img ', '<img loading="lazy" decoding="async" ', $html);
            preg_match_all('/<h2 id="([^"]+)">(.*?)<\/h2>/s', $html, $headings, PREG_SET_ORDER);
            foreach ($headings as $heading) {
                $sections[] = ['id' => $heading[1], 'title' => html_entity_decode(strip_tags($heading[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            }
            $selected = [...self::ARTICLES[$article], 'slug' => $article, 'html' => $html];
        }

        return Inertia::render('Wiki', [
            'guides' => $guides,
            'article' => $selected,
            'sections' => $sections,
        ]);
    }
}
