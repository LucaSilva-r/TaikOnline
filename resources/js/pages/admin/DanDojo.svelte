<script module lang="ts">
    import danDojoRoutes from '@/routes/admin/dan-dojo';
    import { taikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'Dan Dojo', href: danDojoRoutes.index(taikoRouteParam()) }],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';

    type Song = { song_no: number; level: number };
    type Course = { dan: number; name: string; verup_no: number; songs: Song[] };
    type Version = {
        value: string;
        label: string;
        song_count: number;
        courses: Course[];
    };

    let { versions, status = '' }: { versions: Version[]; status?: string } =
        $props();

    let randomizing = $state<string | null>(null);

    function randomize(version: string) {
        randomizing = version;
        router.post(
            danDojoRoutes.randomize({ ...taikoRouteParam(), version }).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => (randomizing = null),
            },
        );
    }
</script>

<AppHead title="Dan dojo" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Dan dojo</h1>
        <p class="text-sm text-muted-foreground">
            Courses currently published to cabinets, per version. Randomizing
            authors a fresh set from that version's song catalog — proving the
            dojo works without an arcade datatable.
        </p>
    </div>

    {#if status}
        <div
            class="rounded-md border border-green-500/30 bg-green-500/10 px-3 py-2 text-sm text-green-700 dark:text-green-400"
        >
            {status}
        </div>
    {/if}

    <div class="flex flex-col gap-6">
        {#each versions as version (version.value)}
            <div class="overflow-hidden rounded-md border">
                <div
                    class="flex items-center justify-between gap-4 border-b bg-muted/50 px-4 py-2"
                >
                    <div class="flex items-baseline gap-3">
                        <h2 class="font-semibold">{version.label}</h2>
                        <span class="text-xs text-muted-foreground">
                            {version.courses.length} courses · {version.song_count}
                            songs
                        </span>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={randomizing === version.value ||
                            version.song_count === 0}
                        onclick={() => randomize(version.value)}
                    >
                        {randomizing === version.value
                            ? 'Randomizing…'
                            : 'Randomize'}
                    </Button>
                </div>

                {#if version.courses.length === 0}
                    <p class="px-4 py-3 text-sm text-muted-foreground">
                        No courses published.
                    </p>
                {:else}
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="w-[60px] px-4 py-2 font-medium">Dan</th
                                >
                                <th class="px-4 py-2 font-medium">Name</th>
                                <th class="px-4 py-2 font-medium">Songs (no@lv)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#each version.courses as course (course.dan)}
                                <tr class="border-t">
                                    <td class="px-4 py-2 font-mono">{course.dan}</td
                                    >
                                    <td class="px-4 py-2">{course.name}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex flex-wrap gap-1">
                                            {#each course.songs as song (song.song_no)}
                                                <Badge variant="secondary">
                                                    {song.song_no}@{song.level}
                                                </Badge>
                                            {/each}
                                        </div>
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                {/if}
            </div>
        {/each}
    </div>
</div>
