<script module lang="ts">
    import playersRoutesForLayout from '@/routes/admin/players';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Players',
                href: playersRoutesForLayout.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, page, router } from '@inertiajs/svelte';
    import PlayerController from '@/actions/App/Http/Controllers/Admin/PlayerController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import playersRoutes from '@/routes/admin/players';
    import { show as boardShow } from '@/routes/board';
    import { show as moderatePlayer } from '@/routes/mod/players';

    function goToEdit(id: number) {
        router.visit(playersRoutes.edit({ ...taikoRouteParam(), user: id }));
    }

    type AdminUser = {
        id: number;
        name: string;
        username: string;
        email: string;
        role: string;
        created_at: string | null;
        baid: number | null;
    };

    type Filters = {
        q: string;
        role: string | null;
        sort: string;
        direction: 'asc' | 'desc';
        page?: number;
    };

    let {
        users,
        roles,
        filters,
    }: {
        users: Paged<AdminUser>;
        roles: { value: string; label: string }[];
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
        router.get(playersRoutes.index(taikoRouteParam()).url, query, {
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

    const currentUserId = $derived(page.props.auth.user.id);
</script>

<AppHead title="Players" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Players</h1>
        <p class="text-sm text-muted-foreground">
            Registered player accounts. Edit or remove accounts.
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
            placeholder="Name, username, email or id"
            class="max-w-sm"
        />
        <select
            class="h-9 rounded-md border bg-background px-3 text-sm"
            value={filters.role ?? ''}
            onchange={(event) =>
                visit({ role: event.currentTarget.value || null })}
        >
            <option value="">All roles</option>
            {#each roles as role (role.value)}<option value={role.value}
                    >{role.label}</option
                >{/each}
        </select>
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
                            onclick={() => sortBy('id')}
                        >
                            ID{#if filters.sort === 'id'}<span
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
                    <th class="px-3 py-2 font-medium">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 hover:text-foreground"
                            onclick={() => sortBy('username')}
                        >
                            Username{#if filters.sort === 'username'}<span
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
                            onclick={() => sortBy('email')}
                        >
                            Email{#if filters.sort === 'email'}<span
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
                            onclick={() => sortBy('role')}
                        >
                            Role{#if filters.sort === 'role'}<span
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
                            onclick={() => sortBy('created')}
                        >
                            Created{#if filters.sort === 'created'}<span
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
                {#each users.data as user (user.id)}
                    <tr class="border-t">
                        <td class="px-3 py-2">{user.id}</td>
                        <td class="px-3 py-2 font-medium">{user.name}</td>
                        <td class="px-3 py-2">{user.username}</td>
                        <td class="px-3 py-2">{user.email}</td>
                        <td class="px-3 py-2">{user.role}</td>
                        <td class="px-3 py-2">{user.created_at ?? '-'}</td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Link
                                    href={toUrl(
                                        boardShow({
                                            ...taikoRouteParam(),
                                            user: user.id,
                                        }),
                                    )}
                                    class={buttonVariants({
                                        variant: 'outline',
                                        size: 'sm',
                                    })}>Board</Link
                                >
                                {#if user.baid !== null}<Link
                                        href={toUrl(
                                            moderatePlayer({
                                                ...taikoRouteParam(),
                                                player: user.baid,
                                            }),
                                        )}
                                        class={buttonVariants({
                                            variant: 'outline',
                                            size: 'sm',
                                        })}>Moderate</Link
                                    >{/if}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onclick={() => goToEdit(user.id)}
                                >
                                    Edit
                                </Button>
                                {#if user.id !== currentUserId}
                                    <Form
                                        {...PlayerController.destroy.form({
                                            ...taikoRouteParam(),
                                            user: user.id,
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
                                                            `Delete ${user.email}?`,
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
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between">
        <p class="text-sm text-muted-foreground">
            {#if users.total > 0}Showing {users.from} to {users.to} of {users.total}
                players{:else}No players{/if}
        </p>
        {#if users.last_page > 1}
            <div class="flex items-center gap-2">
                {#if users.current_page > 1}<Button
                        variant="outline"
                        size="sm"
                        onclick={() => visit({ page: users.current_page - 1 })}
                        >Previous</Button
                    >{/if}
                <span class="text-sm text-muted-foreground"
                    >{users.current_page} / {users.last_page}</span
                >
                {#if users.current_page < users.last_page}<Button
                        variant="outline"
                        size="sm"
                        onclick={() => visit({ page: users.current_page + 1 })}
                        >Next</Button
                    >{/if}
            </div>
        {/if}
    </div>
</div>
