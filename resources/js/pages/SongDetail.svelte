<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Crown from 'lucide-svelte/icons/crown';
    import ExternalLink from 'lucide-svelte/icons/external-link';
    import Music2 from 'lucide-svelte/icons/music-2';
    import Play from 'lucide-svelte/icons/play';
    import Star from 'lucide-svelte/icons/star';
    import Users from 'lucide-svelte/icons/users';
    import { toast } from 'svelte-sonner';
    import AppHead from '@/components/AppHead.svelte';
    import DanRankTag from '@/components/DanRankTag.svelte';
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
    import { show as playsShow } from '@/routes/plays';
    import songs from '@/routes/songs';

    type Genre = { value: string; label: string; label_jp: string };

    type CrownCounts = { clear: number; gold: number; dondaful: number };

    type LeaderboardEntry = {
        /** Waddamburo: the best play's own page (the row links there instead of the board). */
        play_id?: string | null;
        mode?: string | null;
        rank: number;
        user_id: number;
        player_name: string;
        avatar: string | null;
        score: number;
        score_rank: number;
        crown: number;
        precision: number | null;
        /** Waddamburo: the best play's counts (rolls beside the chart's most, null when not known yet). */
        counts?: {
            good: number;
            ok: number;
            miss: number;
            combo: number;
            rolls: number;
            roll_max: number | null;
        } | null;
    };

    type Difficulty = {
        id?: number;
        level: number;
        /** The chart's own name where courses are not named (osu!). */
        name?: string | null;
        /** The sources shipping this chart, when the song has several. */
        sources?: string[];
        external_url?: string | null;
        play_count: number;
        player_count: number;
        crown_counts: CrownCounts;
        entries: LeaderboardEntry[];
        /** The 真打 (Shin-uchi) plays' board: their own scale, ranked apart. */
        shinuchi_entries?: LeaderboardEntry[];
        /** Boards of the legacy charts this one replaced (played before Waddamburo read it right). */
        legacy?: Difficulty[];
        /** A legacy chart with no current chart of its course. */
        is_legacy?: boolean;
    };

    /** Waddamburo: one way the systems chart the song (Standard first), its chart id by level. */
    type Version = {
        key: string;
        label: string;
        charts: Record<number, number>;
        /** The levels where its chart is not Standard's. */
        differs: number[];
    };

    type RecentPlay = {
        play_id?: string;
        user_id: number;
        player_name: string;
        avatar: string | null;
        level: number;
        difficulty?: string | null;
        played_at: string | null;
        play_result: number;
        score: number;
        score_rank: number;
        precision: number;
        /** The server has not scored it from its replay yet, so its numbers are the client's. */
        awaiting_verification?: boolean;
        /** Play options as tags (1.3x, Hidden, Reversed, Random, Chaos, Shin-uchi). */
        options?: string[];
    };

    let {
        gameVersion,
        song,
        summary,
        difficulties,
        versions = [],
        boards = {},
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
            subtitle?: string | null;
            subtitle_en?: string | null;
            genre: Genre;
            sources?: string[];
            external_url?: string | null;
        };
        summary: {
            total_plays: number;
            unique_players: number;
            first_played_at: string | null;
            last_played_at: string | null;
        };
        difficulties: Difficulty[];
        versions?: Version[];
        boards?: Record<number, Difficulty>;
        recentPlays: RecentPlay[];
        isFavorite: boolean;
        canFavorite: boolean;
        favoriteLimit: number;
        favoriteCount: number;
    } = $props();

    const taikoParam = taikoRouteParam();

    // The page's view, kept in its URL (?version=wii1&shinuchi=1) so a link opens on it.
    const query =
        typeof window === 'undefined'
            ? new URLSearchParams()
            : new URLSearchParams(window.location.search);
    let versionKey = $state(query.get('version') ?? 'standard');
    let shinuchi = $state(query.get('shinuchi') === '1');

    const version = $derived(
        versions.find((candidate) => candidate.key === versionKey) ??
            versions[0],
    );

    // Each course's card in the chosen version: its board, and whether it is Standard's.
    const cards = $derived(
        difficulties.flatMap((difficulty) => {
            if (
                !version ||
                version.key === 'standard' ||
                difficulty.is_legacy
            ) {
                return [{ difficulty, board: difficulty, standard: false }];
            }

            const id = version.charts[difficulty.level];
            const board = id === undefined ? undefined : boards[id];

            return board
                ? [
                      {
                          difficulty,
                          board,
                          standard: !version.differs.includes(difficulty.level),
                      },
                  ]
                : [];
        }),
    );

    const hasShinuchi = $derived(
        cards.some(({ board }) => (board.shinuchi_entries?.length ?? 0) > 0),
    );

    function setView(key: string, withShinuchi: boolean): void {
        versionKey = key;
        shinuchi = withShinuchi;
        const url = new URL(window.location.href);

        if (key === 'standard') {
            url.searchParams.delete('version');
        } else {
            url.searchParams.set('version', key);
        }

        if (withShinuchi) {
            url.searchParams.set('shinuchi', '1');
        } else {
            url.searchParams.delete('shinuchi');
        }

        window.history.replaceState(window.history.state, '', url);
    }

    function differsLabel(candidate: Version): string {
        return candidate.differs.length === 0
            ? 'Same charts as Standard'
            : `Differs on ${candidate.differs.map(difficultyLabel).join(', ')}`;
    }

    function legacyPlayers(difficulty: Difficulty): number {
        return (difficulty.legacy ?? []).reduce(
            (total, old) => total + old.player_count,
            0,
        );
    }

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

    /** A Waddamburo row opens its play; other versions' rows the player's board. */
    function rowUrl(row: { user_id: number; play_id?: string | null }): string {
        return row.play_id
            ? toUrl(playsShow({ ...taikoParam, id: row.play_id }))
            : boardUrl(row.user_id);
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

{#snippet entryRows(entries: LeaderboardEntry[])}
    {#each entries as entry (entry.user_id)}
        <div
            class="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 px-5 py-2.5 transition-colors hover:bg-muted/30"
        >
            <span
                class="text-sm font-semibold tabular-nums text-muted-foreground"
            >
                {entry.rank}
            </span>
            <Link
                href={rowUrl(entry)}
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
                        <AvatarFallback class="text-[10px] font-semibold">
                            {getInitials(entry.player_name)}
                        </AvatarFallback>
                    {/if}
                </Avatar>
                <span class="min-w-0">
                    <span class="flex min-w-0 items-center gap-2">
                        <DanRankTag userId={entry.user_id} size="compact" />
                        <span
                            class="truncate text-sm font-medium hover:underline"
                            >{entry.player_name}</span
                        >
                    </span>
                    {#if entry.counts}
                        <!-- Good / Ok / Bad in the game's colours (良 orange, 可 white, 不可 blue). -->
                        <span
                            class="mt-0.5 flex flex-wrap gap-x-3 text-xs tabular-nums text-muted-foreground"
                        >
                            <span
                                >Good <b class="font-semibold text-orange-500"
                                    >{entry.counts.good}</b
                                ></span
                            >
                            <span
                                >Ok <b class="font-semibold text-foreground"
                                    >{entry.counts.ok}</b
                                ></span
                            >
                            <span
                                >Bad <b class="font-semibold text-sky-500"
                                    >{entry.counts.miss}</b
                                ></span
                            >
                            <span
                                >Combo <b
                                    class="font-semibold text-foreground/80"
                                    >{entry.counts.combo}</b
                                ></span
                            >
                            <span
                                >Rolls <b
                                    class="font-semibold text-foreground/80"
                                    >{entry.counts.rolls}{entry.counts
                                        .roll_max != null
                                        ? ` / ${entry.counts.roll_max}`
                                        : ''}</b
                                ></span
                            >
                            {#if entry.mode === 'ai_battle'}
                                <span
                                    class="inline-flex items-center rounded border border-cyan-500/30 bg-cyan-500/10 px-1.5 py-0.5 text-2xs font-medium text-cyan-700 dark:text-cyan-300"
                                    >AI Battle</span
                                >
                            {/if}
                        </span>
                    {/if}
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
                    class="size-4 {crownClass(entry.crown)}"
                    aria-label={crownLabel(entry.crown)}
                />
                <span class="w-20 text-right font-semibold tabular-nums">
                    {numberFormatter.format(entry.score)}
                </span>
            </div>
        </div>
    {/each}
{/snippet}
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
                {#if song.subtitle_en ?? song.subtitle}
                    <p class="truncate text-xs text-muted-foreground/80">
                        {song.subtitle_en ?? song.subtitle}
                    </p>
                {/if}
                {#if song.external_url}
                    <a
                        href={song.external_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-[var(--taiko-accent-label)] hover:underline"
                    >
                        View on osu!
                        <ExternalLink class="size-3" />
                    </a>
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
            {#if versions.length > 1 || hasShinuchi}
                <!-- The view for every difficulty: which systems' charts, and the normal or Shin-uchi boards. -->
                <div class="flex flex-wrap items-center gap-3">
                    {#if versions.length > 3}
                        <select
                            class="h-8 rounded-md border bg-card px-2 text-sm"
                            value={version?.key}
                            onchange={(event) =>
                                setView(event.currentTarget.value, shinuchi)}
                        >
                            {#each versions as candidate (candidate.key)}
                                <option value={candidate.key}
                                    >{candidate.label}</option
                                >
                            {/each}
                        </select>
                    {:else if versions.length > 1}
                        <div
                            class="flex flex-wrap overflow-hidden rounded-md border text-sm"
                        >
                            {#each versions as candidate (candidate.key)}
                                <button
                                    type="button"
                                    class="px-3 py-1 {version?.key ===
                                    candidate.key
                                        ? 'bg-muted font-semibold'
                                        : 'text-muted-foreground'}"
                                    title={candidate.key === 'standard'
                                        ? 'The charts these systems share'
                                        : differsLabel(candidate)}
                                    onclick={() =>
                                        setView(candidate.key, shinuchi)}
                                >
                                    {candidate.label}
                                </button>
                            {/each}
                        </div>
                    {/if}
                    {#if version && version.key !== 'standard'}
                        <span class="text-xs text-muted-foreground"
                            >{differsLabel(version)}</span
                        >
                    {/if}
                    {#if hasShinuchi}
                        <div
                            class="ml-auto flex overflow-hidden rounded-md border text-sm"
                        >
                            {#each [false, true] as option (option)}
                                <button
                                    type="button"
                                    class="px-3 py-1 {shinuchi === option
                                        ? 'bg-muted font-semibold'
                                        : 'text-muted-foreground'}"
                                    onclick={() =>
                                        setView(
                                            version?.key ?? 'standard',
                                            option,
                                        )}
                                >
                                    {option ? 'Shin-uchi' : 'Normal'}
                                </button>
                            {/each}
                        </div>
                    {/if}
                </div>
            {/if}
            {#if difficulties.length === 0}
                <div
                    class="rounded-lg border p-6 text-sm text-muted-foreground"
                >
                    No ranked scores for this song yet.
                </div>
            {:else}
                {#each cards as { difficulty, board, standard } (difficulty.id ?? difficulty.level)}
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
                                    {difficulty.name ??
                                        difficultyLabel(difficulty.level)}
                                </span>
                                {#if standard}
                                    <Badge
                                        variant="outline"
                                        class="text-xs"
                                        title="The same chart as Standard: one leaderboard"
                                        >Same as Standard</Badge
                                    >
                                {:else if versions.length === 0 && (song.sources?.length ?? 0) > 1 && difficulty.sources && difficulty.sources.length < (song.sources?.length ?? 0)}
                                    <Badge variant="outline" class="text-xs"
                                        >{difficulty.sources.join(' · ')} only</Badge
                                    >
                                {/if}
                                {#if difficulty.is_legacy}
                                    <Badge
                                        variant="outline"
                                        class="text-xs"
                                        title="Played before branching (normal route only), unranked"
                                        >Legacy</Badge
                                    >
                                {/if}
                                {#if board.external_url}
                                    <a
                                        href={board.external_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-muted-foreground hover:text-foreground"
                                        title="Open this difficulty on osu!"
                                    >
                                        <ExternalLink class="size-3.5" />
                                    </a>
                                {/if}
                                <span class="text-sm text-muted-foreground">
                                    {numberFormatter.format(board.play_count)} plays
                                    · {numberFormatter.format(
                                        board.player_count,
                                    )} players
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <span
                                    class="flex items-center gap-1 text-fuchsia-500"
                                    title="Dondaful"
                                >
                                    <Crown class="size-4" />
                                    {board.crown_counts.dondaful}
                                </span>
                                <span
                                    class="flex items-center gap-1 text-amber-500"
                                    title="Full combo"
                                >
                                    <Crown class="size-4" />
                                    {board.crown_counts.gold}
                                </span>
                                <span
                                    class="flex items-center gap-1 text-sky-500"
                                    title="Clear"
                                >
                                    <Crown class="size-4" />
                                    {board.crown_counts.clear}
                                </span>
                            </div>
                        </div>

                        <div class="divide-y">
                            {@render entryRows(
                                shinuchi
                                    ? (board.shinuchi_entries ?? [])
                                    : board.entries,
                            )}
                        </div>
                        {#if board === difficulty && (difficulty.legacy?.length ?? 0) > 0}
                            <!-- Plays from before Waddamburo read this chart right (e.g. 譜面分岐 as its normal route): kept, unranked. -->
                            <details class="border-t">
                                <summary
                                    class="cursor-pointer px-5 py-3 text-sm text-muted-foreground hover:text-foreground"
                                >
                                    Legacy scores · {legacyPlayers(difficulty)} players,
                                    played before branching (normal route only), unranked
                                </summary>
                                {#each difficulty.legacy ?? [] as old (old.id)}
                                    <div class="divide-y border-t opacity-80">
                                        {@render entryRows(old.entries)}
                                    </div>
                                {/each}
                            </details>
                        {/if}
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
                                href={rowUrl(play)}
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
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <DanRankTag
                                            userId={play.user_id}
                                            size="compact"
                                        />
                                        <span
                                            class="truncate text-sm font-medium hover:underline"
                                            >{play.player_name}</span
                                        >
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {play.difficulty ??
                                            difficultyLabel(play.level)} · {formatPrecision(
                                            play.precision,
                                        )} · {formatDate(play.played_at)}
                                    </div>
                                    {#if play.awaiting_verification}
                                        <Badge
                                            variant="secondary"
                                            class="mt-1 border-0 bg-amber-500/15 px-1.5 py-0 text-[10px] text-amber-700 dark:text-amber-300"
                                            >Unranked, awaiting verification</Badge
                                        >
                                    {/if}
                                    {#if play.options?.length}
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            {#each play.options as option (option)}
                                                <Badge
                                                    variant="secondary"
                                                    class="px-1.5 py-0 text-[10px]"
                                                    >{option}</Badge
                                                >
                                            {/each}
                                        </div>
                                    {/if}
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
