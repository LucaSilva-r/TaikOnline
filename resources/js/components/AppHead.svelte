<script lang="ts">
    import type { Snippet } from 'svelte';

    let {
        title = '',
        children,
    }: {
        title?: string;
        children?: Snippet;
    } = $props();

    // The image is built without .env (VITE_APP_NAME unset there): the site's own name, as app.ts.
    const appName = import.meta.env.VITE_APP_NAME || 'TaikOnline';
    const fullTitle = $derived(title ? `${title} - ${appName}` : appName);
</script>

<svelte:head>
    <title>{fullTitle}</title>
    {@render children?.()}
</svelte:head>
