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
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import waddamburoCabinetRoutes from '@/routes/admin/waddamburo-cabinets';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type Cabinet = {
        id: number;
        name: string;
        plays: number;
        last_seen_at: string | null;
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
            A cabinet logs in with its own token (cabinet_token in its
            config.cfg); its players then log in with a 6-digit code. The token
            is shown once, when the cabinet is added. Revoking it cuts the
            cabinet off at once; its plays stay.
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
                    <th class="px-3 py-2 font-medium">Plays</th>
                    <th class="px-3 py-2 font-medium">Last seen</th>
                    <th class="px-3 py-2 font-medium">Added</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                {#each cabinets as cabinet (cabinet.id)}
                    <tr
                        class="border-t {cabinet.revoked_at
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
                            <div class="flex justify-end">
                                <Form
                                    {...(cabinet.revoked_at
                                        ? waddamburoCabinetRoutes.restore
                                        : waddamburoCabinetRoutes.revoke
                                    ).form({
                                        ...taikoRouteParam(),
                                        cabinet: cabinet.id,
                                    })}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        cabinet.revoked_at !== null ||
                                        confirm(
                                            `Revoke ${cabinet.name}? It is cut off at once.`,
                                        )}
                                >
                                    {#snippet children({ processing })}
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant={cabinet.revoked_at
                                                ? 'outline'
                                                : 'destructive'}
                                            disabled={processing}
                                            >{cabinet.revoked_at
                                                ? 'Restore'
                                                : 'Revoke'}</Button
                                        >
                                    {/snippet}
                                </Form>
                            </div>
                        </td>
                    </tr>
                {:else}
                    <tr
                        ><td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                            >No cabinets yet.</td
                        ></tr
                    >
                {/each}
            </tbody>
        </table>
    </div>
</div>
