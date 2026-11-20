<script lang="ts">
    import { Form, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import WaddamburoKatsuShopController from '@/actions/App/Http/Controllers/WaddamburoKatsuShopController';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import KatsuShopLayout from '@/components/KatsuShopLayout.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';

    type Banner = {
        key: string;
        name: string;
        start: string;
        version: string;
        parts: string[];
    };
    type Drawn = { key: string; text: string; new: boolean };

    let {
        hasPlayer,
        medals,
        price,
        banners,
        owned,
    }: {
        hasPlayer: boolean;
        medals: number;
        price: number;
        banners: Banner[];
        connectors: string[];
        owned: string[];
    } = $props();

    const bannerTitles: Record<string, string> = {
        G01: 'Title Gacha',
        G02: 'Special Gacha · Title Parts Revival',
        G03: 'Special Gacha · Title Parts Revival 2',
        G04: 'Title Gacha',
        G05: 'Summer Vacation Gacha',
        G06: 'New Year Holiday Gacha',
        G07: 'Title Gacha',
        G08: 'Summer Vacation Gacha',
        G09: 'Title Gacha · Wave 1',
        G10: 'Title Gacha · Wave 2',
        G11: 'Title Gacha · Wave 1',
        G12: 'Title Gacha · Wave 2',
        G13: 'Title Gacha · Wave 1',
        G14: 'Title Gacha · Wave 2',
    };

    const newestFirst = $derived(
        [...banners].sort((a, b) => b.start.localeCompare(a.start)),
    );
    let selection = $state<string>();
    const selected = $derived(selection ?? newestFirst[0]?.key);
    const banner = $derived(banners.find((entry) => entry.key === selected));
    // idle → turn (the handle) → drop (a capsule rolls out) → results
    let phase = $state<'idle' | 'turn' | 'drop' | 'results'>('idle');
    let drawn = $state<Drawn[]>([]);
    let confirmedKeys = $state<string[]>([]);
    let beforePull = $state<string[] | null>(null);
    const ownedSet = $derived(
        new Set(beforePull ?? [...owned, ...confirmedKeys]),
    );
    const highlightedKeys = $derived(
        new Set(
            phase === 'idle'
                ? drawn.filter((part) => part.new).map((part) => part.key)
                : [],
        ),
    );
    const accent = $derived(
        taikoVersionAccent(banner?.version ?? 'waddamburo'),
    );

    // The machine's capsules: Waddamburo's crest in the colours of gen 3's editions.
    const EDITIONS = [
        'sorairo',
        'momoiro',
        'kimidori',
        'murasaki',
        'white',
        'red',
        'yellow',
        'blue',
        'green',
    ];
    // Back to front, with smaller capsules visible through gaps in the foreground.
    const capsules = [
        [11, 49, 26],
        [23, 41, 26],
        [36, 47, 25],
        [48, 39, 27],
        [59, 43, 25],
        [72, 47, 26],
        [82, 52, 25],
        [7, 58, 31],
        [21, 53, 30],
        [34, 58, 32],
        [47, 50, 32],
        [61, 55, 31],
        [74, 59, 32],
        [15, 68, 36],
        [31, 71, 37],
        [45, 63, 36],
        [58, 72, 37],
        [71, 69, 35],
        [24, 79, 38],
        [41, 80, 38],
        [55, 82, 36],
    ].map(([x, y, size], i) => ({
        x,
        y,
        size,
        colour: taikoVersionAccent(EDITIONS[(i * 7) % EDITIONS.length]).swatch,
        turn: (i * 47) % 360,
    }));

    // idle → turn (the handle) → drop (a capsule rolls out) → results
    const dropColour = $derived(accent.swatch);

    function play(parts: Drawn[]): void {
        selection = parts[0].key.split(':')[0];

        if (beforePull === null) {
            const unlocked = new Set(
                parts.filter((part) => part.new).map((part) => part.key),
            );
            beforePull = [...ownedSet].filter((key) => !unlocked.has(key));
        }

        drawn = parts;
        phase = 'turn';
        setTimeout(() => (phase = 'drop'), 900);
        setTimeout(() => (phase = 'results'), 1950);
    }

    function dismissResults(): void {
        confirmedKeys = [
            ...new Set([...confirmedKeys, ...drawn.map((part) => part.key)]),
        ];
        beforePull = null;
        phase = 'idle';
    }

    onMount(() =>
        router.on('flash', (event) => {
            const parts = (event as CustomEvent).detail?.flash?.gacha as
                Drawn[] | undefined;

            if (parts?.length) {
                play(parts);
            }
        }),
    );

    function ownedCount(entry: Banner): number {
        return entry.parts.filter((_, index) =>
            ownedSet.has(`${entry.key}:${index}`),
        ).length;
    }
</script>

<AppHead title="Title Parts Gacha" />

<KatsuShopLayout active="Gacha" {hasPlayer} {medals}>
    <p class="max-w-3xl text-sm text-muted-foreground">
        Draw title parts from Momoiro through Yellow. Each pull costs {price} Katsu
        Medals and can include a part you already own.
    </p>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
        <ul class="space-y-2">
            {#each newestFirst as entry (entry.key)}
                {@const colours = taikoVersionAccent(entry.version)}
                <li>
                    <button
                        type="button"
                        aria-pressed={selected === entry.key}
                        disabled={phase !== 'idle'}
                        class="flex w-full items-center gap-3 rounded-lg border bg-card p-4 text-left text-card-foreground transition-colors hover:bg-muted/40 aria-pressed:border-primary aria-pressed:bg-accent"
                        onclick={() => (selection = entry.key)}
                    >
                        <AppLogoIcon
                            class="size-8 shrink-0"
                            accent={colours.swatch}
                        />
                        <span>
                            <span class="block font-semibold"
                                >{entry.version.toUpperCase()} · {bannerTitles[
                                    entry.key
                                ] ?? entry.name}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >{entry.start} · {ownedCount(entry)}/{entry
                                    .parts.length}</span
                            >
                        </span>
                    </button>
                </li>
            {/each}
        </ul>

        {#if banner}
            <div
                class="space-y-6 self-start rounded-lg border bg-card p-4 text-card-foreground sm:p-6"
            >
                <div
                    class="machine"
                    style="--paint: {accent.swatch}"
                    aria-hidden="true"
                >
                    <div class="tank" class:mixing={phase === 'turn'}>
                        {#each capsules as capsule, i (i)}
                            <span
                                class="ball absolute size-9"
                                style="--ink:{capsule.colour}; width:{capsule.size}px; height:{capsule.size}px; --roll:{i %
                                2
                                    ? 13
                                    : -17}deg; --nudge:{i % 2
                                    ? 3
                                    : -4}px; --delay:{(i % 7) *
                                    29}ms; left:{capsule.x}%; top:{capsule.y}%; transform:rotate({capsule.turn}deg)"
                            >
                                <AppLogoIcon
                                    class="size-9"
                                    accent={capsule.colour}
                                />
                            </span>
                        {/each}
                    </div>
                    <div class="collar"></div>
                    <div class="cabinet"></div>
                    <div class="coin-mechanism">
                        <span class="coin-slot"></span>
                        <span class="knob">
                            <span class="grip" class:turning={phase === 'turn'}
                            ></span>
                        </span>
                    </div>
                    <div class="tray">
                        {#if phase === 'drop'}
                            <span class="capsule-drop">
                                <AppLogoIcon
                                    class="size-4"
                                    accent={dropColour}
                                />
                            </span>
                        {/if}
                    </div>
                    <div class="foot"></div>
                </div>

                {#if hasPlayer}
                    <div class="flex flex-wrap justify-center gap-3">
                        {#each [1, 5] as count (count)}
                            <Form
                                {...WaddamburoKatsuShopController.pull.form(
                                    taikoRouteParam(),
                                )}
                                onStart={() => {
                                    beforePull = [...ownedSet];
                                }}
                                onFinish={() => {
                                    if (phase === 'idle') {
                                        beforePull = null;
                                    }
                                }}
                                options={{ preserveScroll: true }}
                            >
                                {#snippet children({ processing })}
                                    <input
                                        type="hidden"
                                        name="banner"
                                        value={banner.key}
                                    />
                                    <input
                                        type="hidden"
                                        name="count"
                                        value={count}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing ||
                                            beforePull !== null ||
                                            medals < price * count}
                                        style="background: {accent.primary}; color: {accent.primaryForeground}"
                                    >
                                        Pull ×{count} ({price * count})
                                    </Button>
                                {/snippet}
                            </Form>
                        {/each}
                    </div>
                {/if}

                <section class="space-y-3 border-t pt-4">
                    <h2 class="font-semibold">Parts in this gacha</h2>
                    <ul class="flex flex-wrap gap-1.5">
                        {#each banner.parts as part, index (index)}
                            {@const partKey = `${banner.key}:${index}`}
                            {@const have = ownedSet.has(partKey)}
                            <li
                                class:unlocked={highlightedKeys.has(partKey)}
                                class="rounded border px-2 py-0.5 text-sm {have
                                    ? 'font-medium'
                                    : 'text-muted-foreground opacity-60'}"
                                style={have
                                    ? `border-color: ${accent.swatch}`
                                    : ''}
                            >
                                {part}
                                {#if highlightedKeys.has(partKey)}<span
                                        class="ml-1 text-xs font-semibold"
                                        >New</span
                                    >{/if}
                            </li>
                        {/each}
                    </ul>
                </section>
            </div>
        {/if}
    </div>
</KatsuShopLayout>

{#if phase === 'results'}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
    >
        <div
            class="w-full max-w-md space-y-4 rounded-xl bg-background p-6 shadow-2xl"
        >
            <div class="flex items-center gap-4">
                <AppLogoIcon class="size-16" accent={dropColour} />
                <p class="font-semibold">
                    You drew {drawn.length === 1
                        ? 'a part'
                        : `${drawn.length} parts`}!
                </p>
            </div>
            <ul class="space-y-1.5">
                {#each drawn as part, i (i)}
                    <li
                        class="flex items-center gap-3 rounded-md border px-3 py-2"
                        style="border-left: 4px solid {accent.swatch}"
                    >
                        <span class="flex-1">{part.text}</span>
                        {#if part.new}
                            <span
                                class="rounded-full bg-red-600 px-2 text-xs font-bold text-white"
                                >NEW</span
                            >
                        {/if}
                    </li>
                {/each}
            </ul>
            <Button class="w-full" onclick={dismissResults}>OK</Button>
        </div>
    </div>
{/if}

<style>
    @media (prefers-reduced-motion: no-preference) {
        .unlocked {
            animation: unlock 1.6s ease-out both;
        }
        @keyframes unlock {
            0%,
            30% {
                background-color: var(--accent);
                box-shadow: 0 0 0 2px var(--primary);
            }
            100% {
                box-shadow: 0 0 0 0 transparent;
            }
        }
    }

    .machine {
        position: relative;
        width: 216px;
        height: 344px;
        margin-inline: auto;
        --nickel: linear-gradient(
            90deg,
            #6e7778,
            #d6dbd6 14%,
            #fafaf1 30%,
            #b6bdb8 63%,
            #788382 92%,
            #495250
        );
        --enamel: linear-gradient(
            90deg,
            color-mix(in srgb, var(--paint), #352c26 35%),
            var(--paint) 19%,
            color-mix(in srgb, var(--paint), #fff4de 16%) 34%,
            var(--paint) 68%,
            color-mix(in srgb, var(--paint), #352c26 38%)
        );
    }
    .tank {
        position: absolute;
        inset: 0 0 auto;
        aspect-ratio: 1;
        overflow: hidden;
        border-radius: 50%;
        border: 2px solid #96afb4;
        background: radial-gradient(
            ellipse at 37% 32%,
            #b9d4da44,
            #729aa350 64%,
            #76949999 82%,
            #b9d2d7
        );
        box-shadow:
            inset 2px 1px 5px #e9ffff80,
            inset -5px -4px 12px #445a6552;
    }
    .tank::after {
        content: '';
        position: absolute;
        left: 15%;
        top: 9%;
        width: 18%;
        height: 31%;
        border-radius: 50%;
        transform: rotate(38deg);
        border-left: 5px solid #ffffff96;
    }
    .ball {
        display: grid;
        place-items: center;
        border: 1px solid #435059;
        border-radius: 50%;
        background: linear-gradient(
            165deg,
            #fff,
            #dae8e9 47%,
            #455461 49%,
            var(--ink) 52%,
            #344851
        );
        box-shadow: inset -2px -2px 3px #0004;
    }
    .ball :global(svg) {
        width: 59%;
        height: 59%;
    }
    .mixing .ball {
        animation: tumble 660ms var(--delay) ease-in-out;
    }
    @keyframes tumble {
        45% {
            translate: var(--nudge) -5px;
            rotate: var(--roll);
        }
    }
    .collar,
    .cabinet,
    .coin-mechanism,
    .coin-slot,
    .knob,
    .grip,
    .tray,
    .capsule-drop,
    .foot {
        position: absolute;
    }
    .collar {
        top: 183px;
        inset-inline: 35px;
        height: 17px;
        border-radius: 50% / 24%;
        background: var(--nickel);
        border-block: 2px solid #545e60;
    }
    .cabinet {
        inset: 196px 20px 9px;
        clip-path: polygon(8% 0, 92% 0, 100% 100%, 0 100%);
        background: var(--enamel);
        box-shadow:
            inset 0 3px #fff4,
            inset 0 -6px #0002;
    }
    .coin-mechanism {
        top: 203px;
        inset-inline: 70px;
        height: 71px;
        border-radius: 10px 10px 24px 24px;
        background: linear-gradient(115deg, #e9e9dc, #a3aba7 56%, #858c89);
        border: 1px solid #59605f;
        box-shadow:
            inset 1px 1px #fff9,
            0 2px 2px #302d2e65;
    }
    .coin-slot {
        top: 8px;
        left: 50%;
        translate: -50%;
        width: 27px;
        height: 5px;
        background: #252824;
        border-radius: 3px;
        box-shadow: 0 1px #f2f2e9;
    }
    .knob {
        top: 20px;
        left: 50%;
        translate: -50%;
        width: 43px;
        aspect-ratio: 1;
        border-radius: 50%;
        background: linear-gradient(135deg, #f4f4e8, #757e7b);
        border: 2px solid #5f6663;
        box-shadow:
            0 2px 2px #32373770,
            inset 0 1px #fff;
        display: grid;
        place-items: center;
    }
    .grip {
        position: relative;
        width: 34px;
        height: 11px;
        border-radius: 4px;
        background: #e7e8dd;
        border: 1px solid #646b69;
    }
    .turning {
        animation: turn 900ms ease-in-out;
    }
    .tray {
        inset-inline: 72px;
        bottom: 18px;
        height: 45px;
        overflow: hidden;
        border-radius: 19px 19px 6px 6px;
        background: linear-gradient(#171c1b, #39423e);
        border: 3px solid #747a70;
        box-shadow:
            0 1px #fff8,
            inset 0 -5px #555e55;
    }
    .capsule-drop {
        display: grid;
        place-items: center;
        width: 31px;
        height: 31px;
        left: 18px;
        bottom: 4px;
        border-radius: 50%;
        border: 1px solid #525b60;
        background: linear-gradient(
            160deg,
            #fafbf2,
            #dfe4dc 47%,
            #526263 49%,
            var(--paint) 52%
        );
        animation: drop 800ms both;
    }
    .foot {
        bottom: 0;
        inset-inline: 15px;
        height: 12px;
        border-radius: 4px 4px 6px 6px;
        background: var(--enamel);
        border-top: 1px solid #fff7;
        border-bottom: 4px solid #444943;
    }
    @keyframes turn {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }
    @keyframes drop {
        0% {
            transform: translate(-8px, -26px) scale(0.5);
            opacity: 0;
        }
        40% {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        100% {
            transform: translate(0, 0) rotate(180deg);
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .mixing .ball,
        .turning,
        .capsule-drop {
            animation: none;
        }
    }
</style>
