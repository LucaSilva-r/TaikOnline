<script module lang="ts">
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    import { edit } from '@/routes/profile';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import AppHead from '@/components/AppHead.svelte';
    import DeleteUser from '@/components/DeleteUser.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import TextLink from '@/components/TextLink.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { send } from '@/routes/verification';

    let {
        mustVerifyEmail,
        status = '',
        accessCode = null,
        accessCodeQr = null,
    }: {
        mustVerifyEmail: boolean;
        status?: string;
        accessCode?: string | null;
        accessCodeQr?: string | null;
    } = $props();

    const user = $derived(page.props.auth.user);
</script>

<AppHead title="Profile settings" />

<h1 class="sr-only">Profile settings</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Profile information"
        description="Update your name, username, and email address"
    />

    <Form
        {...ProfileController.update.form(taikoRouteParam())}
        class="space-y-6"
        options={{ preserveScroll: true }}
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    class="mt-1 block w-full"
                    value={user.name}
                    required
                    autocomplete="name"
                    placeholder="Display name"
                />
                <p class="text-sm text-muted-foreground">
                    This is your public display name and can be changed at any
                    time.
                </p>
                <InputError class="mt-2" message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="username">Username</Label>
                <Input
                    id="username"
                    class="mt-1 block w-full"
                    value={user.username}
                    disabled
                    readonly
                    tabindex={-1}
                    aria-readonly="true"
                />
                <p class="text-sm text-muted-foreground">
                    Used to log in. Your username is permanent and cannot be
                    changed.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    class="mt-1 block w-full"
                    value={user.email}
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" message={errors.email} />
            </div>

            {#if mustVerifyEmail && !user.email_verified_at}
                <div>
                    <p class="-mt-4 text-sm text-muted-foreground">
                        Your email address is unverified.
                        <TextLink href={send()} as="button">
                            Click here to resend the verification email.
                        </TextLink>
                    </p>

                    {#if status === 'verification-link-sent'}
                        <div class="mt-2 text-sm font-medium text-green-600">
                            A new verification link has been sent to your email
                            address.
                        </div>
                    {/if}
                </div>
            {/if}

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="update-profile-button">Save</Button
                >
            </div>
        {/snippet}
    </Form>
</div>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Banapassport access code"
        description="Your permanent access code for playing and viewing arcade scores online."
    />

    {#if accessCode}
        <div class="space-y-4">
            <div class="grid gap-2">
                <Label>Linked access code</Label>
                <Input value={accessCode} readonly class="mt-1 block w-full" />
            </div>

            {#if accessCodeQr}
                <div class="flex flex-col items-center gap-2">
                    <div class="rounded-md bg-white p-3">
                        <div class="h-[220px] w-[220px]">
                            <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                            {@html accessCodeQr}
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Scan with Zucchini's QR card reader to use this code in
                        place of a physical card.
                    </p>
                </div>
            {/if}
            <p class="text-sm text-muted-foreground">
                Access codes cannot be changed or unlinked from your profile.
                Contact an administrator if your code needs to be replaced.
            </p>
        </div>
    {:else}
        <div
            class="rounded-md border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300"
        >
            This account does not have an access code. Contact an administrator
            to assign one.
        </div>
    {/if}
</div>

<DeleteUser />
