<script module lang="ts">
    export const layout = {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import PasswordInput from '@/components/PasswordInput.svelte';
    import TextLink from '@/components/TextLink.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Spinner } from '@/components/ui/spinner';
    import { login } from '@/routes';
    import { store } from '@/routes/register';

    // Prefilled when arriving from the dongle "Sign up" deep-link so a freshly
    // created card is linked as the account is created.
    let { accessCode = null }: { accessCode?: string | null } = $props();
</script>

<AppHead title="Register" />

<Form
    {...store.form()}
    resetOnSuccess={['password', 'password_confirmation']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autocomplete="name"
                    name="name"
                    placeholder="Display name"
                />
                <p class="text-sm text-muted-foreground">
                    This is your public display name. You can change it at any
                    time.
                </p>
                <InputError message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    type="text"
                    required
                    autocomplete="username"
                    name="username"
                    placeholder="username"
                />
                <p class="text-sm text-muted-foreground">
                    Used to log in. Choose carefully — your username cannot be
                    changed later.
                </p>
                <InputError message={errors.username} />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <Label for="access_code">Access code</Label>
                <Input
                    id="access_code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    name="access_code"
                    value={accessCode ?? ''}
                    placeholder="Optional card access code"
                />
                <p class="text-sm text-muted-foreground">
                    Link an existing Zucchini-issued card while creating your
                    account.
                </p>
                <InputError message={errors.access_code} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                disabled={processing}
                data-test="register-user-button"
            >
                {#if processing}<Spinner />{/if}
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink href={login()} class="underline underline-offset-4">
                Log in
            </TextLink>
        </div>
    {/snippet}
</Form>
