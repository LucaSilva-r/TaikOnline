<script lang="ts">
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import type { Snippet } from 'svelte';
    import { cn } from '@/lib/utils';

    let {
        title,
        description,
        icon,
        overview,
        children,
        class: className = '',
    }: {
        title: string;
        description: string;
        icon: Snippet;
        overview?: Snippet;
        children: Snippet;
        class?: string;
    } = $props();
</script>

<details
    class={cn(
        'group/mode overflow-hidden rounded-xl border bg-card',
        className,
    )}
>
    <summary
        class="flex cursor-pointer list-none items-center gap-3 px-4 py-4 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:px-5 [&::-webkit-details-marker]:hidden"
    >
        <span
            class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-current/15 bg-current/5"
            aria-hidden="true"
        >
            {@render icon()}
        </span>
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-foreground">{title}</span>
            <span class="mt-0.5 block text-xs text-muted-foreground sm:text-sm"
                >{description}</span
            >
        </span>
        {#if overview}
            <span class="shrink-0">{@render overview()}</span>
        {/if}
        <ChevronDown
            class="size-4 shrink-0 text-muted-foreground transition-transform group-open/mode:rotate-180"
            aria-hidden="true"
        />
    </summary>
    <div class="border-t text-foreground">
        {@render children()}
    </div>
</details>
