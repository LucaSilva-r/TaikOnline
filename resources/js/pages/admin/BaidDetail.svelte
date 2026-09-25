<script module lang="ts">
    import baids from '@/routes/admin/baids';
    import { taikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            { title: 'BAIDs', href: baids.index(taikoRouteParam()) },
            { title: 'Detail', href: '#' },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import BaidAccessCodeTransfer from '@/components/BaidAccessCodeTransfer.svelte';
    import OperatorController from '@/actions/App/Http/Controllers/Green/OperatorController';

    type Result = {
        id: number;
        game_version: string;
        song_no: number;
        level: number;
        score: number;
        score_rank: number;
        played_at: string | null;
    };

    type Best = {
        id: number;
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

<AppHead title={`BAID ${player.baid}`} />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold">
                {player.mydon_name || 'Unregistered'} · {player.baid}
            </h1>
            <p class="text-sm text-muted-foreground">
                {player.access_code || 'No access code'} · {player.total_credit_count}
                credits
            </p>
        </div>

        <Form {...baids.destroy.form({ ...taikoRouteParam(), player: player.baid })}>
            {#snippet children({ processing })}
                <Button
                    type="submit"
                    size="sm"
                    variant="destructive"
                    disabled={processing}
                    onclick={(event: Event) => {
                        if (
                            !confirm(
                                `Permanently delete this BAID, its access code and ALL scores? This cannot be undone.`,
                            )
                        ) {
                            event.preventDefault();
                        }
                    }}
                >
                    Delete BAID
                </Button>
            {/snippet}
        </Form>
    </div>

    <section class="rounded-md border p-4">
        <BaidAccessCodeTransfer baid={player.baid} accessCode={player.access_code} />
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-md border">
            <div class="border-b px-3 py-2 font-medium">Recent Plays</div>
            <table class="w-full text-sm">
                <tbody>
                    {#each recentResults as result (result.id)}
                        <tr class="border-b last:border-0">
                            <td class="px-3 py-2">{result.game_version}</td>
                            <td class="px-3 py-2">#{result.song_no}</td>
                            <td class="px-3 py-2">Lv {result.level}</td>
                            <td class="px-3 py-2">{result.score}</td>
                            <td class="px-3 py-2">{result.played_at || '-'}</td>
                            <td class="px-3 py-2 text-right">
                                <Form
                                    {...OperatorController.destroyPlay.form({ ...taikoRouteParam(), player: player.baid, result: result.id })}
                                    options={{ preserveScroll: true }}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}
                                            onclick={(event: Event) => {
                                                if (!confirm('Delete this play?')) {
                                                    event.preventDefault();
                                                }
                                            }}
                                        >
                                            ✕
                                        </Button>
                                    {/snippet}
                                </Form>
                            </td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </section>

        <section class="rounded-md border">
            <div class="border-b px-3 py-2 font-medium">Best Scores</div>
            <table class="w-full text-sm">
                <tbody>
                    {#each bests as best (best.id)}
                        <tr class="border-b last:border-0">
                            <td class="px-3 py-2">{best.game_version}</td>
                            <td class="px-3 py-2">#{best.song_no}</td>
                            <td class="px-3 py-2">Lv {best.level}</td>
                            <td class="px-3 py-2">{best.best_score}</td>
                            <td class="px-3 py-2">Rank {best.best_score_rank}</td>
                            <td class="px-3 py-2 text-right">
                                <Form
                                    {...OperatorController.destroyBest.form({ ...taikoRouteParam(), player: player.baid, best: best.id })}
                                    options={{ preserveScroll: true }}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="ghost"
                                            disabled={processing}
                                            onclick={(event: Event) => {
                                                if (!confirm('Delete this best score?')) {
                                                    event.preventDefault();
                                                }
                                            }}
                                        >
                                            ✕
                                        </Button>
                                    {/snippet}
                                </Form>
                            </td>
                        </tr>
                    {/each}
                </tbody>
            </table>
        </section>
    </div>
</div>
