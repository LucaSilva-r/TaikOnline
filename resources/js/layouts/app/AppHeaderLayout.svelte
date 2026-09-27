<script lang="ts">
    import type { Snippet } from 'svelte';
    import AlphaBanner from '@/components/AlphaBanner.svelte';
    import AppContent from '@/components/AppContent.svelte';
    import AppHeader from '@/components/AppHeader.svelte';
    import AppShell from '@/components/AppShell.svelte';
    import { Toaster } from '@/components/ui/sonner';
    import type { BreadcrumbItem } from '@/types';

    let {
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        children?: Snippet;
    } = $props();
</script>

<AppShell variant="header">
    <AppHeader {breadcrumbs} />
    <AppContent variant="header">
        {@render children?.()}
    </AppContent>
    <AlphaBanner />
    <Toaster />
</AppShell>
