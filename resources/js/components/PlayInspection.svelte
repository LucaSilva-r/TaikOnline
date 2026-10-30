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
            branch?: string;
        }[];
        long_notes: {
            start: number;
            end: number;
            kind: string;
            required: number;
            hits: number[];
            branch?: string;
        }[];
        /** 譜面分岐 charts: each check's yellow bar line, where its route begins and the route picked. */
        branches?: { t: number; start: number; route: string }[];
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
    // time: the replay's playhead (bound to the page's replay player); null without one.
    let {
        inspection,
        time = $bindable(null),
    }: { inspection: Inspection; time?: number | null } = $props();

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
    const pads = ['Left Don', 'Right Don', 'Left Ka', 'Right Ka'];
    const padShort = ['L Don', 'R Don', 'L Ka', 'R Ka'];
    const rollKinds: Record<string, string> = {
        roll: 'Drumroll',
        big_roll: 'Big drumroll',
        balloon: 'Balloon',
        kusudama: 'Kusudama',
    };

    const notes = $derived(inspection.notes);
    // Rolls and balloons on the routes played (a 譜面分岐 chart lists every route's).
    const longNotes = $derived(
        inspection.long_notes.filter(
            (note) =>
                note.branch === undefined ||
                note.branch ===
                    ((inspection.branches ?? []).findLast(
                        (check) => check.start <= note.start,
                    )?.route ?? 'normal'),
        ),
    );
    const first = $derived(notes.length > 0 ? notes[0].t : 0);
    const last = $derived(
        Math.max(
            notes.length > 0 ? notes[notes.length - 1].t : 0,
            ...longNotes.map((note) => note.end),
        ),
    );
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
        const bins = Array.from(
            { length: (limit * 2) / binSize },
            (_, index) => ({
                from: -limit + index * binSize,
                count: 0,
            }),
        );
        for (const offset of offsets) {
            const index = Math.floor((offset + limit) / binSize);
            if (index >= 0 && index < bins.length) {
                bins[index].count++;
            }
        }
        return {
            limit,
            bins,
            max: Math.max(1, ...bins.map((bin) => bin.count)),
        };
    });

    // Every hit on a roll, balloon or kusudama (capped ones too: the replay keeps them).
    const rollInputs = $derived(
        inspection.inputs.filter((input) =>
            ['roll', 'capped', 'pop'].includes(input.hit),
        ),
    );

    // Drumroll hits: the time since the same hand's previous hit on the same roll.
    const rollIntervals = $derived.by(() => {
        const points: { t: number; interval: number; hand: number }[] = [];
        const previous = new Map<number, number>();
        let rollEnd = -Infinity;
        for (const input of rollInputs) {
            const roll = longNotes.find(
                (note) =>
                    input.t >= note.start - 50 && input.t <= note.end + 50,
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
        Math.max(
            100,
            ...rollIntervals.map((point) => Math.min(point.interval, 400)),
        ),
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

    // Per roll, as WdbPlay::rollSpeed measures it (the moderation columns): over stretches of 10 hits or
    // more, the scored hits' rate, the fastest pad's, and the fastest pad drummed with even gaps.
    const rate = (times: number[]): number | null =>
        times.length >= 10 && times[times.length - 1] > times[0]
            ? ((times.length - 1) * 1000) / (times[times.length - 1] - times[0])
            : null;
    const highest = (values: (number | null)[]): number | null =>
        values.reduce<number | null>(
            (best, value) =>
                value === null ? best : Math.max(best ?? 0, value),
            null,
        );
    const rolls = $derived(
        longNotes.map((note, index) => {
            const onIt = rollInputs.filter(
                (input) => input.t >= note.start && input.t <= note.end,
            );
            const scored = onIt.filter((input) => input.hit !== 'capped');
            const byPad = (list: typeof onIt) =>
                [0, 1, 2, 3].map((padIndex) =>
                    list
                        .filter((input) => input.action === padIndex)
                        .map((input) => input.t),
                );
            const steady = byPad(onIt).map((times) => {
                const gaps = times.slice(1).map((at, gap) => at - times[gap]);
                if (gaps.length < 9) {
                    return null;
                }
                const average =
                    gaps.reduce((sum, gap) => sum + gap, 0) / gaps.length;
                const spread = Math.sqrt(
                    gaps.reduce((sum, gap) => sum + (gap - average) ** 2, 0) /
                        gaps.length,
                );
                return average > 0 && spread < average / 5
                    ? 1000 / average
                    : null;
            });
            return {
                index,
                ...note,
                raw: onIt.length,
                scored: scored.length,
                rate: rate(scored.map((input) => input.t)),
                padRate: highest(byPad(scored).map(rate)),
                steady: highest(steady),
            };
        }),
    );

    function seconds(at: number): string {
        const total = Math.max(0, at / 1000);
        return `${Math.floor(total / 60)}:${(total % 60).toFixed(1).padStart(4, '0')}`;
    }

    function round(value: number, digits = 1): string {
        return value.toFixed(digits);
    }

    // The window the timeline charts show: the whole song, a range picked by dragging or zooming, or a
    // few seconds around the replay's playhead while following it.
    const followSpan = 8000;
    const minimumSpan = 500;
    let view = $state<{ from: number; to: number } | null>(null);
    let follow = $state(false);
    const domain = $derived.by(() => {
        if (follow && time !== null) {
            return { from: time - followSpan / 2, to: time + followSpan / 2 };
        }
        return view ?? { from: first, to: last };
    });
    const span = $derived(Math.max(1, domain.to - domain.from));
    const zoomed = $derived(follow || view !== null);
    const inView = (at: number): boolean =>
        at >= domain.from - 50 && at <= domain.to + 50;

    function x(at: number): number {
        return pad.left + ((at - domain.from) / span) * plotWidth;
    }

    function timeTicks(): number[] {
        const steps = [
            60_000, 30_000, 10_000, 5_000, 2_000, 1_000, 500, 250, 100,
        ];
        const step = steps.find((candidate) => span / candidate >= 4) ?? 100;
        const ticks: number[] = [];
        for (
            let tick = Math.ceil(domain.from / step) * step;
            tick <= domain.to;
            tick += step
        ) {
            ticks.push(tick);
        }
        return ticks;
    }

    function tickLabel(at: number): string {
        return span < 4000
            ? `${seconds(at).replace(/\.\d$/, '')}.${String(Math.round(at % 1000)).padStart(3, '0')}`
            : seconds(at);
    }

    function zoomTo(from: number, to: number): void {
        follow = false;
        const length = Math.max(minimumSpan, to - from);
        const middle = (from + to) / 2;
        const clampedFrom = Math.max(first - 1000, middle - length / 2);
        view = {
            from: clampedFrom,
            to: Math.min(last + 1000, clampedFrom + length),
        };
    }

    function resetView(): void {
        view = null;
        follow = false;
    }

    // Pointer on a timeline chart: right-drag across to zoom to that range; left-drag to move along the song
    // (the window when zoomed in, the replay when following it or on the whole song); a left click moves
    // the replay there; Ctrl + wheel zooms around the pointer, double-click shows the whole song.
    let drag = $state<{ svg: SVGSVGElement; from: number; to: number } | null>(
        null,
    );
    let pan: {
        svg: SVGSVGElement;
        startX: number;
        from: number;
        to: number;
        time: number | null;
        moved: boolean;
        clicked: number;
    } | null = null;

    function timeAt(svg: SVGSVGElement, clientX: number): number {
        const box = svg.getBoundingClientRect();
        const viewX = ((clientX - box.left) / box.width) * width;
        return domain.from + ((viewX - pad.left) / plotWidth) * span;
    }

    function pointerDown(event: PointerEvent): void {
        const svg = event.currentTarget as SVGSVGElement;
        if (event.button === 2) {
            svg.setPointerCapture(event.pointerId);
            const at = timeAt(svg, event.clientX);
            drag = { svg, from: at, to: at };
        } else if (event.button === 0) {
            svg.setPointerCapture(event.pointerId);
            pan = {
                svg,
                startX: event.clientX,
                from: domain.from,
                to: domain.to,
                time,
                moved: false,
                clicked: timeAt(svg, event.clientX),
            };
        }
    }

    function pointerMove(event: PointerEvent): void {
        if (drag) {
            drag = { ...drag, to: timeAt(drag.svg, event.clientX) };
            return;
        }
        if (!pan) {
            return;
        }
        const box = pan.svg.getBoundingClientRect();
        const pixels = event.clientX - pan.startX;
        pan.moved ||= Math.abs(pixels) > 4;
        if (!pan.moved) {
            return;
        }
        // Dragging right brings earlier moments into view, as on a map.
        const shift =
            ((pixels / box.width) * width * (pan.to - pan.from)) / plotWidth;
        if (zoomed && !follow) {
            view = { from: pan.from - shift, to: pan.to - shift };
        } else if (pan.time !== null) {
            time = Math.max(0, pan.time - shift);
        }
    }

    function pointerUp(): void {
        if (pan) {
            if (!pan.moved && time !== null) {
                time = Math.max(0, pan.clicked);
            }
            pan = null;
        }
        if (!drag) {
            return;
        }
        const [from, to] = [
            Math.min(drag.from, drag.to),
            Math.max(drag.from, drag.to),
        ];
        drag = null;
        if (x(to) - x(from) > 6) {
            zoomTo(from, to);
        }
    }

    function wheel(event: WheelEvent): void {
        if (!event.ctrlKey) {
            return;
        }
        event.preventDefault();
        const at = timeAt(event.currentTarget as SVGSVGElement, event.clientX);
        const factor = event.deltaY > 0 ? 1.25 : 0.8;
        const length = Math.min(
            last - first + 2000,
            Math.max(minimumSpan, span * factor),
        );
        const share = (at - domain.from) / span;
        zoomTo(at - share * length, at - share * length + length);
    }

    // The overview strip: the whole song, the window shown, the playhead; a click moves the window there.
    const overviewHeight = 36;
    const overviewX = (at: number): number =>
        pad.left + ((at - first) / Math.max(1, last - first)) * plotWidth;

    function overviewClick(event: MouseEvent): void {
        const svg = event.currentTarget as SVGSVGElement;
        const box = svg.getBoundingClientRect();
        const viewX = ((event.clientX - box.left) / box.width) * width;
        const at = first + ((viewX - pad.left) / plotWidth) * (last - first);
        const length = zoomed ? span : Math.min(followSpan * 2, last - first);
        zoomTo(at - length / 2, at + length / 2);
    }

    function overviewKey(event: KeyboardEvent): void {
        if (event.key === 'Escape') {
            resetView();
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const length = zoomed
                ? span
                : Math.min(followSpan * 2, last - first);
            const step = (event.key === 'ArrowLeft' ? -1 : 1) * length * 0.5;
            const from = zoomed ? domain.from : first;
            zoomTo(from + step, from + step + length);
        }
    }

    function showRoll(roll: { start: number; end: number }): void {
        zoomTo(roll.start - 400, roll.end + 400);
        if (time !== null) {
            time = roll.start - 400;
        }
    }

    const offsetY = (offset: number): number =>
        pad.top +
        plotHeight / 2 -
        (offset / inspection.windows.miss) * (plotHeight / 2);
    const accuracyY = (value: number): number =>
        pad.top + plotHeight - (value / 100) * plotHeight;
    const rollY = (interval: number): number =>
        pad.top +
        plotHeight -
        (Math.min(interval, rollMax) / rollMax) * plotHeight;
    const resultColor = (result: string | undefined): string =>
        results.find((item) => item.key === result)?.color ?? 'currentColor';

    // The per-pad strip under the drumroll chart, drawn once the window is a few seconds wide.
    const padStripHeight = 64;
    const padRowY = (padIndex: number): number => 8 + padIndex * 13;
</script>

{#snippet overlays()}
    <!-- The drag selection and the replay's playhead, over a timeline chart. -->
    {#if drag}
        <rect
            x={Math.min(x(drag.from), x(drag.to))}
            y={pad.top}
            width={Math.abs(x(drag.to) - x(drag.from))}
            height={plotHeight}
            class="fill-foreground/10 stroke-foreground/30"
        />
    {/if}
    {#if time !== null && inView(time)}
        <line
            x1={x(time)}
            x2={x(time)}
            y1={pad.top}
            y2={pad.top + plotHeight}
            stroke="var(--playhead)"
            stroke-width="2"
        />
    {/if}
{/snippet}

{#snippet axis()}
    {#each timeTicks() as tick (tick)}
        <text
            x={x(tick)}
            y={height - 6}
            text-anchor="middle"
            class="fill-muted-foreground text-[11px]">{tickLabel(tick)}</text
        >
    {/each}
{/snippet}

<div class="viz flex flex-col gap-5">
    <!-- Headline numbers -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {#each [{ label: 'Mean offset', value: `${round(mean)} ms` }, { label: 'Spread (σ)', value: `${round(deviation)} ms` }, { label: 'Unstable rate', value: round(deviation * 10, 0) }, { label: 'Hits on no note', value: String(ghostHits.length) }, { label: 'Capped roll hits', value: String(cappedHits) }, { label: 'Left-hand inputs', value: `${round(leftShare, 0)}%` }] as tile (tile.label)}
            <div class="rounded-lg border bg-card p-3">
                <div class="text-xs text-muted-foreground">{tile.label}</div>
                <div class="mt-1 text-lg font-semibold tabular-nums">
                    {tile.value}
                </div>
            </div>
        {/each}
    </div>

    <!-- The window the timeline charts show -->
    <div class="flex flex-col gap-2 rounded-lg border bg-card p-3">
        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span class="font-medium text-foreground"
                >{zoomed
                    ? `${seconds(domain.from)} – ${seconds(domain.to)}`
                    : 'Whole song'}</span
            >
            <span
                >Drag to move along the song, right-drag across a chart to zoom
                in, Ctrl + scroll to zoom, double-click for the whole song{time !==
                null
                    ? '; click to move the replay there, Space to play or pause'
                    : ''}.</span
            >
            <span class="ml-auto flex items-center gap-3">
                {#if time !== null}
                    <label class="inline-flex items-center gap-1.5">
                        <input type="checkbox" bind:checked={follow} />
                        Follow the replay
                    </label>
                {/if}
                {#if zoomed}
                    <button type="button" class="underline" onclick={resetView}
                        >Whole song</button
                    >
                {/if}
            </span>
        </div>
        <svg
            viewBox="0 0 {width} {overviewHeight}"
            class="w-full cursor-pointer"
            role="button"
            tabindex="0"
            aria-label="Where the charts are in the song: click to move there, arrow keys to step, Escape for the whole song"
            onclick={overviewClick}
            onkeydown={overviewKey}
        >
            <rect
                x={pad.left}
                y="4"
                width={plotWidth}
                height={overviewHeight - 8}
                rx="3"
                class="fill-muted"
            />
            {#each longNotes as note, index (index)}
                <rect
                    x={overviewX(note.start)}
                    y="8"
                    width={Math.max(
                        1.5,
                        overviewX(note.end) - overviewX(note.start),
                    )}
                    height={overviewHeight - 16}
                    fill="var(--hand-right)"
                    opacity="0.7"
                />
            {/each}
            {#each misses as note, index (index)}
                <line
                    x1={overviewX(note.t)}
                    x2={overviewX(note.t)}
                    y1="8"
                    y2={overviewHeight - 8}
                    stroke={resultColor('miss')}
                    stroke-width="1.5"
                />
            {/each}
            {#if zoomed}
                <rect
                    x={overviewX(Math.max(first, domain.from))}
                    y="2"
                    width={Math.max(
                        2,
                        overviewX(Math.min(last, domain.to)) -
                            overviewX(Math.max(first, domain.from)),
                    )}
                    height={overviewHeight - 4}
                    rx="3"
                    class="fill-foreground/10 stroke-foreground/60"
                />
            {/if}
            {#if time !== null}
                <line
                    x1={overviewX(time)}
                    x2={overviewX(time)}
                    y1="2"
                    y2={overviewHeight - 2}
                    stroke="var(--playhead)"
                    stroke-width="2"
                />
            {/if}
        </svg>
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
        <svg
            viewBox="0 0 {width} {height}"
            class="w-full touch-none select-none"
            role="img"
            aria-label="Hit offsets over time"
            onpointerdown={pointerDown}
            oncontextmenu={(event) => event.preventDefault()}
            onpointermove={pointerMove}
            onpointerup={pointerUp}
            onwheel={wheel}
            ondblclick={resetView}
        >
            <defs>
                <clipPath id="plot-timing"
                    ><rect
                        x={pad.left}
                        y="0"
                        width={plotWidth}
                        {height}
                    /></clipPath
                >
            </defs>
            <rect
                x={pad.left}
                y={offsetY(inspection.windows.great)}
                width={plotWidth}
                height={offsetY(-inspection.windows.great) -
                    offsetY(inspection.windows.great)}
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
                    class="fill-muted-foreground text-[11px]"
                    >{round(line, 0)}</text
                >
            {/each}
            {@render axis()}
            <g clip-path="url(#plot-timing)">
                {#each ghostHits.filter( (input) => inView(input.t) ) as input, index (index)}
                    <line
                        x1={x(input.t)}
                        x2={x(input.t)}
                        y1={pad.top + plotHeight - 8}
                        y2={pad.top + plotHeight}
                        class="stroke-muted-foreground"
                        stroke-width="2"
                        ><title>{seconds(input.t)} · hit on no note</title
                        ></line
                    >
                {/each}
                {#each misses.filter( (note) => inView(note.t) ) as note, index (index)}
                    <line
                        x1={x(note.t)}
                        x2={x(note.t)}
                        y1={pad.top}
                        y2={pad.top + 8}
                        stroke={resultColor('miss')}
                        stroke-width="2"
                        ><title>{seconds(note.t)} · Bad ({note.kind})</title
                        ></line
                    >
                {/each}
                {#each hits.filter( (note) => inView(note.t) ) as note, index (index)}
                    <circle
                        cx={x(note.t)}
                        cy={offsetY(note.offset as number)}
                        r={zoomed ? 4 : 3}
                        fill={resultColor(note.result)}
                        class="stroke-card"
                        stroke-width="1"
                        ><title
                            >{seconds(note.t)} · {note.kind} · {round(
                                note.offset as number,
                            )} ms · {note.result === 'great'
                                ? 'Good'
                                : 'Ok'}</title
                        ></circle
                    >
                {/each}
                {@render overlays()}
            </g>
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
            <svg
                viewBox="0 0 {width} {height}"
                class="w-full touch-none select-none"
                role="img"
                aria-label="Good rate over time"
                onpointerdown={pointerDown}
                oncontextmenu={(event) => event.preventDefault()}
                onpointermove={pointerMove}
                onpointerup={pointerUp}
                onwheel={wheel}
                ondblclick={resetView}
            >
                <defs>
                    <clipPath id="plot-accuracy"
                        ><rect
                            x={pad.left}
                            y="0"
                            width={plotWidth}
                            {height}
                        /></clipPath
                    >
                </defs>
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
                {@render axis()}
                <g clip-path="url(#plot-accuracy)">
                    <polyline
                        points={accuracy
                            .map(
                                (point) =>
                                    `${x(point.t)},${accuracyY(point.value)}`,
                            )
                            .join(' ')}
                        fill="none"
                        stroke="var(--result-great)"
                        stroke-width="2"
                        stroke-linejoin="round"
                    />
                    {@render overlays()}
                </g>
            </svg>
        </figure>

        <!-- Offset distribution -->
        <figure class="flex flex-col gap-2 rounded-lg border bg-card p-4">
            <figcaption class="flex flex-wrap items-baseline gap-x-4">
                <span class="font-medium">Hit offsets</span>
                <span class="text-xs text-muted-foreground"
                    >notes per {binSize} ms, early to late (the whole song)</span
                >
            </figcaption>
            <svg
                viewBox="0 0 {width} {height}"
                class="w-full"
                role="img"
                aria-label="Hit offset histogram"
            >
                {#each histogram.bins as bin, index (index)}
                    {@const barWidth = plotWidth / histogram.bins.length}
                    {@const barHeight =
                        (bin.count / histogram.max) * plotHeight}
                    {#if bin.count > 0}
                        <rect
                            x={pad.left + index * barWidth + 1}
                            y={pad.top + plotHeight - barHeight}
                            width={Math.max(1, barWidth - 2)}
                            height={barHeight}
                            rx="2"
                            fill="var(--result-great)"
                            ><title
                                >{bin.from} to {bin.from + binSize} ms · {bin.count}
                                notes</title
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
                        x={pad.left +
                            ((tick + histogram.limit) / (histogram.limit * 2)) *
                                plotWidth}
                        y={height - 6}
                        text-anchor="middle"
                        class="fill-muted-foreground text-[11px]"
                        >{tick} ms</text
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
            <svg
                viewBox="0 0 {width} {height}"
                class="w-full touch-none select-none"
                role="img"
                aria-label="Drumroll hit intervals"
                onpointerdown={pointerDown}
                oncontextmenu={(event) => event.preventDefault()}
                onpointermove={pointerMove}
                onpointerup={pointerUp}
                onwheel={wheel}
                ondblclick={resetView}
            >
                <defs>
                    <clipPath id="plot-rolls"
                        ><rect
                            x={pad.left}
                            y="0"
                            width={plotWidth}
                            {height}
                        /></clipPath
                    >
                </defs>
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
                        class="fill-muted-foreground text-[11px]"
                        >{round(line, 0)}</text
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
                {@render axis()}
                <g clip-path="url(#plot-rolls)">
                    {#each longNotes.filter((note) => inView(note.start) || inView(note.end)) as note, index (index)}
                        <rect
                            x={x(note.start)}
                            y={pad.top}
                            width={Math.max(1, x(note.end) - x(note.start))}
                            height={plotHeight}
                            class="fill-muted/60"
                        />
                    {/each}
                    {#each rollIntervals.filter( (point) => inView(point.t) ) as point, index (index)}
                        <circle
                            cx={x(point.t)}
                            cy={rollY(point.interval)}
                            r={zoomed ? 4 : 3}
                            fill={hands[point.hand].color}
                            class="stroke-card"
                            stroke-width="1"
                            ><title
                                >{seconds(point.t)} · {hands[point.hand].label} ·
                                {round(point.interval)} ms</title
                            ></circle
                        >
                    {/each}
                    {@render overlays()}
                </g>
            </svg>
            {#if zoomed && span <= 20_000}
                <!-- Every hit by pad: a macro shows as an even comb, a player's hands as ragged ones. -->
                <svg
                    viewBox="0 0 {width} {padStripHeight}"
                    class="w-full"
                    role="img"
                    aria-label="Drumroll hits by pad"
                >
                    {#each pads as label, padIndex (label)}
                        <text
                            x={pad.left - 6}
                            y={padRowY(padIndex) + 4}
                            text-anchor="end"
                            class="fill-muted-foreground text-[10px]"
                            >{padShort[padIndex]}</text
                        >
                        <line
                            x1={pad.left}
                            x2={width - pad.right}
                            y1={padRowY(padIndex)}
                            y2={padRowY(padIndex)}
                            class="stroke-border"
                        />
                    {/each}
                    <g clip-path="url(#plot-rolls)">
                        {#each rollInputs.filter( (input) => inView(input.t) ) as input, index (index)}
                            <line
                                x1={x(input.t)}
                                x2={x(input.t)}
                                y1={padRowY(input.action) - 5}
                                y2={padRowY(input.action) + 5}
                                stroke={hands[input.action % 2].color}
                                stroke-width="1.5"
                                opacity={input.hit === 'capped' ? 0.4 : 1}
                                ><title
                                    >{seconds(input.t)} · {pads[
                                        input.action
                                    ]}{input.hit === 'capped'
                                        ? ' · capped'
                                        : ''}</title
                                ></line
                            >
                        {/each}
                    </g>
                </svg>
            {/if}
        {/if}
        {#if rolls.length > 0}
            <!-- Every roll and balloon: click one to zoom the charts (and move the replay) there. -->
            <div class="max-h-72 overflow-y-auto rounded-md border">
                <table class="w-full text-xs tabular-nums">
                    <thead class="sticky top-0 bg-muted text-left">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">At</th>
                            <th class="px-2 py-1.5 font-medium">Kind</th>
                            <th
                                class="px-2 py-1.5 font-medium"
                                title="Hits that scored / every hit">Hits</th
                            >
                            <th
                                class="px-2 py-1.5 font-medium"
                                title="Scored hits a second">Speed</th
                            >
                            <th
                                class="px-2 py-1.5 font-medium"
                                title="Scored hits a second on the fastest pad"
                                >Per pad</th
                            >
                            <th
                                class="px-2 py-1.5 font-medium"
                                title="The fastest pad drummed with even gaps: a macro's mark"
                                >Steady</th
                            >
                        </tr>
                    </thead>
                    <tbody>
                        {#each rolls as roll (roll.index)}
                            <tr
                                class="cursor-pointer border-t hover:bg-muted/50 {inView(
                                    roll.start,
                                ) && zoomed
                                    ? 'bg-muted/40'
                                    : ''}"
                                onclick={() => showRoll(roll)}
                            >
                                <td class="px-2 py-1">{seconds(roll.start)}</td>
                                <td class="px-2 py-1"
                                    >{rollKinds[roll.kind] ??
                                        roll.kind}{roll.required > 0
                                        ? ` (${roll.required})`
                                        : ''}</td
                                >
                                <td class="px-2 py-1"
                                    >{roll.scored} / {roll.raw}</td
                                >
                                <td class="px-2 py-1"
                                    >{roll.rate === null
                                        ? '—'
                                        : `${round(roll.rate)}/s`}</td
                                >
                                <td class="px-2 py-1"
                                    >{roll.padRate === null
                                        ? '—'
                                        : `${round(roll.padRate)}/s`}</td
                                >
                                <td class="px-2 py-1"
                                    >{roll.steady === null
                                        ? '—'
                                        : `${round(roll.steady)}/s`}</td
                                >
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
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
        --playhead: #e5484d;
    }

    :global(.dark) .viz {
        --result-great: #d95926;
        --result-good: #199e70;
        --result-miss: #3987e5;
        --hand-left: #3987e5;
        --hand-right: #c98500;
        --playhead: #f2555a;
    }
</style>
