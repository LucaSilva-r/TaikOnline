<?php

use Inertia\Testing\AssertableInertia as Assert;

it('lets visitors browse the Waddamburo guide without signing in', function (): void {
    $this->get('/waddamburo/wiki')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Wiki')
        ->has('guides', 10)
        ->where('article', null)
        ->where('guides.0.url', '/waddamburo/wiki/getting-started')
        ->has('sections', 0));
});

it('renders each published guide with working section anchors', function (string $slug): void {
    $this->get("/waddamburo/wiki/{$slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Wiki')
        ->where('article.slug', $slug)
        ->where('article.html', fn (string $html): bool => str_contains($html, '<h2 id="'))
        ->has('sections.0.id')
        ->has('sections.0.title'));
})->with([
    'getting-started', 'controls', 'song-libraries', 'home-and-arcade', 'online-play',
    'practice-and-replays', 'layout-editor', 'modchart-editor', 'settings-and-timing', 'troubleshooting',
]);

it('keeps guide navigation in the selected site scope', function (): void {
    $this->get('/green/wiki/layout-editor')->assertInertia(fn (Assert $page) => $page
        ->where('article.title', 'Layout editor')
        ->where('guides.0.url', '/green/wiki/getting-started'));
});

it('renders the mode comparison as a table', function (): void {
    $this->get('/waddamburo/wiki/home-and-arcade')->assertInertia(fn (Assert $page) => $page
        ->where('article.html', fn (string $html): bool => str_contains($html, '<table>')));
});

it('returns 404 for unpublished articles and paths outside the guide catalog', function (string $path): void {
    $this->get('/waddamburo/wiki/'.$path)->assertNotFound();
})->with(['not-a-guide', 'accounts.json', 'nested/article']);
