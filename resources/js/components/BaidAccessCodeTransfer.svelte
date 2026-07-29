<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import OperatorController from '@/actions/App/Http/Controllers/Green/OperatorController';
    import type { RouteFormDefinition } from '@/wayfinder';

    let {
        baid,
        accessCode = null,
        rotateForm = null,
        unbindForm = null,
    }: {
        baid: number;
        accessCode?: string | null;
        /** Supplied on screens that manage a user account, so rotating and unbinding live here too. */
        rotateForm?: RouteFormDefinition<'post'> | null;
        unbindForm?: RouteFormDefinition<'post'> | null;
    } = $props();

    type Mode = 'replace' | 'rotate' | 'unbind' | 'unlink';

    let mode = $state<Mode>('replace');
</script>

<div class="max-w-xl space-y-4">
    <Heading
        variant="small"
        title="Move or release this BAID"
        description="The BAID number never changes, so scores, best scores, cosmetics and tokens always stay with the player."
    />

    <div class="grid gap-2">
        <Label>Current access code</Label>
        <Input
            value={accessCode ?? 'No access code'}
            readonly
            tabindex={-1}
            aria-readonly="true"
            class="mt-1 block w-full"
        />
    </div>

    {#if accessCode}
        <fieldset class="grid gap-2">
            <legend class="mb-2 text-sm font-medium"
                >What do you want to do?</legend
            >

            <label class="flex items-start gap-3 rounded-md border p-3 text-sm">
                <input
                    type="radio"
                    class="mt-1"
                    value="replace"
                    bind:group={mode}
                />
                <span>
                    <span class="font-medium"
                        >Replace with a new access code</span
                    >
                    <span class="block text-muted-foreground">
                        Point this BAID at a different card, e.g. a real
                        banapassport. All data carries over.
                    </span>
                </span>
            </label>

            {#if rotateForm}
                <label
                    class="flex items-start gap-3 rounded-md border p-3 text-sm"
                >
                    <input
                        type="radio"
                        class="mt-1"
                        value="rotate"
                        bind:group={mode}
                    />
                    <span>
                        <span class="font-medium"
                            >Issue a fresh access code</span
                        >
                        <span class="block text-muted-foreground">
                            Same as replacing, but the server generates the new
                            code for you.
                        </span>
                    </span>
                </label>
            {/if}

            {#if unbindForm}
                <label
                    class="flex items-start gap-3 rounded-md border p-3 text-sm"
                >
                    <input
                        type="radio"
                        class="mt-1"
                        value="unbind"
                        bind:group={mode}
                    />
                    <span>
                        <span class="font-medium">Unbind from this account</span
                        >
                        <span class="block text-muted-foreground">
                            Detaches the web account only. The card keeps
                            working on this BAID and someone else can claim it.
                        </span>
                    </span>
                </label>
            {/if}

            <label class="flex items-start gap-3 rounded-md border p-3 text-sm">
                <input
                    type="radio"
                    class="mt-1"
                    value="unlink"
                    bind:group={mode}
                />
                <span>
                    <span class="font-medium">Unlink the access code</span>
                    <span class="block text-muted-foreground">
                        Frees the card and clears the owning account. The BAID
                        and all of its data survive as an anonymous BAID nobody
                        can tap into.
                    </span>
                </span>
            </label>
        </fieldset>

        {#if mode === 'replace'}
            <Form
                {...OperatorController.replaceAccessCode.form({
                    ...taikoRouteParam(),
                    player: baid,
                })}
                class="space-y-4"
                options={{ preserveScroll: true }}
                resetOnSuccess
            >
                {#snippet children({ errors, processing })}
                    <div class="grid gap-2">
                        <Label for="new_access_code">New access code</Label>
                        <Input
                            id="new_access_code"
                            name="access_code"
                            class="mt-1 block w-full"
                            required
                            placeholder="Card access code"
                        />
                        <InputError class="mt-2" message={errors.access_code} />
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        onclick={(event: Event) => {
                            if (
                                !confirm(
                                    `Move BAID ${baid} onto the new access code? The old card will stop working.`,
                                )
                            ) {
                                event.preventDefault();
                            }
                        }}
                    >
                        Replace access code
                    </Button>
                {/snippet}
            </Form>
        {:else if mode === 'rotate' && rotateForm}
            <Form {...rotateForm} options={{ preserveScroll: true }}>
                {#snippet children({ errors, processing })}
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        onclick={(event: Event) => {
                            if (
                                !confirm(
                                    `Issue a fresh access code for BAID ${baid}? The old card will stop working.`,
                                )
                            ) {
                                event.preventDefault();
                            }
                        }}
                    >
                        Issue fresh code
                    </Button>
                    <InputError class="mt-2" message={errors.access_code} />
                {/snippet}
            </Form>
        {:else if mode === 'unbind' && unbindForm}
            <Form {...unbindForm} options={{ preserveScroll: true }}>
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing}
                        onclick={(event: Event) => {
                            if (
                                !confirm(
                                    `Unbind ${accessCode} from this account? The card keeps working on BAID ${baid}.`,
                                )
                            ) {
                                event.preventDefault();
                            }
                        }}
                    >
                        Unbind from account
                    </Button>
                {/snippet}
            </Form>
        {:else}
            <Form
                {...OperatorController.unlinkAccessCode.form({
                    ...taikoRouteParam(),
                    player: baid,
                })}
                options={{ preserveScroll: true }}
            >
                {#snippet children({ processing })}
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing}
                        onclick={(event: Event) => {
                            if (
                                !confirm(
                                    `Unlink ${accessCode} from BAID ${baid}? The BAID keeps all of its data but becomes anonymous.`,
                                )
                            ) {
                                event.preventDefault();
                            }
                        }}
                    >
                        Unlink access code
                    </Button>
                {/snippet}
            </Form>
        {/if}
    {:else}
        <p class="text-sm text-muted-foreground">
            This BAID has no access code to move or release.
        </p>
    {/if}
</div>
