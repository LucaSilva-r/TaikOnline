<script module lang="ts">
    /** A play's judgement timeline as the engine gives it (WaddamburoScorer::inspect, the binary's --inspect). Times in ms. */
    export type Inspection = {
        windows: { great: number; good: number; miss: number };
        duration: number;
        notes: {
            t: number;
            kind: string;
            hand?: boolean;
            result?: 'great' | 'good' | 'miss';
            offset?: number;
            judged_at?: number;
        }[];
        long_notes: {
            start: number;
            end: number;
            kind: string;
            required: number;
            hits: number[];
        }[];
        /** action: 0 left Don, 1 right Don, 2 left Ka, 3 right Ka. */
        inputs: {
            t: number;
            action: number;
            hit: 'note' | 'strong' | 'roll' | 'pop' | 'capped' | 'none';
            note?: number;
        }[];
    };
</script>

<script lang="ts">
    let { inspection }: { inspection: Inspection } = $props();

    // Plot frame (viewBox units; the SVG scales to its container's width).
    const width = 1000;
    const height = 220;
    const pad = { left: 44, right: 12, top: 12, bottom: 24 };
    const plotWidth = width - pad.left - pad.right;
    const plotHeight = height - pad.top - pad.bottom;
    const results = [
        { key: 'great', label: 'Good', color: 'var(--result-great)' },
        { key: 'good', label: 'Ok', color: 'var(--result-good)' },
        { key: 'miss', label: 'Bad', color: 'var(--result-miss)' },
    ] as const;
    const hands = [
        { key: 0, label: 'Left hand', color: 'var(--hand-left)' },
        { key: 1, label: 'Right hand', color: 'var(--hand-right)' },
    ] as const;

    const notes = $derived(inspection.notes);
    const first = $derived(notes.length > 0 ? notes[0].t : 0);
    const last = $derived(
        Math.max(
            notes.length > 0 ? notes[notes.length - 1].t : 0,
            ...inspection.long_notes.map((note) => note.end),
        ),
    );
    const span = $derived(Math.max(1, last - first));
    const hits = $derived(
        notes.filter(
            (note) => note.offset !== undefined && note.result !== 'miss',
        ),
    );
    const offsets = $derived(hits.map((note) => note.offset as number));
    const mean = $derived(
        offsets.length === 0
            ? 0
            : offsets.reduce((sum, value) => sum + value, 0) / offsets.length,
    );
    const deviation = $derived(
        offsets.length < 2
            ? 0
            : Math.sqrt(
                  offsets.reduce((sum, value) => sum + (value - mean) ** 2, 0) /
                      (offsets.length - 1),
              ),
    );
    // Inputs during the chart that hit nothing (before the first note and after the last do not count).
    const ghostHits = $derived(
        inspection.inputs.filter(
            (input) =>
                input.hit === 'none' &&
                input.t >= first - inspection.windows.miss &&
                input.t <= last + inspection.windows.miss,
        ),
    );
    const misses = $derived(notes.filter((note) => note.result === 'miss'));
    const cappedHits = $derived(
        inspection.inputs.filter((input) => input.hit === 'capped').length,
    );
    const leftShare = $derived(
        inspection.inputs.length === 0
            ? 0
            : (inspection.inputs.filter((input) => input.action % 2 === 0)
                  .length /
                  inspection.inputs.length) *
                  100,
    );

    // How the play went over the song: the Good share of the last 20 judged notes.
    const accuracy = $derived.by(() => {
        const window = 20;
        const judged = notes.filter((note) => note.result !== undefined);
        return judged.map((note, index) => {
            const from = Math.max(0, index - window + 1);
            const slice = judged.slice(from, index + 1);
            return {
                t: note.t,
                value:
                    (slice.filter((item) => item.result === 'great').length /
                        slice.length) *
                    100,
            };
        });
    });

    // Offset histogram: 2 ms bins across the Ok window.
    const binSize = 2;
    const histogram = $derived.by(() => {
        const limit = Math.ceil(inspection.windows.good / binSize) * binSize;
        const bins = Array.from({ length: (limit * 2) / binSize }, (_, index) => ({
            from: -limit + index * binSize,
            count: 0,
        }));
        for (const offset of offsets) {
            const index = Math.floor((offset + limit) / binSize);
            if (index >= 0 && index < bins.length) {
                bins[index].count++;
            }
        }
        return { limit, bins, max: Math.max(1, ...bins.map((bin) => bin.count)) };
    });

    // Drumroll hits: the time since the same hand's previous hit on the same roll.
    const rollIntervals = $derived.by(() => {
        const points: { t: number; interval: number; hand: number }[] = [];
        const previous = new Map<number, number>();
        let rollEnd = -Infinity;
        for (const input of inspection.inputs) {
            if (!['roll', 'capped', 'pop'].includes(input.hit)) {
                continue;
            }
            const roll = inspection.long_notes.find(
                (note) => input.t >= note.start - 50 && input.t <= note.end + 50,
            );
            if (roll && roll.end !== rollEnd) {
                previous.clear();
                rollEnd = roll.end;
            }
            const hand = input.action % 2;
            const before = previous.get(hand);
            if (before !== undefined) {
                points.push({ t: input.t, interval: input.t - before, hand });
            }
            previous.set(hand, input.t);
        }
        return points;
    });
    const rollMax = $derived(
        Math.max(100, ...rollIntervals.map((point) => Math.min(point.interval, 400))),
    );
    const handStats = $derived(
        hands.map((hand) => {
            const values = rollIntervals
                .filter((point) => point.hand === hand.key)
                .map((point) => point.interval);
            const average =
                values.length === 0
                    ? 0
                    : values.reduce((sum, value) => sum + value, 0) /
                      values.length;
            const spread =
                values.length < 2
                    ? 0
                    : Math.sqrt(
                          values.reduce(
                              (sum, value) => sum + (value - average) ** 2,
                              0,
                          ) /
                              (values.length - 1),
                      );
            return { ...hand, count: values.length, average, spread };
        }),
    );

    function x(time: number): number {
        return pad.left + ((time - first) / span) * plotWidth;
    }

    function seconds(time: number): string {
        const total = Math.max(0, time / 1000);
        return `${Math.floor(total / 60)}:${(total % 60).toFixed(1).padStart(4, '0')}`;
    }

    function timeTicks(): number[] {
        const step = span > 120_000 ? 30_000 : span > 40_000 ? 10_000 : 5_000;
        const ticks: number[] = [];
        for (let tick = Math.ceil(first / step) * step; tick <= last; tick += step) {
            ticks.push(tick);
        }
        return ticks;
    }

    function round(value: number, digits = 1): string {
        return value.toFixed(digits);
    }

    const offsetY = (offset: number): number =>
        pad.top +
        plotHeight / 2 -
        (offset / inspection.windows.miss) * (plotHeight / 2);
    const accuracyY = (value: number): number =>
        pad.top + plotHeight - (value / 100) * plotHeight;
    const rollY = (interval: number): number =>
        pad.top + plotHeight - (Math.min(interval, rollMax) / rollMax) * plotHeight;
    const resultColor = (result: string | undefined): string =>
        results.find((item) => item.key === result)?.color ?? 'currentColor';
</script>

<div class="viz flex flex-col gap-5">
    <!-- Headline numbers -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {#each [
            { label: 'Mean offset', value: `${round(mean)} ms` },
            { label: 'Spread (σ)', value: `${round(deviation)} ms` },
            { label: 'Unstable rate', value: round(deviation * 10, 0) },
            { label: 'Hits on no note', value: String(ghostHits.length) },
            { label: 'Capped roll hits', value: String(cappedHits) },
            { label: 'Left-hand inputs', value: `${round(leftShare, 0)}%` },
        ] as tile (tile.label)}
            <div class="rounded-lg border bg-card p-3">
                <div class="text-xs text-muted-foreground">{tile.label}</div>
                <div class="mt-1 text-lg font-semibold tabular-nums">
                    {tile.value}
                </div>
            </div>
        {/each}
    </div>

    <!-- Timing over the song -->
    <figure class="flex flex-col gap-2 rounded-lg border bg-card p-4">
        <figcaption class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <span class="font-medium">Hit timing over the song</span>
            <span class="text-xs text-muted-foreground"
                >ms early (below) or late (above); shaded: the Good window</span
            >
            <span class="ml-auto flex flex-wrap gap-3 text-xs">
                {#each results as result (result.key)}
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="size-2.5 rounded-full"
                            style:background={result.color}
                        ></span>
                        {result.label}
                    </span>
                {/each}
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2.5 w-0.5 bg-muted-foreground"></span>
                    Hit on no note
                </span>
            </span>
        </figcaption>
        <svg viewBox="0 0 {width} {height}" class="w-full" role="img" aria-label="Hit offsets over time">
            <rect
                x={pad.left}
                y={offsetY(inspection.windows.great)}
                width={plotWidth}
                height={offsetY(-inspection.windows.great) - offsetY(inspection.windows.great)}
                class="fill-muted"
            />
            {#each [-inspection.windows.good, 0, inspection.windows.good] as line (line)}
                <line
                    x1={pad.left}
                    x2={width - pad.right}
                    y1={offsetY(line)}
                    y2={offsetY(line)}
                    class="stroke-border"
                    stroke-dasharray={line === 0 ? undefined : '4 4'}
                />
                <text
                    x={pad.left - 6}
                    y={offsetY(line) + 4}
                    text-anchor="end"
                    class="fill-muted-foreground text-[11px]">{round(line, 0)}</text
                >
            {/each}
            {#each timeTicks() as tick (tick)}
                <text
                    x={x(tick)}
                    y={height - 6}
                    text-anchor="middle"
                    class="fill-muted-foreground text-[11px]">{seconds(tick)}</text
                >
            {/each}
            {#each ghostHits as input, index (index)}
                <line
                    x1={x(input.t)}
                    x2={x(input.t)}
                    y1={pad.top + plotHeight - 8}
                    y2={pad.top + plotHeight}
                    class="stroke-muted-foreground"
                    stroke-width="2"
                    ><title>{seconds(input.t)} · hit on no note</title></line
                >
            {/each}
            {#each misses as note, index (index)}
                <line
                    x1={x(note.t)}
                    x2={x(note.t)}
                    y1={pad.top}
                    y2={pad.top + 8}
                    stroke={resultColor('miss')}
                    stroke-width="2"
                    ><title>{seconds(note.t)} · Bad ({note.kind})</title></line
                >
            {/each}
            {#each hits as note, index (index)}
                <circle
                    cx={x(note.t)}
                    cy={offsetY(note.offset as number)}
                    r="3"
                    fill={resultColor(note.result)}
                    class="stroke-card"
                    stroke-width="1"
                    ><title
                        >{seconds(note.t)} · {note.kind} · {round(note.offset as number)} ms · {note.result === 'great'
                            ? 'Good'
                            : 'Ok'}</title
                    ></circle
                >
            {/each}
        </svg>
    </figure>

    <div class="grid gap-5 lg:grid-cols-2">
        <!-- How the play went -->
        <figure class="flex flex-col gap-2 rounded-lg border bg-card p-4">
            <figcaption class="flex flex-wrap items-baseline gap-x-4">
                <span class="font-medium">Good rate over the song</span>
                <span class="text-xs text-muted-foreground"
                    >share of Good over the last 20 notes</span
                >
            </figcaption>
            <svg viewBox="0 0 {width} {height}" class="w-full" role="img" aria-label="Good rate over time">
                {#each [0, 50, 100] as line (line)}
                    <line
                        x1={pad.left}
                        x2={width - pad.right}
                        y1={accuracyY(line)}
                        y2={accuracyY(line)}
                        class="stroke-border"
                        stroke-dasharray={line === 0 ? undefined : '4 4'}
                    />
                    <text
                        x={pad.left - 6}
                        y={accuracyY(line) + 4}
                        text-anchor="end"
                        class="fill-muted-foreground text-[11px]">{line}%</text
                    >
                {/each}
                {#each timeTicks() as tick (tick)}
                    <text
                        x={x(tick)}
                        y={height - 6}
                        text-anchor="middle"
                        class="fill-muted-foreground text-[11px]">{seconds(tick)}</text
                    >
                {/each}
                <polyline
                    points={accuracy
                        .map((point) => `${x(point.t)},${accuracyY(point.value)}`)
                        .join(' ')}
                    fill="none"
                    stroke="var(--result-great)"
                    stroke-width="2"
                    stroke-linejoin="round"
                />
            </svg>
        </figure>

        <!-- Offset distribution -->
        <figure class="flex flex-col gap-2 rounded-lg border bg-card p-4">
            <figcaption class="flex flex-wrap items-baseline gap-x-4">
                <span class="font-medium">Hit offsets</span>
                <span class="text-xs text-muted-foreground"
                    >notes per {binSize} ms, early to late</span
                >
            </figcaption>
            <svg viewBox="0 0 {width} {height}" class="w-full" role="img" aria-label="Hit offset histogram">
                {#each histogram.bins as bin, index (index)}
                    {@const barWidth = plotWidth / histogram.bins.length}
                    {@const barHeight = (bin.count / histogram.max) * plotHeight}
                    {#if bin.count > 0}
                        <rect
                            x={pad.left + index * barWidth + 1}
                            y={pad.top + plotHeight - barHeight}
                            width={Math.max(1, barWidth - 2)}
                            height={barHeight}
                            rx="2"
                            fill="var(--result-great)"
                            ><title
                                >{bin.from} to {bin.from + binSize} ms · {bin.count} notes</title
                            ></rect
                        >
                    {/if}
                {/each}
                <line
                    x1={pad.left}
                    x2={width - pad.right}
                    y1={pad.top + plotHeight}
                    y2={pad.top + plotHeight}
                    class="stroke-border"
                />
                {#each [-histogram.limit, 0, histogram.limit] as tick (tick)}
                    <text
                        x={pad.left + ((tick + histogram.limit) / (histogram.limit * 2)) * plotWidth}
                        y={height - 6}
                        text-anchor="middle"
                        class="fill-muted-foreground text-[11px]">{tick} ms</text
                    >
                {/each}
            </svg>
        </figure>
    </div>

    <!-- Drumrolls -->
    <figure class="flex flex-col gap-2 rounded-lg border bg-card p-4">
        <figcaption class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <span class="font-medium">Drumroll hit intervals</span>
            <span class="text-xs text-muted-foreground"
                >ms since the same hand's previous hit on the roll; dashed: the
                60 hits/s cap</span
            >
            <span class="ml-auto flex flex-wrap gap-3 text-xs">
                {#each handStats as hand (hand.key)}
                    <span class="inline-flex items-center gap-1.5">
                        <span
                            class="size-2.5 rounded-full"
                            style:background={hand.color}
                        ></span>
                        {hand.label}: {hand.count} hits, {round(hand.average)} ± {round(
                            hand.spread,
                        )} ms
                    </span>
                {/each}
            </span>
        </figcaption>
        {#if rollIntervals.length === 0}
            <p class="text-sm text-muted-foreground">No drumroll hits.</p>
        {:else}
            <svg viewBox="0 0 {width} {height}" class="w-full" role="img" aria-label="Drumroll hit intervals">
                {#each [0, rollMax / 2, rollMax] as line (line)}
                    <line
                        x1={pad.left}
                        x2={width - pad.right}
                        y1={rollY(line)}
                        y2={rollY(line)}
                        class="stroke-border"
                    />
                    <text
                        x={pad.left - 6}
                        y={rollY(line) + 4}
                        text-anchor="end"
                        class="fill-muted-foreground text-[11px]">{round(line, 0)}</text
                    >
                {/each}
                <line
                    x1={pad.left}
                    x2={width - pad.right}
                    y1={rollY(1000 / 60)}
                    y2={rollY(1000 / 60)}
                    class="stroke-muted-foreground"
                    stroke-dasharray="4 4"
                />
                {#each timeTicks() as tick (tick)}
                    <text
                        x={x(tick)}
                        y={height - 6}
                        text-anchor="middle"
                        class="fill-muted-foreground text-[11px]">{seconds(tick)}</text
                    >
                {/each}
                {#each rollIntervals as point, index (index)}
                    <circle
                        cx={x(point.t)}
                        cy={rollY(point.interval)}
                        r="3"
                        fill={hands[point.hand].color}
                        class="stroke-card"
                        stroke-width="1"
                        ><title
                            >{seconds(point.t)} · {hands[point.hand].label} · {round(point.interval)} ms</title
                        ></circle
                    >
                {/each}
            </svg>
        {/if}
    </figure>
</div>

<style>
    .viz {
        --result-great: #eb6834;
        --result-good: #1baf7a;
        --result-miss: #2a78d6;
        --hand-left: #2a78d6;
        --hand-right: #eda100;
    }

    :global(.dark) .viz {
        --result-great: #d95926;
        --result-good: #199e70;
        --result-miss: #3987e5;
        --hand-left: #3987e5;
        --hand-right: #c98500;
    }
</style>
