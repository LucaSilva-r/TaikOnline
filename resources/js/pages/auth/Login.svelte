<script module lang="ts">
    export const layout = {
        title: 'Log in to your account',
        description:
            'Enter your username or email and password below to log in',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import TextLink from '@/components/TextLink.svelte';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { register } from '@/routes';
    import { store } from '@/routes/login';
    import { request } from '@/routes/password';

    let {
        status = '',
        canResetPassword,
        canRegister,
        signupAccessCode = null,
        signupVersion = null,
        playIntent = false,
    }: {
        status?: string;
        canResetPassword: boolean;
        canRegister: boolean;
        signupAccessCode?: string | null;
        signupVersion?: string | null;
        playIntent?: boolean;
    } = $props();

    // Forward a pending card access code (user was bounced here from the
    // dongle deep-link) to the Sign up link so registration can carry it on.
    const registerHref = $derived(
        signupAccessCode
            ? register({
                  query: {
                      access_code: signupAccessCode,
                      v: signupVersion ?? 'green',
                  },
              })
            : register(),
    );
</script>

<AppHead title="Log in" />

{#if playIntent}
    <div
        class="mb-4 rounded-md border border-[var(--taiko-accent-border)] bg-[var(--taiko-accent-soft)] p-3 text-center text-sm"
    >
        Log in to send your Banapass to a cabinet. You will return to the Play
        page afterward.
    </div>
{/if}

{#if status}
    <div class="mb-4 text-center text-sm font-medium text-green-600">
        {status}
    </div>
{/if}

<Form
    {...store.form()}
    resetOnSuccess={['password']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Username or email</Label>
                <Input
                    id="email"
                    type="text"
                    name="email"
                    required
                    autocomplete="username"
                    placeholder="username or email@example.com"
                />
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    {#if canResetPassword}
                        <TextLink href={request()} class="text-sm">
                            Forgot password?
                        </TextLink>
                    {/if}
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Password"
                />
                <InputError message={errors.password} />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Remember me</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                disabled={processing}
                data-test="login-button"
            >
                {#if processing}<Spinner />{/if}
                Log in
            </Button>
        </div>

        {#if canRegister}
            <div class="text-center text-sm text-muted-foreground">
                Don't have an account?
                <TextLink href={registerHref}>Sign up</TextLink>
            </div>
        {/if}
    {/snippet}
</Form>
