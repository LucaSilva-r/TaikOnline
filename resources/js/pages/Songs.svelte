<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Input } from '@/components/ui/input';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import songs from '@/routes/songs';
    import { Link, router } from '@inertiajs/svelte';
    import Music2 from 'lucide-svelte/icons/music-2';
    import Play from 'lucide-svelte/icons/play';
    import Search from 'lucide-svelte/icons/search';
    import Users from 'lucide-svelte/icons/users';

    type Genre = { value: string; label: string };

    type SongEntry = {
        id: number;
        song_no: number;
        title: string;
        title_en: string | null;
        genre: Genre;
        play_count: number;
        player_count: number;
    };

    type PaginatedSongs = {
        data: SongEntry[];
        current_page: number;
        last_page: number;
        from: number;
        to: number;
        total: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };

    let {
        gameVersion,
        songs: songList,
        filters,
    }: {
        gameVersion: { value: string; label: string };
        songs: PaginatedSongs;
        filters: { q: string };
    } = $props();

    const taikoParam = taikoRouteParam();
    const numberFormatter = new Intl.NumberFormat('en-US');

    let search = $state(filters.q);
    let debounce: ReturnType<typeof setTimeout> | undefined;

    function onSearchInput(event: Event): void {
        search = (event.currentTarget as HTMLInputElement).value;
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            router.get(
                toUrl(songs.index(taikoParam)),
                { q: search },
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);
    }

    function detailUrl(id: number): string {
        return toUrl(songs.show({ ...taikoParam, song: id }));
    }

    /** Tailwind classes keyed on the song genre for a splash of colour. */
    const genreStyles: Record<string, string> = {
        jpop: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        anime: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
        classical: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        game_music: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        namco_original: 'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400',
        variety: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        vocaloid: 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
        medley: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        childrens_songs: 'bg-lime-500/15 text-lime-600 dark:text-lime-400',
    };

    function genreClass(value: string): string {
        return genreStyles[value] ?? 'bg-muted text-muted-foreground';
    }
</script>

<AppHead title="Songs" />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <div
        class="relative overflow-hidden rounded-xl border bg-gradient-to-br from-[var(--taiko-accent-soft)] via-card to-card px-6 py-7"
    >
        <div
            class="pointer-events-none absolute -right-8 -top-10 size-40 rounded-full bg-[var(--taiko-accent)] opacity-15 blur-3xl"
        ></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-4">
                <div
                    class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-md"
                >
                    <Music2 class="size-6" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Songs</h1>
                    <p class="text-sm text-muted-foreground">
                        Browse the library ·
                        <span class="font-medium text-[var(--taiko-accent-label)]">
                            {gameVersion.label}
                        </span>
                    </p>
                </div>
            </div>

            <div class="relative sm:w-72">
                <Search
                    class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    type="search"
                    placeholder="Search songs… (title or romaji)"
                    class="pl-9"
                    value={search}
                    oninput={onSearchInput}
                />
            </div>
        </div>
    </div>

    {#if songList.data.length === 0}
        <div class="rounded-md border p-6 text-sm text-muted-foreground">
            No songs found.
        </div>
    {:else}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {#each songList.data as song (song.id)}
                <Link
                    href={detailUrl(song.id)}
                    class="group flex flex-col gap-3 rounded-xl border bg-card p-4 transition-all hover:-translate-y-0.5 hover:border-[var(--taiko-accent)] hover:shadow-md"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div
                                class="truncate font-semibold group-hover:text-[var(--taiko-accent-label)]"
                            >
                                {song.title}
                            </div>
                            {#if song.title_en}
                                <div class="truncate text-xs text-muted-foreground">
                                    {song.title_en}
                                </div>
                            {/if}
                        </div>
                        <span
                            class="font-mono text-[10px] text-muted-foreground/60"
                        >
                            #{song.song_no}
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <Badge
                            class="border-0 {genreClass(song.genre.value)}"
                            variant="secondary"
                        >
                            {song.genre.label}
                        </Badge>
                        <div
                            class="flex items-center gap-3 text-xs text-muted-foreground"
                        >
                            <span class="flex items-center gap-1" title="Plays">
                                <Play class="size-3.5" />
                                {numberFormatter.format(song.play_count)}
                            </span>
                            <span
                                class="flex items-center gap-1"
                                title="Players"
                            >
                                <Users class="size-3.5" />
                                {numberFormatter.format(song.player_count)}
                            </span>
                        </div>
                    </div>
                </Link>
            {/each}
        </div>

        {#if songList.last_page > 1}
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Showing {songList.from}–{songList.to} of {songList.total}
                </p>
                <div class="flex items-center gap-2">
                    {#if songList.prev_page_url}
                        <Link
                            class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                            href={songList.prev_page_url}
                            preserveScroll
                        >
                            Previous
                        </Link>
                    {/if}
                    <span
                        class="inline-flex h-8 items-center justify-center px-3 text-sm text-muted-foreground"
                    >
                        {songList.current_page} / {songList.last_page}
                    </span>
                    {#if songList.next_page_url}
                        <Link
                            class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                            href={songList.next_page_url}
                            preserveScroll
                        >
                            Next
                        </Link>
                    {/if}
                </div>
            </div>
        {/if}
    {/if}
</section>
