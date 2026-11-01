<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { privacy, rules } from '@/routes/legal';

    let { class: className = '' }: { class?: string } = $props();
    const taikoParam = taikoRouteParam();
</script>

<footer class="border-t bg-card/40 {className}">
    <div
        class="mx-auto w-full max-w-7xl px-4 py-6 text-center text-xs leading-relaxed text-muted-foreground"
    >
        TaikOnline is an unofficial, non-commercial fan project. It is not
        affiliated with, endorsed by, or sponsored by Bandai Namco Entertainment
        Inc. or any of its affiliates. "Taiko no Tatsujin" and all related names,
        logos, characters, and trademarks are the property of their respective
        owners.
        {#if page.props.legalVersion}
            <div class="mt-2 flex flex-wrap justify-center gap-4">
                <Link href={toUrl(rules(taikoParam))} class="underline">Rules</Link>
                <Link href={toUrl(privacy(taikoParam))} class="underline">Privacy Policy</Link>
            </div>
        {/if}
    </div>
</footer>
