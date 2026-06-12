<script module lang="ts">
    import baidsRoutes from '@/routes/admin/baids';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'BAIDs', href: baidsRoutes.index(taikoRouteParamForLayout()) }],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';

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

<AppHead title="BAIDs" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">BAIDs</h1>
        <p class="text-sm text-muted-foreground">Access codes and profiles created by cabinet requests.</p>
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
                    <th class="px-3 py-2 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {#each players.data as player (player.baid)}
                    <tr class="border-t">
                        <td class="px-3 py-2">
                            <Link class="font-medium underline-offset-4 hover:underline" href={toUrl(baidsRoutes.show({ ...taikoRouteParam(), player: player.baid }))}>
                                {player.baid}
                            </Link>
                        </td>
                        <td class="px-3 py-2">{player.mydon_name || 'Unregistered'}</td>
                        <td class="px-3 py-2 font-mono text-xs">{player.access_code || '-'}</td>
                        <td class="px-3 py-2">{player.play_results_count}</td>
                        <td class="px-3 py-2">{player.song_bests_count}</td>
                        <td class="px-3 py-2">{player.last_played_at || '-'}</td>
                        <td class="px-3 py-2 text-right">
                            <Form {...baidsRoutes.destroy.form({ ...taikoRouteParam(), player: player.baid })} options={{ preserveScroll: true }}>
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        onclick={(event: Event) => {
                                            if (!confirm(`Delete BAID ${player.baid} (${player.access_code || 'no access code'}) and ALL its scores? This cannot be undone.`)) {
                                                event.preventDefault();
                                            }
                                        }}
                                    >
                                        Delete
                                    </Button>
                                {/snippet}
                            </Form>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
