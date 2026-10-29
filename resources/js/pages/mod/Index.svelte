<script module lang="ts">
    import moderationForLayout from '@/routes/mod';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Moderation',
                href: moderationForLayout.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import moderation from '@/routes/mod';
    import moderationPlayers from '@/routes/mod/players';

    type PlayerRow = {
        baid: number;
        mydon_name: string | null;
        user: {
            id: number;
            name: string;
            username: string | null;
            role: string;
        } | null;
        last_played_at?: string | null;
    };
    type Filters = {
        q: string;
        sort: string;
        direction: 'asc' | 'desc';
        page?: number;
    };
    type SanctionRow = {
        id: number;
        type: string;
        reason: string;
        expires_at: string | null;
        created_at: string | null;
        created_by: string | null;
        player: PlayerRow;
    };
    type LogRow = {
        id: number;
        actor: string | null;
        action: string;
        baid: number | null;
        wdb_play_id: string | null;
        reason: string | null;
        created_at: string | null;
    };

    let {
        filters,
        players,
        activeSanctions,
        log,
    }: {
        filters: Filters;
        players: {
            data: PlayerRow[];
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
        activeSanctions: SanctionRow[];
        log: LogRow[] | null;
    } = $props();

    const taikoParam = taikoRouteParam();
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function date(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }

    function playerHref(baid: number): string {
        return toUrl(moderationPlayers.show({ ...taikoParam, player: baid }));
    }

    // Every visit carries all filters (the pager too), so paging never drops one.
    function visit(changes: Partial<Filters>): void {
        const query = Object.fromEntries(
            Object.entries({ ...filters, page: undefined, ...changes }).filter(
                ([, value]) =>
                    value !== null && value !== undefined && value !== '',
            ),
        );
        router.get(moderation.index(taikoParam).url, query, {
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

    const sortable = [
        { key: 'baid', label: 'BAID' },
        { key: 'name', label: 'Name' },
        { key: 'don', label: 'Don-chan' },
    ];

    function playerName(player: PlayerRow): string {
        return player.user?.name ?? player.mydon_name ?? 'Unregistered';
    }
</script>

<AppHead title="Moderation" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Moderation</h1>
        <p class="text-sm text-muted-foreground">
            Look a player up by name, username, Don-chan name or BAID to see
            their plays and sanctions.
        </p>
    </div>

    <form
        class="flex max-w-xl gap-2"
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
            placeholder="Name, username, Don-chan name or BAID"
            aria-label="Search players"
        />
        <Button type="submit">Search</Button>
    </form>

    <section class="flex flex-col gap-2">
        <h2 class="font-medium">Players</h2>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        {#each sortable as column (column.key)}
                            <th class="px-3 py-2 font-medium">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    onclick={() => sortBy(column.key)}
                                >
                                    {column.label}{#if filters.sort === column.key}<span
                                            aria-hidden="true"
                                            >{filters.direction === 'asc'
                                                ? '▲'
                                                : '▼'}</span
                                        >{/if}
                                </button>
                            </th>
                        {/each}
                        <th class="px-3 py-2 font-medium">Role</th>
                        <th class="px-3 py-2 font-medium">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                                onclick={() => sortBy('last')}
                            >
                                Last play{#if filters.sort === 'last'}<span
                                        aria-hidden="true"
                                        >{filters.direction === 'asc'
                                            ? '▲'
                                            : '▼'}</span
                                    >{/if}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-right font-medium">Open</th>
                    </tr>
                </thead>
                <tbody>
                    {#each players.data as player (player.baid)}
                        <tr class="border-t">
                            <td class="px-3 py-2 tabular-nums">{player.baid}</td
                            >
                            <td class="px-3 py-2">{player.user?.name ?? '—'}</td
                            >
                            <td class="px-3 py-2">{player.mydon_name ?? '—'}</td
                            >
                            <td class="px-3 py-2">{player.user?.role ?? '—'}</td
                            >
                            <td class="px-3 py-2"
                                >{date(player.last_played_at ?? null)}</td
                            >
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-2">
                                    <Link
                                        href={playerHref(player.baid)}
                                        class={buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        })}>Moderate</Link
                                    >
                                    {#if player.user}<Link
                                            href={toUrl(
                                                boardShow({
                                                    ...taikoParam,
                                                    user: player.user.id,
                                                }),
                                            )}
                                            class={buttonVariants({
                                                variant: 'outline',
                                                size: 'sm',
                                            })}>Board</Link
                                        >{/if}
                                </div>
                            </td>
                        </tr>
                    {:else}
                        <tr
                            ><td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                                >No players match.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                {#if players.total > 0}Showing {players.from} to {players.to} of
                    {players.total} players{/if}
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
    </section>

    <section class="flex flex-col gap-2">
        <h2 class="font-medium">Sanctions in force</h2>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Player</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Reason</th>
                        <th class="px-3 py-2 font-medium">Expires</th>
                        <th class="px-3 py-2 font-medium">By</th>
                    </tr>
                </thead>
                <tbody>
                    {#each activeSanctions as sanction (sanction.id)}
                        <tr class="border-t">
                            <td class="px-3 py-2">
                                <Link
                                    href={playerHref(sanction.player.baid)}
                                    class="underline"
                                    >{playerName(sanction.player)}</Link
                                >
                            </td>
                            <td class="px-3 py-2"
                                ><Badge variant="secondary"
                                    >{sanction.type}</Badge
                                ></td
                            >
                            <td class="max-w-md px-3 py-2 break-words"
                                >{sanction.reason}</td
                            >
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{sanction.expires_at
                                    ? date(sanction.expires_at)
                                    : 'Never'}</td
                            >
                            <td class="px-3 py-2"
                                >{sanction.created_by ?? '—'}</td
                            >
                        </tr>
                    {:else}
                        <tr
                            ><td
                                colspan="5"
                                class="px-3 py-6 text-center text-muted-foreground"
                                >No sanctions in force.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
    </section>

    {#if log !== null}
        <section class="flex flex-col gap-2">
            <h2 class="font-medium">Action log</h2>
            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">When</th>
                            <th class="px-3 py-2 font-medium">Who</th>
                            <th class="px-3 py-2 font-medium">Action</th>
                            <th class="px-3 py-2 font-medium">Player</th>
                            <th class="px-3 py-2 font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each log as entry (entry.id)}
                            <tr class="border-t">
                                <td class="px-3 py-2 whitespace-nowrap"
                                    >{date(entry.created_at)}</td
                                >
                                <td class="px-3 py-2">{entry.actor ?? '—'}</td>
                                <td class="px-3 py-2">{entry.action}</td>
                                <td class="px-3 py-2 tabular-nums">
                                    {#if entry.baid !== null}
                                        <Link
                                            href={playerHref(entry.baid)}
                                            class="underline">{entry.baid}</Link
                                        >
                                    {:else}
                                        —
                                    {/if}
                                </td>
                                <td class="max-w-md px-3 py-2 break-words"
                                    >{entry.reason ?? ''}</td
                                >
                            </tr>
                        {:else}
                            <tr
                                ><td
                                    colspan="5"
                                    class="px-3 py-6 text-center text-muted-foreground"
                                    >Nothing logged yet.</td
                                ></tr
                            >
                        {/each}
                    </tbody>
                </table>
            </div>
        </section>
    {/if}
</div>
