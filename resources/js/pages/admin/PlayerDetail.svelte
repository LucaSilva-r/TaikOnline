<script module lang="ts">
    import players from '@/routes/admin/players';
    import { taikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            { title: 'Players', href: players.index(taikoRouteParam()) },
            { title: 'Detail', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    type Result = {
        game_version: string;
        song_no: number;
        level: number;
        score: number;
        score_rank: number;
        played_at: string | null;
    };

    type Best = {
        game_version: string;
        song_no: number;
        level: number;
        best_score: number;
        best_score_rank: number;
    };

    let {
        player,
        recentResults,
        bests,
    }: {
        player: {
            baid: number;
            mydon_name: string;
            access_code: string | null;
            last_played_at: string | null;
            total_credit_count: number;
            recent_song_numbers: number[];
        };
        recentResults: Result[];
        bests: Best[];
    } = $props();
</script>

<AppHead title={`Player ${player.baid}`} />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">
            {player.mydon_name || 'Unregistered'} · {player.baid}
        </h1>
        <p class="text-sm text-muted-foreground">
            {player.access_code || 'No access code'} · {player.total_credit_count}
            credits
        </p>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-md border">
            <div class="border-b px-3 py-2 font-medium">Recent Plays</div>
            <table class="w-full text-sm">
                <tbody>
                    {#each recentResults as result, index (`${result.game_version}-${result.song_no}-${result.level}-${result.played_at}-${index}`)}
                        <tr class="border-b last:border-0">
                            <td class="px-3 py-2">{result.game_version}</td>
                            <td class="px-3 py-2">#{result.song_no}</td>
                            <td class="px-3 py-2">Lv {result.level}</td>
                            <td class="px-3 py-2">{result.score}</td>
                            <td class="px-3 py-2">{result.played_at || '-'}</td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </section>

        <section class="rounded-md border">
            <div class="border-b px-3 py-2 font-medium">Best Scores</div>
            <table class="w-full text-sm">
                <tbody>
                    {#each bests as best (`${best.game_version}-${best.song_no}-${best.level}`)}
                        <tr class="border-b last:border-0">
                            <td class="px-3 py-2">{best.game_version}</td>
                            <td class="px-3 py-2">#{best.song_no}</td>
                            <td class="px-3 py-2">Lv {best.level}</td>
                            <td class="px-3 py-2">{best.best_score}</td>
                            <td class="px-3 py-2"
                                >Rank {best.best_score_rank}</td
                            >
                        </tr>
                    {/each}
                </tbody>
            </table>
        </section>
    </div>
</div>
