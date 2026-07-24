<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import CabinetLoginController from '@/actions/App/Http/Controllers/Settings/CabinetLoginController';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import {
        InputOTP,
        InputOTPGroup,
        InputOTPSlot,
    } from '@/components/ui/input-otp';
    import { Label } from '@/components/ui/label';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { edit as editProfile } from '@/routes/profile';

    let {
        hasUsableAccessCode,
    }: {
        hasUsableAccessCode: boolean;
    } = $props();
    let code = $state('');
</script>

<AppHead title="Play on cabinet" />

<section class="mx-auto w-full max-w-3xl px-4 py-12 sm:py-16">
    <div class="mb-8 text-center">
        <p class="mb-2 text-sm font-semibold text-[var(--taiko-accent-label)]">
            Play on cabinet
        </p>
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
            Send your Banapass to the game
        </h1>
        <p class="mx-auto mt-3 max-w-xl text-balance text-muted-foreground">
            Enter the six-digit code shown by the cabinet. Not all cabinets may
            have 6 pin login enabled.
        </p>
    </div>

    {#if hasUsableAccessCode}
        <Form
            {...CabinetLoginController.store.form(taikoRouteParam())}
            class="flex flex-col items-center gap-8"
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => (code = '')}
            onError={() => (code = '')}
        >
            {#snippet children({ errors, processing })}
                <input type="hidden" name="code" value={code} />

                <div class="flex flex-col items-center gap-5 text-center">
                    <Label for="cabinet-code">Cabinet code</Label>
                    <InputOTP
                        id="cabinet-code"
                        bind:value={code}
                        maxlength={6}
                        disabled={processing}
                        autofocus
                        aria-invalid={errors.code ? 'true' : undefined}
                        class="justify-center"
                    >
                        <InputOTPGroup class="gap-2 sm:gap-4">
                            {#each { length: 6 } as _, index (index)}
                                <InputOTPSlot
                                    {index}
                                    class="h-14 w-9 rounded-none border-0 border-b-4 border-muted-foreground/40 bg-transparent text-2xl font-bold shadow-none first:rounded-none first:border-l-0 last:rounded-none data-[active=true]:border-[var(--taiko-accent)] data-[active=true]:ring-0 dark:bg-transparent sm:w-12 sm:text-3xl"
                                />
                            {/each}
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError message={errors.code} />
                </div>

                <Button
                    type="submit"
                    class="min-w-40 px-6"
                    disabled={processing || code.length !== 6}
                >
                    {processing ? 'Logging in…' : 'Login'}
                </Button>

                <p class="max-w-md text-center text-sm text-muted-foreground">
                    The code expires when the cabinet stops accepting cards or
                    when the displayed number rotates.
                </p>
            {/snippet}
        </Form>
    {:else}
        <div
            class="flex flex-col items-center gap-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-6 text-center sm:p-8"
        >
            <div class="space-y-2">
                <h2 class="font-semibold text-amber-800 dark:text-amber-200">
                    A Banapassport is required
                </h2>
                <p class="max-w-lg text-sm text-amber-700 dark:text-amber-300">
                    This account does not have a usable Banapass access code.
                    Check your profile for its current status or contact an
                    administrator to have one assigned.
                </p>
            </div>

            <Button variant="outline" asChild>
                {#snippet children(props)}
                    <Link
                        href={editProfile(taikoRouteParam())}
                        class={props.class}
                    >
                        Open profile settings
                    </Link>
                {/snippet}
            </Button>
        </div>
    {/if}
</section>
