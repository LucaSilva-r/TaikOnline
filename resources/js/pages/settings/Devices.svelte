<script module lang="ts">
    import { index } from '@/routes/devices';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Devices',
                href: index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import DeviceController from '@/actions/App/Http/Controllers/Settings/DeviceController';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import {
        Dialog,
        DialogClose,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
        DialogTrigger,
    } from '@/components/ui/dialog';

    type Device = {
        id: number;
        name: string;
        created_at: string | null;
        last_used_at: string | null;
        expires_at: string | null;
    };

    let { devices = [] }: { devices?: Device[] } = $props();

    function formatDate(iso: string | null): string {
        if (!iso) return '—';
        return new Date(iso).toLocaleString();
    }
</script>

<AppHead title="Devices" />

<h1 class="sr-only">Devices</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Waddamburo devices"
        description="PCs where you signed in to Waddamburo, and friends' PCs you joined with your six-digit code. Revoking signs that device out on its next request."
    />

    {#if devices.length === 0}
        <p class="text-muted-foreground text-sm">No devices signed in.</p>
    {:else}
        <ul class="divide-y rounded-md border">
            {#each devices as device (device.id)}
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{device.name}</span>
                            {#if device.expires_at}
                                <Badge variant="secondary">Visit · until {formatDate(device.expires_at)}</Badge>
                            {/if}
                        </div>
                        <p class="text-muted-foreground text-xs">
                            Signed in: {formatDate(device.created_at)} · Last used: {formatDate(device.last_used_at)}
                        </p>
                    </div>
                    <Dialog>
                        <DialogTrigger>
                            <Button variant="destructive" size="sm">Revoke</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                {...DeviceController.destroy.form({ ...taikoRouteParam(), device: device.id })}
                                options={{ preserveScroll: true }}
                            >
                                {#snippet children({ processing })}
                                    <div class="space-y-3">
                                        <DialogTitle>Sign out {device.name}?</DialogTitle>
                                        <DialogDescription>
                                            Waddamburo on that device loses access to your account; plays it has not
                                            uploaded yet stay on it until you sign in again.
                                        </DialogDescription>
                                    </div>
                                    <DialogFooter class="gap-2">
                                        <DialogClose>
                                            <Button variant="secondary">Cancel</Button>
                                        </DialogClose>
                                        <Button type="submit" variant="destructive" disabled={processing}>
                                            Revoke
                                        </Button>
                                    </DialogFooter>
                                {/snippet}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </li>
            {/each}
        </ul>
    {/if}
</div>
