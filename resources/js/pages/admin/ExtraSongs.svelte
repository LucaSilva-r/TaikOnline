<script module lang="ts">
    import extraSongIndexRoutes from '@/routes/admin/extra-songs';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'Extra songs', href: extraSongIndexRoutes.index(indexTaikoRouteParam()) }],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Badge } from '@/components/ui/badge';
    import extraSongRoutes from '@/routes/admin/extra-songs';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Chart = { id: number; difficulty: number; sha256: string };
    type Song = { id: number; title: string; subtitle: string | null; edition: string | null; is_ranked: boolean; charts: Chart[] };
    type Pending = { id: number; sha256: string; observed_title: string | null; observed_source_id: string | null; bests_count: number; last_seen_at: string | null };
    type Page<T> = { data: T[]; total: number };
    let { songs, pending }: { songs: Page<Song>; pending: Pending[] } = $props();
    const difficulties = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
</script>

<AppHead title="Extra songs" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Extra songs</h1>
        <p class="text-sm text-muted-foreground">Register exact fumen binaries for public Extra leaderboards. Uploaded files are hashed and discarded.</p>
    </div>

    <Form {...extraSongRoutes.store.form(taikoRouteParam())} enctype="multipart/form-data" class="grid gap-4 rounded-lg border p-4">
        {#snippet children({ errors, processing, recentlySuccessful })}
            <div class="grid gap-4 md:grid-cols-3">
                <div class="grid gap-2"><Label for="title">Title</Label><Input id="title" name="title" required />{#if errors.title}<p class="text-sm text-destructive">{errors.title}</p>{/if}</div>
                <div class="grid gap-2"><Label for="subtitle">Subtitle</Label><Input id="subtitle" name="subtitle" /></div>
                <div class="grid gap-2"><Label for="edition">Edition</Label><Input id="edition" name="edition" placeholder="Optional revision label" /></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {#each difficulties as difficulty, index}
                    <div class="grid gap-2"><Label for={`chart-${index + 1}`}>{difficulty}</Label><Input id={`chart-${index + 1}`} name={`charts[${index + 1}]`} type="file" accept=".bin" /></div>
                {/each}
            </div>
            {#if errors.charts}<p class="text-sm text-destructive">{errors.charts}</p>{/if}
            <div class="flex items-center gap-3"><Button type="submit" disabled={processing}>{processing ? 'Importing…' : 'Register Extra song'}</Button>{#if recentlySuccessful}<span class="text-sm text-emerald-600">Registered</span>{/if}</div>
        {/snippet}
    </Form>

    <section class="grid gap-3">
        <h2 class="font-semibold">Registered ({songs.total})</h2>
        {#each songs.data as song (song.id)}
            <div class="rounded-lg border p-4">
                <div class="flex flex-wrap items-center gap-2"><span class="font-medium">{song.title}</span>{#if song.edition}<Badge variant="secondary">{song.edition}</Badge>{/if}</div>
                <div class="mt-3 flex flex-wrap gap-2">{#each song.charts as chart (chart.id)}<Badge variant="outline">{difficulties[chart.difficulty - 1]} · {chart.sha256.slice(0, 12)}</Badge>{/each}</div>
            </div>
        {/each}
    </section>

    <section class="grid gap-3">
        <h2 class="font-semibold">Observed but unregistered</h2>
        {#each pending as chart (chart.id)}
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm">
                <div><div class="font-medium">{chart.observed_title ?? 'Untitled chart'}</div><div class="font-mono text-xs text-muted-foreground">{chart.sha256}</div></div>
                <Badge variant="secondary">{chart.bests_count} player bests</Badge>
            </div>
        {/each}
    </section>
</div>
