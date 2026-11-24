<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import Music2 from 'lucide-svelte/icons/music-2';
    import Search from 'lucide-svelte/icons/search';
    import Star from 'lucide-svelte/icons/star';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import SongCard from '@/components/SongCard.svelte';
    import { Input } from '@/components/ui/input';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import songs from '@/routes/songs';

    type Genre = { value: string; label: string };

    type SongEntry = {
        id: number;
        song_no: number;
        title: string;
        title_en: string | null;
        genre: Genre;
        play_count: number;
        player_count: number;
        is_favorite: boolean;
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
        sources = [],
        canFavorite,
        favoriteLimit,
        favoriteCount,
    }: {
        gameVersion: { value: string; label: string };
        songs: PaginatedSongs;
        filters: { q: string; source?: string | null };
        /** Chart sources to filter by (Waddamburo only). */
        sources?: Genre[];
        canFavorite: boolean;
        favoriteLimit: number;
        favoriteCount: number;
    } = $props();

    const taikoParam = taikoRouteParam();

    function toggleFavorite(
        event: MouseEvent,
        id: number,
        isFavorite: boolean,
    ): void {
        event.preventDefault();
        event.stopPropagation();

        if (!isFavorite && favoriteCount >= favoriteLimit) {
            toast.error(
                `You can only favourite ${favoriteLimit} songs in ${gameVersion.label}. Remove one first.`,
            );

            return;
        }

        router.post(
            toUrl(songs.favorite({ ...taikoParam, song: id })),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    let search = $state(filters.q);
    let source = $state(filters.source ?? null);
    let debounce: ReturnType<typeof setTimeout> | undefined;

    // Every visit carries the search and the source filter (the pager too).
    function reload(page?: number): void {
        router.get(
            toUrl(songs.index(taikoParam)),
            {
                q: search,
                ...(source ? { source } : {}),
                ...(page ? { page } : {}),
            },
            // Typing replaces the history entry; a page turn is a step Back undoes.
            { preserveState: true, replace: !page, preserveScroll: true },
        );
    }

    function onSearchInput(event: Event): void {
        search = (event.currentTarget as HTMLInputElement).value;
        clearTimeout(debounce);
        debounce = setTimeout(() => reload(), 300);
    }

    function pickSource(value: string | null): void {
        source = value;
        reload();
    }

    function detailUrl(id: number): string {
        return toUrl(songs.show({ ...taikoParam, song: id }));
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
        <div
            class="relative flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
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
                        <span
                            class="font-medium text-[var(--taiko-accent-label)]"
                        >
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
        {#if sources.length > 0}
            <div class="relative mt-4 flex flex-wrap gap-2">
                {#each [{ value: null, label: 'All' }, ...sources] as option (option.value)}
                    <button
                        type="button"
                        onclick={() => pickSource(option.value)}
                        aria-pressed={source === option.value}
                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors {source ===
                        option.value
                            ? 'border-[var(--taiko-accent)] bg-[var(--taiko-accent)] text-white'
                            : 'bg-card text-muted-foreground hover:text-foreground'}"
                    >
                        {option.label}
                    </button>
                {/each}
            </div>
        {/if}
    </div>

    {#if songList.data.length === 0}
        <div class="rounded-md border p-6 text-sm text-muted-foreground">
            No songs found.
        </div>
    {:else}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {#each songList.data as song (song.id)}
                <div class="relative min-w-0">
                    {#if canFavorite}
                        <button
                            type="button"
                            onclick={(event) =>
                                toggleFavorite(
                                    event,
                                    song.id,
                                    song.is_favorite,
                                )}
                            class="absolute right-2 top-2 z-10 flex size-8 items-center justify-center rounded-full text-muted-foreground/50 transition-colors hover:bg-muted hover:text-amber-500"
                            aria-pressed={song.is_favorite}
                            title={song.is_favorite
                                ? 'Remove from favourites'
                                : 'Add to favourites'}
                        >
                            <Star
                                class="size-4 {song.is_favorite
                                    ? 'fill-amber-400 text-amber-400'
                                    : ''}"
                            />
                        </button>
                    {/if}
                    <SongCard {song} href={detailUrl(song.id)} {canFavorite} />
                </div>
            {/each}
        </div>

        {#if songList.last_page > 1}
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Showing {songList.from}–{songList.to} of {songList.total}
                </p>
                <div class="flex items-center gap-2">
                    {#if songList.prev_page_url}
                        <button
                            type="button"
                            class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                            onclick={() => reload(songList.current_page - 1)}
                            >Previous</button
                        >
                    {/if}
                    <span
                        class="inline-flex h-8 items-center justify-center px-3 text-sm text-muted-foreground"
                    >
                        {songList.current_page} / {songList.last_page}
                    </span>
                    {#if songList.next_page_url}
                        <button
                            type="button"
                            class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                            onclick={() => reload(songList.current_page + 1)}
                            >Next</button
                        >
                    {/if}
                </div>
            </div>
        {/if}
    {/if}
</section>
