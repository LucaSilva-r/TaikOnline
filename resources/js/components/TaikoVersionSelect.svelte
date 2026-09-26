<script lang="ts">
    import {
        Select,
        SelectContent,
        SelectItem,
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
            <span
                class="size-2.5 rounded-full border border-black/10 bg-[var(--taiko-accent)] shadow-sm dark:border-white/20"
            ></span>
            {selectedLabel}
        </span>
    </SelectTrigger>
    <SelectContent>
        {#each options as option (option.value)}
            <SelectItem
                value={option.value}
                label={option.label}
                style={taikoVersionAccentStyle(option.value)}
            >
                <span class="flex items-center gap-2">
                    <span
                        class="size-2.5 rounded-full border border-black/10 bg-[var(--version-swatch)] shadow-sm dark:border-white/20"
                    ></span>
                    <span>{option.label}</span>
                </span>
            </SelectItem>
        {/each}
    </SelectContent>
</Select>
