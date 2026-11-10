<script module lang="ts">
    import waddamburoCabinetIndexRoutes from '@/routes/admin/waddamburo-cabinets';
    import { taikoRouteParam as indexTaikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [
            {
                title: 'Waddamburo cabinets',
                href: waddamburoCabinetIndexRoutes.index(
                    indexTaikoRouteParam(),
                ),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import waddamburoCabinetRoutes from '@/routes/admin/waddamburo-cabinets';
    import playersRoutes from '@/routes/admin/players';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Cabinet = {
        id: number;
        name: string;
        owner: { id: number; name: string; banned: boolean } | null;
        plays: number;
        last_seen_at: string | null;
        disabled_at: string | null;
        revoked_at: string | null;
        created_at: string | null;
    };
    let {
        cabinets,
        issued,
    }: {
        cabinets: Cabinet[];
        issued: { id: number; name: string; token: string } | null;
    } = $props();

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function date(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }
</script>

<AppHead title="Waddamburo cabinets" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Waddamburo cabinets</h1>
        <p class="text-sm text-muted-foreground">
            Every cabinet token ever made, by staff or by players for their own
            cabinet. A cabinet logs in with its token (cabinet_token in its
            config.cfg); its players then log in with a 6-digit code. Disabling
            turns a token off until enabled again; revoking cuts it off for
            good. A banned owner's tokens are off while the ban lasts. Plays
            stay either way.
        </p>
    </div>

    {#if issued}
        <div
            class="flex max-w-2xl flex-col gap-2 rounded-md border border-emerald-500/40 bg-emerald-500/10 p-4"
        >
            <div class="text-sm font-medium">
                Token for {issued.name}: copy it now, it is not shown again.
            </div>
            <code
                class="rounded bg-background px-2 py-1 text-sm break-all select-all"
                >cabinet_token = {issued.token}</code
            >
        </div>
    {/if}

    <Form
        {...waddamburoCabinetRoutes.store.form(taikoRouteParam())}
        class="flex max-w-2xl flex-wrap items-end gap-3 rounded-md border p-4"
        options={{ preserveScroll: true }}
        resetOnSuccess
    >
        {#snippet children({ errors, processing })}
            <div class="flex flex-1 flex-col gap-1.5">
                <Label for="cabinet-name">Cabinet name</Label>
                <Input
                    id="cabinet-name"
                    name="name"
                    maxlength={100}
                    required
                    placeholder="Arcade on Main Street, left cab"
                />
                <InputError message={errors.name} />
            </div>
            <Button type="submit" disabled={processing}>Add cabinet</Button>
        {/snippet}
    </Form>

    <div class="overflow-x-auto rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">Cabinet</th>
                    <th class="px-3 py-2 font-medium">Owner</th>
                    <th class="px-3 py-2 font-medium">Plays</th>
                    <th class="px-3 py-2 font-medium">Last seen</th>
                    <th class="px-3 py-2 font-medium">Added</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                {#each cabinets as cabinet (cabinet.id)}
                    <tr
                        class="border-t {cabinet.revoked_at ||
                        cabinet.disabled_at ||
                        cabinet.owner?.banned
                            ? 'text-muted-foreground'
                            : ''}"
                    >
                        <td class="px-3 py-2">
                            {cabinet.name}
                            {#if cabinet.revoked_at}
                                <Badge
                                    variant="secondary"
                                    class="ml-2 border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                                    >revoked {date(cabinet.revoked_at)}</Badge
                                >
                            {:else if cabinet.disabled_at}
                                <Badge
                                    variant="secondary"
                                    class="ml-2 border-0 bg-amber-500/15 text-amber-700 dark:text-amber-400"
                                    >disabled {date(cabinet.disabled_at)}</Badge
                                >
                            {/if}
                        </td>
                        <td class="px-3 py-2">
                            {#if cabinet.owner}
                                <Link
                                    class="underline-offset-2 hover:underline"
                                    href={playersRoutes.edit({
                                        ...taikoRouteParam(),
                                        user: cabinet.owner.id,
                                    })}>{cabinet.owner.name}</Link
                                >
                                {#if cabinet.owner.banned}
                                    <Badge
                                        variant="secondary"
                                        class="ml-2 border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                                        >banned</Badge
                                    >
                                {/if}
                            {:else}
                                <span class="text-muted-foreground">staff</span>
                            {/if}
                        </td>
                        <td class="px-3 py-2 tabular-nums">{cabinet.plays}</td>
                        <td class="px-3 py-2 whitespace-nowrap"
                            >{date(cabinet.last_seen_at)}</td
                        >
                        <td class="px-3 py-2 whitespace-nowrap"
                            >{date(cabinet.created_at)}</td
                        >
                        <td class="px-3 py-2">
                            {#if !cabinet.revoked_at}
                                <div class="flex justify-end gap-2">
                                    <Form
                                        {...(cabinet.disabled_at
                                            ? waddamburoCabinetRoutes.enable
                                            : waddamburoCabinetRoutes.disable
                                        ).form({
                                            ...taikoRouteParam(),
                                            cabinet: cabinet.id,
                                        })}
                                        options={{ preserveScroll: true }}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                                >{cabinet.disabled_at
                                                    ? 'Enable'
                                                    : 'Disable'}</Button
                                            >
                                        {/snippet}
                                    </Form>
                                    <Form
                                        {...waddamburoCabinetRoutes.revoke.form(
                                            {
                                                ...taikoRouteParam(),
                                                cabinet: cabinet.id,
                                            },
                                        )}
                                        options={{ preserveScroll: true }}
                                        onBefore={() =>
                                            confirm(
                                                `Revoke ${cabinet.name} for good? It cannot be undone.`,
                                            )}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                disabled={processing}
                                                >Revoke</Button
                                            >
                                        {/snippet}
                                    </Form>
                                </div>
                            {/if}
                        </td>
                    </tr>
                {:else}
                    <tr
                        ><td
                            colspan="6"
                            class="px-3 py-6 text-center text-muted-foreground"
                            >No cabinets yet.</td
                        ></tr
                    >
                {/each}
            </tbody>
        </table>
    </div>
</div>
