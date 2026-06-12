<script module lang="ts">
    import usersRoutesForLayout from '@/routes/admin/users';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            { title: 'Users', href: usersRoutesForLayout.index(taikoRouteParamForLayout()) },
            { title: 'Edit', href: '' },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, page } from '@inertiajs/svelte';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import usersRoutes from '@/routes/admin/users';

    type AdminUser = {
        id: number;
        name: string;
        username: string;
        email: string;
        role: string;
        created_at: string | null;
    };

    type RoleOption = { value: string; label: string };

    let {
        user,
        roles,
        accessCode = null,
    }: {
        user: AdminUser;
        roles: RoleOption[];
        accessCode?: string | null;
    } = $props();

    const currentUserId = $derived(page.props.auth.user.id);
    const isSelf = $derived(user.id === currentUserId);
</script>

<AppHead title={`Edit ${user.name}`} />

<div class="flex flex-1 flex-col gap-6 p-4">
    <Heading
        title={`Edit ${user.name}`}
        description="Update name, email, and role for this user."
    />

    <Form
        {...UserController.update.form({ ...taikoRouteParam(), user: user.id })}
        class="max-w-xl space-y-6"
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
                />
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
                <p class="mt-2 text-sm text-muted-foreground">
                    Usernames are permanent and cannot be changed.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    class="mt-1 block w-full"
                    value={user.email}
                    required
                    autocomplete="email"
                />
                <InputError class="mt-2" message={errors.email} />
            </div>

            <div class="grid gap-2">
                <Label for="role">Role</Label>
                <select
                    id="role"
                    name="role"
                    value={user.role}
                    disabled={isSelf}
                    class="rounded-md border bg-background px-2 py-2 text-sm"
                >
                    {#each roles as role (role.value)}
                        <option value={role.value}>{role.label}</option>
                    {/each}
                </select>
                {#if isSelf}
                    <p class="text-xs text-muted-foreground">
                        You cannot change your own role.
                    </p>
                {/if}
                <InputError class="mt-2" message={errors.role} />
            </div>

            <div class="flex items-center gap-3">
                <Button type="submit" disabled={processing}>Save</Button>
                <Button asChild variant="ghost">
                    {#snippet children(props)}
                        <Link href={usersRoutes.index(taikoRouteParam())} {...props}>Cancel</Link>
                    {/snippet}
                </Button>
            </div>
        {/snippet}
    </Form>

    <Heading
        variant="small"
        title="Reset password"
        description="Set a new password for this user. They will need to log in again with the new password."
    />

    <Form
        {...UserController.updatePassword.form({ ...taikoRouteParam(), user: user.id })}
        class="max-w-xl space-y-6"
        options={{ preserveScroll: true }}
        resetOnSuccess
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <Input
                    id="password"
                    type="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    required
                    autocomplete="new-password"
                />
            </div>

            <Button type="submit" disabled={processing}>Update password</Button>
        {/snippet}
    </Form>

    <Heading
        variant="small"
        title="Banapassport access code"
        description="Link or unlink this user's arcade card. Only one card can be linked at a time."
    />

    {#if accessCode}
        <div class="max-w-xl space-y-4">
            <div class="grid gap-2">
                <Label>Linked access code</Label>
                <Input value={accessCode} readonly class="mt-1 block w-full" />
            </div>
            <div class="flex flex-wrap gap-3">
                <Form
                    {...UserController.rotateAccessCode.form({ ...taikoRouteParam(), user: user.id })}
                    options={{ preserveScroll: true }}
                >
                    {#snippet children({ errors, processing })}
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            onclick={(event: Event) => {
                                if (!confirm('Rotate this access code?')) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            Rotate
                        </Button>
                        <InputError class="mt-2" message={errors.access_code} />
                    {/snippet}
                </Form>

                <Form
                    {...UserController.unbindAccessCode.form({ ...taikoRouteParam(), user: user.id })}
                    options={{ preserveScroll: true }}
                >
                    {#snippet children({ processing })}
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                            onclick={(event: Event) => {
                                if (!confirm('Unlink this access code?')) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            Unlink
                        </Button>
                    {/snippet}
                </Form>
            </div>
        </div>
    {:else}
        <Form
            {...UserController.bindAccessCode.form({ ...taikoRouteParam(), user: user.id })}
            class="max-w-xl space-y-6"
            options={{ preserveScroll: true }}
            resetOnSuccess
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-2">
                    <Label for="access_code">Access code</Label>
                    <Input
                        id="access_code"
                        name="access_code"
                        class="mt-1 block w-full"
                        required
                        placeholder="Card access code"
                    />
                    <InputError class="mt-2" message={errors.access_code} />
                </div>

                <Button type="submit" disabled={processing}>Link</Button>
            {/snippet}
        </Form>
    {/if}
</div>
