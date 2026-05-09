<script module lang="ts">
    import { index } from '@/routes/cabinets';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Cabinet settings',
                href: index(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import CabinetController from '@/actions/App/Http/Controllers/Settings/CabinetController';
    import { toUrl } from '@/lib/utils';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
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
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    type Cabinet = {
        serial: string;
        nickname: string | null;
        registered_at: string | null;
        last_heartbeat_at: string | null;
        last_ip: string | null;
        is_online: boolean;
    };

    let { cabinets = [] }: { cabinets?: Cabinet[] } = $props();

    function formatDate(iso: string | null): string {
        if (!iso) return '—';
        return new Date(iso).toLocaleString();
    }
</script>

<AppHead title="Cabinet settings" />

<h1 class="sr-only">Cabinet settings</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Registered cabinets"
        description="Each registered cabinet gets a unique serial. Drop the generated zip into your arcade to apply it."
    />

    {#if cabinets.length === 0}
        <p class="text-muted-foreground text-sm">No cabinets registered yet.</p>
    {:else}
        <ul class="divide-y rounded-md border">
            {#each cabinets as cab (cab.serial)}
                <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Link
                                href={toUrl(CabinetController.show.url({ cabinet: cab.serial }))}
                                class="font-medium hover:underline"
                            >
                                {cab.nickname ?? 'Unnamed cabinet'}
                            </Link>
                            {#if cab.is_online}
                                <Badge variant="default">Online</Badge>
                            {:else}
                                <Badge variant="secondary">Offline</Badge>
                            {/if}
                        </div>
                        <p class="text-muted-foreground font-mono text-xs">{cab.serial}</p>
                        <p class="text-muted-foreground text-xs">
                            Registered: {formatDate(cab.registered_at)}
                            · Last heartbeat: {formatDate(cab.last_heartbeat_at)}
                            {#if cab.last_ip}· IP: {cab.last_ip}{/if}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            {#snippet children(props)}
                                <a
                                    href={CabinetController.download.url({ cabinet: cab.serial })}
                                    download
                                    class="{props.class} whitespace-nowrap"
                                >
                                    Download zip
                                </a>
                            {/snippet}
                        </Button>
                        <Dialog>
                            <DialogTrigger>
                                <Button variant="destructive" size="sm">Revoke</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <Form
                                    {...CabinetController.destroy.form({ cabinet: cab.serial })}
                                    options={{ preserveScroll: true }}
                                >
                                    {#snippet children({ processing })}
                                        <div class="space-y-3">
                                            <DialogTitle>Revoke this cabinet?</DialogTitle>
                                            <DialogDescription>
                                                The serial <span class="font-mono">{cab.serial}</span>
                                                will be released back to the pool. The cabinet will lose online
                                                authentication until you register and reinstall a new zip.
                                            </DialogDescription>
                                        </div>
                                        <DialogFooter class="gap-2">
                                            <DialogClose>
                                                <Button variant="secondary">Cancel</Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Revoke cabinet
                                            </Button>
                                        </DialogFooter>
                                    {/snippet}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </li>
            {/each}
        </ul>
    {/if}
</div>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Register a new cabinet"
        description="Allocates a fresh serial and bundles a drop-in zip with dongle_serial.txt and chassisinfo.xml."
    />

    <Form
        {...CabinetController.store.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
        resetOnSuccess
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="nickname">Nickname</Label>
                <Input
                    id="nickname"
                    name="nickname"
                    class="mt-1 block w-full"
                    placeholder="e.g. Living-room cab"
                    required
                    maxlength={64}
                />
                <InputError class="mt-2" message={errors.nickname} />
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" disabled={processing}>Register cabinet</Button>
            </div>
        {/snippet}
    </Form>
</div>
