<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import WaddamburoKatsuShopController from '@/actions/App/Http/Controllers/WaddamburoKatsuShopController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';
    import gacha from '@/routes/gacha';

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

    // Shop types: 1 song, 3 kigurumi, 5 head, 4 body, 2 tone; the sheet's slots for the costumes.
    const SECTIONS: { type: number; title: string; slot?: string }[] = [
        { type: 1, title: 'Songs' },
        { type: 3, title: 'Kigurumi', slot: 'kigurumi' },
        { type: 5, title: 'Heads', slot: 'head' },
        { type: 4, title: 'Bodies', slot: 'body' },
        { type: 2, title: 'Drum sounds' },
    ];
    const ICON = 48;

    function icon(slot: string | undefined, id: number): string {
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

<section class="mx-auto w-full max-w-5xl space-y-8 px-4 py-10">
    <nav class="flex gap-2">
        <span
            class="rounded-full bg-sky-600 px-4 py-1 text-sm font-semibold text-white"
            >Katsu Shop</span
        >
        <Link
            href={gacha.index(taikoRouteParam()).url}
            class="rounded-full border px-4 py-1 text-sm">Gacha</Link
        >
    </nav>
    <header
        class="space-y-2 rounded-xl border-l-8 bg-gradient-to-r from-amber-100 to-sky-100 p-6 dark:from-amber-950/40 dark:to-sky-950/40"
        style="border-color: hsl(195 89% 42%)"
    >
        <h1 class="text-2xl font-semibold">Katsu Medal Shop</h1>
        <p class="text-sm text-muted-foreground">
            Goods from earlier versions, as Donder Hiroba sold them in Green:
            buy anything, any time. Every credit you play in Waddamburo with
            your card gives {perCredit} Katsu Medals (dan courses give none); they
            never reset. What you buy unlocks in "Only what I earned".
        </p>
        {#if hasPlayer}
            <p
                class="inline-flex items-center gap-2 rounded-full bg-sky-600 px-4 py-1 text-lg font-semibold text-white"
                data-test="katsu-medals"
            >
                {medals.toLocaleString()} Katsu Medals
            </p>
        {:else}
            <p class="text-sm">Link a card to earn and spend Katsu Medals.</p>
        {/if}
    </header>

    {#each versions as version (version.key)}
        {@const accent = taikoVersionAccent(version.key)}
        <section
            class="space-y-6 rounded-xl border-2 p-4"
            style="border-color: {accent.swatch}"
        >
            <h2
                class="-mx-4 -mt-4 rounded-t-lg px-4 py-2 text-xl font-bold"
                style="background: {accent.swatch}; color: {accent.primaryForeground}"
            >
                {version.label}
            </h2>
            {#each SECTIONS as section (section.type)}
                {@const goods = items.filter(
                    (item) =>
                        item.version === version.key &&
                        item.type === section.type,
                )}
                {#if goods.length > 0}
                    <section class="space-y-3">
                        <h3
                            class="text-base font-semibold"
                            style="color: {accent.label}"
                        >
                            {section.title}
                            <span class="text-sm text-muted-foreground"
                                >({goods.length})</span
                            >
                        </h3>
                        <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {#each goods as item (item.id)}
                                <li
                                    class="flex items-center gap-3 rounded-md border border-l-4 bg-card p-2"
                                    style="border-left-color: {accent.swatch}"
                                >
                                    {#if section.slot}
                                        <span
                                            class="shrink-0"
                                            style={icon(section.slot, item.id)}
                                            aria-hidden="true"
                                        ></span>
                                    {/if}
                                    <span
                                        class="min-w-0 flex-1 truncate text-sm"
                                        title={item.name}>{item.name}</span
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
                                            options={{ preserveScroll: true }}
                                        >
                                            {#snippet children({ processing })}
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
                                                        medals < item.price}
                                                    >{item.price}</Button
                                                >
                                            {/snippet}
                                        </Form>
                                    {:else}
                                        <span class="text-xs">{item.price}</span
                                        >
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}
            {/each}
        </section>
    {/each}
</section>
