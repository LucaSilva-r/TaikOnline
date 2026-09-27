<script module lang="ts">
    import waddamburoChartIndexRoutes from '@/routes/admin/waddamburo-charts';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'Waddamburo charts', href: waddamburoChartIndexRoutes.index(indexTaikoRouteParam()) }],
    };
</script>

<script lang="ts">
    import { Form, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import waddamburoChartRoutes from '@/routes/admin/waddamburo-charts';
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
        ranked: boolean;
        plays_count: number;
    };
    type Page<T> = { data: T[]; total: number; prev_page_url: string | null; next_page_url: string | null };
    let { charts, filters }: { charts: Page<Chart>; filters: { q: string } } = $props();
    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
</script>

<AppHead title="Waddamburo charts" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo charts</h1>
        <p class="text-sm text-muted-foreground">
            Every chart played in Waddamburo, by its hash. Leaderboards show all of them; only ranked charts count
            towards the Waddamburo rankings. Ranking a chart recomputes its players' standings.
        </p>
    </div>

    <form method="get" class="flex gap-2" onsubmit={(event) => { event.preventDefault(); router.get(waddamburoChartRoutes.index(taikoRouteParam()).url, { q: new FormData(event.currentTarget).get('q') }, { preserveState: true }); }}>
        <Input name="q" value={filters.q} placeholder="Title or hash prefix" class="max-w-sm" />
        <Button type="submit" variant="secondary">Search</Button>
    </form>

    <section class="grid gap-2">
        <h2 class="font-semibold">Charts ({charts.total})</h2>
        {#each charts.data as chart (chart.id)}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{chart.title ?? 'Untitled chart'}</span>
                        {#if chart.course !== null}<Badge variant="outline">{courses[chart.course] ?? chart.course}{chart.level ? ` ★${chart.level}` : ''}</Badge>{/if}
                        {#if chart.source}<Badge variant="secondary">{chart.source}</Badge>{/if}
                        {#if !chart.has_notes}<Badge variant="destructive">no notes yet</Badge>{/if}
                    </div>
                    <div class="font-mono text-xs text-muted-foreground">{chart.sha256}</div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-muted-foreground">{chart.plays_count} plays</span>
                    <Form {...waddamburoChartRoutes.update.form({ ...taikoRouteParam(), chart: chart.id })} options={{ preserveScroll: true }}>
                        {#snippet children({ processing })}
                            <input type="hidden" name="ranked" value={chart.ranked ? '0' : '1'} />
                            <Button type="submit" size="sm" variant={chart.ranked ? 'default' : 'outline'} disabled={processing}>
                                {chart.ranked ? 'Ranked' : 'Rank'}
                            </Button>
                        {/snippet}
                    </Form>
                </div>
            </div>
        {/each}
        <div class="flex gap-2">
            {#if charts.prev_page_url}<Button variant="outline" size="sm" onclick={() => router.get(charts.prev_page_url!)}>Previous</Button>{/if}
            {#if charts.next_page_url}<Button variant="outline" size="sm" onclick={() => router.get(charts.next_page_url!)}>Next</Button>{/if}
        </div>
    </section>
</div>
