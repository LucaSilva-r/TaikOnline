<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import {
        Tooltip,
        TooltipContent,
        TooltipProvider,
        TooltipTrigger,
    } from '@/components/ui/tooltip';
    import { taikoVersionAccent } from '@/lib/taiko-version';

    let {
        userId,
        size = 'default',
    }: {
        userId: number | null;
        size?: 'default' | 'compact';
    } = $props();
    const rank = $derived(
        userId === null
            ? null
            : (page.props.danRanks?.[userId] ??
                  (page.props.auth.user?.id === userId
                      ? page.props.auth.danRank
                      : null)),
    );
    const description = $derived(
        rank
            ? `${rank.dojo} Dan Dojo · ${rank.label}${rank.perfect ? ' · Perfect clear (all Great)' : rank.gold ? ' · Gold clear' : ''}`
            : '',
    );
</script>

{#if rank}
    <TooltipProvider delayDuration={150}>
        <Tooltip>
            <TooltipTrigger>
                {#snippet child({ props })}
                    <!-- svelte-ignore a11y_no_noninteractive_tabindex (Keyboard users need to focus the rank to read its tooltip.) -->
                    <span
                        {...props}
                        tabindex="0"
                        lang="ja"
                        data-series={rank.series}
                        style:--dan-color={taikoVersionAccent(rank.series)
                            .swatch}
                        aria-label={description}
                        class={[
                            'dan-rank inline-flex shrink-0 cursor-help items-center whitespace-nowrap rounded-md border align-middle font-serif font-black tracking-wider focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            size === 'compact'
                                ? 'px-1.5 py-0.5 text-sm leading-4 shadow-sm'
                                : 'px-2.5 py-1 text-lg leading-5 shadow-md',
                            rank.gold ? 'text-amber-300' : 'text-white',
                        ]}
                    >
                        <span class:dan-perfect-text={rank.perfect}
                            >{rank.name}</span
                        >
                    </span>
                {/snippet}
            </TooltipTrigger>
            <TooltipContent>{description}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
{/if}

<style>
    .dan-rank {
        border-color: color-mix(in srgb, var(--dan-color) 75%, white);
        background: linear-gradient(
            145deg,
            color-mix(in srgb, var(--dan-color) 50%, #09090b),
            color-mix(in srgb, var(--dan-color) 20%, #09090b)
        );
        text-shadow: 0 1px 2px rgb(0 0 0 / 70%);
    }

    .dan-rank[data-series='white'] {
        background: linear-gradient(145deg, #52525b, #18181b);
    }

    .dan-rank[data-series^='nijiiro-'] {
        border-color: #c084fc;
        background: linear-gradient(
            115deg,
            #831843,
            #7c2d12,
            #365314,
            #164e63,
            #312e81,
            #581c87
        );
    }
</style>
