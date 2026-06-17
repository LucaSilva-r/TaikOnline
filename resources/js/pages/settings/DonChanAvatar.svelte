<script module lang="ts">
    import { edit } from '@/routes/avatar';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profile Picture',
                href: edit(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import AvatarController from '@/actions/App/Http/Controllers/Settings/AvatarController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Button } from '@/components/ui/button';
    import { DonchanRenderer, FACE_FRAME_COUNT, hexToRgb } from '@/lib/donchan/renderer';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type SpriteItem = { id: number; x: number; y: number };
    type Sheet = {
        url: string;
        cell: number;
        width: number;
        height: number;
        items: SpriteItem[];
    } | null;
    type ColorTab = 'face' | 'body' | 'limb';

    const COLORS: string[] = [
        '#f94729', '#68c0c1', '#dd1400', '#f8f1df', '#019587', '#00bf86',
        '#00ff99', '#65ffc3', '#ffffff', '#690001', '#fe0000', '#ff6a65',
        '#feb2b4', '#00bbc2', '#00f7ff', '#66fafe', '#b4feff', '#e4e4e4',
        '#993900', '#ff5f01', '#ff9e79', '#fecfb3', '#024f95', '#0088fe',
        '#68b8ff', '#b3dbff', '#b9b9b9', '#b37802', '#ffaa00', '#ffcc67',
        '#fee2b3', '#000d80', '#0119ff', '#6774ff', '#b3baff', '#858585',
        '#b49b01', '#ffdd00', '#ffff00', '#feff71', '#2b0181', '#5600ff',
        '#9966ff', '#ccb4ff', '#505050', '#39a102', '#77c800', '#b3ff00',
        '#ddff8c', '#62007e', '#c600ff', '#df69fe', '#edb3ff', '#232323',
        '#006600', '#02b900', '#00ff00', '#89ff9e', '#990158', '#ff0097',
        '#ff67be', '#ffb4df', '#000000',
    ];
    const COLOR_IDS = Array.from({ length: 63 }, (_, index) => index);
    const COLOR_TABS: { key: ColorTab; label: string }[] = [
        { key: 'face', label: 'Face' },
        { key: 'body', label: 'Body' },
        { key: 'limb', label: 'Limb' },
    ];

    let {
        hasAvatar = false,
        avatar = null,
        versionLabel = '',
        kigurumiSheet = null,
        faces = [],
        defaults,
    }: {
        hasAvatar?: boolean;
        avatar?: string | null;
        versionLabel?: string;
        kigurumiSheet?: Sheet;
        faces?: string[];
        defaults: {
            costume: number;
            colorFace: number;
            colorBody: number;
            colorLimb: number;
            face: string | null;
            faceFrame: number;
        };
    } = $props();

    let canvas = $state<HTMLCanvasElement>();
    let renderer: DonchanRenderer | null = null;

    let costume = $state(defaults.costume);
    let colorFace = $state(defaults.colorFace);
    let colorBody = $state(defaults.colorBody);
    let colorLimb = $state(defaults.colorLimb);
    let face = $state(defaults.face ?? faces[0] ?? '');
    let faceFrame = $state(defaults.faceFrame ?? 0);
    let activeColorTab = $state<ColorTab>('face');

    let loading = $state(true);
    let saving = $state(false);

    let animations = $state<string[]>([]);
    let selectedAnimation = $state('');
    let playing = $state(true);
    let scrub = $state(0);

    const MODEL_BASE = '/donchan/models/cos';
    const FACE_BASE = '/donchan/face';
    const ANIMATIONS = '/donchan/animations.glb';

    function prettyAnimation(name: string): string {
        return name.replace(/_/g, ' ').replace(/\bdon\b/i, 'Don');
    }

    function selectedColor(key: ColorTab): number {
        return key === 'body' ? colorBody : key === 'limb' ? colorLimb : colorFace;
    }

    function pushColors(): void {
        renderer?.setColors(
            hexToRgb(COLORS[colorBody]),
            hexToRgb(COLORS[colorFace]),
            hexToRgb(COLORS[colorLimb]),
        );
        renderer?.render();
    }

    async function loadCostume(id: number): Promise<void> {
        if (!renderer) {
            return;
        }

        loading = true;
        await renderer.loadCostume(`${MODEL_BASE}/${id}.glb`, ANIMATIONS);
        renderer.setColors(
            hexToRgb(COLORS[colorBody]),
            hexToRgb(COLORS[colorFace]),
            hexToRgb(COLORS[colorLimb]),
        );
        if (face) {
            await renderer.setFace(`${FACE_BASE}/${face}`, faceFrame);
        }
        animations = renderer.animationNames;
        selectedAnimation = renderer.currentAnimation ?? animations[0] ?? '';
        renderer.setPlaying(playing);
        renderer.render();
        loading = false;
    }

    function selectAnimation(name: string): void {
        selectedAnimation = name;
        renderer?.playClip(name);
        renderer?.setPlaying(playing);
    }

    function togglePlaying(): void {
        playing = !playing;
        renderer?.setPlaying(playing);
    }

    let dragging = false;

    function onPointerDown(event: PointerEvent): void {
        dragging = true;
        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    }

    function onPointerMove(event: PointerEvent): void {
        if (!dragging) {
            return;
        }
        renderer?.rotateBy(event.movementX * 0.01, event.movementY * 0.01);
    }

    function onPointerUp(event: PointerEvent): void {
        dragging = false;
        (event.currentTarget as HTMLElement).releasePointerCapture(event.pointerId);
    }

    function resetRotation(): void {
        renderer?.resetRotation();
    }

    function onScrub(value: number): void {
        playing = false;
        scrub = value;
        renderer?.seek(value / 100);
    }

    function selectCostume(id: number): void {
        costume = id;
        void loadCostume(id);
    }

    function selectColor(key: ColorTab, id: number): void {
        if (key === 'body') {
            colorBody = id;
        } else if (key === 'limb') {
            colorLimb = id;
        } else {
            colorFace = id;
        }
        pushColors();
    }

    async function selectFace(file: string): Promise<void> {
        face = file;
        await renderer?.setFace(`${FACE_BASE}/${file}`, faceFrame);
        renderer?.render();
    }

    function stepFaceFrame(delta: number): void {
        faceFrame = ((faceFrame + delta) % FACE_FRAME_COUNT + FACE_FRAME_COUNT) % FACE_FRAME_COUNT;
        renderer?.setFaceFrame(faceFrame);
    }

    function save(): void {
        if (!renderer) {
            return;
        }

        saving = true;
        const image = renderer.screenshot();
        router.post(
            AvatarController.update(taikoRouteParam()).url,
            {
                image,
                costume,
                color_face: colorFace,
                color_body: colorBody,
                color_limb: colorLimb,
                face,
                face_frame: faceFrame,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    saving = false;
                },
            },
        );
    }

    function sprite(item: SpriteItem): string {
        if (!kigurumiSheet) {
            return '';
        }

        return `width:56px;height:56px;background-image:url(${kigurumiSheet.url});background-position:-${item.x}px -${item.y}px;background-repeat:no-repeat;image-rendering:pixelated;`;
    }

    onMount(() => {
        if (!canvas) {
            return;
        }

        renderer = new DonchanRenderer(canvas, 512);
        renderer.onFrame = (normalized: number) => {
            scrub = Math.round(normalized * 100);
        };
        void loadCostume(costume);

        return () => {
            renderer?.dispose();
            renderer = null;
        };
    });
</script>

<AppHead title="Profile Picture" />

<h1 class="sr-only">Profile Picture</h1>

<div class="flex flex-col space-y-8">
    <Heading
        variant="small"
        title="Profile Picture"
        description="Customize your Don and set it as your profile picture for {versionLabel}."
    />

    <div class="flex flex-col gap-8 lg:flex-row">
        <div class="flex flex-col items-center gap-4">
            <div class="relative h-[320px] w-[320px] overflow-hidden rounded-lg border bg-[linear-gradient(45deg,#f3f4f6_25%,transparent_25%,transparent_75%,#f3f4f6_75%),linear-gradient(45deg,#f3f4f6_25%,#fff_25%,#fff_75%,#f3f4f6_75%)] [background-position:0_0,10px_10px] [background-size:20px_20px]">
                <canvas
                    bind:this={canvas}
                    class="h-full w-full cursor-grab touch-none active:cursor-grabbing"
                    onpointerdown={onPointerDown}
                    onpointermove={onPointerMove}
                    onpointerup={onPointerUp}
                ></canvas>
                {#if loading}
                    <div class="absolute inset-0 flex items-center justify-center bg-background/60 text-sm text-muted-foreground">
                        Loading…
                    </div>
                {/if}
            </div>

            {#if animations.length > 0}
                <div class="flex w-[320px] flex-col gap-2">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick={togglePlaying}
                            class="rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                            aria-label={playing ? 'Pause' : 'Play'}
                        >
                            {playing ? '⏸' : '▶'}
                        </button>
                        <select
                            value={selectedAnimation}
                            onchange={(event) => selectAnimation(event.currentTarget.value)}
                            class="flex-1 rounded-md border bg-background px-2 py-1.5 text-sm"
                        >
                            {#each animations as name (name)}
                                <option value={name}>{prettyAnimation(name)}</option>
                            {/each}
                        </select>
                    </div>
                    <input
                        type="range"
                        min="0"
                        max="100"
                        value={scrub}
                        oninput={(event) => onScrub(Number(event.currentTarget.value))}
                        class="w-full"
                        aria-label="Animation frame"
                    />
                    <p class="text-center text-xs text-muted-foreground">
                        Play an animation, then pause and scrub to the pose you want.
                    </p>
                </div>
            {/if}

            <button
                type="button"
                onclick={resetRotation}
                class="w-[320px] rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted"
            >
                Reset view
            </button>
            <p class="w-[320px] text-center text-xs text-muted-foreground">
                Drag the Don to rotate.
            </p>

            <Button onclick={save} disabled={saving || loading} data-test="save-avatar-button">
                {saving ? 'Saving…' : 'Set as profile picture'}
            </Button>

            {#if hasAvatar && avatar}
                <div class="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>Current:</span>
                    <img src={avatar} alt="Current profile picture" class="h-10 w-10 rounded-full border object-cover" />
                </div>
            {/if}
        </div>

        <div class="flex-1 space-y-8">
            <section class="space-y-3">
                <Heading variant="small" title="Colors" />
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
                <div class="grid grid-cols-[repeat(9,minmax(0,1fr))] gap-1">
                    {#each COLOR_IDS as colorId (colorId)}
                        <button
                            type="button"
                            onclick={() => selectColor(activeColorTab, colorId)}
                            class="h-8 w-full rounded-md border-2 transition-[border-color] hover:scale-105"
                            style="background-color: {COLORS[colorId]}; border-color: {colorId === selectedColor(activeColorTab) ? 'currentColor' : 'transparent'};"
                            title={String(colorId)}
                            aria-label="{activeColorTab} color {colorId}"
                        ></button>
                    {/each}
                </div>
            </section>

            {#if faces.length > 0}
                <section class="space-y-3">
                    <Heading variant="small" title="Face" />
                    <div class="flex flex-wrap gap-2">
                        {#each faces as file (file)}
                            <button
                                type="button"
                                onclick={() => selectFace(file)}
                                class="overflow-hidden rounded-md border-2 transition-[border-color] hover:scale-105 {file ===
                                face
                                    ? 'border-primary'
                                    : 'border-transparent'}"
                                style="width:48px;height:48px;"
                                title={file}
                            >
                                <span
                                    style="display:block;width:48px;height:48px;background-image:url({FACE_BASE}/{file});background-size:48px auto;background-position:0 0;background-repeat:no-repeat;image-rendering:pixelated;"
                                ></span>
                            </button>
                        {/each}
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium">Expression</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={() => stepFaceFrame(-1)}
                                class="rounded-md border px-2 py-1 text-sm hover:bg-muted"
                                aria-label="Previous expression"
                            >
                                ◀
                            </button>
                            <span
                                class="rounded-md border"
                                style="display:block;width:48px;height:48px;background-image:url({FACE_BASE}/{face});background-size:48px auto;background-position:0 -{faceFrame * 48}px;background-repeat:no-repeat;image-rendering:pixelated;"
                            ></span>
                            <button
                                type="button"
                                onclick={() => stepFaceFrame(1)}
                                class="rounded-md border px-2 py-1 text-sm hover:bg-muted"
                                aria-label="Next expression"
                            >
                                ▶
                            </button>
                            <span class="text-xs text-muted-foreground">{faceFrame + 1} / {FACE_FRAME_COUNT}</span>
                        </div>
                    </div>
                </section>
            {/if}

            {#if kigurumiSheet && kigurumiSheet.items.length > 0}
                <section class="space-y-3">
                    <Heading variant="small" title="Kigurumi" />
                    <div
                        class="overflow-y-auto rounded-md border p-2"
                        style="display:grid;grid-template-columns:repeat(auto-fill,56px);gap:0.5rem;max-height:20rem;justify-content:center;"
                    >
                        {#each kigurumiSheet.items as item (item.id)}
                            <button
                                type="button"
                                onclick={() => selectCostume(item.id)}
                                class="flex items-center justify-center overflow-hidden rounded-md border-2 transition-[border-color] hover:scale-105 {item.id ===
                                costume
                                    ? 'border-primary'
                                    : 'border-transparent'}"
                                style="width:56px;height:56px;"
                                title="ID: {item.id}"
                                aria-label="Kigurumi {item.id}"
                            >
                                <span style={sprite(item)}></span>
                            </button>
                        {/each}
                    </div>
                </section>
            {/if}
        </div>
    </div>
</div>
