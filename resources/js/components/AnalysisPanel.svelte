<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import OctagonAlert from 'lucide-svelte/icons/octagon-alert';
    import TriangleAlert from 'lucide-svelte/icons/triangle-alert';
    import { toUrl } from '@/lib/utils';
    import { show as danPlayShow } from '@/routes/dan-plays';
    import { show as playShow } from '@/routes/plays';

    /**
     * Draws what the private analyzer sends: its findings, numbers and limits all come in the analysis
     * (admins only), so this public code holds none of its checks.
     */
    type Metric = {
        label: string;
        value: number;
        unit: string;
        review: number | null;
        strong: number | null;
        lower_is_worse: boolean;
        status: 'ok' | 'review' | 'strong';
        hint: string;
    };

    type Copy = {
        play: string;
        baid: number;
        kind?: string;
        correlation: number;
    };

    type Song = {
        findings?: { flag: string; title: string; detail: string }[];
        metrics?: Metric[];
        copy_of?: Copy | null;
    };

    type Analysis = Song & {
        songs?: (Song & { position: number; flag: string })[];
    };

    let {
        flag,
        analysis = null,
        songTitles = [],
        song = 0,
    }: {
        flag: string | null;
        analysis?: Record<string, unknown> | null;
        songTitles?: string[];
        /** A dan run's song being looked at. */
        song?: number;
    } = $props();

    const parsed = $derived(analysis as Analysis | null);
    const currentSong = $derived(
        parsed?.songs?.find((entry) => entry.position === song),
    );
    // So a flag on another song is not missed while looking at this one.
    const otherFlagged = $derived(
        (parsed?.songs ?? []).filter(
            (entry) => entry.position !== song && entry.flag !== 'none',
        ),
    );

    const tones: Record<
        string,
        { text: string; fill: string; border: string; label: string }
    > = {
        strong: {
            text: 'text-red-700 dark:text-red-400',
            fill: 'bg-red-600 dark:bg-red-500',
            border: 'border-red-500/40 bg-red-500/10',
            label: 'Strong',
        },
        review: {
            text: 'text-amber-700 dark:text-amber-400',
            fill: 'bg-amber-500',
            border: 'border-amber-500/40 bg-amber-500/10',
            label: 'Review',
        },
        ok: {
            text: 'text-muted-foreground',
            fill: 'bg-muted-foreground/50',
            border: '',
            label: 'Within limits',
        },
    };

    const formatter = new Intl.NumberFormat('en-US', {
        maximumFractionDigits: 2,
    });

    // The scale leaves room past the furthest of the value and its limits.
    function scale(metric: Metric): number {
        return (
            Math.max(metric.value, metric.review ?? 0, metric.strong ?? 0) *
                1.2 || 1
        );
    }

    function percent(value: number, metric: Metric): number {
        return Math.min(100, (value / scale(metric)) * 100);
    }

    function limits(metric: Metric): string {
        const side = metric.lower_is_worse ? 'under' : 'from';
        const parts = [
            metric.review === null
                ? null
                : `review ${side} ${formatter.format(metric.review)}`,
            metric.strong === null
                ? null
                : `strong ${side} ${formatter.format(metric.strong)}`,
        ];

        return parts.filter(Boolean).join(' · ');
    }

    function copyHref(copy: Copy): string {
        const route = copy.kind === 'dan' ? danPlayShow : playShow;

        return toUrl(route({ taikoVersion: 'waddamburo', id: copy.play }));
    }
</script>

{#snippet tick(value: number | null, metric: Metric, tone: string)}
    {#if value !== null}
        <span
            class="absolute -top-0.5 h-3 w-0.5 rounded-full {tone}"
            style:left="{percent(value, metric)}%"
            aria-hidden="true"
        ></span>
    {/if}
{/snippet}

{#snippet details(song: Song)}
    {#if song.findings?.length}
        <ul class="grid gap-2 sm:grid-cols-2">
            {#each song.findings as finding (finding.title)}
                {@const tone = tones[finding.flag] ?? tones.review}
                <li class="flex gap-2 rounded-md border p-3 {tone.border}">
                    {#if finding.flag === 'strong'}
                        <OctagonAlert
                            class="mt-0.5 size-4 shrink-0 {tone.text}"
                        />
                    {:else}
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 {tone.text}"
                        />
                    {/if}
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {finding.title}
                            <span class="ml-1 text-xs font-normal {tone.text}"
                                >{tone.label}</span
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {finding.detail}
                        </p>
                    </div>
                </li>
            {/each}
        </ul>
    {/if}
    {#if song.copy_of}
        <p class="text-sm">
            Closest match:
            <Link href={copyHref(song.copy_of)} class="underline"
                >this play by player {song.copy_of.baid}</Link
            >
            <span class="text-muted-foreground"
                >(correlation {formatter.format(
                    song.copy_of.correlation,
                )})</span
            >
        </p>
    {/if}
    {#if song.metrics?.length}
        <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            {#each song.metrics as metric (metric.label)}
                {@const tone = tones[metric.status] ?? tones.ok}
                <div title={metric.hint}>
                    <div
                        class="flex items-baseline justify-between gap-2 text-sm"
                    >
                        <span class="min-w-0 truncate">{metric.label}</span>
                        <span class="shrink-0 font-medium tabular-nums"
                            >{formatter.format(metric.value)}{metric.unit
                                ? ` ${metric.unit}`
                                : ''}</span
                        >
                    </div>
                    <div class="relative mt-1.5 h-2 rounded-full bg-muted">
                        <div
                            class="h-2 rounded-full {tone.fill}"
                            style:width="{percent(metric.value, metric)}%"
                        ></div>
                        {@render tick(metric.review, metric, 'bg-amber-500')}
                        {@render tick(
                            metric.strong,
                            metric,
                            'bg-red-600 dark:bg-red-500',
                        )}
                    </div>
                    <div class="mt-1 flex justify-between gap-2 text-xs">
                        <span class={tone.text}>{tone.label}</span>
                        <span class="text-muted-foreground"
                            >{limits(metric)}</span
                        >
                    </div>
                </div>
            {/each}
        </div>
    {/if}
{/snippet}

{#if flag && flag !== 'none'}
    {@const tone = tones[flag] ?? tones.review}
    <div class="flex items-center gap-2 text-sm font-medium {tone.text}">
        {#if flag === 'strong'}
            <OctagonAlert class="size-4" />
        {:else}
            <TriangleAlert class="size-4" />
        {/if}
        Flagged by the analyzer: {tone.label.toLowerCase()}
    </div>
{/if}

{#if parsed}
    <div class="flex flex-col gap-4 rounded-lg border bg-card p-4">
        {#if parsed.songs}
            {#each otherFlagged as other (other.position)}
                <p class="text-sm {(tones[other.flag] ?? tones.review).text}">
                    Also flagged on
                    {songTitles[other.position] ??
                        `song ${other.position + 1}`}:
                    {(tones[other.flag] ?? tones.review).label.toLowerCase()}
                </p>
            {/each}
            {#if currentSong}
                {#if currentSong.flag === 'none'}
                    <p class="text-sm text-muted-foreground">
                        Nothing flagged on this song.
                    </p>
                {/if}
                {@render details(currentSong)}
            {:else}
                <p class="text-sm text-muted-foreground">
                    The run did not reach this song.
                </p>
            {/if}
        {:else}
            {#if !parsed.findings?.length}
                <p class="text-sm text-muted-foreground">
                    Nothing flagged on this play.
                </p>
            {/if}
            {@render details(parsed)}
        {/if}
        <details class="text-xs text-muted-foreground">
            <summary class="cursor-pointer">Raw analysis</summary>
            <pre
                class="mt-2 overflow-x-auto rounded-md bg-muted p-3">{JSON.stringify(
                    parsed,
                    null,
                    2,
                )}</pre>
        </details>
    </div>
{/if}
