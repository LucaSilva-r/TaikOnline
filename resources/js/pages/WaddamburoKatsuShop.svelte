<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import WaddamburoKatsuShopController from '@/actions/App/Http/Controllers/WaddamburoKatsuShopController';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import KatsuShopLayout from '@/components/KatsuShopLayout.svelte';
    import SideModeAccordion from '@/components/SideModeAccordion.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';

    type Item = {
        type: number;
        id: number;
        name: string;
        price: number;
        owned: boolean;
        version: string;
    };
    type SpriteItem = { id: number; x: number; y: number };
    type Sheet = {
        url: string;
        cell: number;
        width: number;
        height: number;
        slots: Record<string, SpriteItem[]>;
    } | null;

    let {
        hasPlayer,
        medals,
        perCredit,
        items,
        versions,
        sheet,
    }: {
        hasPlayer: boolean;
        medals: number;
        perCredit: number;
        items: Item[];
        versions: { key: string; label: string }[];
        sheet: Sheet;
    } = $props();

    // Shop types: 1 song, 3 kigurumi, 5 head, 4 body, 6 make, 2 tone; the sheet's slots for the costumes.
    const SECTIONS: { type: number; title: string; slot?: string }[] = [
        { type: 1, title: 'Songs' },
        { type: 3, title: 'Kigurumi', slot: 'kigurumi' },
        { type: 5, title: 'Heads', slot: 'head' },
        { type: 4, title: 'Bodies', slot: 'body' },
        { type: 6, title: 'Make', slot: 'face' },
        { type: 2, title: 'Drum sounds' },
        { type: 7, title: 'Puchi charas', slot: 'puchi' },
    ];
    const ICON = 48;

    function costumeSpriteStyle(slot: string | undefined, id: number): string {
        const item = slot
            ? sheet?.slots[slot]?.find((entry) => entry.id === id)
            : undefined;

        if (!sheet || !item) {
            return '';
        }

        const scale = ICON / sheet.cell;

        return `width:${ICON}px;height:${ICON}px;background-image:url(${sheet.url});background-size:${Math.round(sheet.width * scale)}px ${Math.round(sheet.height * scale)}px;background-position:-${Math.round(item.x * scale)}px -${Math.round(item.y * scale)}px;background-repeat:no-repeat;image-rendering:pixelated;`;
    }
</script>

<AppHead title="Katsu Medal Shop" />

<KatsuShopLayout active="Katsu Shop" {hasPlayer} {medals}>
    <p class="max-w-3xl text-sm text-muted-foreground">
        Shop goods from earlier editions, as Donder Hiroba sold them in Green.
        Each Waddamburo credit played with your card earns {perCredit}
        Katsu Medals, except in dan courses. Medals never reset. Purchases unlock
        in “Only what I earned”.
    </p>
    <div class="grid gap-3">
        {#each [...versions].reverse() as version (version.key)}
            {@const accent = taikoVersionAccent(version.key)}
            {@const editionItems = items.filter(
                (item) => item.version === version.key,
            )}
            <SideModeAccordion
                class="rounded-lg"
                title={version.label}
                description={`${editionItems.length} items`}
            >
                {#snippet icon()}
                    <AppLogoIcon class="size-7" accent={accent.swatch} />
                {/snippet}
                {#snippet overview()}
                    {#if hasPlayer}
                        <span class="text-sm tabular-nums text-muted-foreground"
                            >{editionItems.filter((item) => item.owned).length} owned</span
                        >
                    {/if}
                {/snippet}
                <div class="space-y-6 p-4 sm:p-5">
                    {#each SECTIONS as section (section.type)}
                        {@const goods = editionItems.filter(
                            (item) => item.type === section.type,
                        )}
                        {#if goods.length > 0}
                            <section class="space-y-3">
                                <h3 class="text-base font-semibold">
                                    {section.title}
                                    <span class="text-sm text-muted-foreground"
                                        >({goods.length})</span
                                    >
                                </h3>
                                <ul
                                    class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                                >
                                    {#each goods as item (item.id)}
                                        <li
                                            class="flex items-center gap-3 rounded-md border bg-background p-2"
                                        >
                                            {#if section.slot}
                                                <span
                                                    class="shrink-0"
                                                    style={costumeSpriteStyle(
                                                        section.slot,
                                                        item.id,
                                                    )}
                                                    aria-hidden="true"
                                                ></span>
                                            {/if}
                                            <span
                                                class="min-w-0 flex-1 truncate text-sm"
                                                title={item.name ||
                                                    `Puchi #${item.id}`}
                                                >{item.name ||
                                                    `Puchi #${item.id}`}</span
                                            >
                                            {#if item.owned}
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                    >Owned</span
                                                >
                                            {:else if hasPlayer}
                                                <Form
                                                    {...WaddamburoKatsuShopController.purchase.form(
                                                        taikoRouteParam(),
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {#snippet children({
                                                        processing,
                                                    })}
                                                        <input
                                                            type="hidden"
                                                            name="type"
                                                            value={item.type}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="id"
                                                            value={item.id}
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={processing ||
                                                                medals <
                                                                    item.price}
                                                            >{item.price}</Button
                                                        >
                                                    {/snippet}
                                                </Form>
                                            {:else}
                                                <span class="text-xs"
                                                    >{item.price}</span
                                                >
                                            {/if}
                                        </li>
                                    {/each}
                                </ul>
                            </section>
                        {/if}
                    {/each}
                </div>
            </SideModeAccordion>
        {/each}
    </div>
</KatsuShopLayout>
