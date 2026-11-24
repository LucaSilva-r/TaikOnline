<script module lang="ts">
    export type SongCardEntry = {
        id: number;
        song_no: number;
        title: string;
        title_en: string | null;
        genre: { value: string; label: string };
        play_count: number;
        player_count: number;
        is_favorite: boolean;
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import Play from 'lucide-svelte/icons/play';
    import Users from 'lucide-svelte/icons/users';
    import { Badge } from '@/components/ui/badge';

    let {
        song,
        href,
        canFavorite = false,
    }: {
        song: SongCardEntry;
        href: string;
        /** Leaves room for the favourite star the song list lays over the card. */
        canFavorite?: boolean;
    } = $props();

    const numberFormatter = new Intl.NumberFormat('en-US');

    /** Tailwind classes keyed on the song genre for a splash of colour. */
    const genreStyles: Record<string, string> = {
        jpop: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        anime: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
        classical: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
        game_music: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        namco_original:
            'bg-fuchsia-500/15 text-fuchsia-600 dark:text-fuchsia-400',
        variety: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        vocaloid: 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
        medley: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
        childrens_songs: 'bg-lime-500/15 text-lime-600 dark:text-lime-400',
        wdb_stock: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
        wdb_nijiiro: 'bg-rose-500/15 text-rose-600 dark:text-rose-400',
        wdb_wii: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-300',
        wdb_wiiu: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-300',
        wdb_osulazer: 'bg-pink-500/15 text-pink-600 dark:text-pink-400',
        wdb_custom: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
        wdb_stock_nijiiro: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    };

    function genreClass(value: string): string {
        return genreStyles[value] ?? 'bg-muted text-muted-foreground';
    }
</script>

<Link
    {href}
    class="group flex h-full min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 transition-all hover:-translate-y-0.5 hover:border-[var(--taiko-accent)] hover:shadow-md"
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
            class="font-mono text-[10px] text-muted-foreground/60 {canFavorite
                ? 'mr-8'
                : ''}"
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
        <div class="flex items-center gap-3 text-xs text-muted-foreground">
            <span class="flex items-center gap-1" title="Plays">
                <Play class="size-3.5" />
                {numberFormatter.format(song.play_count)}
            </span>
            <span class="flex items-center gap-1" title="Players">
                <Users class="size-3.5" />
                {numberFormatter.format(song.player_count)}
            </span>
        </div>
    </div>
</Link>
