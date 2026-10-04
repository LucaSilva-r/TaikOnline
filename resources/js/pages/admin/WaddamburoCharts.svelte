<script module lang="ts">
    import waddamburoChartIndexRoutes from '@/routes/admin/waddamburo-charts';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'Waddamburo charts', href: waddamburoChartIndexRoutes.index(indexTaikoRouteParam()) }],
    };
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import waddamburoChartRoutes from '@/routes/admin/waddamburo-charts';
    import songRoutes from '@/routes/songs';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Chart = {
        id: number;
        sha256: string;
        title: string | null;
        subtitle: string | null;
        source: string | null;
        course: number | null;
        level: number | null;
        has_notes: boolean;
        song_id: number | null;
        ranked: boolean;
        plays_count: number;
    };
    type Page<T> = {
        data: T[];
        total: number;
        from: number | null;
        to: number | null;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    type Filters = { q: string; source: string | null; sort: string; direction: 'asc' | 'desc' };
    let { charts, sources, filters }: { charts: Page<Chart>; sources: string[]; filters: Filters } = $props();
    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
    const columns = [
        { key: 'title', label: 'Title' },
        { key: 'source', label: 'Source' },
        { key: 'course', label: 'Course' },
        { key: 'plays', label: 'Plays' },
        { key: 'ranked', label: 'Ranked' },
    ];

    function visit(changes: Partial<Filters>): void {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...changes }).filter(([, value]) => value !== null && value !== ''),
        );
        router.get(waddamburoChartRoutes.index(taikoRouteParam()).url, query, { preserveState: true });
    }

    function sortBy(key: string): void {
        visit({ sort: key, direction: filters.sort === key && filters.direction === 'desc' ? 'asc' : 'desc' });
    }
</script>

<AppHead title="Waddamburo charts" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo charts</h1>
        <p class="text-sm text-muted-foreground">
            Every chart played in Waddamburo, by its hash. Leaderboards show all of them; only ranked charts count
            towards the Waddamburo rankings. Ranking a chart recomputes its players' standings.
        </p>
    </div>

    <form class="flex flex-wrap gap-2" onsubmit={(event) => { event.preventDefault(); visit({ q: String(new FormData(event.currentTarget).get('q') ?? '') }); }}>
        <Input name="q" value={filters.q} placeholder="Title or hash prefix" class="max-w-sm" />
        <select
            class="h-9 rounded-md border bg-background px-3 text-sm"
            value={filters.source ?? ''}
            onchange={(event) => visit({ source: event.currentTarget.value || null })}
        >
            <option value="">All sources</option>
            {#each sources as source (source)}<option value={source}>{source}</option>{/each}
        </select>
        <Button type="submit" variant="secondary">Search</Button>
    </form>

    <div class="overflow-x-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    {#each columns as column (column.key)}
                        <th class="px-3 py-2 font-medium">
                            <button type="button" class="inline-flex items-center gap-1 hover:text-foreground" onclick={() => sortBy(column.key)}>
                                {column.label}
                                {#if filters.sort === column.key}<span aria-hidden="true">{filters.direction === 'asc' ? '▲' : '▼'}</span>{/if}
                            </button>
                        </th>
                    {/each}
                </tr>
            </thead>
            <tbody>
                {#each charts.data as chart (chart.id)}
                    <tr class="border-t hover:bg-muted/30">
                        <td class="max-w-0 px-3 py-2">
                            {#if chart.song_id !== null}
                                <Link href={songRoutes.show({ ...taikoRouteParam(), song: chart.song_id })} class="font-medium hover:underline">{chart.title}</Link>
                            {:else}
                                <span class="font-medium text-muted-foreground">Untitled chart</span>
                            {/if}
                            {#if !chart.has_notes}<Badge variant="destructive" class="ml-1 h-5 px-1.5 text-[10px]">no notes</Badge>{/if}
                            <div class="truncate font-mono text-xs text-muted-foreground" title={chart.sha256}>
                                {chart.subtitle ? `${chart.subtitle} · ` : ''}{chart.sha256}
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{#if chart.source}<Badge variant="secondary">{chart.source}</Badge>{/if}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {#if chart.course !== null}{courses[chart.course] ?? chart.course}{chart.level ? ` ★${chart.level}` : ''}{/if}
                        </td>
                        <td class="px-3 py-2 text-muted-foreground tabular-nums">{chart.plays_count}</td>
                        <td class="px-3 py-2">
                            <Form {...waddamburoChartRoutes.update.form({ ...taikoRouteParam(), chart: chart.id })} options={{ preserveScroll: true, preserveState: true }}>
                                {#snippet children({ processing })}
                                    <input type="hidden" name="ranked" value={chart.ranked ? '0' : '1'} />
                                    <Button type="submit" size="sm" variant={chart.ranked ? 'default' : 'outline'} disabled={processing}>
                                        {chart.ranked ? 'Ranked' : 'Rank'}
                                    </Button>
                                {/snippet}
                            </Form>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between">
        <p class="text-sm text-muted-foreground">
            {#if charts.total > 0}Showing {charts.from} to {charts.to} of {charts.total} charts{:else}No charts{/if}
        </p>
        {#if charts.last_page > 1}
            <div class="flex items-center gap-2">
                {#if charts.prev_page_url}<Button variant="outline" size="sm" onclick={() => router.get(charts.prev_page_url!, {}, { preserveState: true })}>Previous</Button>{/if}
                <span class="text-sm text-muted-foreground">{charts.current_page} / {charts.last_page}</span>
                {#if charts.next_page_url}<Button variant="outline" size="sm" onclick={() => router.get(charts.next_page_url!, {}, { preserveState: true })}>Next</Button>{/if}
            </div>
        {/if}
    </div>
</div>
