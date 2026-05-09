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
    import { router } from '@inertiajs/svelte';
    import CabinetController from '@/actions/App/Http/Controllers/Settings/CabinetController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';

    type Entry = { key: number; value: string };

    type ReportedMeta = {
        shop_id?: string;
        rack_id?: string;
        country_id?: string;
        hdd_ver?: number;
        usbmem_ver?: number;
        usbmem_key?: string;
    };

    type Cabinet = {
        serial: string;
        nickname: string | null;
        registered_at: string | null;
        last_heartbeat_at: string | null;
        last_reported_at: string | null;
        last_ip: string | null;
        is_online: boolean;
        reported_config: Entry[];
        reported_meta: ReportedMeta;
        desired_config: Entry[];
    };

    type BookkeepingEntry = {
        update_date: string;
        shop_id: string;
        all_play_count: number;
        service_switch_count: number;
        free_play_count: number;
        payload: Record<string, number> | null;
        created_at: string | null;
    };

    let {
        cabinet,
        bookkeeping = [],
    }: { cabinet: Cabinet; bookkeeping?: BookkeepingEntry[] } = $props();

    let desired = $state<Entry[]>(
        cabinet.desired_config.length > 0
            ? cabinet.desired_config.map((e) => ({ key: e.key, value: e.value }))
            : cabinet.reported_config.map((e) => ({ key: e.key, value: e.value })),
    );
    let saving = $state(false);

    function formatDate(iso: string | null): string {
        if (!iso) return '—';
        return new Date(iso).toLocaleString();
    }

    function decodeBase64Utf8(b64: string): string {
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(
                Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)),
            );
        } catch {
            return '';
        }
    }

    function decodeBase64Hex(b64: string): string {
        try {
            const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
            return Array.from(bytes)
                .map((b) => b.toString(16).padStart(2, '0'))
                .join(' ');
        } catch {
            return '';
        }
    }

    function addRow() {
        desired = [...desired, { key: 0, value: '' }];
    }

    function removeRow(idx: number) {
        desired = desired.filter((_, i) => i !== idx);
    }

    function copyReported() {
        desired = cabinet.reported_config.map((e) => ({ key: e.key, value: e.value }));
    }

    function save() {
        saving = true;
        router.patch(
            CabinetController.updateConfig.url({ cabinet: cabinet.serial }),
            { desired_config: desired },
            {
                preserveScroll: true,
                onFinish: () => (saving = false),
            },
        );
    }
</script>

<AppHead title="Cabinet — {cabinet.nickname ?? cabinet.serial}" />

<div class="flex flex-col space-y-8">
    <Heading
        variant="small"
        title={cabinet.nickname ?? 'Unnamed cabinet'}
        description="View what the cabinet currently reports and push your desired configuration."
    />

    <div class="space-y-2 rounded-md border p-4">
        <div class="flex items-center gap-2">
            <span class="font-mono text-sm">{cabinet.serial}</span>
            {#if cabinet.is_online}
                <Badge variant="default">Online</Badge>
            {:else}
                <Badge variant="secondary">Offline</Badge>
            {/if}
        </div>
        <p class="text-muted-foreground text-xs">
            Registered: {formatDate(cabinet.registered_at)}
            · Last heartbeat: {formatDate(cabinet.last_heartbeat_at)}
            · Last reported: {formatDate(cabinet.last_reported_at)}
            {#if cabinet.last_ip}· IP: {cabinet.last_ip}{/if}
        </p>
    </div>

    <section class="space-y-3">
        <Heading
            variant="small"
            title="Reported metadata"
            description="Identifiers and firmware versions sent during the last startup auth."
        />
        {#if Object.keys(cabinet.reported_meta).length === 0}
            <p class="text-muted-foreground text-sm">No metadata reported yet.</p>
        {:else}
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 rounded-md border p-4 sm:grid-cols-2 text-sm">
                <div>
                    <dt class="text-muted-foreground">Shop ID</dt>
                    <dd class="font-mono">{cabinet.reported_meta.shop_id || '—'}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Rack ID</dt>
                    <dd class="font-mono">{cabinet.reported_meta.rack_id || '—'}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Country</dt>
                    <dd class="font-mono">{cabinet.reported_meta.country_id || '—'}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">USB key</dt>
                    <dd class="font-mono break-all">{cabinet.reported_meta.usbmem_key || '—'}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">HDD ver</dt>
                    <dd class="font-mono">{cabinet.reported_meta.hdd_ver ?? '—'}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">USB mem ver</dt>
                    <dd class="font-mono">{cabinet.reported_meta.usbmem_ver ?? '—'}</dd>
                </div>
            </dl>
        {/if}
    </section>

    <section class="space-y-3">
        <Heading
            variant="small"
            title="Reported configuration"
            description="Last operation_info snapshot the cabinet sent during startup auth."
        />
        {#if cabinet.reported_config.length === 0}
            <p class="text-muted-foreground text-sm">
                No configuration reported yet. The cabinet must complete a startup auth at least once.
            </p>
        {:else}
            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left">Key</th>
                            <th class="px-3 py-2 text-left">Value (UTF-8)</th>
                            <th class="px-3 py-2 text-left">Value (hex)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each cabinet.reported_config as entry (entry.key)}
                            <tr class="border-t">
                                <td class="px-3 py-2 font-mono">{entry.key}</td>
                                <td class="px-3 py-2 font-mono">{decodeBase64Utf8(entry.value) || '—'}</td>
                                <td class="text-muted-foreground px-3 py-2 font-mono text-xs">
                                    {decodeBase64Hex(entry.value)}
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>

    <section class="space-y-3">
        <Heading
            variant="small"
            title="Desired configuration"
            description="Pushed back to the cabinet on next startup auth. Values are base64-encoded bytes."
        />

        <div class="space-y-3">
            {#each desired as entry, idx (idx)}
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="grid w-full gap-1 sm:w-32">
                        <Label for={`key-${idx}`}>Key</Label>
                        <Input
                            id={`key-${idx}`}
                            type="number"
                            min={0}
                            bind:value={entry.key}
                        />
                    </div>
                    <div class="grid w-full flex-1 gap-1">
                        <Label for={`value-${idx}`}>Value (base64)</Label>
                        <Input id={`value-${idx}`} bind:value={entry.value} />
                    </div>
                    <Button variant="outline" size="sm" onclick={() => removeRow(idx)}>
                        Remove
                    </Button>
                </div>
            {/each}
            <div class="flex gap-2">
                <Button variant="outline" size="sm" onclick={addRow}>Add entry</Button>
                <Button variant="outline" size="sm" onclick={copyReported}>
                    Copy from reported
                </Button>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <Button onclick={save} disabled={saving}>Save desired config</Button>
        </div>
    </section>

    <section class="space-y-3">
        <Heading
            variant="small"
            title="Bookkeeping (last 10)"
            description="Daily counters submitted via the bookkeeping endpoint."
        />
        {#if bookkeeping.length === 0}
            <p class="text-muted-foreground text-sm">No bookkeeping logs yet.</p>
        {:else}
            <div class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 text-left">Date</th>
                            <th class="px-3 py-2 text-left">Shop</th>
                            <th class="px-3 py-2 text-right">Plays</th>
                            <th class="px-3 py-2 text-right">Service sw</th>
                            <th class="px-3 py-2 text-right">Free play</th>
                            <th class="px-3 py-2 text-left">Credit cost / songs</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each bookkeeping as row, idx (idx)}
                            <tr class="border-t">
                                <td class="px-3 py-2 font-mono">{row.update_date || '—'}</td>
                                <td class="px-3 py-2 font-mono">{row.shop_id || '—'}</td>
                                <td class="px-3 py-2 text-right font-mono">{row.all_play_count}</td>
                                <td class="px-3 py-2 text-right font-mono">{row.service_switch_count}</td>
                                <td class="px-3 py-2 text-right font-mono">{row.free_play_count}</td>
                                <td class="text-muted-foreground px-3 py-2 font-mono text-xs">
                                    {row.payload?.credit_cost_1 ?? '—'}/{row.payload?.credit_cost_2 ?? '—'}
                                    · {row.payload?.credit_songs_1 ?? '—'}/{row.payload?.credit_songs_2 ?? '—'}
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        {/if}
    </section>
</div>
