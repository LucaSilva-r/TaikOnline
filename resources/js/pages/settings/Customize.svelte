<script module lang="ts">
    import { edit } from '@/routes/customize';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Customization settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import CustomizeController from '@/actions/App/Http/Controllers/Settings/CustomizeController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';

    const COLORS: string[] = [
        '#f94729', '#68c0c1', '#dd1400', '#f8f1df', '#019587', '#00bf86',
        '#00ff99', '#65ffc3', '#ffffff', '#690001', '#fe0000', '#ff6a65',
        '#feb2b4', '#00bbc2', '#00f7ff', '#66fafe', '#b4feff', '#e4e4e4',
        '#993900', '#ff5f01', '#ff9e79', '#fecfb3', '#024f95', '#0088fe',
        '#68b8ff', '#b3dbff', '#b9b9b9', '#b37802', '#ffaa00', '#ffcc67',
        '#fee2b3', '#000d80', '#0119ff', '#6774ff', '#b3baff', '#858585',
        '#b49b01', '#ffdd00', '#ffff00', '#feff71', '#2b0181', '#5600ff',
        '#9966ff', '#ccb4ff', '#505050', '#39a102', '#77c800', '#b3ff00',
        '#ddff8c', '#62007e', '#c600ff', '#df69fe', '#edb3ff', '#232323',
        '#006600', '#02b900', '#00ff00', '#89ff9e', '#990158', '#ff0097',
        '#ff67be', '#ffb4df', '#000000',
    ];

   const COLOR_IDS = Array.from({ length: 63 }, (_, index) => index);

    let {
        hasAccessCode,
        colorFace = 0,
        colorBody = 0,
        colorLimb = 0,
        status = '',
    }: {
        hasAccessCode: boolean;
        colorFace?: number;
        colorBody?: number;
        colorLimb?: number;
        status?: string;
    } = $props();

    let selectedFace = $state(colorFace);
    let selectedBody = $state(colorBody);
    let selectedLimb = $state(colorLimb);
</script>

<AppHead title="Customization settings" />

<h1 class="sr-only">Customization settings</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Donchan customization"
        description="Change your Donchan's appearance. Changes are saved to your Banapassport and synced across all arcades."
    />

    {#if !hasAccessCode}
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-800">
                Link your Banapassport access code in the Profile settings to customize your Donchan.
            </p>
        </div>
    {:else}
        <Form
            {...CustomizeController.update.form()}
            class="space-y-6"
            options={{ preserveScroll: true }}
        >
            {#snippet children({ errors, processing })}
                <div class="flex flex-col gap-8">
                    <!-- Face color picker -->
                    <div class="grid gap-3">
                        <label class="text-sm font-medium">Face</label>
                        <div class="inline-flex items-center gap-3">
                            <div
                                class="h-4 w-4 rounded border border-muted-foreground/20"
                                style="background-color: {COLORS[selectedFace]}; border-color: {COLORS[selectedFace]};"
                            ></div>
                            <span class="text-xs text-muted-foreground">ID: {selectedFace}</span>
                        </div>
                        <div class="grid grid-cols-[repeat(9,minmax(0,1fr))] gap-1">
                            {#each COLOR_IDS as colorId (colorId)}
                                <button
                                    type="button"
                                    onclick={() => (selectedFace = colorId)}
                                    class="h-8 w-full rounded-md border-2 transition-[border-color] hover:scale-105"
                                    style="background-color: {COLORS[colorId]}; border-color: {colorId === selectedFace ? 'currentColor' : 'transparent'};"
                                    title="{colorId}"
                                ></button>
                            {/each}
                        </div>
                        <input type="hidden" name="color_face" bind:value={selectedFace} />
                        <InputError message={errors.color_face} />
                    </div>

                    <!-- Body color picker -->
                    <div class="grid gap-3">
                        <label class="text-sm font-medium">Body</label>
                        <div class="inline-flex items-center gap-3">
                            <div
                                class="h-4 w-4 rounded border border-muted-foreground/20"
                                style="background-color: {COLORS[selectedBody]}; border-color: {COLORS[selectedBody]};"
                            ></div>
                            <span class="text-xs text-muted-foreground">ID: {selectedBody}</span>
                        </div>
                        <div class="grid grid-cols-[repeat(9,minmax(0,1fr))] gap-1">
                            {#each COLOR_IDS as colorId (colorId)}
                                <button
                                    type="button"
                                    onclick={() => (selectedBody = colorId)}
                                    class="h-8 w-full rounded-md border-2 transition-[border-color] hover:scale-105"
                                    style="background-color: {COLORS[colorId]}; border-color: {colorId === selectedBody ? 'currentColor' : 'transparent'};"
                                    title="{colorId}"
                                ></button>
                            {/each}
                        </div>
                        <input type="hidden" name="color_body" bind:value={selectedBody} />
                        <InputError message={errors.color_body} />
                    </div>

                    <!-- Limb color picker -->
                    <div class="grid gap-3">
                        <label class="text-sm font-medium">Limb</label>
                        <div class="inline-flex items-center gap-3">
                            <div
                                class="h-4 w-4 rounded border border-muted-foreground/20"
                                style="background-color: {COLORS[selectedLimb]}; border-color: {COLORS[selectedLimb]};"
                            ></div>
                            <span class="text-xs text-muted-foreground">ID: {selectedLimb}</span>
                        </div>
                        <div class="grid grid-cols-[repeat(9,minmax(0,1fr))] gap-1">
                            {#each COLOR_IDS as colorId (colorId)}
                                <button
                                    type="button"
                                    onclick={() => (selectedLimb = colorId)}
                                    class="h-8 w-full rounded-md border-2 transition-[border-color] hover:scale-105"
                                    style="background-color: {COLORS[colorId]}; border-color: {colorId === selectedLimb ? 'currentColor' : 'transparent'};"
                                    title="{colorId}"
                                ></button>
                            {/each}
                        </div>
                        <input type="hidden" name="color_limb" bind:value={selectedLimb} />
                        <InputError message={errors.color_limb} />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-customization-button"
                    >Save</Button>
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
