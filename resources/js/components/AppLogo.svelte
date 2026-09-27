<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
</script>

<div class="flex aspect-square size-8 items-center justify-center">
    <AppLogoIcon class="size-8" />
</div>
<div class="ml-1 grid flex-1 text-left text-sm">
    <span class="mb-0.5 truncate leading-tight font-semibold"
        >{page.props.name}</span
    >
</div>
