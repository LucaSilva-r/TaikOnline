<script lang="ts">
    import {
        Select,
        SelectContent,
        SelectItem,
        SelectTrigger,
    } from '@/components/ui/select';
    import {
        switchTaikoScope,
        taikoVersionContext,
        type TaikoVersionOption,
    } from '@/lib/taiko-version';

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

    let value = $state('');

    $effect(() => {
        value = context.scope;
    });

    function changeVersion(nextScope: string): void {
        if (! nextScope || nextScope === context.scope) {
            return;
        }

        switchTaikoScope(nextScope);
    }
</script>

<Select bind:value onValueChange={changeVersion}>
    <SelectTrigger size="sm" class="min-w-[9.5rem]">
        <span data-slot="select-value" class="text-xs font-medium">
            {selectedLabel}
        </span>
    </SelectTrigger>
    <SelectContent>
        {#each options as option (option.value)}
            <SelectItem value={option.value} label={option.label} />
        {/each}
    </SelectContent>
</Select>
