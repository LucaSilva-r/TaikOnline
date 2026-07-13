<script module lang="ts">
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    import { edit } from '@/routes/costumes';

    export const layout = {
        wide: true,
        breadcrumbs: [
            {
                title: 'DonChan',
                href: edit(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import CostumeController from '@/actions/App/Http/Controllers/Settings/CostumeController';
    import CustomizeController from '@/actions/App/Http/Controllers/Settings/CustomizeController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { romajiToHiragana } from '@/lib/romaji';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type ColorTab = 'face' | 'body' | 'limb';
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
    type TitlePlate = {
        id: number;
        label: string;
        backgroundClass: string;
    };
    type OfficialTitle = {
        id: number;
        name: string;
        plate: number;
    };

    const TITLE_PLATES: TitlePlate[] = [
        {
            id: 0,
            label: 'Wood',
            backgroundClass:
                'bg-[repeating-linear-gradient(0deg,#d58a2c_0px,#d58a2c_2px,#efa94f_2px,#efa94f_5px)]',
        },
        {
            id: 1,
            label: 'Rainbow',
            backgroundClass:
                'bg-[linear-gradient(90deg,#ff5571_0%,#ffad4c_18%,#ffe85e_35%,#67d885_53%,#62b8ff_70%,#b67cff_86%,#ff69cc_100%)]',
        },
        {
            id: 2,
            label: 'Gold',
            backgroundClass:
                'bg-[linear-gradient(90deg,#bf6500_0%,#ffc322_20%,#fff2a4_48%,#f6b400_73%,#ad5000_100%)]',
        },
        {
            id: 3,
            label: 'Purple',
            backgroundClass:
                'bg-[linear-gradient(90deg,#8119d4_0%,#c85cff_22%,#f0b1ff_50%,#bc4cf3_76%,#7011bd_100%)]',
        },
    ];

    const COLORS: string[] = [
        '#f94729',
        '#68c0c1',
        '#dd1400',
        '#f8f1df',
        '#019587',
        '#00bf86',
        '#00ff99',
        '#65ffc3',
        '#ffffff',
        '#690001',
        '#fe0000',
        '#ff6a65',
        '#feb2b4',
        '#00bbc2',
        '#00f7ff',
        '#66fafe',
        '#b4feff',
        '#e4e4e4',
        '#993900',
        '#ff5f01',
        '#ff9e79',
        '#fecfb3',
        '#024f95',
        '#0088fe',
        '#68b8ff',
        '#b3dbff',
        '#b9b9b9',
        '#b37802',
        '#ffaa00',
        '#ffcc67',
        '#fee2b3',
        '#000d80',
        '#0119ff',
        '#6774ff',
        '#b3baff',
        '#858585',
        '#b49b01',
        '#ffdd00',
        '#ffff00',
        '#feff71',
        '#2b0181',
        '#5600ff',
        '#9966ff',
        '#ccb4ff',
        '#505050',
        '#39a102',
        '#77c800',
        '#b3ff00',
        '#ddff8c',
        '#62007e',
        '#c600ff',
        '#df69fe',
        '#edb3ff',
        '#232323',
        '#006600',
        '#02b900',
        '#00ff00',
        '#89ff9e',
        '#990158',
        '#ff0097',
        '#ff67be',
        '#ffb4df',
        '#000000',
    ];

    const COLOR_IDS = Array.from({ length: 63 }, (_, index) => index);
    const COLOR_TABS: { key: ColorTab; label: string; field: string }[] = [
        { key: 'face', label: 'Face', field: 'color_face' },
        { key: 'body', label: 'Body', field: 'color_body' },
        { key: 'limb', label: 'Limb', field: 'color_limb' },
    ];

    const SLOTS: { key: string; label: string; field: keyof Preset }[] = [
        { key: 'kigurumi', label: 'Full body', field: 'costume_1' },
        { key: 'body', label: 'Body', field: 'costume_3' },
        { key: 'head', label: 'Head', field: 'costume_2' },
        { key: 'puchi', label: 'Puchi-chara', field: 'costume_5' },
    ];
    const PICKER_ICON_SIZE = 64;
    const PICKER_BUTTON_SIZE = 56;

    let {
        supported = true,
        supportsTitlePlates = false,
        hasAccessCode,
        versionLabel = '',
        sheet = null,
        presets,
        activePreset = 0,
        mydonName = '',
        title = '',
        titlePlateId = 0,
        officialTitles = [],
        officialTitleId = 0,
        colorFace = 0,
        colorBody = 0,
        colorLimb = 0,
    }: {
        supported?: boolean;
        supportsTitlePlates?: boolean;
        hasAccessCode: boolean;
        versionLabel?: string;
        sheet?: Sheet;
        presets: Preset[];
        activePreset?: number;
        mydonName?: string;
        title?: string;
        titlePlateId?: number;
        officialTitles?: OfficialTitle[];
        officialTitleId?: number;
        colorFace?: number;
        colorBody?: number;
        colorLimb?: number;
    } = $props();

    let donChanName = $state(mydonName);
    let donChanTitle = $derived(title);
    let selectedTitlePlateId = $derived(titlePlateById(titlePlateId).id);
    let selectedOfficialTitleId = $derived(officialTitleId);
    let selectedFace = $state(colorFace);
    let selectedBody = $state(colorBody);
    let selectedLimb = $state(colorLimb);
    let activeColorTab = $state<ColorTab>('face');

    let sets = $state<Preset[]>(presets.map((p) => ({ ...p })));
    let worn = $state(activePreset);
    let editIndex = $state(activePreset);

    const availableSlots = $derived(
        SLOTS.filter((s) => (sheet?.slots[s.key] ?? []).length > 0),
    );
    let activeCostumeTab = $state(SLOTS[0].key);
    $effect(() => {
        if (
            !availableSlots.some((s) => s.key === activeCostumeTab) &&
            availableSlots.length > 0
        ) {
            activeCostumeTab = availableSlots[0].key;
        }
    });

    const current = $derived(sets[editIndex]);

    function updateDonChanName(event: Event): void {
        donChanName = (event.currentTarget as HTMLInputElement).value;
    }

    function transliterateDonChanName(): void {
        donChanName = romajiToHiragana(donChanName);
    }

    function updateDonChanTitle(event: Event): void {
        donChanTitle = (event.currentTarget as HTMLInputElement).value;
    }

    function titlePlateById(id: number): TitlePlate {
        return (
            TITLE_PLATES.find((plate) => plate.id === id) ?? TITLE_PLATES[0]!
        );
    }

    const selectedTitlePlate = $derived(titlePlateById(selectedTitlePlateId));
    const selectedOfficialTitle = $derived(
        officialTitles.find(
            (officialTitle) => officialTitle.id === selectedOfficialTitleId,
        ),
    );
    const selectedOfficialTitlePlate = $derived(
        titlePlateById(selectedOfficialTitle?.plate ?? 0),
    );

    function selectedColor(key: ColorTab): number {
        if (key === 'body') {
            return selectedBody;
        }

        if (key === 'limb') {
            return selectedLimb;
        }

        return selectedFace;
    }

    function selectColor(key: ColorTab, colorId: number): void {
        if (key === 'body') {
            selectedBody = colorId;

            return;
        }

        if (key === 'limb') {
            selectedLimb = colorId;

            return;
        }

        selectedFace = colorId;
    }

    function slotItems(key: string): SpriteItem[] {
        return sheet?.slots[key] ?? [];
    }

    function sprite(item: SpriteItem): string {
        if (!sheet) {
            return '';
        }

        const scale = PICKER_ICON_SIZE / sheet.cell;
        const backgroundWidth = Math.round(sheet.width * scale);
        const backgroundHeight = Math.round(sheet.height * scale);
        const x = Math.round(item.x * scale);
        const y = Math.round(item.y * scale);

        return `display:block;width:${PICKER_BUTTON_SIZE}px;height:${PICKER_BUTTON_SIZE}px;background-image:url(${sheet.url});background-size:${backgroundWidth}px ${backgroundHeight}px;background-position:-${x}px -${y}px;background-repeat:no-repeat;image-rendering:pixelated;`;
    }
</script>

<AppHead title="DonChan" />

<h1 class="sr-only">DonChan</h1>

<div class="flex w-full max-w-5xl flex-col space-y-10">
    <Heading
        variant="small"
        title="DonChan"
        description="Manage your DonChan name, title, colors, and costume presets for {versionLabel}."
    />

    {#if !supported}
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-800">
                DonChan costume customization isn't available in {versionLabel}.
                The costume slot system (body / head / makeup) was introduced in
                Momoiro — switch to a newer version to edit your DonChan.
            </p>
        </div>
    {:else if !hasAccessCode}
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-800">
                Link your Banapassport access code in the Profile settings to
                customize your DonChan.
            </p>
        </div>
    {:else}
        <section class="w-full space-y-4">
            <Heading
                variant="small"
                title="Name"
                description="Change the name shown for your DonChan."
            />

            <Form
                {...CustomizeController.updateName.form(taikoRouteParam())}
                class="space-y-6"
                options={{ preserveScroll: true }}
            >
                {#snippet children({ errors, processing })}
                    <div class="grid max-w-md gap-2">
                        <Label for="mydon_name">DonChan name</Label>
                        <Input
                            id="mydon_name"
                            name="mydon_name"
                            value={donChanName}
                            oninput={updateDonChanName}
                            onblur={transliterateDonChanName}
                            required
                            autocomplete="off"
                            placeholder="donchan → どんちゃん"
                            aria-describedby="mydon-name-help"
                        />
                        <div
                            id="mydon-name-help"
                            class="flex items-start justify-between gap-4 text-sm text-muted-foreground"
                        >
                            <p>
                                Up to 5 hiragana. Romaji is converted when you
                                leave the field.
                            </p>
                            <span class="shrink-0"
                                >{Array.from(donChanName).length}/5</span
                            >
                        </div>
                        <InputError message={errors.mydon_name} />
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-donchan-name-button"
                    >
                        Save name
                    </Button>
                {/snippet}
            </Form>
        </section>

        <section class="w-full space-y-4">
            <Heading
                variant="small"
                title="Title"
                description="Choose a custom or official title for {versionLabel}."
            />

            <Form
                {...CustomizeController.updateTitle.form(taikoRouteParam())}
                class="space-y-6 rounded-lg border p-4"
                options={{ preserveScroll: true }}
            >
                {#snippet children({ errors, processing })}
                    <div>
                        <h3 class="font-medium">Custom title</h3>
                        <p class="text-sm text-muted-foreground">
                            Custom text and its plate are saved only for {versionLabel}.
                        </p>
                    </div>

                    <div class="grid max-w-md gap-2">
                        <Label for="title">Title text</Label>
                        <Input
                            id="title"
                            name="title"
                            value={donChanTitle}
                            oninput={updateDonChanTitle}
                            maxlength={255}
                            autocomplete="off"
                            placeholder="ほんのきもち"
                            aria-describedby="title-help"
                        />
                        <p
                            id="title-help"
                            class="text-sm text-muted-foreground"
                        >
                            Leave it empty to remove this version's title.
                        </p>
                        <InputError message={errors.title} />
                    </div>

                    {#if supportsTitlePlates}
                        <div class="grid max-w-md gap-2">
                            <Label for="titleplate_id"
                                >Title plate background</Label
                            >
                            <select
                                id="titleplate_id"
                                name="titleplate_id"
                                bind:value={selectedTitlePlateId}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                aria-describedby="titleplate-help"
                            >
                                {#each TITLE_PLATES as plate (plate.id)}
                                    <option
                                        value={plate.id}
                                        class="bg-popover text-popover-foreground"
                                    >
                                        {plate.label} (ID {plate.id})
                                    </option>
                                {/each}
                            </select>
                            <p
                                id="titleplate-help"
                                class="text-sm text-muted-foreground"
                            >
                                These are the four backgrounds GEN 3 accepts for
                                custom text.
                            </p>
                            <InputError message={errors.titleplate_id} />
                        </div>

                        <div class="grid max-w-md gap-2">
                            <span class="text-sm font-medium">Preview</span>
                            <div
                                class="flex min-h-28 items-center justify-center overflow-hidden rounded-md border bg-muted/30 p-4"
                            >
                                <div
                                    class="relative flex aspect-[8.5/1] w-full max-w-72 items-center justify-center overflow-hidden rounded-full border-2 border-zinc-900 px-7 shadow-[inset_0_2px_0_rgba(255,255,255,0.7),inset_0_-2px_0_rgba(0,0,0,0.22),0_2px_0_rgba(0,0,0,0.2)] {selectedTitlePlate.backgroundClass}"
                                    role="img"
                                    aria-label="{selectedTitlePlate.label} title plate preview"
                                >
                                    <span
                                        class="truncate text-center text-sm font-black tracking-wide text-zinc-950 [text-shadow:0_1px_0_rgba(255,255,255,0.65)] sm:text-base"
                                    >
                                        {donChanTitle || 'Title preview'}
                                    </span>
                                </div>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                CSS approximation; the game renders its own
                                background artwork.
                            </p>
                        </div>
                    {/if}

                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-donchan-title-button"
                    >
                        Save custom title
                    </Button>
                {/snippet}
            </Form>

            {#if officialTitles.length > 0}
                <Form
                    {...CustomizeController.updateOfficialTitle.form(
                        taikoRouteParam(),
                    )}
                    class="space-y-6 rounded-lg border p-4"
                    options={{ preserveScroll: true }}
                >
                    {#snippet children({ errors, processing })}
                        <div>
                            <h3 class="font-medium">Official title</h3>
                            <p class="text-sm text-muted-foreground">
                                Selecting one applies its exact text, unlock
                                flag, and game-defined plate for {versionLabel}.
                            </p>
                        </div>

                        <div class="grid max-w-md gap-2">
                            <Label for="title_id">Title catalog</Label>
                            <select
                                id="title_id"
                                name="title_id"
                                bind:value={selectedOfficialTitleId}
                                required
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option
                                    value={0}
                                    disabled
                                    class="bg-popover text-popover-foreground"
                                >
                                    Choose an official title…
                                </option>
                                {#each officialTitles as officialTitle (officialTitle.id)}
                                    <option
                                        value={officialTitle.id}
                                        class="bg-popover text-popover-foreground"
                                    >
                                        #{officialTitle.id} — {officialTitle.name}
                                    </option>
                                {/each}
                            </select>
                            <p class="text-sm text-muted-foreground">
                                {officialTitles.length} titles are available in this
                                version.
                            </p>
                            <InputError message={errors.title_id} />
                        </div>

                        {#if selectedOfficialTitle}
                            <div class="grid max-w-md gap-2">
                                <span class="text-sm font-medium">Preview</span>
                                <div
                                    class="flex min-h-28 items-center justify-center overflow-hidden rounded-md border bg-muted/30 p-4"
                                >
                                    <div
                                        class="relative flex aspect-[8.5/1] w-full max-w-72 items-center justify-center overflow-hidden rounded-full border-2 border-zinc-900 px-7 shadow-[inset_0_2px_0_rgba(255,255,255,0.7),inset_0_-2px_0_rgba(0,0,0,0.22),0_2px_0_rgba(0,0,0,0.2)] {selectedOfficialTitlePlate.backgroundClass}"
                                        role="img"
                                        aria-label="{selectedOfficialTitlePlate.label} title plate preview"
                                    >
                                        <span
                                            class="truncate text-center text-sm font-black tracking-wide text-zinc-950 [text-shadow:0_1px_0_rgba(255,255,255,0.65)] sm:text-base"
                                        >
                                            {selectedOfficialTitle.name}
                                        </span>
                                    </div>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    CSS approximation; the game renders its own
                                    background artwork.
                                </p>
                            </div>
                        {/if}

                        <Button
                            type="submit"
                            disabled={processing ||
                                selectedOfficialTitleId === 0}
                            data-test="update-official-donchan-title-button"
                        >
                            Use official title
                        </Button>
                    {/snippet}
                </Form>
            {/if}
        </section>

        <section class="w-full space-y-4">
            <Heading
                variant="small"
                title="Colors"
                description="Change the Face, Body, and Limb colors saved to your Banapassport."
            />

            <p
                class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
            >
                Color settings are currently shared across all game versions.
                Changing them here will update DonChan colors everywhere, not
                only {versionLabel}.
            </p>

            <Form
                {...CustomizeController.update.form(taikoRouteParam())}
                class="space-y-6"
                options={{ preserveScroll: true }}
            >
                {#snippet children({ errors, processing })}
                    <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                        {#each COLOR_TABS as tab (tab.key)}
                            <button
                                type="button"
                                onclick={() => (activeColorTab = tab.key)}
                                class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors {activeColorTab ===
                                tab.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'}"
                            >
                                {tab.label}
                            </button>
                        {/each}
                    </div>

                    {#each COLOR_TABS as tab (tab.key)}
                        {#if activeColorTab === tab.key}
                            <div class="grid gap-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-medium"
                                        >{tab.label}</span
                                    >
                                    <div
                                        class="h-4 w-4 rounded border border-muted-foreground/20"
                                        style="background-color: {COLORS[
                                            selectedColor(tab.key)
                                        ]}; border-color: {COLORS[
                                            selectedColor(tab.key)
                                        ]};"
                                    ></div>
                                    <span class="text-xs text-muted-foreground">
                                        ID: {selectedColor(tab.key)}
                                    </span>
                                </div>

                                <div
                                    class="grid grid-cols-[repeat(9,minmax(0,1fr))] gap-1"
                                >
                                    {#each COLOR_IDS as colorId (colorId)}
                                        <button
                                            type="button"
                                            onclick={() =>
                                                selectColor(tab.key, colorId)}
                                            class="h-8 w-full rounded-md border-2 transition-[border-color] hover:scale-105"
                                            style="background-color: {COLORS[
                                                colorId
                                            ]}; border-color: {colorId ===
                                            selectedColor(tab.key)
                                                ? 'currentColor'
                                                : 'transparent'};"
                                            title={String(colorId)}
                                            aria-label="{tab.label} color {colorId}"
                                        ></button>
                                    {/each}
                                </div>

                                <InputError message={errors[tab.field]} />
                            </div>
                        {/if}
                    {/each}

                    <input
                        type="hidden"
                        name="color_face"
                        value={selectedFace}
                    />
                    <input
                        type="hidden"
                        name="color_body"
                        value={selectedBody}
                    />
                    <input
                        type="hidden"
                        name="color_limb"
                        value={selectedLimb}
                    />

                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-customization-button"
                        >Save colors</Button
                    >
                {/snippet}
            </Form>
        </section>

        <section class="w-full space-y-4">
            <Heading
                variant="small"
                title="Costumes"
                description="Set up your three costume presets for {versionLabel}."
            />

            <Form
                {...CostumeController.update.form(taikoRouteParam())}
                class="space-y-6"
                options={{ preserveScroll: true }}
            >
                {#snippet children({ processing })}
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
                                        <span class="ml-1 text-xs text-primary"
                                            >worn</span
                                        >
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

                    <div class="flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                        {#each availableSlots as slot (slot.key)}
                            <button
                                type="button"
                                onclick={() => (activeCostumeTab = slot.key)}
                                class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors {activeCostumeTab ===
                                slot.key
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'}"
                            >
                                {slot.label}
                            </button>
                        {/each}
                    </div>

                    {#each availableSlots as slot (slot.key)}
                        {#if activeCostumeTab === slot.key}
                            <div class="grid gap-3">
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-medium"
                                        >{slot.label}</span
                                    >
                                    <span class="text-xs text-muted-foreground">
                                        ID: {current[slot.field]}
                                    </span>
                                </div>

                                {#if slot.key !== 'kigurumi' && current.costume_1 !== 0}
                                    <p
                                        class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                                    >
                                        A Full body costume (ID {current.costume_1})
                                        is set in this preset and overrides
                                        Body, Head and Puchi-chara in game. Set
                                        Full body to the default (ID 0) to show
                                        them.
                                    </p>
                                {/if}

                                {#if slotItems(slot.key).length === 0}
                                    <p class="text-xs text-muted-foreground">
                                        No items for this slot yet.
                                    </p>
                                {:else}
                                    <div
                                        class="overflow-y-auto rounded-md border p-2"
                                        style="display:grid;grid-template-columns:repeat(auto-fill,{PICKER_BUTTON_SIZE}px);gap:0.5rem;max-height:24rem;justify-content:center;"
                                    >
                                        {#each slotItems(slot.key) as item (item.id)}
                                            <button
                                                type="button"
                                                onclick={() =>
                                                    (current[slot.field] =
                                                        item.id)}
                                                class="flex items-center justify-center overflow-hidden rounded-md border-2 bg-white transition-[border-color] hover:scale-105 {item.id ===
                                                current[slot.field]
                                                    ? 'border-primary'
                                                    : 'border-transparent'}"
                                                style="width:{PICKER_BUTTON_SIZE}px;height:{PICKER_BUTTON_SIZE}px;"
                                                title="ID: {item.id}"
                                                aria-label="{slot.label} {item.id}"
                                            >
                                                <span style={sprite(item)}
                                                ></span>
                                            </button>
                                        {/each}
                                    </div>
                                {/if}
                            </div>
                        {/if}
                    {/each}

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

                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-costumes-button"
                    >
                        Save costumes
                    </Button>
                {/snippet}
            </Form>
        </section>
    {/if}
</div>
