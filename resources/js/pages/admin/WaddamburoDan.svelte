<script module lang="ts">
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    import waddamburoDanIndexRoutes from '@/routes/admin/waddamburo-dan';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Waddamburo dan',
                href: waddamburoDanIndexRoutes.index(indexTaikoRouteParam()),
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import waddamburoDanRoutes from '@/routes/admin/waddamburo-dan';

    type Status = 'official' | 'custom' | 'none';
    type Song = { song_key: string; title: string | null; course: number; notes: number; status: Status };
    type Course = { level: number; name: string; open: boolean; runs: number; songs: Song[] };
    type Series = { id: string; name: string; enabled: boolean; open: number; runs: number; courses: Course[] };

    let { series }: { series: Series[] } = $props();

    const COURSES = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
    const STATUS: Record<Status, string> = {
        official: 'Official',
        custom: 'Not official',
        none: 'No chart',
    };

    function toggle(entry: Series): void {
        router.patch(
            waddamburoDanRoutes.update({ ...taikoRouteParam(), series: entry.id }).url,
            { enabled: !entry.enabled },
            { preserveScroll: true },
        );
    }
</script>

<AppHead title="Waddamburo dan" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo dan</h1>
        <p class="text-sm text-muted-foreground">
            A course opens in the game once all three of its songs have an official chart. A disabled series is not
            sent to the game and its new runs are refused; runs already played keep their pages.
        </p>
    </div>

    <div class="flex flex-col gap-3">
        {#each series as entry (entry.id)}
            <details class="rounded-md border">
                <summary class="flex cursor-pointer flex-wrap items-center gap-3 p-3">
                    <span class="font-medium">{entry.name}</span>
                    <Badge variant={entry.enabled ? 'default' : 'outline'}>{entry.enabled ? 'Served' : 'Disabled'}</Badge>
                    <span class="text-sm text-muted-foreground">
                        {entry.open} of {entry.courses.length} courses open · {entry.runs} runs
                    </span>
                    <Button
                        class="ml-auto"
                        size="sm"
                        variant="outline"
                        onclick={(event: MouseEvent) => {
                            event.preventDefault();
                            toggle(entry);
                        }}
                    >
                        {entry.enabled ? 'Disable' : 'Enable'}
                    </Button>
                </summary>
                <div class="overflow-x-auto border-t">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="p-2 font-medium">Course</th>
                                <th class="p-2 font-medium">Song</th>
                                <th class="p-2 font-medium">Difficulty</th>
                                <th class="p-2 text-right font-medium">Notes</th>
                                <th class="p-2 font-medium">Chart</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each entry.courses as course (course.level)}
                                {#each course.songs as song, index (index)}
                                    <tr class="border-t">
                                        <td class="p-2 whitespace-nowrap">
                                            {#if index === 0}
                                                <span class="font-medium">{course.name}</span>
                                                {#if !course.open}
                                                    <Badge variant="destructive">Closed</Badge>
                                                {/if}
                                                {#if course.runs > 0}
                                                    <span class="text-muted-foreground">· {course.runs} runs</span>
                                                {/if}
                                            {/if}
                                        </td>
                                        <td class="p-2">
                                            {song.title ?? 'Unknown song'}
                                            <span class="text-muted-foreground">{song.song_key}</span>
                                        </td>
                                        <td class="p-2">{COURSES[song.course] ?? song.course}</td>
                                        <td class="p-2 text-right tabular-nums">{song.notes}</td>
                                        <td class="p-2 whitespace-nowrap">
                                            <Badge variant={song.status === 'official' ? 'outline' : 'destructive'}>
                                                {STATUS[song.status]}
                                            </Badge>
                                        </td>
                                    </tr>
                                {/each}
                            {/each}
                        </tbody>
                    </table>
                </div>
            </details>
        {/each}
    </div>
</div>
