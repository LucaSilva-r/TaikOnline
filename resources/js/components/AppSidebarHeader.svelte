<script lang="ts">
    import Breadcrumbs from '@/components/Breadcrumbs.svelte';
    import TaikoVersionSelect from '@/components/TaikoVersionSelect.svelte';
    import { SidebarTrigger } from '@/components/ui/sidebar';
    import type { BreadcrumbItem } from '@/types';

    let {
        breadcrumbs = [],
    }: {
        breadcrumbs?: BreadcrumbItem[];
    } = $props();
</script>

<div class="flex w-full items-center justify-center bg-red-600 px-4 py-2 text-center text-sm font-semibold text-white">
    ⚠️ ALPHA — This platform is in early alpha. Your data can and WILL be deleted without warning.
</div>

<header
    class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
>
    <div class="flex items-center gap-2">
        <SidebarTrigger class="-ml-1" />
        {#if breadcrumbs && breadcrumbs.length > 0}
            <Breadcrumbs {breadcrumbs} />
        {/if}
    </div>
    <div class="ml-auto">
        <TaikoVersionSelect />
    </div>
</header>
