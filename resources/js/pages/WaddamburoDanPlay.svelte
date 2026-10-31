<script lang="ts">
    import { Deferred, Form, Link, router } from '@inertiajs/svelte';
    import { tick } from 'svelte';
    import Flag from 'lucide-svelte/icons/flag';
    import Gavel from 'lucide-svelte/icons/gavel';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PlayInspection, { type Inspection } from '@/components/PlayInspection.svelte';
    import ReplayPlayer, { type Replay } from '@/components/ReplayPlayer.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { Skeleton } from '@/components/ui/skeleton';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import { report as reportRun } from '@/routes/dan-plays';
    import moderationRuns from '@/routes/mod/dan-plays';
    import moderationPlayers from '@/routes/mod/players';

    type Requirement = { type: string; red: number[]; gold: number[] };
    type Stats = {
        mean_offset: number | null;
        early: number;
        late: number;
        roll_speed: { average: number; peak: number } | null;
    } | null;
    // Song, ms, gauge %, goods, oks, bads, combo, rolls, score.
    type Row = [number, number, number, number, number, number, number, number, number];

    let {
        play,
        course,
        player,
        songs,
        progress = null,
        replays = null,
        stats = null,
        inspections = null,
        report = null,
        moderation = null,
    }: {
        play: {
            id: string;
            result_level: number;
            score: number;
            great: number;
            good: number;
            miss: number;
            max_combo: number;
            rolls: number;
            gauge_percent: number;
            songs_played: number;
            played_at: string | null;
            scored: boolean;
        };
        course: { name: string; label: string; notes: number; requirements: Requirement[]; series: string[] };
        player: { user_id: number | null; name: string; avatar: string | null };
        songs: { title: string; course: number | null; level: number | null; great: number; good: number; miss: number; rolls: number }[];
        progress?: Row[] | null;
        replays?: (Replay | null)[] | null;
        stats?: Stats[] | null;
        inspections?: (Inspection | null)[] | null;
        report?: { reasons: { value: string; label: string }[]; reported: boolean } | null;
        moderation?: { baid: number; hidden_at: string | null; hidden_reason: string | null; sanctions: string[]; can_act: boolean; open_reports: number } | null;
    } = $props();

    const taikoParam = taikoRouteParam();
    const results = ['Failed', 'Passed', 'Gold'];
    const NAMES: Record<string, string> = {
        soul_gauge: 'Soul gauge',
        goods: 'Goods',
        oks: 'OKs',
        bads: 'Bads',
        combo: 'Max combo',
        hits: 'Hits',
        score: 'Score',
        rolls: 'Rolls',
    };

    let song = $state(0);
    let time = $state(0);
    let reporting = $state(false);

    const limit = (type: string): boolean => type === 'bads' || type === 'oks';
    const rows = $derived(progress ?? []);

    // The run as it stood at this moment: the last row at or before the song and time being watched.
    const now = $derived.by((): Row | null => {
        let low = 0;
        let high = rows.length;
        while (low < high) {
            const middle = (low + high) >> 1;
            const row = rows[middle];
            if (row[0] < song || (row[0] === song && row[1] <= time)) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }
        return low === 0 ? null : rows[low - 1];
    });

    function value(type: string, row: Row | null): number {
        if (row === null) {
            return 0;
        }
        const [, , gauge, goods, oks, bads, combo, rollHits, score] = row;
        return {
            soul_gauge: gauge,
            goods,
            oks,
            bads,
            combo,
            hits: goods + oks + rollHits,
            score,
            rolls: rollHits,
        }[type] ?? 0;
    }

    // Goods and combo fail once the notes left cannot reach them, as the scorer decides: the others only at the end.
    function brokenBy(requirement: Requirement, row: Row): boolean {
        const current = value(requirement.type, row);
        if (limit(requirement.type)) {
            return current >= requirement.red[0];
        }
        if (requirement.type === 'goods' || requirement.type === 'combo') {
            const left = Math.max(course.notes - row[3] - row[4] - row[5], 0);
            return current + left < requirement.red[0];
        }
        return false;
    }

    // Where a requirement broke in the run: the moment worth checking, whatever is being watched.
    function brokenAt(requirement: Requirement): Row | null {
        return rows.find((row) => brokenBy(requirement, row)) ?? null;
    }

    // The player remounts on a song change and starts at 0: the seek waits for it.
    async function seek(row: Row): Promise<void> {
        song = row[0];
        await tick();
        time = row[1];
    }

    const signed = (value: number): string => `${value > 0 ? '+' : ''}${value}`;

    const clock = (ms: number): string => `${Math.floor(ms / 60000)}:${String(Math.floor(Math.max(ms, 0) / 1000) % 60).padStart(2, '0')}`;

    function ask(message: string, action: 'hide' | 'restore' | 'dismissReports', required = false) {
        const reason = prompt(message);
        if (reason === null || (required && reason.trim() === '')) {
            return;
        }
        router.patch(moderationRuns[action]({ ...taikoParam, play: play.id }).url, { reason }, { preserveScroll: true });
    }
</script>

<AppHead title="{player.name}: {course.label}" />

<div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <header class="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-card p-4">
        <div>
            <h1 class="text-xl font-semibold">{course.label}</h1>
            <p class="text-sm text-muted-foreground">
                {course.name} · {course.series.join(', ')} ·
                {#if player.user_id !== null}
                    <Link href={toUrl(boardShow({ ...taikoParam, user: player.user_id }))} class="underline">{player.name}</Link>
                {:else}
                    {player.name}
                {/if}
                {#if play.played_at}
                    · {new Date(play.played_at).toLocaleDateString()}
                {/if}
            </p>
        </div>
        <div class="flex items-center gap-3">
            {#if !play.scored}
                <Badge variant="outline">Not scored yet</Badge>
            {/if}
            <Badge variant={play.result_level === 0 ? 'outline' : 'default'} class={play.result_level === 2 ? 'bg-yellow-500 text-black' : ''}>
                {results[play.result_level]}
            </Badge>
            <span class="text-2xl font-bold tabular-nums">{play.score.toLocaleString()}</span>
        </div>
    </header>

    <div class="flex flex-wrap gap-1">
        {#each songs as entry, index (index)}
            <Button
                variant={song === index ? 'default' : 'outline'}
                size="sm"
                disabled={index >= play.songs_played}
                onclick={() => {
                    song = index;
                    time = 0;
                }}
            >
                {index + 1}. {entry.title}
            </Button>
        {/each}
    </div>

    <!-- For staff it stays at the top of the screen, so the lane and its time stay in view beside the charts below. -->
    <div class={moderation ? 'sticky top-0 z-40 -my-2 border-b bg-background/95 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/80' : ''}>
        <Deferred data="replays">
            {#snippet fallback()}
                <Skeleton class="h-[190px] rounded-lg" />
            {/snippet}
            {#if replays?.[song]}
                {#key song}
                    <ReplayPlayer replay={replays[song]!} bind:time />
                {/key}
            {:else}
                <p class="rounded-lg border p-6 text-center text-sm text-muted-foreground">This song has no replay.</p>
            {/if}
        </Deferred>
    </div>

    <section class="rounded-lg border bg-card p-4">
        <h2 class="font-semibold">Requirements at this moment</h2>
        <Deferred data="progress">
            {#snippet fallback()}
                <Skeleton class="mt-3 h-24 rounded" />
            {/snippet}
            <ul class="mt-3 flex flex-col gap-3">
                {#each course.requirements.filter((requirement) => requirement.red[0] !== 0) as requirement (requirement.type)}
                    {@const current = value(requirement.type, now)}
                    {@const target = requirement.red[0]}
                    {@const gold = requirement.gold[0]}
                    {@const broken = now !== null && brokenBy(requirement, now)}
                    {@const met = !limit(requirement.type) && current >= target}
                    {@const breach = brokenAt(requirement)}
                    <li>
                        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                            <span class="font-medium">
                                {NAMES[requirement.type] ?? requirement.type}
                                <span class="font-normal text-muted-foreground">
                                    {limit(requirement.type) ? 'under' : 'at least'} {target.toLocaleString()}{#if gold !== 0}, gold {gold.toLocaleString()}{/if}
                                </span>
                            </span>
                            <span class="tabular-nums font-semibold {broken ? 'text-destructive' : met ? 'text-primary' : ''}">
                                {current.toLocaleString()}{requirement.type === 'soul_gauge' ? '%' : ''}
                                {#if broken}· failed{:else if met}· met{/if}
                            </span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded bg-muted">
                            <div
                                class="h-full transition-[width] duration-100 {broken ? 'bg-destructive' : 'bg-primary'}"
                                style="width: {Math.min((current / target) * 100, 100)}%"
                            ></div>
                        </div>
                        {#if breach}
                            <button
                                type="button"
                                class="mt-1 text-xs text-destructive underline"
                                onclick={() => seek(breach)}
                            >
                                Broke in song {breach[0] + 1} at {clock(breach[1])}
                            </button>
                        {/if}
                    </li>
                {/each}
            </ul>
            {#if progress === null}
                <p class="mt-2 text-sm text-muted-foreground">Not scored yet.</p>
            {/if}
        </Deferred>
    </section>

    <Deferred data="stats">
        {#snippet fallback()}
            <Skeleton class="h-[86px] rounded-lg" />
        {/snippet}
        {#if stats?.[song]}
            {@const shown = stats[song]!}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                {#each [['Average timing', shown.mean_offset === null ? '—' : `${signed(shown.mean_offset)} ms`], ['Early / late hits', `${shown.early} / ${shown.late}`], ['Drumroll speed', shown.roll_speed ? `${shown.roll_speed.average} hits/s` : '—'], ['Fastest drumroll', shown.roll_speed ? `${shown.roll_speed.peak} hits/s` : '—']] as [label, figure] (label)}
                    <div class="rounded-lg border bg-card p-4">
                        <div class="text-sm text-muted-foreground">{label}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums">{figure}</div>
                    </div>
                {/each}
            </div>
        {/if}
    </Deferred>

    {#if moderation}
        <section class="flex flex-col gap-4 rounded-xl border border-amber-500/40 bg-amber-500/5 p-5">
            <div class="flex flex-wrap items-center gap-2">
                <Gavel class="size-5 text-amber-600 dark:text-amber-400" />
                <h2 class="font-semibold">Moderation</h2>
                <span class="text-xs text-muted-foreground">Staff only. Never share what is here with players.</span>
                {#if moderation.hidden_at}
                    <Badge variant="secondary" class="border-0 bg-red-500/15 text-red-600 dark:text-red-400">hidden {new Date(moderation.hidden_at).toLocaleDateString()}</Badge>
                {/if}
                {#if moderation.open_reports > 0}
                    <Badge variant="secondary" class="border-0 bg-red-500/15 text-red-600 dark:text-red-400">{moderation.open_reports} open report{moderation.open_reports === 1 ? '' : 's'}</Badge>
                {/if}
                {#each moderation.sanctions as sanction (sanction)}
                    <Badge variant="secondary" class="border-0 bg-red-500/15 text-red-600 dark:text-red-400">{sanction}</Badge>
                {/each}
                <div class="ml-auto flex flex-wrap gap-2">
                    <Link href={toUrl(moderationPlayers.show({ ...taikoParam, player: moderation.baid }))} class={buttonVariants({ variant: 'outline', size: 'sm' })}>Player · sanctions</Link>
                    {#if moderation.can_act}
                        {#if moderation.open_reports > 0}
                            <Button size="sm" variant="outline" onclick={() => ask('Dismiss the open reports on this run? Reason (optional):', 'dismissReports')}>Dismiss reports</Button>
                        {/if}
                        {#if moderation.hidden_at}
                            <Button size="sm" variant="outline" onclick={() => ask('Restore this run? Reason (optional):', 'restore')}>Restore run</Button>
                        {:else}
                            <Button size="sm" variant="destructive" onclick={() => ask('Hide this run from every board. Reason:', 'hide', true)}>Hide run</Button>
                        {/if}
                    {/if}
                </div>
            </div>
            {#if moderation.hidden_reason}
                <p class="text-sm text-muted-foreground">Hidden because: {moderation.hidden_reason}</p>
            {/if}
            <Deferred data="inspections">
                {#snippet fallback()}
                    <Skeleton class="h-64 rounded-lg" />
                {/snippet}
                {#if inspections?.[song]}
                    {#key song}
                        <PlayInspection inspection={inspections[song]!} bind:time />
                    {/key}
                {:else}
                    <p class="text-sm text-muted-foreground">No timeline yet: the run has not been scored (app:rescore-waddamburo-dan).</p>
                {/if}
            </Deferred>
        </section>
    {/if}

    <section class="overflow-x-auto rounded-lg border bg-card p-4">
        <h2 class="font-semibold">Songs</h2>
        <table class="mt-2 w-full text-sm tabular-nums">
            <thead class="text-left text-muted-foreground">
                <tr><th class="font-medium">Song</th><th class="text-right font-medium">Good</th><th class="text-right font-medium">OK</th><th class="text-right font-medium">Bad</th><th class="text-right font-medium">Rolls</th></tr>
            </thead>
            <tbody>
                {#each songs as entry, index (index)}
                    <tr class={index >= play.songs_played ? 'text-muted-foreground' : ''}>
                        <td class="py-0.5">{entry.title}{#if index >= play.songs_played} (not reached){/if}</td>
                        <td class="text-right">{entry.great}</td>
                        <td class="text-right">{entry.good}</td>
                        <td class="text-right">{entry.miss}</td>
                        <td class="text-right">{entry.rolls}</td>
                    </tr>
                {/each}
                <tr class="border-t font-semibold">
                    <td class="py-0.5">Total, max combo {play.max_combo}, gauge {play.gauge_percent}%</td>
                    <td class="text-right">{play.great}</td>
                    <td class="text-right">{play.good}</td>
                    <td class="text-right">{play.miss}</td>
                    <td class="text-right">{play.rolls}</td>
                </tr>
            </tbody>
        </table>
    </section>

    {#if report}
        {#if report.reported}
            <p class="text-sm text-muted-foreground">You reported this run.</p>
        {:else if !reporting}
            <Button variant="outline" size="sm" class="self-start" onclick={() => (reporting = true)}>
                <Flag class="size-4" /> Report this run
            </Button>
        {:else}
            <Form {...reportRun.form({ ...taikoParam, id: play.id })} class="flex flex-col gap-2 rounded-lg border bg-card p-4" onSuccess={() => (reporting = false)}>
                {#snippet children({ errors, processing })}
                    <select name="reason" required class="rounded border bg-background p-2 text-sm">
                        {#each report.reasons as reason (reason.value)}
                            <option value={reason.value}>{reason.label}</option>
                        {/each}
                    </select>
                    <InputError message={errors.reason} />
                    <textarea name="comment" maxlength="500" rows="2" placeholder="What did you notice? (optional)" class="rounded border bg-background p-2 text-sm"></textarea>
                    <InputError message={errors.comment} />
                    <Button type="submit" size="sm" class="self-start" disabled={processing}>Send report</Button>
                {/snippet}
            </Form>
        {/if}
    {/if}
</div>
