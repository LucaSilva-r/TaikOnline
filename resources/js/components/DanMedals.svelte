<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import { Switch } from 'bits-ui';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as danPlayShow } from '@/routes/dan-plays';
    import { update as updateDanRank } from '@/routes/dan-rank';

    export type DisplayDanRank = {
        series: string;
        label: string;
        rank: number;
        gold: boolean;
        perfect: boolean;
    };

    export type DanCourse = {
        level: number;
        name: string;
        label: string | null;
        title: string;
        run: string | null;
        best: number | null;
        perfect: boolean;
    };
    export type DanSeries = {
        id: string;
        name: string;
        ranks_total: number;
        ranks_passed: number;
        ranks_gold: number;
        ranks_perfect: number;
        challenges_total: number;
        challenges_passed: number;
        top_rank: string | null;
        top_perfect: boolean;
        courses: DanCourse[];
    };

    let {
        series,
        selected = null,
        canChoose = false,
    }: {
        series: DanSeries[];
        selected?: DisplayDanRank | null;
        canChoose?: boolean;
    } = $props();

    const form = useForm<{ series: string | null }>({ series: null });
    const switchId = $props.id();
    let pendingSeries = $state<string | null | undefined>(undefined);
    const displayedSeries = $derived(
        pendingSeries === undefined ? selected?.series : pendingSeries,
    );
    const selectedSeries = $derived(
        series.find((entry) => entry.id === selected?.series)?.name,
    );

    function displayRank(seriesId: string | null) {
        if (form.processing) {
            return;
        }

        form.clearErrors();
        form.series = seriesId;
        pendingSeries = seriesId;
        form.patch(updateDanRank.url(taikoRouteParam()), {
            preserveScroll: true,
            onFinish: () => {
                pendingSeries = undefined;
            },
        });
    }

    let openId = $state<string | null>(null);
    const open = $derived(series.find((entry) => entry.id === openId) ?? null);
    const ranks = $derived(
        open?.courses.filter((course) => course.label !== null) ?? [],
    );
    const challenges = $derived(
        open?.courses.filter((course) => course.label === null) ?? [],
    );

    const RADIUS = 46;
    const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

    const played = (entry: DanSeries): boolean =>
        entry.courses.some((course) => course.best !== null);
    const percent = (entry: DanSeries): number =>
        entry.ranks_total === 0
            ? 0
            : Math.round((entry.ranks_passed / entry.ranks_total) * 100);
    const allGold = (entry: DanSeries): boolean =>
        entry.ranks_total > 0 && entry.ranks_gold === entry.ranks_total;
    const cardClass = (best: number | null, perfect: boolean): string =>
        perfect
            ? 'dan-perfect-course'
            : best === 2
              ? 'border-yellow-500 bg-yellow-500/20'
              : best === 1
                ? 'border-primary bg-primary/15'
                : best === 0
                  ? 'border-dashed'
                  : 'opacity-50';
    const stateOf = (best: number | null, perfect: boolean): string =>
        perfect
            ? 'Perfect (all Great)'
            : best === 2
              ? 'Gold'
              : best === 1
                ? 'Passed'
                : best === 0
                  ? 'Failed'
                  : 'Not played';
</script>

<div class="grid grid-cols-3 gap-x-2 gap-y-5 p-4 sm:grid-cols-5">
    {#each series as entry (entry.id)}
        {@const colour = played(entry)
            ? taikoVersionAccent(entry.id).swatch
            : 'hsl(0 0% 45%)'}
        <button
            type="button"
            class="group flex flex-col items-center gap-1.5 rounded-lg p-1 text-center transition hover:bg-accent/40 focus-visible:outline-2 {openId ===
            entry.id
                ? 'bg-accent/40'
                : ''}"
            aria-expanded={openId === entry.id}
            onclick={() => (openId = openId === entry.id ? null : entry.id)}
        >
            <span
                class="relative block size-24 transition group-hover:scale-105 {played(
                    entry,
                )
                    ? ''
                    : 'opacity-40'}"
            >
                <AppLogoIcon
                    class="absolute inset-[6%] size-[88%]"
                    accent={colour}
                />
                <svg
                    viewBox="0 0 100 100"
                    class="absolute inset-0 -rotate-90"
                    aria-hidden="true"
                >
                    {#if entry.top_perfect}
                        <defs>
                            <linearGradient
                                id={`${switchId}-${entry.id}-perfect`}
                                x1="0%"
                                y1="0%"
                                x2="100%"
                                y2="100%"
                            >
                                <stop offset="0%" stop-color="#fda4af" />
                                <stop offset="25%" stop-color="#fde68a" />
                                <stop offset="50%" stop-color="#86efac" />
                                <stop offset="75%" stop-color="#67e8f9" />
                                <stop offset="100%" stop-color="#c4b5fd" />
                            </linearGradient>
                        </defs>
                    {/if}
                    <circle
                        cx="50"
                        cy="50"
                        r={RADIUS}
                        fill="none"
                        stroke="currentColor"
                        stroke-width="4"
                        class="text-muted"
                    />
                    <circle
                        cx="50"
                        cy="50"
                        r={RADIUS}
                        fill="none"
                        stroke={entry.top_perfect
                            ? `url(#${switchId}-${entry.id}-perfect)`
                            : allGold(entry)
                              ? '#f5c518'
                              : colour}
                        stroke-width="4"
                        stroke-linecap="round"
                        stroke-dasharray={CIRCUMFERENCE}
                        stroke-dashoffset={CIRCUMFERENCE *
                            (1 - percent(entry) / 100)}
                    />
                </svg>
                {#if percent(entry) > 0}
                    <span
                        class="absolute -top-1 left-1/2 -translate-x-1/2 rounded-full border bg-background px-1.5 text-2xs font-bold tabular-nums"
                    >
                        {percent(entry)}%
                    </span>
                {/if}
            </span>
            <span class="text-sm font-semibold leading-tight">{entry.name}</span
            >
            <span
                class="text-xs text-muted-foreground leading-tight"
                class:dan-perfect-text={entry.top_perfect}
            >
                {entry.top_rank ??
                    (played(entry) ? 'No rank yet' : 'Not played')}
                {#if entry.top_perfect}
                    · Perfect{/if}
            </span>
        </button>
    {/each}
</div>

{#if open}
    <div class="border-t p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex items-center gap-3">
                <h3 class="font-semibold">{open.name}</h3>
                {#if canChoose}
                    <div class="flex items-center gap-2.5">
                        <Switch.Root
                            id={switchId}
                            checked={displayedSeries === open.id}
                            onCheckedChange={(checked) =>
                                displayRank(checked ? open.id : null)}
                            disabled={form.processing || !open.top_rank}
                            aria-describedby={`${switchId}-hint`}
                            aria-label={`Display highest ${open.name} rank on Taikonline and in Waddamburo`}
                            class="peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent bg-muted-foreground/35 shadow-xs transition-colors outline-none data-[state=checked]:bg-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Switch.Thumb
                                class="pointer-events-none block size-5 rounded-full bg-white shadow-sm transition-transform data-[state=checked]:translate-x-5 data-[state=unchecked]:translate-x-0"
                            />
                        </Switch.Root>
                        <label
                            for={switchId}
                            class="cursor-pointer text-sm font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-50"
                            >Display rank</label
                        >
                    </div>
                {/if}
            </div>
            <span class="text-sm tabular-nums text-muted-foreground">
                {open.ranks_passed}/{open.ranks_total} ranks, {open.ranks_gold} gold
                {#if open.ranks_perfect > 0}<span class="dan-perfect-text">
                        · {open.ranks_perfect} perfect</span
                    >{/if}
                {#if open.challenges_total > 0}
                    · {open.challenges_passed}/{open.challenges_total} challenges
                {/if}
            </span>
        </div>
        {#if selected}
            <div
                class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
            >
                <span
                    >Displayed: {selectedSeries ?? selected.series} · {selected.label}{selected.perfect
                        ? ' (Perfect)'
                        : selected.gold
                          ? ' (Gold)'
                          : ''}</span
                >
            </div>
        {/if}
        {#if canChoose}
            <p
                id={`${switchId}-hint`}
                class="mt-2 text-xs text-muted-foreground"
            >
                Displays your highest cleared rank in this series on Taikonline
                and in Waddamburo. Restart Waddamburo after changing it.
            </p>
            {#if form.errors.series}
                <p class="mt-2 text-sm text-destructive" role="alert">
                    {form.errors.series}
                </p>
            {/if}
        {/if}
        <ul class="mt-3 grid grid-cols-3 gap-1.5 sm:grid-cols-5 lg:grid-cols-7">
            {#each ranks as course (course.level)}
                <li>
                    <svelte:element
                        this={course.run ? 'a' : 'div'}
                        href={course.run
                            ? toUrl(
                                  danPlayShow({
                                      ...taikoRouteParam(),
                                      id: course.run,
                                  }),
                              )
                            : undefined}
                        title="{course.name}: {stateOf(
                            course.best,
                            course.perfect,
                        )}"
                        class="flex flex-col items-center rounded border px-1 py-1 {course.run
                            ? 'hover:bg-accent'
                            : ''} {cardClass(course.best, course.perfect)}"
                    >
                        <span
                            class="text-sm font-semibold"
                            class:dan-perfect-text={course.perfect}
                            >{course.label}</span
                        >
                        <span
                            class="text-2xs text-muted-foreground"
                            class:dan-perfect-text={course.perfect}
                            >{course.name}</span
                        >
                    </svelte:element>
                </li>
            {/each}
        </ul>
        {#if challenges.length > 0}
            <h4 class="mt-4 text-sm font-semibold">Challenges</h4>
            <ul class="mt-2 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                {#each challenges as course (course.level)}
                    <li>
                        <svelte:element
                            this={course.run ? 'a' : 'div'}
                            href={course.run
                                ? toUrl(
                                      danPlayShow({
                                          ...taikoRouteParam(),
                                          id: course.run,
                                      }),
                                  )
                                : undefined}
                            title="{course.name}: {stateOf(
                                course.best,
                                course.perfect,
                            )}"
                            class="block rounded border px-2 py-1 {course.run
                                ? 'hover:bg-accent'
                                : ''} {cardClass(course.best, course.perfect)}"
                        >
                            <span
                                class="block truncate text-sm font-medium"
                                class:dan-perfect-text={course.perfect}
                                >{course.title}</span
                            >
                            <span
                                class="block truncate text-2xs text-muted-foreground"
                                >{course.name}</span
                            >
                        </svelte:element>
                    </li>
                {/each}
            </ul>
        {/if}
    </div>
{/if}
