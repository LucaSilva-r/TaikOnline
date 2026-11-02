<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import DeleteUser from '@/components/DeleteUser.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { accept, privacy, rules } from '@/routes/legal';
    import { logout } from '@/routes';

    let {
        title,
        html,
        mustAccept,
        version,
        versions = [],
        since = null,
        changes = null,
    }: {
        title: string;
        html: string;
        mustAccept: boolean;
        version: string;
        versions?: string[];
        since?: string | null;
        changes?:
            { change: 'same' | 'removed' | 'added'; html: string }[] | null;
    } = $props();

    // Another earlier version to compare with, or none ('' rather than nothing: a player behind on the rules gets
    // the one they accepted unless they say otherwise).
    function compare(event: Event) {
        router.get(
            window.location.pathname,
            { since: (event.currentTarget as HTMLSelectElement).value },
            { preserveScroll: true, preserveState: true },
        );
    }

    const taikoParam = taikoRouteParam();
    const links = [
        { label: 'Rules', href: toUrl(rules(taikoParam)) },
        { label: 'Privacy Policy', href: toUrl(privacy(taikoParam)) },
    ];
</script>

<AppHead {title} />

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-8">
    <nav class="flex flex-wrap gap-4 text-sm" aria-label="Legal documents">
        {#each links as link (link.label)}
            <Link
                href={link.href}
                aria-current={link.label === title ? 'page' : undefined}
                class={link.label === title
                    ? 'font-medium text-foreground'
                    : 'text-muted-foreground hover:text-foreground'}
                >{link.label}</Link
            >
        {/each}
    </nav>

    {#if mustAccept}
        <section
            class="flex flex-col gap-4 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4"
        >
            <div>
                <h2 class="font-semibold">Please read the Rules</h2>
                <p class="text-sm text-muted-foreground">
                    To keep using your account, including uploading scores from
                    Waddamburo, read the Rules below and accept them.
                </p>
            </div>
            <Form {...accept.form(taikoParam)} class="flex flex-col gap-3">
                {#snippet children({ errors, processing })}
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="accepted"
                            value="1"
                            class="mt-0.5"
                            required
                        />
                        <span>I have read and accept the Rules.</span>
                    </label>
                    <InputError message={errors.accepted} />
                    <div class="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}
                            >Accept</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            onclick={() => router.post(logout().url)}
                            >Log out</Button
                        >
                    </div>
                {/snippet}
            </Form>
        </section>
    {/if}

    {#if versions.length > 0}
        <label class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-muted-foreground">Show what changed since</span>
            <select
                class="rounded-md border bg-background px-2 py-1"
                value={since ?? ''}
                onchange={compare}
            >
                <option value="">(don't show changes)</option>
                {#each versions.toReversed() as earlier (earlier)}
                    <option value={earlier}>Version {earlier}</option>
                {/each}
            </select>
        </label>
    {/if}

    {#if changes}
        <p class="text-sm text-muted-foreground">
            Changes since version {since}:
            <span class="added px-1">added</span>
            <span class="removed px-1">removed</span>
        </p>
        <article class="legal">
            {#each changes as block, index (index)}
                {#if block.change === 'same'}
                    {@html block.html}
                {:else}
                    <div class={block.change}>{@html block.html}</div>
                {/if}
            {/each}
        </article>
    {:else}
        <article class="legal">
            {@html html}
        </article>
    {/if}
    <p class="text-xs text-muted-foreground">Version {version}</p>

    {#if mustAccept}
        <DeleteUser />
    {/if}
</div>

<style>
    .added {
        border-left: 3px solid rgb(34 197 94);
        background: rgb(34 197 94 / 0.12);
        padding-left: 0.5rem;
    }

    .removed {
        border-left: 3px solid rgb(239 68 68);
        background: rgb(239 68 68 / 0.12);
        padding-left: 0.5rem;
        text-decoration: line-through;
        opacity: 0.8;
    }

    .legal {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        font-size: 0.9375rem;
        line-height: 1.6;
    }

    .legal :global(h1) {
        font-size: 1.5rem;
        font-weight: 600;
    }

    .legal :global(h2) {
        margin-top: 1rem;
        font-size: 1.125rem;
        font-weight: 600;
    }

    .legal :global(ul) {
        padding-left: 1.25rem;
        list-style: disc;
    }

    .legal :global(a) {
        text-decoration: underline;
    }

    .legal :global(table) {
        display: block;
        overflow-x: auto;
        font-size: 0.875rem;
    }

    .legal :global(th),
    .legal :global(td) {
        padding: 0.375rem 0.75rem 0.375rem 0;
        border-bottom: 1px solid var(--border);
        text-align: left;
        vertical-align: top;
    }
</style>
