<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import WaddamburoKatsuShopController from '@/actions/App/Http/Controllers/WaddamburoKatsuShopController';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam, taikoVersionAccent } from '@/lib/taiko-version';
    import katsuShop from '@/routes/katsu-shop';

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

    let selected = $state(banners.at(-1)?.key ?? '');
    const banner = $derived(banners.find((entry) => entry.key === selected));
    const ownedSet = $derived(new Set(owned));
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
    const capsules = Array.from({ length: 18 }, (_, i) => ({
        colour: taikoVersionAccent(EDITIONS[i % EDITIONS.length]).swatch,
        x: 14 + ((i * 37) % 72),
        y: 18 + ((i * 53) % 58),
        turn: (i * 47) % 360,
    }));

    // idle → turn (the handle) → drop (a capsule rolls out) → flash → results
    let phase = $state<'idle' | 'turn' | 'drop' | 'flash' | 'results'>('idle');
    let drawn = $state<Drawn[]>([]);
    const dropColour = $derived(accent.swatch);

    function play(parts: Drawn[]): void {
        drawn = parts;
        phase = 'turn';
        setTimeout(() => (phase = 'drop'), 900);
        setTimeout(() => (phase = 'flash'), 1900);
        setTimeout(() => (phase = 'results'), 2500);
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

<section class="mx-auto w-full max-w-5xl space-y-8 px-4 py-10">
    <nav class="flex gap-2">
        <Link
            href={katsuShop.index(taikoRouteParam()).url}
            class="rounded-full border px-4 py-1 text-sm">Katsu Shop</Link
        >
        <span
            class="rounded-full bg-sky-600 px-4 py-1 text-sm font-semibold text-white"
            >Gacha</span
        >
    </nav>

    <header
        class="space-y-2 rounded-xl border-l-8 bg-gradient-to-r from-amber-100 to-sky-100 p-6 dark:from-amber-950/40 dark:to-sky-950/40"
        style="border-color: hsl(195 89% 42%)"
    >
        <h1 class="text-2xl font-semibold">Title Parts Gacha</h1>
        <p class="text-sm text-muted-foreground">
            Donder Hiroba's gachas from Momoiro to Yellow, all open. Each pull
            costs {price} Katsu Medals and gives one title part from that gacha; you
            can draw a part you already have.
        </p>
        {#if hasPlayer}
            <p
                class="inline-flex items-center gap-2 rounded-full bg-sky-600 px-4 py-1 text-lg font-semibold text-white"
            >
                {medals.toLocaleString()} Katsu Medals
            </p>
        {:else}
            <p class="text-sm">Link a card to earn and spend Katsu Medals.</p>
        {/if}
    </header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
        <ul class="space-y-2">
            {#each banners as entry (entry.key)}
                {@const colours = taikoVersionAccent(entry.version)}
                <li>
                    <button
                        type="button"
                        class="w-full rounded-lg border-2 p-3 text-left transition {selected ===
                        entry.key
                            ? 'shadow-md'
                            : 'opacity-80 hover:opacity-100'}"
                        style="border-color: {colours.swatch}; background: {selected ===
                        entry.key
                            ? colours.swatch
                            : 'transparent'}; color: {selected === entry.key
                            ? colours.primaryForeground
                            : 'inherit'}"
                        onclick={() => (selected = entry.key)}
                    >
                        <span class="block font-semibold">{entry.name}</span>
                        <span class="text-xs"
                            >{entry.start} · {ownedCount(entry)}/{entry.parts
                                .length}</span
                        >
                    </button>
                </li>
            {/each}
        </ul>

        {#if banner}
            <div class="space-y-6">
                <!-- The machine: a dome of crests on a base, its handle and chute. -->
                <div
                    class="relative mx-auto h-80 w-64 select-none"
                    aria-hidden="true"
                >
                    <div
                        class="absolute inset-x-4 top-0 h-52 overflow-hidden rounded-full border-4 border-white/80 bg-sky-100/60 shadow-inner dark:bg-sky-900/30 {phase ===
                        'turn'
                            ? 'machine-shake'
                            : ''}"
                    >
                        {#each capsules as capsule, i (i)}
                            <span
                                class="absolute size-9"
                                style="left: {capsule.x}%; top: {capsule.y}%; transform: rotate({capsule.turn}deg)"
                            >
                                <AppLogoIcon
                                    class="size-9"
                                    accent={capsule.colour}
                                />
                            </span>
                        {/each}
                    </div>
                    <div
                        class="absolute inset-x-0 bottom-0 h-32 rounded-2xl shadow-lg"
                        style="background: {accent.swatch}"
                    >
                        <span
                            class="absolute top-6 left-1/2 block size-14 -translate-x-1/2 rounded-full border-4 border-white bg-white/30 {phase ===
                            'turn'
                                ? 'handle-turn'
                                : ''}"
                        >
                            <span
                                class="absolute top-1/2 left-1 h-2 w-10 -translate-y-1/2 rounded bg-white"
                            ></span>
                        </span>
                        <span
                            class="absolute right-6 bottom-4 h-10 w-12 rounded-md bg-black/40"
                        ></span>
                    </div>
                    {#if phase === 'drop' || phase === 'flash'}
                        <span
                            class="absolute right-7 bottom-5 size-10 capsule-drop"
                        >
                            <AppLogoIcon class="size-10" accent={dropColour} />
                        </span>
                    {/if}
                </div>

                {#if hasPlayer}
                    <div class="flex flex-wrap justify-center gap-3">
                        {#each [1, 5] as count (count)}
                            <Form
                                {...WaddamburoKatsuShopController.pull.form(
                                    taikoRouteParam(),
                                )}
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
                                            (phase !== 'idle' &&
                                                phase !== 'results') ||
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

                <section class="space-y-2">
                    <h2 class="font-semibold" style="color: {accent.label}">
                        Parts in this gacha
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        {#each banner.parts as part, index (index)}
                            {@const have = ownedSet.has(
                                `${banner.key}:${index}`,
                            )}
                            <li
                                class="rounded border px-2 py-0.5 text-sm {have
                                    ? 'font-medium'
                                    : 'text-muted-foreground opacity-60'}"
                                style={have
                                    ? `border-color: ${accent.swatch}`
                                    : ''}
                            >
                                {part}
                            </li>
                        {/each}
                    </ul>
                </section>
            </div>
        {/if}
    </div>
</section>

{#if phase === 'flash'}
    <div class="pointer-events-none fixed inset-0 z-40 flash"></div>
{/if}

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
            <Button class="w-full" onclick={() => (phase = 'idle')}>OK</Button>
        </div>
    </div>
{/if}

<style>
    .machine-shake {
        animation: shake 0.15s linear 6;
    }
    .handle-turn {
        animation: turn 0.9s ease-in-out;
    }
    .capsule-drop {
        animation: drop 1s ease-out forwards;
    }
    .flash {
        background: radial-gradient(
            circle,
            white 0%,
            rgba(255, 255, 255, 0.85) 40%,
            transparent 75%
        );
        animation: flash 0.6s ease-out forwards;
    }
    @keyframes shake {
        0%,
        100% {
            transform: translateX(0);
        }
        25% {
            transform: translateX(-3px) rotate(-1deg);
        }
        75% {
            transform: translateX(3px) rotate(1deg);
        }
    }
    @keyframes turn {
        from {
            transform: translateX(-50%) rotate(0deg);
        }
        to {
            transform: translateX(-50%) rotate(360deg);
        }
    }
    @keyframes drop {
        0% {
            transform: translateY(-20px) scale(0.6);
            opacity: 0;
        }
        40% {
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        100% {
            transform: translate(-80px, -120px) scale(2.4) rotate(360deg);
        }
    }
    @keyframes flash {
        from {
            opacity: 0;
        }
        30% {
            opacity: 1;
        }
        to {
            opacity: 0.9;
        }
    }
</style>
