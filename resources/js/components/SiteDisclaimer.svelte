<script lang="ts">
    let { class: className = '' }: { class?: string } = $props();
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
    </div>
</footer>
