<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Recent Plays', href: '/green/recent-plays' }],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    type Result = {
        baid: number;
        mydon_name: string | null;
        song_no: number;
        level: number;
        score: number;
        score_rank: number;
        played_at: string | null;
    };

    let {
        results,
    }: {
        results: { data: Result[] };
    } = $props();
</script>

<AppHead title="Recent Plays" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Recent Plays</h1>
        <p class="text-sm text-muted-foreground">Latest play results submitted by the cabinet.</p>
    </div>

    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">Player</th>
                    <th class="px-3 py-2 font-medium">Song</th>
                    <th class="px-3 py-2 font-medium">Level</th>
                    <th class="px-3 py-2 font-medium">Score</th>
                    <th class="px-3 py-2 font-medium">Rank</th>
                    <th class="px-3 py-2 font-medium">Played At</th>
                </tr>
            </thead>
            <tbody>
                {#each results.data as result, index (`${result.baid}-${result.song_no}-${result.played_at}-${index}`)}
                    <tr class="border-t">
                        <td class="px-3 py-2">{result.mydon_name || result.baid}</td>
                        <td class="px-3 py-2">#{result.song_no}</td>
                        <td class="px-3 py-2">{result.level}</td>
                        <td class="px-3 py-2">{result.score}</td>
                        <td class="px-3 py-2">{result.score_rank}</td>
                        <td class="px-3 py-2">{result.played_at || '-'}</td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
