<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { Badge } from '@/components/ui/badge';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import songs from '@/routes/songs';
    import { Link, router } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Crown from 'lucide-svelte/icons/crown';
    import Music2 from 'lucide-svelte/icons/music-2';
    import Play from 'lucide-svelte/icons/play';
    import Star from 'lucide-svelte/icons/star';
    import Users from 'lucide-svelte/icons/users';
    import { toast } from 'svelte-sonner';

    type Genre = { value: string; label: string; label_jp: string };

    type CrownCounts = { clear: number; gold: number; dondaful: number };

    type LeaderboardEntry = {
        rank: number;
        user_id: number;
        player_name: string;
        avatar: string | null;
        score: number;
        score_rank: number;
        crown: number;
        precision: number | null;
    };

    type Difficulty = {
        level: number;
        play_count: number;
        player_count: number;
        crown_counts: CrownCounts;
        entries: LeaderboardEntry[];
    };

    type RecentPlay = {
        user_id: number;
        player_name: string;
        avatar: string | null;
        level: number;
        played_at: string | null;
        play_result: number;
        score: number;
        score_rank: number;
        precision: number;
    };

    let {
        gameVersion,
        song,
        summary,
        difficulties,
        recentPlays,
        isFavorite,
        canFavorite,
        favoriteLimit,
        favoriteCount,
    }: {
        gameVersion: { value: string; label: string };
        song: {
            id: number;
            song_no: number;
            title: string;
            title_en: string | null;
            genre: Genre;
        };
        summary: {
            total_plays: number;
            unique_players: number;
            first_played_at: string | null;
            last_played_at: string | null;
        };
        difficulties: Difficulty[];
        recentPlays: RecentPlay[];
        isFavorite: boolean;
        canFavorite: boolean;
        favoriteLimit: number;
        favoriteCount: number;
    } = $props();

    const taikoParam = taikoRouteParam();

    function toggleFavorite(): void {
        if (!isFavorite && favoriteCount >= favoriteLimit) {
            toast.error(
                `You can only favourite ${favoriteLimit} songs in ${gameVersion.label}. Remove one first.`,
            );
            return;
        }
        router.post(
            toUrl(songs.favorite({ ...taikoParam, song: song.id })),
            {},
            { preserveScroll: true, preserveState: true },
        );
    }
    const numberFormatter = new Intl.NumberFormat('en-US');
    const dateFormatter = new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function boardUrl(userId: number): string {
        return toUrl(boardShow({ ...taikoParam, user: userId }));
    }

    function formatDate(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : 'Never';
    }

    function formatPrecision(value: number | null): string {
        return value === null ? '—' : `${value.toFixed(2)}%`;
    }

    function difficultyLabel(level: number): string {
        return (
            {
                1: 'Easy',
                2: 'Normal',
                3: 'Hard',
                4: 'Oni',
                5: 'Ura Oni',
            }[level] ?? `Lv ${level}`
        );
    }

    /** Taiko difficulty colours. */
    function difficultyClass(level: number): string {
        return (
            {
                1: 'bg-rose-500 text-white',
                2: 'bg-sky-500 text-white',
                3: 'bg-emerald-500 text-white',
                4: 'bg-fuchsia-600 text-white',
                5: 'bg-violet-800 text-white',
            }[level] ?? 'bg-muted text-muted-foreground'
        );
    }

    function crownLabel(crown: number): string {
        return (
            { 1: 'Clear', 2: 'Full combo', 3: 'Dondaful' }[crown] ?? 'No crown'
        );
    }

    function crownClass(crown: number): string {
        return (
            {
                1: 'text-sky-500',
                2: 'text-amber-500',
                3: 'text-fuchsia-500',
            }[crown] ?? 'text-muted-foreground/30'
        );
    }
</script>

<AppHead title={song.title} />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <Link
        href={toUrl(songs.index(taikoParam))}
        class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
    >
        <ArrowLeft class="size-4" />
        All songs
    </Link>

    <!-- Header -->
    <div
        class="relative overflow-hidden rounded-xl border bg-gradient-to-br from-[var(--taiko-accent-soft)] via-card to-card px-6 py-7"
    >
        <div
            class="pointer-events-none absolute -right-8 -top-10 size-48 rounded-full bg-[var(--taiko-accent)] opacity-15 blur-3xl"
        ></div>
        <div class="relative flex items-center gap-4">
            <div
                class="flex size-14 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-md"
            >
                <Music2 class="size-7" />
            </div>
            <div class="min-w-0 flex-1">
                <div class="mb-1 flex items-center gap-2">
                    <Badge variant="secondary">{song.genre.label}</Badge>
                    <span class="font-mono text-xs text-muted-foreground/60">
                        #{song.song_no}
                    </span>
                </div>
                <h1 class="truncate text-2xl font-bold tracking-tight">
                    {song.title}
                </h1>
                {#if song.title_en}
                    <p class="truncate text-sm text-muted-foreground">
                        {song.title_en}
                    </p>
                {/if}
            </div>
            {#if canFavorite}
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <button
                        type="button"
                        onclick={toggleFavorite}
                        class="flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground {isFavorite
                            ? 'border-amber-400/60 text-amber-600 dark:text-amber-400'
                            : 'text-muted-foreground'}"
                        aria-pressed={isFavorite}
                    >
                        <Star
                            class="size-4 {isFavorite
                                ? 'fill-amber-400 text-amber-400'
                                : ''}"
                        />
                        <span class="hidden sm:inline">
                            {isFavorite ? 'Favourited' : 'Favourite'}
                        </span>
                    </button>
                    <span class="text-xs text-muted-foreground/70">
                        {favoriteCount}/{favoriteLimit} favourites
                    </span>
                </div>
            {/if}
        </div>
    </div>

    <!-- Summary stats -->
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <div class="text-sm text-muted-foreground">Total Plays</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {numberFormatter.format(summary.total_plays)}
                    </div>
                </div>
                <div
                    class="flex size-10 items-center justify-center rounded-md bg-sky-500/15 text-sky-500"
                >
                    <Play class="size-5" />
                </div>
            </div>
        </div>
        <div class="rounded-lg border bg-card p-4">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <div class="text-sm text-muted-foreground">Players</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {numberFormatter.format(summary.unique_players)}
                    </div>
                </div>
                <div
                    class="flex size-10 items-center justify-center rounded-md bg-emerald-500/15 text-emerald-500"
                >
                    <Users class="size-5" />
                </div>
            </div>
        </div>
        <div class="rounded-lg border bg-card p-4">
            <div class="text-sm text-muted-foreground">First Played</div>
            <div class="mt-1 text-sm font-medium">
                {formatDate(summary.first_played_at)}
            </div>
        </div>
        <div class="rounded-lg border bg-card p-4">
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <CalendarDays class="size-4" />
                Last Played
            </div>
            <div class="mt-1 text-sm font-medium">
                {formatDate(summary.last_played_at)}
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <!-- Difficulty leaderboards -->
        <div class="flex min-w-0 flex-col gap-6">
            {#if difficulties.length === 0}
                <div
                    class="rounded-lg border p-6 text-sm text-muted-foreground"
                >
                    No ranked scores for this song yet.
                </div>
            {:else}
                {#each difficulties as difficulty (difficulty.level)}
                    <section class="overflow-hidden rounded-xl border bg-card">
                        <div
                            class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="rounded-md px-2.5 py-1 text-xs font-bold {difficultyClass(
                                        difficulty.level,
                                    )}"
                                >
                                    {difficultyLabel(difficulty.level)}
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    {numberFormatter.format(
                                        difficulty.play_count,
                                    )} plays · {numberFormatter.format(
                                        difficulty.player_count,
                                    )} players
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span
                                    class="flex items-center gap-1 text-fuchsia-500"
                                    title="Dondaful"
                                >
                                    <Crown class="size-4" />
                                    {difficulty.crown_counts.dondaful}
                                </span>
                                <span
                                    class="flex items-center gap-1 text-amber-500"
                                    title="Full combo"
                                >
                                    <Crown class="size-4" />
                                    {difficulty.crown_counts.gold}
                                </span>
                                <span
                                    class="flex items-center gap-1 text-sky-500"
                                    title="Clear"
                                >
                                    <Crown class="size-4" />
                                    {difficulty.crown_counts.clear}
                                </span>
                            </div>
                        </div>

                        <div class="divide-y">
                            {#each difficulty.entries as entry (entry.user_id)}
                                <div
                                    class="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 px-5 py-2.5 transition-colors hover:bg-muted/30"
                                >
                                    <span
                                        class="text-sm font-semibold tabular-nums text-muted-foreground"
                                    >
                                        {entry.rank}
                                    </span>
                                    <Link
                                        href={boardUrl(entry.user_id)}
                                        class="flex min-w-0 items-center gap-2.5"
                                    >
                                        <Avatar class="size-8 border bg-muted">
                                            {#if entry.avatar}
                                                <AvatarImage
                                                    src={entry.avatar}
                                                    alt={entry.player_name}
                                                    class="object-cover"
                                                />
                                            {:else}
                                                <AvatarFallback
                                                    class="text-[10px] font-semibold"
                                                >
                                                    {getInitials(
                                                        entry.player_name,
                                                    )}
                                                </AvatarFallback>
                                            {/if}
                                        </Avatar>
                                        <span
                                            class="truncate text-sm font-medium hover:underline"
                                        >
                                            {entry.player_name}
                                        </span>
                                    </Link>
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="hidden w-16 text-right text-sm tabular-nums text-muted-foreground sm:block"
                                            title="Precision"
                                        >
                                            {formatPrecision(entry.precision)}
                                        </span>
                                        <Crown
                                            class="size-4 {crownClass(
                                                entry.crown,
                                            )}"
                                            aria-label={crownLabel(entry.crown)}
                                        />
                                        <span
                                            class="w-20 text-right font-semibold tabular-nums"
                                        >
                                            {numberFormatter.format(
                                                entry.score,
                                            )}
                                        </span>
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </section>
                {/each}
            {/if}
        </div>

        <!-- Recent plays -->
        <section class="h-fit overflow-hidden rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Recent Plays</h2>
                <p class="text-sm text-muted-foreground">Latest activity</p>
            </div>
            {#if recentPlays.length === 0}
                <div class="p-5 text-sm text-muted-foreground">
                    No plays yet.
                </div>
            {:else}
                <div class="divide-y">
                    {#each recentPlays as play, index (index)}
                        <div class="flex items-center gap-3 px-5 py-3">
                            <Link
                                href={boardUrl(play.user_id)}
                                class="flex min-w-0 flex-1 items-center gap-2.5"
                            >
                                <Avatar class="size-8 border bg-muted">
                                    {#if play.avatar}
                                        <AvatarImage
                                            src={play.avatar}
                                            alt={play.player_name}
                                            class="object-cover"
                                        />
                                    {:else}
                                        <AvatarFallback
                                            class="text-[10px] font-semibold"
                                        >
                                            {getInitials(play.player_name)}
                                        </AvatarFallback>
                                    {/if}
                                </Avatar>
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-sm font-medium hover:underline"
                                    >
                                        {play.player_name}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {difficultyLabel(play.level)} · {formatPrecision(
                                            play.precision,
                                        )} · {formatDate(play.played_at)}
                                    </div>
                                </div>
                            </Link>
                            <div class="flex items-center gap-2">
                                <Crown
                                    class="size-4 {crownClass(
                                        play.play_result,
                                    )}"
                                />
                                <span
                                    class="text-sm font-semibold tabular-nums"
                                >
                                    {numberFormatter.format(play.score)}
                                </span>
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
        </section>
    </div>
</section>
