<script lang="ts">
    import { Deferred, Form, Link, router } from '@inertiajs/svelte';
    import Flag from 'lucide-svelte/icons/flag';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import ReplayPlayer, { type Replay } from '@/components/ReplayPlayer.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Skeleton } from '@/components/ui/skeleton';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import { report as reportRun } from '@/routes/dan-plays';
    import moderationRuns from '@/routes/mod/dan-plays';

    type Requirement = { type: string; red: number[]; gold: number[] };
    // Song, ms, gauge %, goods, oks, bads, combo, rolls, score.
    type Row = [number, number, number, number, number, number, number, number, number];

    let {
        play,
        course,
        player,
        songs,
        progress = null,
        replays = null,
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
        course: { name: string; label: string; requirements: Requirement[]; series: string[] };
        player: { user_id: number | null; name: string; avatar: string | null };
        songs: { title: string; course: number | null; level: number | null; great: number; good: number; miss: number; rolls: number }[];
        progress?: Row[] | null;
        replays?: (Replay | null)[] | null;
        report?: { reasons: { value: string; label: string }[]; reported: boolean } | null;
        moderation?: { baid: number; hidden_at: string | null; hidden_reason: string | null; open_reports: number } | null;
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

    // Where a limit broke in the run: the moment worth checking, whatever is being watched.
    function brokenAt(requirement: Requirement): Row | null {
        return limit(requirement.type) ? (rows.find((row) => value(requirement.type, row) >= requirement.red[0]) ?? null) : null;
    }

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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
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
                    {@const broken = limit(requirement.type) && current >= target}
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
                                onclick={() => {
                                    song = breach[0];
                                    time = breach[1];
                                }}
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

    <section class="rounded-lg border bg-card p-4">
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

    {#if moderation}
        <section class="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-4 text-sm">
            <span class="font-semibold">Moderation</span>
            {#if moderation.hidden_at}
                <Badge variant="outline">Hidden: {moderation.hidden_reason}</Badge>
                <Button variant="outline" size="sm" onclick={() => ask('Restore this run? Reason (optional):', 'restore')}>Restore</Button>
            {:else}
                <Button variant="outline" size="sm" onclick={() => ask('Hide this run. Reason:', 'hide', true)}>Hide</Button>
            {/if}
            {#if moderation.open_reports > 0}
                <span>{moderation.open_reports} open report{moderation.open_reports === 1 ? '' : 's'}</span>
                <Button variant="outline" size="sm" onclick={() => ask('Dismiss the open reports? Reason (optional):', 'dismissReports')}>Dismiss</Button>
            {/if}
        </section>
    {/if}
</div>
