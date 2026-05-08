<script module lang="ts">
    export const layout = {
        breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';

    function goToEdit(id: number) {
        router.visit(`/admin/users/${id}/edit`);
    }

    type AdminUser = {
        id: number;
        name: string;
        email: string;
        role: string;
        created_at: string | null;
    };

    let {
        users,
    }: {
        users: { data: AdminUser[] };
    } = $props();

    const currentUserId = $derived(page.props.auth.user.id);
</script>

<AppHead title="User management" />

<div class="flex flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-xl font-semibold">User management</h1>
        <p class="text-sm text-muted-foreground">Edit users or remove accounts.</p>
    </div>

    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left">
                <tr>
                    <th class="px-3 py-2 font-medium">ID</th>
                    <th class="px-3 py-2 font-medium">Name</th>
                    <th class="px-3 py-2 font-medium">Email</th>
                    <th class="px-3 py-2 font-medium">Role</th>
                    <th class="px-3 py-2 font-medium">Created</th>
                    <th class="px-3 py-2 font-medium text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                {#each users.data as user (user.id)}
                    <tr class="border-t">
                        <td class="px-3 py-2">{user.id}</td>
                        <td class="px-3 py-2 font-medium">{user.name}</td>
                        <td class="px-3 py-2">{user.email}</td>
                        <td class="px-3 py-2">{user.role}</td>
                        <td class="px-3 py-2">{user.created_at ?? '-'}</td>
                        <td class="px-3 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onclick={() => goToEdit(user.id)}
                                >
                                    Edit
                                </Button>
                                {#if user.id !== currentUserId}
                                    <Form
                                        {...UserController.destroy.form(user.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                disabled={processing}
                                                onclick={(event: Event) => {
                                                    if (!confirm(`Delete ${user.email}?`)) {
                                                        event.preventDefault();
                                                    }
                                                }}
                                            >
                                                Delete
                                            </Button>
                                        {/snippet}
                                    </Form>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>
