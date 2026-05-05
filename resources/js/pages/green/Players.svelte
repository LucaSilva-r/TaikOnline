<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Players', href: '/green/players' }],
    };
</script>

<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';

    type Player = {
        baid: number;
        mydon_name: string;
        access_code: string | null;
        last_played_at: string | null;
        play_results_count: number;
        song_bests_count: number;
    };

    let {
        players,
    }: {
        players: { data: Player[] };
    } = $props();
</script>

<AppHead title="Players" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Players</h1>
        <p class="text-sm text-muted-foreground">Cards and profiles created by Green cabinet requests.</p>
    </div>

    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">BAID</th>
                    <th class="px-3 py-2 font-medium">Name</th>
                    <th class="px-3 py-2 font-medium">Access Code</th>
                    <th class="px-3 py-2 font-medium">Plays</th>
                    <th class="px-3 py-2 font-medium">Bests</th>
                    <th class="px-3 py-2 font-medium">Last Play</th>
                </tr>
            </thead>
            <tbody>
                {#each players.data as player (player.baid)}
                    <tr class="border-t">
                        <td class="px-3 py-2">
                            <Link class="font-medium underline-offset-4 hover:underline" href={`/green/players/${player.baid}`}>
                                {player.baid}
                            </Link>
                        </td>
                        <td class="px-3 py-2">{player.mydon_name || 'Unregistered'}</td>
                        <td class="px-3 py-2 font-mono text-xs">{player.access_code || '-'}</td>
                        <td class="px-3 py-2">{player.play_results_count}</td>
                        <td class="px-3 py-2">{player.song_bests_count}</td>
                        <td class="px-3 py-2">{player.last_played_at || '-'}</td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
