<script module lang="ts">
    import { status } from '@/routes/admin';
    import { taikoRouteParam } from '@/lib/taiko-version';
    export const layout = {
        breadcrumbs: [{ title: 'Server Status', href: status(taikoRouteParam()) }],
    };
</script>

<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    type DataFile = {
        name: string;
        present: boolean;
        path: string;
    };

    let {
        gameData,
        protobuf,
    }: {
        gameData: DataFile[];
        protobuf: Record<string, boolean>;
    } = $props();
</script>

<AppHead title="Server Status" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">Server Status</h1>
        <p class="text-sm text-muted-foreground">Green protobuf generation and required datatable files.</p>
    </div>

    <section class="rounded-md border">
        <div class="border-b px-3 py-2 font-medium">Protobuf</div>
        <div class="grid gap-2 p-3 text-sm sm:grid-cols-3">
            {#each Object.entries(protobuf) as [name, present] (name)}
                <div class="flex items-center justify-between rounded border px-3 py-2">
                    <span>{name}</span>
                    <span class={present ? 'text-green-600' : 'text-destructive'}>{present ? 'OK' : 'Missing'}</span>
                </div>
            {/each}
        </div>
    </section>

    <section class="rounded-md border">
        <div class="border-b px-3 py-2 font-medium">Game Data</div>
        <table class="w-full text-sm">
            <tbody>
                {#each gameData as file (file.name)}
                    <tr class="border-b last:border-0">
                        <td class="px-3 py-2">{file.name}</td>
                        <td class="px-3 py-2 font-mono text-xs">{file.path}</td>
                        <td class={file.present ? 'px-3 py-2 text-green-600' : 'px-3 py-2 text-destructive'}>
                            {file.present ? 'OK' : 'Missing'}
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </section>
</div>
