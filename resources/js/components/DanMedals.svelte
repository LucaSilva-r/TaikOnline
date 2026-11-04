<script lang="ts">
    import { useForm } from '@inertiajs/svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as danPlayShow } from '@/routes/dan-plays';
    import { update as updateDanRank } from '@/routes/dan-rank';

    export type DisplayDanRank = {
        series: string;
        label: string;
        rank: number;
        gold: boolean;
    };

    export type DanCourse = {
        level: number;
        name: string;
        label: string | null;
        title: string;
        run: string | null;
        best: number | null;
    };
    export type DanSeries = {
        id: string;
        name: string;
        ranks_total: number;
        ranks_passed: number;
        ranks_gold: number;
        challenges_total: number;
        challenges_passed: number;
        top_rank: string | null;
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
    const selectedSeries = $derived(
        series.find((entry) => entry.id === selected?.series)?.name,
    );

    function displayRank(seriesId: string | null) {
        form.clearErrors();
        form.series = seriesId;
        form.patch(updateDanRank.url(taikoRouteParam()), {
            preserveScroll: true,
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
    const cardClass = (best: number | null): string =>
        best === 2
            ? 'border-yellow-500 bg-yellow-500/20'
            : best === 1
              ? 'border-primary bg-primary/15'
              : best === 0
                ? 'border-dashed'
                : 'opacity-50';
    const stateOf = (best: number | null): string =>
        best === 2
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
                        stroke={allGold(entry) ? '#f5c518' : colour}
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
            <span class="text-xs text-muted-foreground leading-tight">
                {entry.top_rank ??
                    (played(entry) ? 'No rank yet' : 'Not played')}
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
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={form.processing ||
                            !open.top_rank ||
                            selected?.series === open.id}
                        onclick={() => displayRank(open.id)}
                    >
                        {selected?.series === open.id
                            ? 'Displayed'
                            : 'Display rank'}
                    </Button>
                {/if}
            </div>
            <span class="text-sm tabular-nums text-muted-foreground">
                {open.ranks_passed}/{open.ranks_total} ranks, {open.ranks_gold} gold
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
                    >Displayed: {selectedSeries ?? selected.series} · {selected.label}{selected.gold
                        ? ' (Gold)'
                        : ''}</span
                >
                {#if canChoose}
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={form.processing}
                        onclick={() => displayRank(null)}>Hide rank</Button
                    >
                {/if}
            </div>
        {/if}
        {#if canChoose}
            <p class="mt-2 text-xs text-muted-foreground">
                Displays your highest cleared rank in this series. Restart
                Waddamburo after changing it.
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
                        title="{course.name}: {stateOf(course.best)}"
                        class="flex flex-col items-center rounded border px-1 py-1 {course.run
                            ? 'hover:bg-accent'
                            : ''} {cardClass(course.best)}"
                    >
                        <span class="text-sm font-semibold">{course.label}</span
                        >
                        <span class="text-2xs text-muted-foreground"
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
                            title="{course.name}: {stateOf(course.best)}"
                            class="block rounded border px-2 py-1 {course.run
                                ? 'hover:bg-accent'
                                : ''} {cardClass(course.best)}"
                        >
                            <span class="block truncate text-sm font-medium"
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
