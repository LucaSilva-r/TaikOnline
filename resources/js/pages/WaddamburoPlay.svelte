<script lang="ts">
    import { Deferred, Link, router } from '@inertiajs/svelte';
    import ArrowLeft from 'lucide-svelte/icons/arrow-left';
    import Crown from 'lucide-svelte/icons/crown';
    import ExternalLink from 'lucide-svelte/icons/external-link';
    import Gavel from 'lucide-svelte/icons/gavel';
    import AppHead from '@/components/AppHead.svelte';
    import PlayInspection, {
        type Inspection,
    } from '@/components/PlayInspection.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import ReplayPlayer, { type Replay } from '@/components/ReplayPlayer.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { Skeleton } from '@/components/ui/skeleton';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import moderationPlayers from '@/routes/mod/players';
    import moderationPlays from '@/routes/mod/plays';
    import { show as playsShow } from '@/routes/plays';
    import songs from '@/routes/songs';

    type Play = {
        id: string;
        score: number;
        great: number;
        good: number;
        miss: number;
        max_combo: number;
        rolls: number;
        roll_max: number | null;
        gauge: number;
        cleared: boolean;
        crown: number;
        precision: number;
        level: number;
        options: string[];
        played_at: string | null;
        audio_offset_ms: number | null;
        input_offset_ms: number | null;
        scored: boolean;
        placement: number | null;
    };
    type Stats = {
        mean_offset: number | null;
        early: number;
        late: number;
        roll_speed: { average: number; peak: number } | null;
    } | null;
    type OtherPlay = {
        id: string;
        score: number;
        crown: number;
        options: string[];
        played_at: string | null;
        hidden: boolean;
    };
    type Moderation = {
        baid: number;
        hidden_at: string | null;
        hidden_reason: string | null;
        sanctions: string[];
        can_act: boolean;
    };

    let {
        play,
        player,
        chart,
        otherPlays,
        stats,
        replay,
        moderation,
        inspection,
    }: {
        play: Play;
        player: { user_id: number | null; name: string; avatar: string | null };
        chart: {
            song_id: number | null;
            title: string;
            title_en: string | null;
            difficulty: string | null;
            ranked: boolean;
            external_url: string | null;
        };
        otherPlays: OtherPlay[];
        stats?: Stats;
        replay?: Replay | null;
        moderation: Moderation | null;
        inspection?: Inspection | null;
    } = $props();

    const taikoParam = taikoRouteParam();
    const numberFormatter = new Intl.NumberFormat('en-US');
    const dateFormatter = new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const levels = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];

    function formatDate(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }

    function levelClass(level: number): string {
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

    function signed(value: number): string {
        return `${value > 0 ? '+' : ''}${value}`;
    }

    function hide() {
        const reason = prompt('Hide this play from every board. Reason:');
        if (reason) {
            router.patch(
                moderationPlays.hide({ ...taikoParam, play: play.id }).url,
                { reason },
                { preserveScroll: true },
            );
        }
    }

    function restore() {
        const reason = prompt('Restore this play? Reason (optional):');
        if (reason !== null) {
            router.patch(
                moderationPlays.restore({ ...taikoParam, play: play.id }).url,
                { reason },
                { preserveScroll: true },
            );
        }
    }
</script>

<AppHead title={`${player.name} · ${chart.title}`} />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    {#if chart.song_id !== null}
        <Link
            href={toUrl(songs.show({ ...taikoParam, song: chart.song_id }))}
            class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" />
            {chart.title}
        </Link>
    {/if}

    <!-- Header -->
    <div
        class="relative overflow-hidden rounded-xl border bg-gradient-to-br from-[var(--taiko-accent-soft)] via-card to-card px-6 py-7"
    >
        <div
            class="relative flex flex-col gap-5 sm:flex-row sm:items-center"
        >
            <svelte:element
                this={player.user_id !== null ? 'a' : 'div'}
                href={player.user_id !== null
                    ? toUrl(boardShow({ ...taikoParam, user: player.user_id }))
                    : undefined}
                class="flex min-w-0 flex-1 items-center gap-4"
            >
                <Avatar class="size-16 border-2 border-background bg-muted">
                    {#if player.avatar}
                        <AvatarImage
                            src={player.avatar}
                            alt={player.name}
                            class="object-cover"
                        />
                    {:else}
                        <AvatarFallback class="font-semibold">
                            {getInitials(player.name)}
                        </AvatarFallback>
                    {/if}
                </Avatar>
                <div class="min-w-0">
                    <div class="truncate text-xl font-bold tracking-tight">
                        {player.name}
                    </div>
                    <div
                        class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                    >
                        <span
                            class="rounded px-2 py-0.5 text-xs font-semibold {levelClass(
                                play.level,
                            )}"
                            >{chart.difficulty ??
                                levels[play.level - 1] ??
                                ''}</span
                        >
                        <span class="truncate">{chart.title}</span>
                        {#if !chart.ranked}
                            <Badge variant="secondary">Unranked</Badge>
                        {/if}
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {formatDate(play.played_at)}
                    </div>
                </div>
            </svelte:element>
            <div class="flex items-center gap-4 sm:text-right">
                <Crown
                    class="size-8 {crownClass(play.crown)}"
                    aria-label={crownLabel(play.crown)}
                />
                <div>
                    <div class="text-3xl font-bold tabular-nums">
                        {numberFormatter.format(play.score)}
                    </div>
                    <div class="text-sm text-muted-foreground">
                        {crownLabel(play.crown)} · {play.precision.toFixed(2)}%
                        {#if play.placement !== null}
                            · #{play.placement} on this chart
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        {#if play.options.length > 0 || chart.external_url}
            <div class="relative mt-4 flex flex-wrap items-center gap-2">
                {#each play.options as option (option)}
                    <Badge variant="outline">{option}</Badge>
                {/each}
                {#if chart.external_url}
                    <a
                        href={chart.external_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 text-xs font-medium text-[var(--taiko-accent-label)] hover:underline"
                    >
                        View on osu!
                        <ExternalLink class="size-3" />
                    </a>
                {/if}
            </div>
        {/if}
    </div>

    {#if !play.scored}
        <p class="text-sm text-muted-foreground">
            The server has not scored this play from its replay yet (its chart
            is not uploaded): these are the numbers the game sent.
        </p>
    {/if}

    <!-- Counts -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {#each [
            { label: 'Good', value: play.great, class: 'text-orange-500' },
            { label: 'Ok', value: play.good, class: '' },
            { label: 'Bad', value: play.miss, class: 'text-sky-500' },
            { label: 'Max combo', value: play.max_combo, class: '' },
            { label: 'Drumroll', value: play.roll_max !== null ? `${play.rolls} / ${play.roll_max}` : play.rolls, class: '' },
            { label: 'Soul gauge', value: `${play.gauge}${play.cleared ? ' · cleared' : ''}`, class: '' },
        ] as stat (stat.label)}
            <div class="rounded-lg border bg-card p-4">
                <div class="text-sm text-muted-foreground">{stat.label}</div>
                <div
                    class="mt-1 text-2xl font-semibold tabular-nums {stat.class}"
                >
                    {typeof stat.value === 'number'
                        ? numberFormatter.format(stat.value)
                        : stat.value}
                </div>
            </div>
        {/each}
    </div>

    <!-- The play, as the engine judged it -->
    <Deferred data="replay">
        {#snippet fallback()}
            <Skeleton class="h-[190px] rounded-lg" />
        {/snippet}
        {#if replay}
            <ReplayPlayer {replay} />
        {/if}
    </Deferred>

    <!-- From the engine's timeline -->
    <Deferred data="stats">
        {#snippet fallback()}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                {#each [0, 1, 2, 3] as index (index)}
                    <Skeleton class="h-[86px] rounded-lg" />
                {/each}
            </div>
        {/snippet}
        {#if stats}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-lg border bg-card p-4">
                    <div class="text-sm text-muted-foreground">
                        Average timing
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {stats.mean_offset === null
                            ? '—'
                            : `${signed(stats.mean_offset)} ms`}
                    </div>
                </div>
                <div class="rounded-lg border bg-card p-4">
                    <div class="text-sm text-muted-foreground">
                        Early / late hits
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {stats.early} / {stats.late}
                    </div>
                </div>
                <div class="rounded-lg border bg-card p-4">
                    <div class="text-sm text-muted-foreground">
                        Drumroll speed
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {stats.roll_speed
                            ? `${stats.roll_speed.average} hits/s`
                            : '—'}
                    </div>
                </div>
                <div class="rounded-lg border bg-card p-4">
                    <div class="text-sm text-muted-foreground">
                        Fastest drumroll
                    </div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">
                        {stats.roll_speed
                            ? `${stats.roll_speed.peak} hits/s`
                            : '—'}
                    </div>
                </div>
            </div>
        {/if}
    </Deferred>

    {#if moderation}
        <section
            class="flex flex-col gap-4 rounded-xl border border-amber-500/40 bg-amber-500/5 p-5"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Gavel class="size-5 text-amber-600 dark:text-amber-400" />
                <h2 class="font-semibold">Moderation</h2>
                <span class="text-xs text-muted-foreground"
                    >Staff only. Never share what is here with players.</span
                >
                {#if moderation.hidden_at}
                    <Badge
                        variant="secondary"
                        class="border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                        >hidden {formatDate(moderation.hidden_at)}</Badge
                    >
                {/if}
                {#each moderation.sanctions as sanction (sanction)}
                    <Badge
                        variant="secondary"
                        class="border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                        >{sanction}</Badge
                    >
                {/each}
                <div class="ml-auto flex flex-wrap gap-2">
                    <Link
                        href={toUrl(
                            moderationPlayers.show({
                                ...taikoParam,
                                player: moderation.baid,
                            }),
                        )}
                        class={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}>Player · sanctions</Link
                    >
                    {#if moderation.can_act}
                        {#if moderation.hidden_at}
                            <Button size="sm" variant="outline" onclick={restore}
                                >Restore play</Button
                            >
                        {:else}
                            <Button
                                size="sm"
                                variant="destructive"
                                onclick={hide}>Hide play</Button
                            >
                        {/if}
                    {/if}
                </div>
            </div>
            {#if moderation.hidden_reason}
                <p class="text-sm text-muted-foreground">
                    Hidden because: {moderation.hidden_reason}
                </p>
            {/if}
            <Deferred data="inspection">
                {#snippet fallback()}
                    <Skeleton class="h-64 rounded-lg" />
                {/snippet}
                {#if inspection}
                    <PlayInspection {inspection} />
                {:else}
                    <p class="text-sm text-muted-foreground">
                        No timeline yet: the play has not been rescored by a
                        Waddamburo that keeps it (app:rescore-waddamburo-plays
                        --all), or its chart is not uploaded.
                    </p>
                {/if}
            </Deferred>
        </section>
    {/if}

    {#if otherPlays.length > 0}
        <section class="overflow-hidden rounded-lg border bg-card">
            <h2 class="border-b px-5 py-3 font-semibold">
                {player.name}'s other plays on this chart
            </h2>
            <div class="divide-y">
                {#each otherPlays as other (other.id)}
                    <Link
                        href={toUrl(playsShow({ ...taikoParam, id: other.id }))}
                        class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/50 {other.hidden
                            ? 'text-muted-foreground'
                            : ''}"
                    >
                        <Crown
                            class="size-4 shrink-0 {crownClass(other.crown)}"
                            aria-label={crownLabel(other.crown)}
                        />
                        <span class="font-semibold tabular-nums"
                            >{numberFormatter.format(other.score)}</span
                        >
                        {#each other.options as option (option)}
                            <Badge variant="outline">{option}</Badge>
                        {/each}
                        {#if other.hidden}
                            <Badge variant="secondary">hidden</Badge>
                        {/if}
                        <span class="ml-auto text-xs text-muted-foreground"
                            >{formatDate(other.played_at)}</span
                        >
                    </Link>
                {/each}
            </div>
        </section>
    {/if}
</section>
