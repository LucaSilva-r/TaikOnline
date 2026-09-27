<script lang="ts">
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectSeparator,
        SelectTrigger,
    } from '@/components/ui/select';
    import {
        switchTaikoScope,
        taikoVersionAccentStyle,
        taikoVersionContext,
    } from '@/lib/taiko-version';
    import type { TaikoVersionOption } from '@/lib/taiko-version';

    const context = $derived(taikoVersionContext());
    const options = $derived<TaikoVersionOption[]>(
        context.allowAll
            ? [{ value: 'all', label: 'ALL VERSIONS' }, ...context.versions]
            : context.versions,
    );
    const selectedLabel = $derived(
        options.find((option) => option.value === context.scope)?.label ??
            context.scope.toUpperCase(),
    );

    let value = $derived(context.scope);

    // Waddamburo is the site's own game: its crest instead of a swatch, set apart from the Taiko versions.
    const own = 'waddamburo';

    function changeVersion(nextScope: string): void {
        if (!nextScope || nextScope === context.scope) {
            return;
        }

        switchTaikoScope(nextScope);
    }
</script>

<Select bind:value onValueChange={changeVersion}>
    <SelectTrigger
        size="sm"
        class="min-w-[9.5rem] border-[var(--taiko-accent-border)] bg-[var(--taiko-accent-soft)] text-foreground focus-visible:border-[var(--taiko-accent)] focus-visible:ring-[var(--taiko-accent-ring)]"
    >
        <span data-slot="select-value" class="text-xs font-medium">
            {#if context.scope === own}
                <AppLogoIcon class="size-3.5" />
            {:else}
                <span
                    class="size-2.5 rounded-full border border-black/10 bg-[var(--taiko-accent)] shadow-sm dark:border-white/20"
                ></span>
            {/if}
            {selectedLabel}
        </span>
    </SelectTrigger>
    <SelectContent>
        {#each options as option, index (option.value)}
            <SelectItem
                value={option.value}
                label={option.label}
                style={taikoVersionAccentStyle(option.value)}
            >
                <span class="flex items-center gap-2">
                    {#if option.value === own}
                        <AppLogoIcon class="size-4" accent="#c81820" />
                        <span class="font-semibold">{option.label}</span>
                    {:else}
                        <span
                            class="size-2.5 rounded-full border border-black/10 bg-[var(--version-swatch)] shadow-sm dark:border-white/20"
                        ></span>
                        <span>{option.label}</span>
                    {/if}
                </span>
            </SelectItem>
            {#if option.value === own && index < options.length - 1}
                <SelectSeparator />
            {/if}
        {/each}
    </SelectContent>
</Select>
