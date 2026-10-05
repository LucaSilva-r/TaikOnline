<script module lang="ts">
    import waddamburoChartIndexRoutes from '@/routes/admin/waddamburo-charts';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Waddamburo charts',
                href: waddamburoChartIndexRoutes.index(indexTaikoRouteParam()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import { SvelteSet } from 'svelte/reactivity';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import waddamburoChartRoutes from '@/routes/admin/waddamburo-charts';
    import waddamburoSongRoutes from '@/routes/admin/waddamburo-songs';
    import songRoutes from '@/routes/songs';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Chart = {
        id: number;
        sha256: string;
        course: number | null;
        level: number | null;
        difficulty: string | null;
        sources: string[];
        has_notes: boolean;
        ranked: boolean;
        plays_count: number;
    };
    type Song = {
        id: number;
        title: string | null;
        title_en: string | null;
        subtitle: string | null;
        family: string;
        song_key: string;
        chart_count: number;
        ranked_count: number;
        plays_count: number;
        sources: string[];
        charts: Chart[];
    };
    type Page<T> = {
        data: T[];
        total: number;
        from: number | null;
        to: number | null;
        current_page: number;
        last_page: number;
    };
    type Filters = {
        q: string;
        source: string | null;
        ranked: 'all' | 'none' | 'some' | null;
        sort: string;
        direction: 'asc' | 'desc';
        page?: number;
    };
    let {
        songs,
        sources,
        unlinkedCharts,
        filters,
    }: {
        songs: Page<Song>;
        sources: string[];
        unlinkedCharts: number;
        filters: Filters;
    } = $props();

    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
    const columns = [
        { key: 'title', label: 'Song' },
        { key: null, label: 'Sources' },
        { key: null, label: 'Courses' },
        { key: 'plays', label: 'Plays' },
        { key: 'ranked', label: 'Ranked' },
    ];
    const open = new SvelteSet<number>();

    // Every visit carries all filters (the pager too), so paging never drops one.
    function visit(changes: Partial<Filters>): void {
        const query = Object.fromEntries(
            Object.entries({ ...filters, page: undefined, ...changes }).filter(
                ([, value]) =>
                    value !== null && value !== undefined && value !== '',
            ),
        );
        router.get(waddamburoChartRoutes.index(taikoRouteParam()).url, query, {
            preserveState: true,
        });
    }

    function sortBy(key: string): void {
        visit({
            sort: key,
            direction:
                filters.sort === key && filters.direction === 'desc'
                    ? 'asc'
                    : 'desc',
        });
    }

    function toggle(id: number): void {
        if (open.has(id)) open.delete(id);
        else open.add(id);
    }

    function courseLabel(chart: Chart): string {
        const course =
            chart.course === null
                ? ''
                : (courses[chart.course] ?? String(chart.course));
        return chart.difficulty ?? course;
    }
</script>

<AppHead title="Waddamburo charts" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo charts</h1>
        <p class="text-sm text-muted-foreground">
            Every Waddamburo song and its charts (one per hash; stock and
            Nijiiro share most). Leaderboards show all charts; only ranked ones
            count towards the Waddamburo rankings. Ranking recomputes the
            players' standings.
            {#if unlinkedCharts > 0}{unlinkedCharts} played charts have no song yet
                (their notes were never uploaded).{/if}
        </p>
    </div>

    <form
        class="flex flex-wrap gap-2"
        onsubmit={(event) => {
            event.preventDefault();
            visit({
                q: String(new FormData(event.currentTarget).get('q') ?? ''),
            });
        }}
    >
        <Input
            name="q"
            value={filters.q}
            placeholder="Title, song id or hash prefix"
            class="max-w-sm"
        />
        <select
            class="h-9 rounded-md border bg-background px-3 text-sm"
            value={filters.source ?? ''}
            onchange={(event) =>
                visit({ source: event.currentTarget.value || null })}
        >
            <option value="">All sources</option>
            {#each sources as source (source)}<option value={source}
                    >{source}</option
                >{/each}
        </select>
        <select
            class="h-9 rounded-md border bg-background px-3 text-sm"
            value={filters.ranked ?? ''}
            onchange={(event) =>
                visit({
                    ranked: (event.currentTarget.value ||
                        null) as Filters['ranked'],
                })}
        >
            <option value="">Any ranking</option>
            <option value="all">Fully ranked</option>
            <option value="some">Partly ranked</option>
            <option value="none">Unranked</option>
        </select>
        <Button type="submit" variant="secondary">Search</Button>
    </form>

    <div class="overflow-x-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="w-8"></th>
                    {#each columns as column (column.label)}
                        <th class="px-3 py-2 font-medium">
                            {#if column.key}
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    onclick={() => sortBy(column.key!)}
                                >
                                    {column.label}
                                    {#if filters.sort === column.key}<span
                                            aria-hidden="true"
                                            >{filters.direction === 'asc'
                                                ? '▲'
                                                : '▼'}</span
                                        >{/if}
                                </button>
                            {:else}
                                {column.label}
                            {/if}
                        </th>
                    {/each}
                </tr>
            </thead>
            <tbody>
                {#each songs.data as song (song.id)}
                    <tr
                        class="cursor-pointer border-t hover:bg-muted/30"
                        onclick={() => toggle(song.id)}
                    >
                        <td class="px-2 py-2 text-muted-foreground">
                            <ChevronRight
                                class="size-4 transition-transform {open.has(
                                    song.id,
                                )
                                    ? 'rotate-90'
                                    : ''}"
                            />
                        </td>
                        <td class="max-w-0 px-3 py-2">
                            <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
                            <span onclick={(event) => event.stopPropagation()}>
                                <Link
                                    href={songRoutes.show({
                                        ...taikoRouteParam(),
                                        song: song.id,
                                    })}
                                    class="font-medium hover:underline"
                                    >{song.title ?? 'Untitled'}</Link
                                >
                            </span>
                            <div class="truncate text-xs text-muted-foreground">
                                {[
                                    song.title_en !== song.title
                                        ? song.title_en
                                        : null,
                                    song.subtitle,
                                    song.song_key,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {#each song.sources as source (source)}<Badge
                                    variant="secondary"
                                    class="mr-1">{source}</Badge
                                >{/each}
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {song.charts.map(courseLabel).join(', ')}
                        </td>
                        <td class="px-3 py-2 text-muted-foreground tabular-nums"
                            >{song.plays_count}</td
                        >
                        <td
                            class="px-3 py-2"
                            onclick={(event) => event.stopPropagation()}
                        >
                            <Form
                                {...waddamburoSongRoutes.update.form({
                                    ...taikoRouteParam(),
                                    song: song.id,
                                })}
                                options={{
                                    preserveScroll: true,
                                    preserveState: true,
                                }}
                            >
                                {#snippet children({ processing })}
                                    {@const all =
                                        song.ranked_count === song.chart_count}
                                    <input
                                        type="hidden"
                                        name="ranked"
                                        value={all ? '0' : '1'}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant={all ? 'default' : 'outline'}
                                        disabled={processing}
                                        title={all
                                            ? 'Unrank every chart'
                                            : 'Rank every chart'}
                                    >
                                        {all
                                            ? 'Ranked'
                                            : song.ranked_count > 0
                                              ? `${song.ranked_count}/${song.chart_count} ranked`
                                              : 'Rank'}
                                    </Button>
                                {/snippet}
                            </Form>
                        </td>
                    </tr>
                    {#if open.has(song.id)}
                        {#each song.charts as chart (chart.id)}
                            <tr class="border-t bg-muted/20">
                                <td></td>
                                <td class="max-w-0 py-1.5 pr-3 pl-8">
                                    <span class="font-medium"
                                        >{courseLabel(chart)}</span
                                    >{chart.level ? ` ★${chart.level}` : ''}
                                    {#if !chart.has_notes}<Badge
                                            variant="destructive"
                                            class="ml-1 h-5 px-1.5 text-[10px]"
                                            >no notes</Badge
                                        >{/if}
                                    <div
                                        class="truncate font-mono text-xs text-muted-foreground"
                                        title={chart.sha256}
                                    >
                                        {chart.sha256}
                                    </div>
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    {#each chart.sources as source (source)}<Badge
                                            variant="outline"
                                            class="mr-1">{source}</Badge
                                        >{/each}
                                </td>
                                <td></td>
                                <td
                                    class="px-3 py-1.5 text-muted-foreground tabular-nums"
                                    >{chart.plays_count}</td
                                >
                                <td class="px-3 py-1.5">
                                    <Form
                                        {...waddamburoChartRoutes.update.form({
                                            ...taikoRouteParam(),
                                            chart: chart.id,
                                        })}
                                        options={{
                                            preserveScroll: true,
                                            preserveState: true,
                                        }}
                                    >
                                        {#snippet children({ processing })}
                                            <input
                                                type="hidden"
                                                name="ranked"
                                                value={chart.ranked ? '0' : '1'}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant={chart.ranked
                                                    ? 'default'
                                                    : 'outline'}
                                                disabled={processing}
                                            >
                                                {chart.ranked
                                                    ? 'Ranked'
                                                    : 'Rank'}
                                            </Button>
                                        {/snippet}
                                    </Form>
                                </td>
                            </tr>
                        {/each}
                    {/if}
                {/each}
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between">
        <p class="text-sm text-muted-foreground">
            {#if songs.total > 0}Showing {songs.from} to {songs.to} of {songs.total}
                songs{:else}No songs{/if}
        </p>
        {#if songs.last_page > 1}
            <div class="flex items-center gap-2">
                {#if songs.current_page > 1}<Button
                        variant="outline"
                        size="sm"
                        onclick={() => visit({ page: songs.current_page - 1 })}
                        >Previous</Button
                    >{/if}
                <span class="text-sm text-muted-foreground"
                    >{songs.current_page} / {songs.last_page}</span
                >
                {#if songs.current_page < songs.last_page}<Button
                        variant="outline"
                        size="sm"
                        onclick={() => visit({ page: songs.current_page + 1 })}
                        >Next</Button
                    >{/if}
            </div>
        {/if}
    </div>
</div>
