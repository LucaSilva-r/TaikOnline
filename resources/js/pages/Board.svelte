<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import * as Chart from '@/components/ui/chart';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as songsShow } from '@/routes/songs';
    import { Link } from '@inertiajs/svelte';
    import { LineChart } from 'layerchart';
    import Activity from 'lucide-svelte/icons/activity';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Crown from 'lucide-svelte/icons/crown';
    import Drum from 'lucide-svelte/icons/drum';
    import Medal from 'lucide-svelte/icons/medal';
    import Music2 from 'lucide-svelte/icons/music-2';
    import Star from 'lucide-svelte/icons/star';
    import Trophy from 'lucide-svelte/icons/trophy';

    type Profile = {
        id: number;
        name: string;
        avatar: string | null;
        mydon_name: string | null;
        game_version: {
            value: string;
            label: string;
        };
        last_played_at: string | null;
        total_credit_count: number;
        don_medals: {
            earned: number;
            spent: number;
        };
        katsu_medals: {
            earned: number;
            spent: number;
        };
    };

    type CrownCounts = {
        none: number;
        clear: number;
        gold: number;
        dondaful: number;
    };

    type Summary = {
        rank: number | null;
        total_score: number;
        ranked_song_count: number;
        played_song_count: number;
        crown_counts: CrownCounts;
    };

    type RankHistoryPoint = {
        date: string;
        rank: number;
        total_score: number;
    };

    type RecentPlay = {
        song_title: string;
        song_id: number | null;
        song_no: number;
        level: number;
        played_at: string | null;
        play_result: number;
        score: number;
        score_rank: number;
        good_count: number;
        ok_count: number;
        miss_count: number;
        combo_count: number;
    };

    type BestPerformance = {
        song_title: string;
        song_id: number | null;
        song_no: number;
        level: number;
        score: number;
        score_rank: number;
        crown: number;
        placement: number;
    };

    type NpcData = {
        npc_id: number;
        total_exp: number;
        max_dpn: number;
        npc_costume_id: number;
        selected_special_id_1: number;
        selected_special_id_2: number;
        selected_special_id_3: number;
        bonds_level: number;
    };

    type TokenData = {
        token_id: number;
        token_value: number;
    };

    type BlueBattleData = {
        last_battle_stage_id: number;
        last_boss_life: number;
        last_npc_id: number;
        assign_stage_id: number;
        npcs: NpcData[];
        tokens: TokenData[];
    };

    type GreenGhostWinnings = {
        level_id: number;
        winnings: number;
    };

    type GreenGhostData = {
        total_winnings: number;
        input_median: number;
        input_variance: number;
        rank_id: number;
        win_point: number;
        certified_level_id: number;
        tokens: TokenData[];
        winnings: GreenGhostWinnings[];
    };

    type TokkunSong = {
        song_no: number;
        title: string;
    };

    type TokkunRun = {
        played_at: string;
        play_mode: number;
        banacoin_datetime: string | null;
        tokkun_song_count: number;
        tokkun_speedchange_count: number;
        tokkun_autoplay_count: number;
        tokkun_jump_count: number;
        songs: TokkunSong[];
    };

    type TokkunData = {
        tokkun_tutorial_flg: number;
        summary: {
            total_runs: number;
            total_songs: number;
            total_speedchanges: number;
            total_autoplays: number;
            total_jumps: number;
        };
        recent_runs: TokkunRun[];
    };

    let {
        profile,
        hasPlayer,
        summary,
        rankHistory,
        recentPlays,
        bestPerformances,
        blueBattleData = null,
        greenGhostData = null,
        tokkunData = null,
    }: {
        profile: Profile;
        hasPlayer: boolean;
        summary: Summary;
        rankHistory: RankHistoryPoint[];
        recentPlays: RecentPlay[];
        bestPerformances: BestPerformance[];
        blueBattleData?: BlueBattleData | null;
        greenGhostData?: GreenGhostData | null;
        tokkunData?: TokkunData | null;
    } = $props();

    const taikoParam = taikoRouteParam();

    const numberFormatter = new Intl.NumberFormat();
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    });
    const fullDateFormatter = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });

    const chartData = $derived(
        rankHistory.map((point) => ({
            ...point,
            dateValue: new Date(`${point.date}T00:00:00`),
        })),
    );
    const maxRank = $derived(
        Math.max(1, ...chartData.map((point) => point.rank)),
    );
    const chartConfig = {
        rank: {
            label: 'Rank',
            color: 'var(--primary)',
        },
    } satisfies Chart.ChartConfig;

    const statItems = $derived([
        {
            label: 'Global Rank',
            value: summary.rank ? `#${numberFormatter.format(summary.rank)}` : '-',
            icon: Trophy,
        },
        {
            label: 'Total Score',
            value: numberFormatter.format(summary.total_score),
            icon: Activity,
        },
        {
            label: 'Ranked Charts',
            value: numberFormatter.format(summary.ranked_song_count),
            icon: Music2,
        },
        {
            label: 'Played Songs',
            value: numberFormatter.format(summary.played_song_count),
            icon: Drum,
        },
    ]);

    function formatFullDate(value: string | null): string {
        if (!value) {
            return 'Never';
        }

        return fullDateFormatter.format(new Date(value));
    }

    function formatShortDate(value: Date): string {
        return dateFormatter.format(value);
    }

    function formatChartDate(value: unknown): string {
        return value instanceof Date ? formatShortDate(value) : String(value);
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

    function crownLabel(crown: number): string {
        return (
            {
                0: 'No crown',
                1: 'Clear',
                2: 'Full combo',
                3: 'Dondaful',
            }[crown] ?? 'No crown'
        );
    }
</script>

<AppHead title={`${profile.name} Board`} />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <section class="overflow-hidden rounded-lg border bg-card">
        <div
            class="border-b bg-[var(--taiko-accent-soft)] px-5 py-8"
        >
            <div class="flex flex-col gap-5 sm:flex-row sm:items-end">
                <Avatar class="size-24 border-4 border-background shadow-sm">
                    {#if profile.avatar}
                        <AvatarImage src={profile.avatar} alt={profile.name} class="bg-muted object-cover" />
                    {:else}
                        <AvatarFallback class="text-2xl font-semibold">
                            {getInitials(profile.name)}
                        </AvatarFallback>
                    {/if}
                </Avatar>

                <div class="min-w-0 flex-1">
                    <div
                        class="mb-3 inline-flex items-center rounded-full border bg-background/80 px-3 py-1 text-xs font-medium text-muted-foreground shadow-xs"
                    >
                        {profile.game_version.label}
                    </div>
                    <h1 class="truncate text-3xl font-semibold tracking-tight">
                        {profile.name}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {profile.mydon_name ?? 'No MyDon profile linked'}
                    </p>
                </div>

                <div class="grid gap-1 text-sm sm:text-right">
                    <div
                        class="flex items-center gap-2 text-muted-foreground sm:justify-end"
                    >
                        <CalendarDays class="size-4" />
                        Last played {formatFullDate(profile.last_played_at)}
                    </div>
                    <div class="font-medium">
                        {numberFormatter.format(profile.total_credit_count)}
                        credits
                    </div>
                </div>
            </div>
        </div>

        {#if !hasPlayer}
            <div class="p-6 text-sm text-muted-foreground">
                This user has not linked a player profile yet.
            </div>
        {/if}
    </section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {#each statItems as item (item.label)}
            {@const Icon = item.icon}
            <section class="rounded-lg border bg-card p-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm text-muted-foreground">
                            {item.label}
                        </div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums">
                            {item.value}
                        </div>
                    </div>
                    <div
                        class="flex size-10 items-center justify-center rounded-md bg-accent text-primary"
                    >
                        <Icon class="size-5" />
                    </div>
                </div>
            </section>
        {/each}
    </div>

    <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
        <section class="min-w-0 rounded-lg border bg-card">
            <div class="flex items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold">Rank History</h2>
                    <p class="text-sm text-muted-foreground">
                        Daily snapshot trend
                    </p>
                </div>
                <Trophy class="size-5 text-primary" />
            </div>

            <div class="p-5">
                {#if chartData.length === 0}
                    <div
                        class="flex min-h-64 items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground"
                    >
                        No rank snapshots yet.
                    </div>
                {:else}
                    <Chart.Container
                        config={chartConfig}
                        class="min-h-64 w-full"
                    >
                        <LineChart
                            data={chartData}
                            x="dateValue"
                            y="rank"
                            yReverse
                            axis={true}
                            yDomain={[1, maxRank]}
                            series={[
                                {
                                    key: 'rank',
                                    label: chartConfig.rank.label,
                                    value: 'rank',
                                    color: chartConfig.rank.color,
                                },
                            ]}
                            props={{
                                xAxis: {
                                    format: formatShortDate,
                                },
                                yAxis: {
                                    format: (rank: number) => `#${rank}`,
                                },
                            }}
                        >
                            {#snippet tooltip()}
                                <Chart.Tooltip
                                    labelFormatter={formatChartDate}
                                />
                            {/snippet}
                        </LineChart>
                    </Chart.Container>
                {/if}
            </div>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="flex items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold">Crowns</h2>
                    <p class="text-sm text-muted-foreground">
                        Best clear states
                    </p>
                </div>
                <Crown class="size-5 text-primary" />
            </div>

            <div class="grid gap-3 p-5">
                <div class="flex items-center justify-between rounded-md bg-muted/50 p-3">
                    <span class="text-sm text-muted-foreground">Dondaful</span>
                    <span class="font-semibold tabular-nums">
                        {numberFormatter.format(summary.crown_counts.dondaful)}
                    </span>
                </div>
                <div class="flex items-center justify-between rounded-md bg-muted/50 p-3">
                    <span class="text-sm text-muted-foreground">Full combo</span>
                    <span class="font-semibold tabular-nums">
                        {numberFormatter.format(summary.crown_counts.gold)}
                    </span>
                </div>
                <div class="flex items-center justify-between rounded-md bg-muted/50 p-3">
                    <span class="text-sm text-muted-foreground">Clear</span>
                    <span class="font-semibold tabular-nums">
                        {numberFormatter.format(summary.crown_counts.clear)}
                    </span>
                </div>
                <div class="flex items-center justify-between rounded-md bg-muted/50 p-3">
                    <span class="text-sm text-muted-foreground">No crown</span>
                    <span class="font-semibold tabular-nums">
                        {numberFormatter.format(summary.crown_counts.none)}
                    </span>
                </div>
            </div>
        </section>
    </div>

    {#if blueBattleData}
        <section class="rounded-lg border bg-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold text-lg flex items-center gap-2">
                        <Trophy class="size-5 text-primary" />
                        Ensou Battle (演奏バトル) Progress
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Your campaign progress, NPC partners, and battle stats
                    </p>
                </div>
            </div>

            <div class="grid gap-6 p-5 md:grid-cols-3">
                <!-- Main Battle Stats -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Campaign Progress</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Assigned Stage</span>
                                <span class="font-semibold text-lg text-primary">Stage {blueBattleData.assign_stage_id}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Last Played Stage</span>
                                <span class="font-medium">Stage {blueBattleData.last_battle_stage_id}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Last Boss Life</span>
                                <span class="font-medium tabular-nums">{blueBattleData.last_boss_life} HP</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Battle Tokens -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between md:col-span-2">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Battle Tokens</h3>
                        <div class="flex flex-wrap gap-3">
                            {#each blueBattleData.tokens as token}
                                <div class="flex items-center gap-2 rounded-md bg-background px-3 py-2 border">
                                    <Medal class="size-4 text-amber-500" />
                                    <div class="text-xs">
                                        <div class="text-muted-foreground">Token {token.token_id}</div>
                                        <div class="font-semibold tabular-nums">{token.token_value}</div>
                                    </div>
                                </div>
                            {/each}
                            {#if blueBattleData.tokens.length === 0}
                                <div class="text-sm text-muted-foreground py-2">No battle tokens collected yet.</div>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>

            <!-- NPC Partners -->
            <div class="px-5 pb-5">
                <h3 class="font-semibold text-sm text-muted-foreground mb-4">NPC Partners</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {#each blueBattleData.npcs as npc}
                        <div class="rounded-lg border bg-background p-4 shadow-xs flex flex-col justify-between gap-3">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-semibold text-base flex items-center gap-2">
                                        Partner ID: {npc.npc_id}
                                        {#if npc.npc_id === blueBattleData.last_npc_id}
                                            <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-2xs font-medium text-primary border border-primary/20">
                                                Active
                                            </span>
                                        {/if}
                                    </div>
                                    <div class="text-xs text-muted-foreground mt-0.5">
                                        Bonds Level {npc.bonds_level}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs text-muted-foreground">Max Damage</div>
                                    <div class="font-bold text-sm tabular-nums text-primary">
                                        {npc.max_dpn} DPN
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="flex justify-between items-center text-xs text-muted-foreground border-b pb-1.5">
                                    <span>Total Experience</span>
                                    <span class="font-medium text-foreground tabular-nums">{npc.total_exp} EXP</span>
                                </div>
                                <div>
                                    <div class="text-2xs text-muted-foreground uppercase tracking-wider font-semibold mb-1">
                                        Equipped Special Moves
                                    </div>
                                    <div class="flex gap-1.5">
                                        <span class="inline-flex items-center rounded-md bg-accent px-2 py-1 text-2xs font-medium text-primary">
                                            Move 1: {npc.selected_special_id_1}
                                        </span>
                                        {#if npc.selected_special_id_2 > 0}
                                            <span class="inline-flex items-center rounded-md bg-accent px-2 py-1 text-2xs font-medium text-primary">
                                                Move 2: {npc.selected_special_id_2}
                                            </span>
                                        {/if}
                                        {#if npc.selected_special_id_3 > 0}
                                            <span class="inline-flex items-center rounded-md bg-accent px-2 py-1 text-2xs font-medium text-primary">
                                                Move 3: {npc.selected_special_id_3}
                                            </span>
                                        {/if}
                                    </div>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            </div>
        </section>
    {/if}

    {#if greenGhostData}
        <section class="rounded-lg border bg-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold text-lg flex items-center gap-2">
                        <Trophy class="size-5 text-primary" />
                        AI Battle (AIバトル) Progress
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Your AI Battle performance, rank, and token stats
                    </p>
                </div>
            </div>

            <div class="grid gap-6 p-5 md:grid-cols-3">
                <!-- Main Battle Stats -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">AI Battle Rank</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Rank ID</span>
                                <span class="font-semibold text-lg text-primary">Rank {greenGhostData.rank_id}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Win Points</span>
                                <span class="font-medium">{greenGhostData.win_point} pts</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Certified Level</span>
                                <span class="font-medium">Level {greenGhostData.certified_level_id}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Performance Stats -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Performance Data</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Input Median</span>
                                <span class="font-medium tabular-nums">{greenGhostData.input_median}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Input Variance</span>
                                <span class="font-medium tabular-nums">{greenGhostData.input_variance}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Total Winnings</span>
                                <span class="font-semibold text-primary tabular-nums">{numberFormatter.format(greenGhostData.total_winnings)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Battle Tokens -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Battle Tokens</h3>
                        <div class="flex flex-wrap gap-2.5">
                            {#each greenGhostData.tokens as token}
                                <div class="flex items-center gap-2 rounded-md bg-background px-3 py-1.5 border">
                                    <Medal class="size-4 text-amber-500" />
                                    <div class="text-2xs">
                                        <div class="text-muted-foreground font-medium">Token {token.token_id}</div>
                                        <div class="font-bold tabular-nums">{token.token_value}</div>
                                    </div>
                                </div>
                            {/each}
                            {#if greenGhostData.tokens.length === 0}
                                <div class="text-sm text-muted-foreground py-2">No battle tokens collected yet.</div>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Winnings Level Data -->
            {#if greenGhostData.winnings.length > 0}
                <div class="px-5 pb-5">
                    <h3 class="font-semibold text-sm text-muted-foreground mb-4">Level Winnings</h3>
                    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        {#each greenGhostData.winnings as win}
                            <div class="rounded-lg border bg-background p-3.5 shadow-2xs flex items-center justify-between">
                                <div>
                                    <div class="text-3xs text-muted-foreground uppercase font-semibold">Level ID</div>
                                    <div class="font-bold text-sm text-foreground">Level {win.level_id}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-3xs text-muted-foreground uppercase font-semibold">Winnings</div>
                                    <div class="font-extrabold text-sm text-primary tabular-nums">{win.winnings}</div>
                                </div>
                            </div>
                        {/each}
                    </div>
                </div>
            {/if}
        </section>
    {/if}

    {#if tokkunData}
        <section class="rounded-lg border bg-card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold text-lg flex items-center gap-2">
                        <Trophy class="size-5 text-primary" />
                        Tokkun Mode (特訓モード) Progress
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Your practice session details, metrics, and training history
                    </p>
                </div>
            </div>

            <div class="grid gap-6 p-5 md:grid-cols-3">
                <!-- Main Training Stats -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Overall Training</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Total Sessions</span>
                                <span class="font-semibold text-lg text-primary">{tokkunData.summary.total_runs} runs</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Total Songs Practiced</span>
                                <span class="font-medium">{tokkunData.summary.total_songs} songs</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-sm">Tutorial Completed</span>
                                <span class="font-medium">{tokkunData.tokkun_tutorial_flg ? 'Yes' : 'No'}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Training Actions -->
                <div class="rounded-lg bg-muted/30 p-4 border flex flex-col justify-between md:col-span-2">
                    <div>
                        <h3 class="font-medium text-sm text-muted-foreground mb-3">Practice Metrics</h3>
                        <div class="grid gap-4 grid-cols-3">
                            <div class="flex flex-col items-center justify-center p-3 rounded-md bg-background border">
                                <span class="text-2xs text-muted-foreground uppercase font-semibold">Speed Changes</span>
                                <span class="font-bold text-lg text-primary tabular-nums mt-1">{tokkunData.summary.total_speedchanges}</span>
                            </div>
                            <div class="flex flex-col items-center justify-center p-3 rounded-md bg-background border">
                                <span class="text-2xs text-muted-foreground uppercase font-semibold">Autoplay Runs</span>
                                <span class="font-bold text-lg text-primary tabular-nums mt-1">{tokkunData.summary.total_autoplays}</span>
                            </div>
                            <div class="flex flex-col items-center justify-center p-3 rounded-md bg-background border">
                                <span class="text-2xs text-muted-foreground uppercase font-semibold">Position Jumps</span>
                                <span class="font-bold text-lg text-primary tabular-nums mt-1">{tokkunData.summary.total_jumps}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Sessions -->
            {#if tokkunData.recent_runs.length > 0}
                <div class="px-5 pb-5">
                    <h3 class="font-semibold text-sm text-muted-foreground mb-4">Recent Training Sessions</h3>
                    <div class="divide-y border rounded-lg bg-background overflow-hidden">
                        {#each tokkunData.recent_runs as run}
                            <div class="grid gap-4 p-4 sm:grid-cols-[1fr_auto]">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-semibold text-primary">
                                            {formatFullDate(run.played_at)} at {new Date(run.played_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </span>
                                        {#if run.banacoin_datetime}
                                            <span class="inline-flex items-center rounded-full bg-accent px-2 py-0.5 text-2xs font-medium text-primary border">
                                                Paid Session
                                            </span>
                                        {/if}
                                    </div>
                                    
                                    {#if run.songs.length > 0}
                                        <div class="flex flex-wrap gap-1.5">
                                            {#each run.songs as song}
                                                <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-2xs font-medium text-muted-foreground">
                                                    {song.title}
                                                </span>
                                            {/each}
                                        </div>
                                    {:else}
                                        <div class="text-xs text-muted-foreground">No songs logged.</div>
                                    {/if}
                                </div>

                                <div class="flex flex-wrap items-center gap-4 text-xs text-muted-foreground sm:text-right">
                                    <div class="text-center sm:text-right">
                                        <div>Speed Changes</div>
                                        <div class="font-bold text-foreground tabular-nums">{run.tokkun_speedchange_count}</div>
                                    </div>
                                    <div class="text-center sm:text-right">
                                        <div>Autoplays</div>
                                        <div class="font-bold text-foreground tabular-nums">{run.tokkun_autoplay_count}</div>
                                    </div>
                                    <div class="text-center sm:text-right">
                                        <div>Jumps</div>
                                        <div class="font-bold text-foreground tabular-nums">{run.tokkun_jump_count}</div>
                                    </div>
                                </div>
                            </div>
                        {/each}
                    </div>
                </div>
            {/if}
        </section>
    {/if}

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-lg border bg-card">
            <div class="flex items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold">Recent Plays</h2>
                    <p class="text-sm text-muted-foreground">
                        Latest submitted stages
                    </p>
                </div>
                <Drum class="size-5 text-primary" />
            </div>

            {#if recentPlays.length === 0}
                <div class="p-5 text-sm text-muted-foreground">
                    No recent plays for this version.
                </div>
            {:else}
                <div class="divide-y">
                    {#each recentPlays as play, i (`${i}-${play.song_no}-${play.level}-${play.played_at}`)}
                        <Link
                            href={toUrl(songsShow({ ...taikoParam, song: play.song_id ?? play.song_no }))}
                            class="grid gap-3 px-5 py-4 transition-colors hover:bg-muted/50 sm:grid-cols-[1fr_auto]"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {play.song_title}
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                                >
                                    <span>{difficultyLabel(play.level)}</span>
                                    <span>{crownLabel(play.play_result)}</span>
                                    <span>{formatFullDate(play.played_at)}</span>
                                </div>
                                <div
                                    class="mt-2 flex flex-wrap gap-2 text-xs text-muted-foreground"
                                >
                                    <span>Good {play.good_count}</span>
                                    <span>Ok {play.ok_count}</span>
                                    <span>Miss {play.miss_count}</span>
                                    <span>Combo {play.combo_count}</span>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <div class="font-semibold tabular-nums">
                                    {numberFormatter.format(play.score)}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    Rank {play.score_rank}
                                </div>
                            </div>
                        </Link>
                    {/each}
                </div>
            {/if}
        </section>

        <section class="rounded-lg border bg-card">
            <div class="flex items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 class="font-semibold">Best Performances</h2>
                    <p class="text-sm text-muted-foreground">
                        Highest scoring charts
                    </p>
                </div>
                <Star class="size-5 text-primary" />
            </div>

            {#if bestPerformances.length === 0}
                <div class="p-5 text-sm text-muted-foreground">
                    No best scores for this version.
                </div>
            {:else}
                <div class="divide-y">
                    {#each bestPerformances as best (`${best.song_no}-${best.level}`)}
                        <Link
                            href={toUrl(songsShow({ ...taikoParam, song: best.song_id ?? best.song_no }))}
                            class="grid gap-3 px-5 py-4 transition-colors hover:bg-muted/50 sm:grid-cols-[auto_1fr_auto] sm:items-center"
                        >
                            <div
                                class="flex size-10 items-center justify-center rounded-md bg-accent text-sm font-semibold text-primary"
                            >
                                #{best.placement}
                            </div>
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {best.song_title}
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                                >
                                    <span>{difficultyLabel(best.level)}</span>
                                    <span>{crownLabel(best.crown)}</span>
                                    <span>Rank {best.score_rank}</span>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <div class="font-semibold tabular-nums">
                                    {numberFormatter.format(best.score)}
                                </div>
                                <div
                                    class="mt-1 inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                >
                                    <Medal class="size-3" />
                                    Best
                                </div>
                            </div>
                        </Link>
                    {/each}
                </div>
            {/if}
        </section>
    </div>
</section>
