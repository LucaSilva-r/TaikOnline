<script module lang="ts">
    import { edit } from '@/routes/costumes';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Costumes',
                href: edit(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import CostumeController from '@/actions/App/Http/Controllers/Settings/CostumeController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type SpriteItem = { id: number; x: number; y: number };
    type Sheet = {
        url: string;
        cell: number;
        width: number;
        height: number;
        slots: Record<string, SpriteItem[]>;
    } | null;
    type Preset = {
        costume_1: number;
        costume_2: number;
        costume_3: number;
        costume_5: number;
    };

    // Slot key -> (display label, preset field). Tab order mirrors the
    // donderhiroba きせかえ selector: full body, body, head, puchi.
    const SLOTS: { key: string; label: string; field: keyof Preset }[] = [
        { key: 'kigurumi', label: 'Full body', field: 'costume_1' },
        { key: 'body', label: 'Body', field: 'costume_3' },
        { key: 'head', label: 'Head', field: 'costume_2' },
        { key: 'puchi', label: 'Puchi-chara', field: 'costume_5' },
    ];

    let {
        hasAccessCode,
        versionLabel = '',
        sheet = null,
        presets,
        activePreset = 0,
    }: {
        hasAccessCode: boolean;
        versionLabel?: string;
        sheet?: Sheet;
        presets: Preset[];
        activePreset?: number;
    } = $props();

    let sets = $state<Preset[]>(presets.map((p) => ({ ...p })));
    let worn = $state(activePreset);
    let editIndex = $state(activePreset);

    // Only slots this version actually ships icons for (e.g. Sorairo = kigurumi
    // only; older versions have no puchi-chara).
    const availableSlots = $derived(SLOTS.filter((s) => (sheet?.slots[s.key] ?? []).length > 0));
    let activeTab = $state(SLOTS[0].key);
    $effect(() => {
        if (!availableSlots.some((s) => s.key === activeTab) && availableSlots.length > 0) {
            activeTab = availableSlots[0].key;
        }
    });

    const current = $derived(sets[editIndex]);

    function slotItems(key: string): SpriteItem[] {
        return sheet?.slots[key] ?? [];
    }

    // CSS background shorthand placing one sprite cell from the sheet.
    function sprite(item: SpriteItem): string {
        if (!sheet) {
            return '';
        }
        return `width:${sheet.cell}px;height:${sheet.cell}px;background-image:url(${sheet.url});background-position:-${item.x}px -${item.y}px;background-repeat:no-repeat;image-rendering:pixelated;`;
    }
</script>

<AppHead title="Costumes" />

<h1 class="sr-only">Costumes</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Costume picker"
        description="Set up your three きせかえ presets for {versionLabel}. Saved to your Banapassport."
    />

    {#if !hasAccessCode}
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-800">
                Link your Banapassport access code in the Profile settings to pick costumes.
            </p>
        </div>
    {:else}
        <Form
            {...CostumeController.update.form(taikoRouteParam())}
            class="space-y-6"
            options={{ preserveScroll: true }}
        >
            {#snippet children({ processing })}
                <!-- Preset selector -->
                <div class="grid gap-2">
                    <div class="flex items-center gap-2">
                        {#each sets as _set, i (i)}
                            <button
                                type="button"
                                onclick={() => (editIndex = i)}
                                class="flex-1 rounded-md border-2 px-3 py-2 text-sm font-medium transition-colors {editIndex ===
                                i
                                    ? 'border-primary bg-muted'
                                    : 'border-transparent bg-muted/40 hover:bg-muted/70'}"
                            >
                                Preset {i + 1}
                                {#if worn === i}
                                    <span class="ml-1 text-xs text-primary">★ worn</span>
                                {/if}
                            </button>
                        {/each}
                    </div>
                    {#if worn !== editIndex}
                        <button
                            type="button"
                            onclick={() => (worn = editIndex)}
                            class="self-start text-xs text-muted-foreground underline hover:text-foreground"
                        >
                            Wear this preset
                        </button>
                    {/if}
                </div>

                <!-- Slot tab bar -->
                <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                    {#each availableSlots as slot (slot.key)}
                        <button
                            type="button"
                            onclick={() => (activeTab = slot.key)}
                            class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors {activeTab ===
                            slot.key
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'}"
                        >
                            {slot.label}
                        </button>
                    {/each}
                </div>

                {#each availableSlots as slot (slot.key)}
                    {#if activeTab === slot.key}
                        <div class="grid gap-3">
                            <div class="flex items-center gap-3">
                                <span class="text-sm font-medium">{slot.label}</span>
                                <span class="text-xs text-muted-foreground">
                                    ID: {current[slot.field]}
                                </span>
                            </div>

                            {#if slot.key !== 'kigurumi' && current.costume_1 !== 0}
                                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    A Full body costume (ID {current.costume_1}) is set in this preset
                                    and overrides Body, Head and Puchi-chara in game. Set Full body to
                                    the default (ID 0) to show them.
                                </p>
                            {/if}

                            {#if slotItems(slot.key).length === 0}
                                <p class="text-xs text-muted-foreground">
                                    No items for this slot yet.
                                </p>
                            {:else}
                                <div
                                    class="overflow-y-auto rounded-md border p-2"
                                    style="display:grid;grid-template-columns:repeat(auto-fill,{sheet?.cell ??
                                        72}px);gap:0.5rem;max-height:24rem;justify-content:center;"
                                >
                                    {#each slotItems(slot.key) as item (item.id)}
                                        <button
                                            type="button"
                                            onclick={() => (current[slot.field] = item.id)}
                                            class="rounded-md border-2 transition-[border-color] hover:scale-105 {item.id ===
                                            current[slot.field]
                                                ? 'border-primary'
                                                : 'border-transparent'}"
                                            style={sprite(item)}
                                            title="ID: {item.id}"
                                            aria-label="{slot.label} {item.id}"
                                        ></button>
                                    {/each}
                                </div>
                            {/if}
                        </div>
                    {/if}
                {/each}

                <!-- Submitted state: every preset's parts + which one is worn. -->
                <input type="hidden" name="active_preset" value={worn} />
                {#each sets as set, i (i)}
                    {#each SLOTS as slot (slot.key)}
                        <input
                            type="hidden"
                            name="presets[{i}][{slot.field}]"
                            value={set[slot.field]}
                        />
                    {/each}
                {/each}

                <Button type="submit" disabled={processing} data-test="update-costumes-button">
                    Save
                </Button>
            {/snippet}
        </Form>
    {/if}
</div>
