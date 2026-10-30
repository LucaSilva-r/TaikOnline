<script module lang="ts">
    /** What the replay player draws (WaddamburoPlayController's replay prop, from the play's timeline). Times in ms. */
    export type Replay = {
        duration: number;
        notes: {
            t: number;
            kind: 'don' | 'ka' | 'big_don' | 'big_ka';
            result?: 'great' | 'good' | 'miss';
            judged_at?: number;
            /** 譜面分岐: the route the note belongs to (absent: every route). */
            branch?: Route;
        }[];
        long_notes: {
            start: number;
            end: number;
            kind: 'roll' | 'big_roll' | 'balloon' | 'kusudama';
            required: number;
            hits: number[];
            branch?: Route;
        }[];
        bars: number[];
        gogo: { t: number; on: boolean }[];
        /**
         * 譜面分岐 charts: each check (t, its yellow bar line, a measure before start, where the route it
         * picked begins). Absent for charts without branches.
         */
        branches?: { t: number; start: number; route: Route }[];
        /** The drum pad each input hit: 0 left Don, 1 right Don, 2 left Ka, 3 right Ka. */
        inputs: { t: number; action: number }[];
    };

    export type Route = 'normal' | 'expert' | 'master';
</script>

<script lang="ts">
    import Pause from 'lucide-svelte/icons/pause';
    import Play from 'lucide-svelte/icons/play';
    import RotateCcw from 'lucide-svelte/icons/rotate-ccw';
    import { onMount } from 'svelte';
    import { Button } from '@/components/ui/button';

    // time: the playhead (ms), bindable so the page's charts follow it and seek it.
    let { replay, time = $bindable(0) }: { replay: Replay; time?: number } =
        $props();

    // A plain lane, drawn on a canvas: notes scroll to the judgement circle and leave when the engine judged them.
    const laneHeight = 120;
    const drumX = 60;
    const judgeX = 160;
    const pixelsPerMs = 0.45;
    const colors = {
        lane: '#232323',
        gogo: '#3a2414',
        // The game's lane under each route: 玄人 blue-grey, 達人 purple.
        expert: '#22344a',
        master: '#3d2448',
        check: '#f5d300',
        bar: '#5a5a5a',
        don: '#e8452c',
        ka: '#4aa3c8',
        roll: '#f2b800',
        balloon: '#f07b1d',
        ring: '#f5f5f5',
        great: '#f5a300',
        good: '#f5f5f5',
        miss: '#5b8def',
    };
    const resultLabels = { great: 'Good', good: 'Ok', miss: 'Bad' } as const;
    const routeLabels = {
        normal: 'Normal 普通',
        expert: 'Expert 玄人',
        master: 'Master 達人',
    } as const;
    const checkTimes = $derived(
        new Set((replay.branches ?? []).map((check) => check.t)),
    );

    /** The route the last check at or before now picked (what the lane shows). */
    function currentRoute(now: number): Route {
        return (
            (replay.branches ?? []).findLast((check) => check.t <= now)
                ?.route ?? 'normal'
        );
    }

    /** The route played at chart time at, as known at now (a section not checked yet follows the route being played). */
    function routeAt(at: number, now: number): Route {
        return (
            (replay.branches ?? []).findLast(
                (check) => check.t <= now && check.start <= at,
            )?.route ?? 'normal'
        );
    }

    /** Whether a note of route branch shows at now (off-route notes are neither judged nor drawn). */
    function onRoute(
        branch: Route | undefined,
        at: number,
        now: number,
    ): boolean {
        return branch === undefined || branch === routeAt(at, now);
    }
    // As Waddamburo's own replay offers them (ReviewClock.Speeds): down to a twentieth, for rolls.
    const rates = [0.05, 0.1, 0.25, 0.5, 0.6, 0.7, 0.8, 0.9, 1, 1.25, 1.5, 2];

    const start = $derived(
        Math.min(
            replay.notes[0]?.t ?? 0,
            replay.long_notes[0]?.start ?? Infinity,
        ) - 2000,
    );
    // The judgements in the order they happened, with the combo after each.
    const judgements = $derived.by(() => {
        let combo = 0;
        return replay.notes
            .filter(
                (note) =>
                    note.result !== undefined && note.judged_at !== undefined,
            )
            .map((note) => ({
                at: note.judged_at as number,
                result: note.result as 'great' | 'good' | 'miss',
            }))
            .sort((a, b) => a.at - b.at)
            .map((judgement) => {
                combo = judgement.result === 'miss' ? 0 : combo + 1;
                return { ...judgement, combo };
            });
    });
    const rollHits = $derived(
        replay.long_notes
            .filter((note) => note.kind === 'roll' || note.kind === 'big_roll')
            .flatMap((note) => note.hits)
            .sort((a, b) => a - b),
    );

    let canvas: HTMLCanvasElement | undefined = $state();
    let container: HTMLDivElement | undefined = $state();
    let playing = $state(false);
    let rate = $state(1);
    let canvasWidth = $state(800);

    $effect(() => {
        time = start;
    });

    /** How many sorted values are at or before time. */
    function countUntil(values: number[], until: number): number {
        let low = 0;
        let high = values.length;
        while (low < high) {
            const middle = (low + high) >> 1;
            if (values[middle] <= until) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }
        return low;
    }

    const judgedTimes = $derived(judgements.map((judgement) => judgement.at));
    const inputTimes = $derived(replay.inputs.map((input) => input.t));
    const counters = $derived.by(() => {
        const done = countUntil(judgedTimes, time);
        const counts = { great: 0, good: 0, miss: 0 };
        for (let index = 0; index < done; index++) {
            counts[judgements[index].result]++;
        }
        return {
            ...counts,
            combo: done > 0 ? judgements[done - 1].combo : 0,
            rolls: countUntil(rollHits, time),
            last: done > 0 ? judgements[done - 1] : null,
        };
    });

    function draw(): void {
        if (!canvas) {
            return;
        }
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }
        const ratio = window.devicePixelRatio || 1;
        const width = canvasWidth;
        canvas.width = width * ratio;
        canvas.height = laneHeight * ratio;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        const middle = laneHeight / 2;
        const x = (at: number): number => judgeX + (at - time) * pixelsPerMs;
        const visible = (from: number, to: number): boolean =>
            x(to) > -60 && x(from) < width + 60;

        // Lane, coloured by the 譜面分岐 route and tinted during go-go time.
        const gogo =
            replay.gogo.filter((point) => point.t <= time).at(-1)?.on ?? false;
        const route = currentRoute(time);
        context.fillStyle = route === 'normal' ? colors.lane : colors[route];
        context.fillRect(0, 0, width, laneHeight);
        if (gogo) {
            context.globalAlpha = route === 'normal' ? 1 : 0.5;
            context.fillStyle = colors.gogo;
            context.fillRect(0, 0, width, laneHeight);
            context.globalAlpha = 1;
        }
        // Bar lines; a branch check's is yellow.
        for (const bar of replay.bars) {
            if (visible(bar, bar)) {
                const check = checkTimes.has(bar);
                context.strokeStyle = check ? colors.check : colors.bar;
                context.lineWidth = check ? 3 : 1;
                context.beginPath();
                context.moveTo(x(bar), 8);
                context.lineTo(x(bar), laneHeight - 8);
                context.stroke();
            }
        }
        if (replay.branches) {
            context.fillStyle = route === 'normal' ? '#9a9a9a' : '#ffffff';
            context.font = 'bold 13px sans-serif';
            context.textAlign = 'right';
            context.fillText(routeLabels[route], width - 10, 18);
        }

        // Judgement circle.
        context.strokeStyle = colors.ring;
        context.globalAlpha = 0.5;
        context.lineWidth = 3;
        context.beginPath();
        context.arc(judgeX, middle, 26, 0, Math.PI * 2);
        context.stroke();
        context.globalAlpha = 1;

        // Rolls and balloons.
        for (const note of replay.long_notes) {
            if (
                !visible(note.start, note.end) ||
                note.end < time - 300 ||
                !onRoute(note.branch, note.start, time)
            ) {
                continue;
            }
            const big = note.kind === 'big_roll' || note.kind === 'kusudama';
            const radius = big ? 28 : 18;
            const hits = countUntil(note.hits, time);
            if (note.kind === 'roll' || note.kind === 'big_roll') {
                const from = Math.max(
                    x(note.start),
                    time >= note.start ? judgeX : -Infinity,
                );
                context.fillStyle = colors.roll;
                context.beginPath();
                context.roundRect(
                    from - radius,
                    middle - radius,
                    Math.max(0, x(note.end) - from) + radius * 2,
                    radius * 2,
                    radius,
                );
                context.fill();
            } else {
                // A balloon waits at the judgement circle until it pops or its time is up.
                const popped = note.required > 0 && hits >= note.required;
                if (popped && time > note.start) {
                    continue;
                }
                const at =
                    time >= note.start && time <= note.end
                        ? judgeX
                        : x(note.start);
                context.fillStyle = colors.balloon;
                context.beginPath();
                context.arc(at, middle, radius, 0, Math.PI * 2);
                context.fill();
                if (time >= note.start) {
                    context.fillStyle = '#ffffff';
                    context.font = 'bold 14px sans-serif';
                    context.textAlign = 'center';
                    context.fillText(
                        String(Math.max(0, note.required - hits)),
                        at,
                        middle + 5,
                    );
                }
            }
        }

        // Notes, latest first so earlier ones draw on top; a hit note leaves the lane, a missed one scrolls on.
        for (let index = replay.notes.length - 1; index >= 0; index--) {
            const note = replay.notes[index];
            if (
                !visible(note.t, note.t) ||
                !onRoute(note.branch, note.t, time)
            ) {
                continue;
            }
            const judged =
                note.judged_at !== undefined && note.judged_at <= time;
            if (judged && note.result !== 'miss') {
                continue;
            }
            const big = note.kind === 'big_don' || note.kind === 'big_ka';
            context.globalAlpha = judged ? 0.35 : 1;
            context.fillStyle = note.kind.endsWith('don')
                ? colors.don
                : colors.ka;
            context.strokeStyle = '#ffffff';
            context.lineWidth = 3;
            context.beginPath();
            context.arc(x(note.t), middle, big ? 28 : 19, 0, Math.PI * 2);
            context.fill();
            context.stroke();
            context.globalAlpha = 1;
        }

        // The drum, left of the lane: its pads light up for a moment when hit (Don the face, Ka the rim).
        context.fillStyle = '#161616';
        context.fillRect(0, 0, judgeX - 40, laneHeight);
        const lit = [false, false, false, false];
        for (
            let index = countUntil(inputTimes, time) - 1;
            index >= 0 && replay.inputs[index].t > time - 110;
            index--
        ) {
            lit[replay.inputs[index].action] = true;
        }
        const pad = (
            left: boolean,
            radius: number,
            color: string,
            on: boolean,
        ): void => {
            context.fillStyle = on ? color : '#3a3a3a';
            context.beginPath();
            context.moveTo(drumX, middle);
            context.arc(
                drumX,
                middle,
                radius,
                left ? Math.PI / 2 : -Math.PI / 2,
                left ? Math.PI * 1.5 : Math.PI / 2,
            );
            context.closePath();
            context.fill();
        };
        pad(true, 42, colors.ka, lit[2]);
        pad(false, 42, colors.ka, lit[3]);
        pad(true, 32, colors.don, lit[0]);
        pad(false, 32, colors.don, lit[1]);
        context.strokeStyle = '#161616';
        context.lineWidth = 3;
        context.beginPath();
        context.moveTo(drumX, middle - 43);
        context.lineTo(drumX, middle + 43);
        context.stroke();
        context.beginPath();
        context.arc(drumX, middle, 32, 0, Math.PI * 2);
        context.stroke();

        // The latest judgement, shown for a moment above the circle.
        const last = counters.last;
        if (last && time - last.at < 350) {
            context.fillStyle = colors[last.result];
            context.font = 'bold 18px sans-serif';
            context.textAlign = 'center';
            context.fillText(resultLabels[last.result], judgeX, 20);
        }
    }

    onMount(() => {
        const observer = new ResizeObserver(() => {
            canvasWidth = container?.clientWidth ?? 800;
        });
        if (container) {
            observer.observe(container);
        }
        let previous = performance.now();
        let frame = requestAnimationFrame(function tick(now) {
            if (playing) {
                time = Math.min(
                    replay.duration,
                    time + (now - previous) * rate,
                );
                if (time >= replay.duration) {
                    playing = false;
                }
            }
            previous = now;
            draw();
            frame = requestAnimationFrame(tick);
        });

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
        };
    });

    // Space plays or pauses from anywhere on the page, unless a field or a button has the keyboard.
    function spaceKey(event: KeyboardEvent): void {
        const target = event.target as HTMLElement | null;
        if (
            event.code !== 'Space' ||
            event.repeat ||
            target?.closest(
                'input, textarea, select, button, [contenteditable="true"], [role="button"]',
            )
        ) {
            return;
        }
        event.preventDefault();
        toggle();
    }

    function toggle(): void {
        if (time >= replay.duration) {
            time = start;
        }
        playing = !playing;
    }

    function clock(at: number): string {
        const total = Math.max(0, at / 1000);
        return `${Math.floor(total / 60)}:${Math.floor(total % 60)
            .toString()
            .padStart(2, '0')}`;
    }
</script>

<svelte:window onkeydown={spaceKey} />

<div class="flex flex-col gap-3">
    <div bind:this={container} class="overflow-hidden rounded-lg border">
        <canvas
            bind:this={canvas}
            style:width="{canvasWidth}px"
            style:height="{laneHeight}px"
            class="block"
            aria-label="Replay of the play"
        ></canvas>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <Button
            size="sm"
            variant="outline"
            onclick={toggle}
            aria-label={playing ? 'Pause' : 'Play'}
        >
            {#if playing}
                <Pause class="size-4" />
            {:else}
                <Play class="size-4" />
            {/if}
        </Button>
        <Button
            size="sm"
            variant="ghost"
            onclick={() => {
                time = start;
                playing = false;
            }}
            aria-label="Back to the start"
        >
            <RotateCcw class="size-4" />
        </Button>
        <input
            type="range"
            min={start}
            max={replay.duration}
            step="10"
            bind:value={time}
            class="min-w-40 flex-1 accent-[var(--taiko-accent)]"
            aria-label="Seek"
        />
        <span class="w-20 text-right text-sm tabular-nums text-muted-foreground"
            >{clock(time)} / {clock(replay.duration)}</span
        >
        <select
            bind:value={rate}
            class="h-8 rounded-md border bg-background px-2 text-sm"
            aria-label="Playback speed"
        >
            {#each rates as option (option)}
                <option value={option}>{option}×</option>
            {/each}
        </select>
    </div>

    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm tabular-nums">
        <span>Good <b class="text-orange-500">{counters.great}</b></span>
        <span>Ok <b>{counters.good}</b></span>
        <span>Bad <b class="text-sky-500">{counters.miss}</b></span>
        <span>Combo <b>{counters.combo}</b></span>
        <span>Drumroll <b>{counters.rolls}</b></span>
    </div>
</div>
