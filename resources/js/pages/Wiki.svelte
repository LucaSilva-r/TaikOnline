<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import ArrowRight from 'lucide-svelte/icons/arrow-right';
    import BookOpen from 'lucide-svelte/icons/book-open';
    import Download from 'lucide-svelte/icons/download';
    import Search from 'lucide-svelte/icons/search';
    import AppHead from '@/components/AppHead.svelte';
    import { buttonVariants } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as wikiShow } from '@/routes/wiki';

    type Guide = {
        slug: string;
        title: string;
        summary: string;
        group: string;
        keywords: string;
        url: string;
    };

    let {
        guides,
        article,
        sections,
    }: {
        guides: Guide[];
        article: (Omit<Guide, 'url'> & { html: string }) | null;
        sections: { id: string; title: string }[];
    } = $props();

    const taikoParam = taikoRouteParam();
    let query = $state('');
    const words = $derived(
        query.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean),
    );
    const filtered = $derived(
        guides.filter((guide) => {
            const text =
                `${guide.title} ${guide.summary} ${guide.keywords}`.toLocaleLowerCase();
            return words.every((word) => text.includes(word));
        }),
    );
    const groups = $derived([...new Set(filtered.map((guide) => guide.group))]);
    const currentIndex = $derived(
        guides.findIndex((guide) => guide.slug === article?.slug),
    );
    const previous = $derived(
        currentIndex > 0 ? guides[currentIndex - 1] : null,
    );
    const next = $derived(currentIndex >= 0 ? guides[currentIndex + 1] : null);
    const overviewUrl = toUrl(wikiShow(taikoParam));
</script>

<AppHead
    title={article ? `${article.title} · Waddamburo Wiki` : 'Waddamburo Wiki'}
>
    <meta
        name="description"
        content={article?.summary ??
            'The Waddamburo player guide: setup, drums, songs, online play, Home and Arcade modes, layouts, modcharts, and troubleshooting.'}
    />
</AppHead>

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:py-12">
    <div
        class="mb-8 flex flex-wrap items-center justify-between gap-4 border-b pb-6"
    >
        <Link
            href={overviewUrl}
            class="inline-flex items-center gap-3 text-sm font-semibold"
        >
            <span
                class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                ><BookOpen class="size-5" /></span
            >
            <span
                >Waddamburo <span class="font-normal text-muted-foreground"
                    >/ Wiki</span
                ></span
            >
        </Link>
        <a
            href="https://github.com/LucaSilva-r/Waddamburo-public/releases"
            class={buttonVariants({
                size: 'lg',
                class: 'min-h-12 w-full gap-3 rounded-xl px-6 text-base font-semibold shadow-lg shadow-primary/20 sm:w-auto',
            })}><Download class="size-5" /> Download Waddamburo</a
        >
    </div>

    {#if !article}
        <header
            class="mb-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-center"
        >
            <div class="max-w-2xl">
                <p class="mb-3 text-sm font-medium text-primary">
                    The player guide
                </p>
                <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
                    Waddamburo Wiki
                </h1>
                <p class="mt-4 text-base leading-relaxed text-muted-foreground">
                    Set up your drums, bring in your songs, and make the game
                    your own. Learn how accounts and online play work, then
                    explore layouts, practice, and modchart editing.
                </p>
            </div>
            <aside
                class="rounded-2xl border border-primary/20 bg-primary/5 p-6"
                aria-label="Download Waddamburo"
            >
                <p class="text-lg font-semibold">Ready to play?</p>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                    Get Waddamburo for Windows or Linux, then follow the setup
                    guide.
                </p>
                <a
                    href="https://github.com/LucaSilva-r/Waddamburo-public/releases"
                    class={buttonVariants({
                        size: 'lg',
                        class: 'mt-4 min-h-12 w-full gap-3 rounded-xl text-base font-semibold shadow-md shadow-primary/15',
                    })}><Download class="size-5" /> Download Waddamburo</a
                >
                <p class="mt-3 text-xs leading-relaxed text-muted-foreground">
                    Your own compatible game files are required.
                </p>
            </aside>
        </header>
    {/if}

    <div
        class={article
            ? 'grid min-w-0 items-start gap-8 lg:grid-cols-[220px_minmax(0,1fr)] xl:grid-cols-[220px_minmax(0,1fr)_180px]'
            : 'grid min-w-0 gap-8'}
    >
        <aside
            class={article ? 'min-w-0 lg:sticky lg:top-6' : 'max-w-xl'}
            aria-label="Find a guide"
        >
            <label for="wiki-search" class="mb-2 block text-sm font-medium"
                >Find a guide</label
            >
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground"
                />
                <input
                    id="wiki-search"
                    type="search"
                    bind:value={query}
                    placeholder="Try layouts, drums, or online…"
                    class="h-10 w-full rounded-lg border bg-background pr-3 pl-9 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
            </div>
            {#if query.trim()}
                <p class="mt-2 text-xs text-muted-foreground" role="status">
                    {filtered.length}
                    {filtered.length === 1 ? 'guide' : 'guides'} found
                </p>
            {/if}
            {#if article}
                <details class="mt-4 rounded-lg border p-3 lg:hidden">
                    <summary class="cursor-pointer text-sm font-medium"
                        >All guides</summary
                    >
                    <nav
                        class="mt-3 grid gap-1"
                        aria-label="Wiki guides on mobile"
                    >
                        {#each filtered as guide (guide.slug)}
                            <Link
                                href={guide.url}
                                aria-current={guide.slug === article.slug
                                    ? 'page'
                                    : undefined}
                                class="rounded-md px-2 py-2 text-sm {guide.slug ===
                                article.slug
                                    ? 'bg-primary/10 font-medium text-primary'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground'}"
                                >{guide.title}</Link
                            >
                        {/each}
                        {#if filtered.length === 0}<p
                                class="py-2 text-sm text-muted-foreground"
                            >
                                No guides match. Try fewer words.
                            </p>{/if}
                    </nav>
                </details>
                <nav
                    class="mt-6 hidden space-y-5 lg:block"
                    aria-label="Wiki guides"
                >
                    <Link
                        href={overviewUrl}
                        class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                        ><ArrowLeft class="size-3.5" /> Wiki overview</Link
                    >
                    {#each groups as group (group)}
                        <div>
                            <p
                                class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground"
                            >
                                {group}
                            </p>
                            <div class="grid gap-1">
                                {#each filtered.filter((guide) => guide.group === group) as guide (guide.slug)}
                                    <Link
                                        href={guide.url}
                                        aria-current={guide.slug ===
                                        article.slug
                                            ? 'page'
                                            : undefined}
                                        class="rounded-md px-3 py-2 text-sm transition {guide.slug ===
                                        article.slug
                                            ? 'bg-primary/10 font-medium text-primary'
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'}"
                                        >{guide.title}</Link
                                    >
                                {/each}
                            </div>
                        </div>
                    {/each}
                    {#if filtered.length === 0}<p
                            class="text-sm text-muted-foreground"
                        >
                            No guides match. Try fewer words.
                        </p>{/if}
                </nav>
            {/if}
        </aside>

        {#if article}
            <article class="min-w-0" aria-labelledby="article-title">
                <header class="mb-8">
                    <p class="mb-3 text-sm font-medium text-primary">
                        {article.group}
                    </p>
                    <h1
                        id="article-title"
                        class="text-3xl font-bold tracking-tight sm:text-4xl"
                    >
                        {article.title}
                    </h1>
                    <p class="mt-4 leading-relaxed text-muted-foreground">
                        {article.summary}
                    </p>
                </header>
                {#if sections.length > 0}
                    <details
                        class="mb-8 rounded-lg border bg-card/40 p-4 xl:hidden"
                    >
                        <summary class="cursor-pointer text-sm font-medium"
                            >On this page</summary
                        >
                        <nav
                            class="mt-3 grid gap-2"
                            aria-label="Sections on this page"
                        >
                            {#each sections as section (section.id)}<a
                                    href={`#${section.id}`}
                                    class="text-sm text-muted-foreground hover:text-primary"
                                    >{section.title}</a
                                >{/each}
                        </nav>
                    </details>
                {/if}
                <div class="wiki-content">{@html article.html}</div>
                <nav
                    class="mt-12 grid gap-3 border-t pt-6 sm:grid-cols-2"
                    aria-label="Previous and next guides"
                >
                    {#if previous}<Link
                            href={previous.url}
                            class="rounded-lg border p-4 transition hover:border-primary/40 hover:bg-accent/40"
                            ><span
                                class="mb-2 flex items-center gap-2 text-xs text-muted-foreground"
                                ><ArrowLeft class="size-3.5" /> Previous</span
                            ><span class="text-sm font-medium"
                                >{previous.title}</span
                            ></Link
                        >{:else}<span></span>{/if}
                    {#if next}<Link
                            href={next.url}
                            class="rounded-lg border p-4 transition hover:border-primary/40 hover:bg-accent/40"
                            ><span
                                class="mb-2 flex items-center gap-2 text-xs text-muted-foreground"
                                >Next <ArrowRight class="size-3.5" /></span
                            ><span class="text-sm font-medium"
                                >{next.title}</span
                            ></Link
                        >{/if}
                </nav>
            </article>
            {#if sections.length > 0}
                <nav
                    class="sticky top-6 hidden border-l pl-4 xl:block"
                    aria-label="On this page"
                >
                    <p
                        class="mb-4 text-xs font-semibold tracking-wide text-muted-foreground"
                    >
                        On this page
                    </p>
                    <div class="grid gap-3">
                        {#each sections as section (section.id)}<a
                                href={`#${section.id}`}
                                class="text-sm leading-snug text-muted-foreground hover:text-primary"
                                >{section.title}</a
                            >{/each}
                    </div>
                </nav>
            {/if}
        {:else}
            <div>
                {#if filtered.length === 0}
                    <div
                        class="rounded-xl border border-dashed p-8 text-center"
                    >
                        <h2 class="font-semibold">No guides found</h2>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Try a topic such as timing, songs, or effects.
                        </p>
                        <button
                            type="button"
                            onclick={() => (query = '')}
                            class="mt-4 text-sm font-medium text-primary underline underline-offset-4"
                            >Show all guides</button
                        >
                    </div>
                {/if}
                <div class="grid gap-10">
                    {#each groups as group (group)}
                        <section aria-label={group}>
                            <h2
                                class="mb-4 text-lg font-semibold tracking-tight"
                            >
                                {group}
                            </h2>
                            <div
                                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                {#each filtered.filter((guide) => guide.group === group) as guide (guide.slug)}
                                    <Link
                                        href={guide.url}
                                        class="group flex h-full flex-col gap-3 rounded-xl border bg-card/40 p-5 transition hover:border-primary/40 hover:bg-accent/30 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        ><span
                                            class="flex items-start justify-between gap-3 font-semibold"
                                            >{guide.title}<ArrowRight
                                                class="mt-0.5 size-4 shrink-0 text-muted-foreground transition group-hover:translate-x-1 group-hover:text-primary"
                                            /></span
                                        >
                                        <p
                                            class="text-sm leading-relaxed text-muted-foreground"
                                        >
                                            {guide.summary}
                                        </p></Link
                                    >
                                {/each}
                            </div>
                        </section>
                    {/each}
                </div>
                <div
                    class="mt-10 rounded-xl border bg-muted/30 p-5 text-sm leading-relaxed text-muted-foreground"
                >
                    New to Waddamburo? Start with <Link
                        href={guides[0].url}
                        class="font-medium text-foreground underline underline-offset-4"
                        >Getting started</Link
                    >. These guides cover Waddamburo; the Home and Arcade
                    comparison explains which features belong to each mode.
                </div>
            </div>
        {/if}
    </div>
</div>

<style>
    .wiki-content {
        font-size: 0.9375rem;
        line-height: 1.8;
        overflow-wrap: anywhere;
    }
    .wiki-content :global(p),
    .wiki-content :global(ul),
    .wiki-content :global(ol),
    .wiki-content :global(pre),
    .wiki-content :global(table) {
        margin-bottom: 1.2rem;
    }
    .wiki-content :global(h2) {
        margin-top: 2.25rem;
        margin-bottom: 0.85rem;
        font-size: 1.25rem;
        font-weight: 600;
        line-height: 1.4;
        scroll-margin-top: 1.5rem;
    }
    .wiki-content :global(h3) {
        margin-top: 1.5rem;
        margin-bottom: 0.75rem;
        font-weight: 600;
        scroll-margin-top: 1.5rem;
    }
    .wiki-content :global(ul) {
        padding-left: 1.4rem;
        list-style: disc;
    }
    .wiki-content :global(ol) {
        padding-left: 1.4rem;
        list-style: decimal;
    }
    .wiki-content :global(li) {
        padding-left: 0.2rem;
        margin-bottom: 0.5rem;
    }
    .wiki-content :global(a) {
        color: var(--primary);
        text-decoration: underline;
        text-underline-offset: 3px;
    }
    .wiki-content :global(a:hover) {
        text-decoration-thickness: 2px;
    }
    .wiki-content :global(code) {
        border-radius: 0.25rem;
        background: var(--muted);
        padding: 0.15rem 0.35rem;
        font-size: 0.85em;
    }
    .wiki-content :global(pre) {
        overflow-x: auto;
        border: 1px solid var(--border);
        border-radius: 0.75rem;
        background: var(--muted);
        padding: 1rem;
        line-height: 1.6;
    }
    .wiki-content :global(pre code) {
        padding: 0;
        background: transparent;
    }
    .wiki-content :global(table) {
        display: block;
        max-width: 100%;
        overflow-x: auto;
        font-size: 0.875rem;
        line-height: 1.6;
    }
    .wiki-content :global(th),
    .wiki-content :global(td) {
        min-width: 8rem;
        border-bottom: 1px solid var(--border);
        padding: 0.75rem;
        text-align: left;
        vertical-align: top;
    }
    .wiki-content :global(th) {
        background: var(--muted);
        font-weight: 600;
    }
    .wiki-content :global(img) {
        display: block;
        width: 100%;
        height: auto;
        border: 1px solid var(--border);
        border-radius: 0.75rem;
        background: var(--muted);
    }
    .wiki-content :global(a:has(> img)) {
        display: block;
        border-radius: 0.75rem;
        cursor: zoom-in;
    }
    .wiki-content :global(a:has(> img):focus-visible) {
        outline: 2px solid var(--ring);
        outline-offset: 4px;
    }
    .wiki-content :global(p:has(img)) {
        margin-top: 1.5rem;
        margin-bottom: 0.5rem;
    }
    .wiki-content :global(p:has(img) + p:has(> em:only-child)) {
        margin-bottom: 1.5rem;
        color: var(--muted-foreground);
        font-size: 0.8125rem;
        line-height: 1.6;
    }
    .wiki-content :global(blockquote) {
        border-left: 3px solid var(--primary);
        padding-left: 1rem;
        color: var(--muted-foreground);
    }
</style>
