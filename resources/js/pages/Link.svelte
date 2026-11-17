<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import DeviceLinkController from '@/actions/App/Http/Controllers/Settings/DeviceLinkController';
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
        hasPlayer,
        account,
        done,
        code: pendingCode,
        device,
        codeInvalid,
    }: {
        hasPlayer: boolean;
        account: string;
        done: 'approved' | 'refused' | null;
        code: string | null;
        device: string | null;
        codeInvalid: boolean;
    } = $props();
    let code = $state('');

    function lookUp() {
        router.get(
            DeviceLinkController.create.url(taikoRouteParam()),
            { code },
            { preserveScroll: true },
        );
    }
</script>

<AppHead title="Link a device" />

<section class="mx-auto w-full max-w-3xl px-4 py-12 sm:py-16">
    <div class="mb-8 text-center">
        <p class="mb-2 text-sm font-semibold text-[var(--taiko-accent-label)]">
            Link a device
        </p>
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">
            {#if done === 'approved'}
                You're logged in
            {:else if done === 'refused'}
                Device refused
            {:else if device && pendingCode}
                Log in on this device?
            {:else}
                Log in to Waddamburo
            {/if}
        </h1>
        <p class="mx-auto mt-3 max-w-xl text-balance text-muted-foreground">
            {#if done === 'approved'}
                Go back to Waddamburo: it logs you in within a few seconds. You
                can close this page.
            {:else if done === 'refused'}
                The game was not logged in. You can close this page.
            {:else if device && pendingCode}
                Only approve if you just chose Sign in on this game yourself.
                You can revoke it any time in your settings.
            {:else}
                Enter the six-digit code the game shows. The game then keeps you
                logged in on that computer; you can revoke it any time.
            {/if}
        </p>
    </div>

    {#if done}
        <!-- Nothing more to do here. -->
    {:else if !hasPlayer}
        <div
            class="flex flex-col items-center gap-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-6 text-center sm:p-8"
        >
            <h2 class="font-semibold text-amber-800 dark:text-amber-200">
                A Banapassport is required
            </h2>
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
    {:else if device && pendingCode}
        <div class="flex flex-col items-center gap-6 text-center">
            <p class="text-lg">
                <span class="font-semibold">{device}</span> will play as
                <span class="font-semibold">{account}</span> and upload your scores.
            </p>
            <div class="flex gap-3">
                {#each [{ approve: '1', label: 'Approve' }, { approve: '0', label: 'Refuse' }] as choice (choice.approve)}
                    <Form
                        {...DeviceLinkController.store.form(taikoRouteParam())}
                    >
                        {#snippet children({ errors, processing })}
                            <input
                                type="hidden"
                                name="code"
                                value={pendingCode}
                            />
                            <input
                                type="hidden"
                                name="approve"
                                value={choice.approve}
                            />
                            <Button
                                type="submit"
                                variant={choice.approve === '1'
                                    ? 'default'
                                    : 'outline'}
                                class="min-w-32"
                                disabled={processing}
                            >
                                {choice.label}
                            </Button>
                            {#if choice.approve === '1'}
                                <InputError message={errors.code} />
                            {/if}
                        {/snippet}
                    </Form>
                {/each}
            </div>
            <Form
                {...DeviceLinkController.switchAccount.form(taikoRouteParam())}
            >
                {#snippet children({ processing })}
                    <input type="hidden" name="code" value={pendingCode} />
                    <Button
                        type="submit"
                        variant="link"
                        class="text-muted-foreground"
                        disabled={processing}
                    >
                        Not {account}? Change account
                    </Button>
                {/snippet}
            </Form>
        </div>
    {:else}
        <form
            class="flex flex-col items-center gap-8"
            onsubmit={(event) => {
                event.preventDefault();

                if (code.length === 6) {
                    lookUp();
                }
            }}
        >
            <div class="flex flex-col items-center gap-5 text-center">
                <Label for="device-code">Game code</Label>
                <InputOTP
                    id="device-code"
                    bind:value={code}
                    maxlength={6}
                    autofocus
                    aria-invalid={codeInvalid ? 'true' : undefined}
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
                <InputError
                    message={codeInvalid
                        ? 'That code is invalid or has expired.'
                        : undefined}
                />
            </div>
            <Button
                type="submit"
                class="min-w-40 px-6"
                disabled={code.length !== 6}
            >
                Continue
            </Button>
        </form>
    {/if}
</section>
