<script module lang="ts">
    import baidsRoutes from '@/routes/admin/baids';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            {
                title: 'BAIDs',
                href: baidsRoutes.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
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

    type Filters = {
        q: string;
        sort: string;
        direction: 'asc' | 'desc';
        page?: number;
    };

    let {
        players,
        filters,
    }: {
        players: Paged<Player>;
        filters: Filters;
    } = $props();

    type Paged<T> = {
        data: T[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };

    // Every visit carries all filters (the pager too), so paging never drops one.
    function visit(changes: Partial<Filters>): void {
        const query = Object.fromEntries(
            Object.entries({ ...filters, page: undefined, ...changes }).filter(
                ([, value]) =>
                    value !== null && value !== undefined && value !== '',
            ),
        );
        router.get(baidsRoutes.index(taikoRouteParam()).url, query, {
            preserveState: true,
        });
    }

    function sortBy(key: string): void {
        visit({
            sort: key,
            direction:
                filters.sort === key && filters.direction === 'desc'
                    ? 'asc'
                    : 'desc',
        });
    }
</script>

<AppHead title="BAIDs" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">BAIDs</h1>
        <p class="text-sm text-muted-foreground">
            Access codes and profiles created by cabinet requests.
        </p>
    </div>

    <form
        class="flex flex-wrap gap-2"
        onsubmit={(event) => {
            event.preventDefault();
            visit({
                q: String(new FormData(event.currentTarget).get('q') ?? ''),
            });
        }}
    >
        <Input
            name="q"
            value={filters.q}
            placeholder="BAID, name or access code"
            class="max-w-sm"
        />
        <Button type="submit" variant="secondary">Search</Button>
    </form>

    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('baid')}
                        >
                            BAID{#if filters.sort === 'baid'}<span
                                    aria-hidden="true"
                                    >{filters.direction === 'asc'
                                        ? '▲'
                                        : '▼'}</span
                                >{/if}
                        </button>
                    </th>
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('name')}
                        >
                            Name{#if filters.sort === 'name'}<span
                                    aria-hidden="true"
                                    >{filters.direction === 'asc'
                                        ? '▲'
                                        : '▼'}</span
                                >{/if}
                        </button>
                    </th>
                    <th class="px-3 py-2 font-medium">Access Code</th>
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('plays')}
                        >
                            Plays{#if filters.sort === 'plays'}<span
                                    aria-hidden="true"
                                    >{filters.direction === 'asc'
                                        ? '▲'
                                        : '▼'}</span
                                >{/if}
                        </button>
                    </th>
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('bests')}
                        >
                            Bests{#if filters.sort === 'bests'}<span
                                    aria-hidden="true"
                                    >{filters.direction === 'asc'
                                        ? '▲'
                                        : '▼'}</span
                                >{/if}
                        </button>
                    </th>
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('last')}
                        >
                            Last Play{#if filters.sort === 'last'}<span
                                    aria-hidden="true"
                                    >{filters.direction === 'asc'
                                        ? '▲'
                                        : '▼'}</span
                                >{/if}
                        </button>
                    </th>
                    <th class="px-3 py-2 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {#each players.data as player (player.baid)}
                    <tr class="border-t">
                        <td class="px-3 py-2">
                            <Link
                                class="font-medium underline-offset-4 hover:underline"
                                href={toUrl(
                                    baidsRoutes.show({
                                        ...taikoRouteParam(),
                                        player: player.baid,
                                    }),
                                )}
                            >
                                {player.baid}
                            </Link>
                        </td>
                        <td class="px-3 py-2"
                            >{player.mydon_name || 'Unregistered'}</td
                        >
                        <td class="px-3 py-2 font-mono text-xs"
                            >{player.access_code || '-'}</td
                        >
                        <td class="px-3 py-2">{player.play_results_count}</td>
                        <td class="px-3 py-2">{player.song_bests_count}</td>
                        <td class="px-3 py-2">{player.last_played_at || '-'}</td
                        >
                        <td class="px-3 py-2 text-right">
                            <Form
                                {...baidsRoutes.destroy.form({
                                    ...taikoRouteParam(),
                                    player: player.baid,
                                })}
                                options={{ preserveScroll: true }}
                            >
                                {#snippet children({ processing })}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="destructive"
                                        disabled={processing}
                                        onclick={(event: Event) => {
                                            if (
                                                !confirm(
                                                    `Delete BAID ${player.baid} (${player.access_code || 'no access code'}) and ALL its scores? This cannot be undone.`,
                                                )
                                            ) {
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

    <div class="flex items-center justify-between">
        <p class="text-sm text-muted-foreground">
            {#if players.total > 0}Showing {players.from} to {players.to} of {players.total}
                BAIDs{:else}No BAIDs{/if}
        </p>
        {#if players.last_page > 1}
            <div class="flex items-center gap-2">
                {#if players.current_page > 1}<Button
                        variant="outline"
                        size="sm"
                        onclick={() =>
                            visit({ page: players.current_page - 1 })}
                        >Previous</Button
                    >{/if}
                <span class="text-sm text-muted-foreground"
                    >{players.current_page} / {players.last_page}</span
                >
                {#if players.current_page < players.last_page}<Button
                        variant="outline"
                        size="sm"
                        onclick={() =>
                            visit({ page: players.current_page + 1 })}
                        >Next</Button
                    >{/if}
            </div>
        {/if}
    </div>
</div>
