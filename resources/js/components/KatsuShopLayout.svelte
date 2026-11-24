<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import gacha from '@/routes/gacha';
    import katsuShop from '@/routes/katsu-shop';

    let {
        active,
        hasPlayer,
        medals,
        children,
    }: {
        active: 'Katsu Shop' | 'Gacha';
        hasPlayer: boolean;
        medals: number;
        children: Snippet;
    } = $props();

    const tabs = [
        { label: 'Katsu Shop', route: katsuShop },
        { label: 'Gacha', route: gacha },
    ];
</script>

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <header
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6"
        >
            <div class="max-w-2xl">
                <h1 class="text-3xl font-semibold tracking-tight">
                    Katsu Medal Shop
                </h1>
            </div>
            {#if hasPlayer}
                <div
                    class="border-l-2 border-primary pl-4"
                    data-test="katsu-medals"
                >
                    <p class="text-sm text-muted-foreground">Katsu Medals</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {medals.toLocaleString()}
                    </p>
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">
                    Link a card to earn and spend Katsu Medals.
                </p>
            {/if}
        </div>
        <nav
            aria-label="Shop navigation"
            class="flex gap-5 border-t px-5 text-sm sm:px-6"
        >
            {#each tabs as tab (tab.label)}
                {#if active === tab.label}
                    <span
                        aria-current="page"
                        class="border-b-2 border-primary py-3 font-semibold"
                        >{tab.label}</span
                    >
                {:else}
                    <Link
                        href={tab.route.index(taikoRouteParam()).url}
                        class="py-3 text-muted-foreground hover:text-foreground"
                        >{tab.label}</Link
                    >
                {/if}
            {/each}
        </nav>
    </header>
    {@render children()}
</section>
