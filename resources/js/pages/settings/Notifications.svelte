<script module lang="ts">
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    import { index } from '@/routes/notifications';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Notifications',
                href: index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import X from 'lucide-svelte/icons/x';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import {
        clear,
        destroy,
        index as notificationsIndex,
        read,
        readAll,
    } from '@/routes/notifications';

    type Item = {
        id: string;
        message: string;
        severity: string;
        url: string | null;
        category: string;
        read: boolean;
        created_at: string | null;
    };

    let {
        category,
        categories,
        items,
    }: {
        category: string | null;
        categories: { value: string; label: string; unread: number }[];
        items: {
            data: Item[];
            current_page: number;
            last_page: number;
            prev_page_url: string | null;
            next_page_url: string | null;
        };
    } = $props();

    const taikoParam = taikoRouteParam();
    const systemNotices = $derived(page.props.systemNotices?.active ?? []);
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const tabs = $derived([
        {
            value: null,
            label: 'All',
            unread: categories.reduce((sum, tab) => sum + tab.unread, 0),
        },
        ...categories,
    ]);
    const activeLabel = $derived(
        tabs.find((tab) => tab.value === category)?.label ?? 'All',
    );
    const scope = $derived(category ? { query: { category } } : undefined);

    function tabHref(value: string | null): string {
        return toUrl(
            notificationsIndex(
                taikoParam,
                value ? { query: { category: value } } : undefined,
            ),
        );
    }

    function open(item: Item): void {
        const visit = () => {
            if (item.url) {
                router.visit(item.url);
            }
        };

        if (item.read) {
            visit();
        } else {
            router.post(
                read(taikoParam).url,
                { ids: [item.id] },
                { preserveScroll: true, onFinish: visit },
            );
        }
    }

    function clearAll(): void {
        if (
            confirm(
                activeLabel === 'All'
                    ? 'Delete all your notifications?'
                    : `Delete all your ${activeLabel.toLowerCase()} notifications?`,
            )
        ) {
            router.delete(clear(taikoParam, scope).url, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppHead title="Notifications" />

<div class="flex flex-col gap-6">
    <Heading
        variant="small"
        title="Notifications"
        description="What you have been told, by category"
    />

    <div class="flex flex-wrap items-center gap-1 border-b">
        {#each tabs as tab (tab.label)}
            <Link
                href={tabHref(tab.value)}
                preserveScroll
                aria-current={tab.value === category ? 'page' : undefined}
                class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm {tab.value ===
                category
                    ? 'border-primary font-medium text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground'}"
            >
                {tab.label}
                {#if tab.unread > 0}
                    <Badge
                        variant="secondary"
                        class="px-1.5 py-0 text-[10px] tabular-nums"
                        >{tab.unread}</Badge
                    >
                {/if}
            </Link>
        {/each}
    </div>

    {#if (category === null || category === 'system') && systemNotices.length > 0}
        <section class="flex flex-col gap-2">
            <h3 class="text-sm font-medium">Current notices, for everyone</h3>
            <ul class="divide-y rounded-md border text-sm">
                {#each systemNotices as notice (notice.id)}
                    <li class="break-words p-3">{notice.message}</li>
                {/each}
            </ul>
        </section>
    {/if}

    {#if items.data.length === 0}
        <p class="text-sm text-muted-foreground">No notifications.</p>
    {:else}
        <div class="flex flex-wrap justify-end gap-2">
            <Button
                size="sm"
                variant="outline"
                onclick={() =>
                    router.post(
                        readAll(taikoParam, scope).url,
                        {},
                        { preserveScroll: true },
                    )}>Mark all read</Button
            >
            <Button size="sm" variant="outline" onclick={clearAll}
                >Clear all</Button
            >
        </div>

        <ul class="divide-y rounded-md border">
            {#each items.data as item (item.id)}
                <li class="flex items-start gap-1 p-1">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-start gap-2 rounded-sm p-2 text-left text-sm {item.url
                            ? 'hover:bg-muted/50'
                            : 'cursor-default'}"
                        onclick={() => open(item)}
                    >
                        <span
                            class="mt-1.5 size-2 shrink-0 rounded-full {item.read
                                ? 'bg-transparent'
                                : item.severity === 'info'
                                  ? 'bg-sky-500'
                                  : 'bg-amber-500'}"
                            aria-hidden="true"
                        ></span>
                        <span class="min-w-0">
                            <span
                                class="block break-words {item.read
                                    ? 'text-muted-foreground'
                                    : ''}">{item.message}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >{item.category}{item.created_at
                                    ? ` · ${dateFormatter.format(new Date(item.created_at))}`
                                    : ''}</span
                            >
                        </span>
                    </button>
                    <Button
                        size="icon"
                        variant="ghost"
                        class="size-8 shrink-0"
                        aria-label="Delete this notification"
                        onclick={() =>
                            router.delete(
                                destroy({
                                    ...taikoParam,
                                    notification: item.id,
                                }).url,
                                { preserveScroll: true },
                            )}
                    >
                        <X class="size-4" />
                    </Button>
                </li>
            {/each}
        </ul>

        {#if items.last_page > 1}
            <div class="flex items-center justify-between text-sm">
                {#if items.prev_page_url}
                    <Link
                        href={items.prev_page_url}
                        class={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}>Previous</Link
                    >
                {:else}
                    <span></span>
                {/if}
                <span class="text-muted-foreground"
                    >Page {items.current_page} of {items.last_page}</span
                >
                {#if items.next_page_url}
                    <Link
                        href={items.next_page_url}
                        class={buttonVariants({
                            variant: 'outline',
                            size: 'sm',
                        })}>Next</Link
                    >
                {:else}
                    <span></span>
                {/if}
            </div>
        {/if}
    {/if}
</div>
