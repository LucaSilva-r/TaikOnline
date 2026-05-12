<script module lang="ts">
    import songsRoutes from '@/routes/admin/songs';
    export const layout = {
        breadcrumbs: [{ title: 'Songs', href: songsRoutes.index() }],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';

    type SongGenre = {
        value: string;
        label: string;
        label_jp: string;
    };

    type SongPartsSet = {
        value: string;
        label: string;
    };

    type Song = {
        id: number;
        version: string;
        song_no: number;
        music_id: string;
        title: string;
        title_en: string | null;
        genre: SongGenre;
        partsset: SongPartsSet;
        has_extreme: boolean;
        has_papamama: boolean;
    };

    type PaginatedSongs = {
        data: Song[];
        current_page: number;
        last_page: number;
        from: number;
        to: number;
        per_page: number;
        total: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };

    let { songs }: { songs: PaginatedSongs } = $props();
</script>

<AppHead title="Song catalog" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Song catalog</h1>
        <p class="text-sm text-muted-foreground">
            Browse the imported song library and their metadata.
        </p>
    </div>

    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium w-[60px]">#</th>
                    <th class="px-3 py-2 font-medium">Title</th>
                    <th class="px-3 py-2 font-medium hidden md:table-cell"
                        >ID</th
                    >
                    <th class="px-3 py-2 font-medium w-[120px]">Genre</th>
                    <th
                        class="px-3 py-2 font-medium w-[140px] hidden lg:table-cell"
                        >Parts Set</th
                    >
                    <th
                        class="px-3 py-2 font-medium w-[80px] text-center hidden sm:table-cell"
                        >Tags</th
                    >
                </tr>
            </thead>
            <tbody>
                {#each songs.data as song (song.id)}
                    <tr class="border-t">
                        <td
                            class="px-3 py-2 font-mono text-xs text-muted-foreground"
                            >{song.song_no}</td
                        >
                        <td class="px-3 py-2">
                            <div class="flex flex-col gap-0.5">
                                <span class="font-medium">{song.title}</span>
                                {#if song.title_en}
                                    <span class="text-xs text-muted-foreground"
                                        >{song.title_en}</span
                                    >
                                {/if}
                            </div>
                        </td>
                        <td
                            class="px-3 py-2 font-mono text-xs text-muted-foreground"
                        >
                            [{song.version}] {song.music_id}
                        </td>
                        <td class="px-3 py-2">
                            <Badge variant="secondary">{song.genre.label}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 hidden lg:table-cell"
                            >{song.partsset.label}</td
                        >
                        <td class="px-3 py-2 text-center hidden sm:table-cell">
                            <div class="flex justify-center gap-1">
                                {#if song.has_extreme}
                                    <Badge
                                        variant="destructive"
                                        class="h-[20px] px-1.5 text-[10px]"
                                        >EX</Badge
                                    >
                                {/if}
                                {#if song.has_papamama}
                                    <Badge
                                        variant="secondary"
                                        class="h-[20px] px-1.5 text-[10px]"
                                        >PM</Badge
                                    >
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>

{#if songs.last_page > 1}
    <div class="flex items-center justify-between px-4">
        <p class="text-sm text-muted-foreground">
            Showing {songs.from} to {songs.to} of {songs.total} songs
        </p>
        <div class="flex items-center gap-2">
            {#if songs.current_page > 1}
                <Link
                    class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                    href={songs.prev_page_url!}
                >
                    Previous
                </Link>
            {/if}
            <span
                class="inline-flex h-8 items-center justify-center px-3 text-sm text-muted-foreground"
            >
                {songs.current_page} / {songs.last_page}
            </span>
            {#if songs.current_page < songs.last_page}
                <Link
                    class="inline-flex h-8 items-center justify-center rounded-md border bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                    href={songs.next_page_url!}
                >
                    Next
                </Link>
            {/if}
        </div>
    </div>
{/if}
