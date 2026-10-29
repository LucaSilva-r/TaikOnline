<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import Bell from 'lucide-svelte/icons/bell';
    import { Button } from '@/components/ui/button';
    import {
        DropdownMenu,
        DropdownMenuContent,
        DropdownMenuItem,
        DropdownMenuLabel,
        DropdownMenuSeparator,
        DropdownMenuTrigger,
    } from '@/components/ui/dropdown-menu';
    import { toast } from 'svelte-sonner';
    import { listenPrivate } from '@/lib/realtime';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { read } from '@/routes/notifications';

    const notifications = $derived(page.props.notifications);
    const readUrl = read(taikoRouteParam()).url;
    const realtimeUrl = $derived(notifications?.realtime?.url);
    const channel = $derived(notifications?.realtime?.channel);

    // A pushed notification pops up and refreshes the list (the server's copy is the one shown).
    $effect(() => {
        if (!realtimeUrl || !channel) {
            return;
        }
        return listenPrivate(realtimeUrl, channel, (event, data) => {
            if (event === 'notice' || event === 'site-notice') {
                toast(String(data.message ?? ''));
                router.reload({ only: ['notifications'] });
            }
        });
    });
    const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

    function open(notification: { id: string; url: string | null; read: boolean }): void {
        const visit = () => notification.url && router.visit(notification.url);
        if (notification.read) {
            visit();
        } else {
            router.post(readUrl, { ids: [notification.id] }, { preserveScroll: true, onFinish: visit });
        }
    }
</script>

{#if notifications}
    <DropdownMenu>
        <DropdownMenuTrigger asChild>
            {#snippet children(props)}
                <Button
                    variant="ghost"
                    size="icon"
                    class="relative size-9"
                    onclick={props.onclick}
                    aria-expanded={props['aria-expanded']}
                    data-state={props['data-state']}
                    aria-label="Notifications{notifications.unread ? ` (${notifications.unread} unread)` : ''}"
                >
                    <Bell class="size-5" />
                    {#if notifications.unread > 0}
                        <span
                            class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-4 font-semibold text-white"
                            >{notifications.unread > 99 ? '99+' : notifications.unread}</span
                        >
                    {/if}
                </Button>
            {/snippet}
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between">
                <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                {#if notifications.unread > 0}
                    <button
                        type="button"
                        class="px-2 text-xs text-muted-foreground hover:text-foreground"
                        onclick={() => router.post(readUrl, {}, { preserveScroll: true })}>Mark all read</button
                    >
                {/if}
            </div>
            <DropdownMenuSeparator />
            <div class="max-h-96 overflow-y-auto">
                {#each notifications.latest as notification (notification.id)}
                    <DropdownMenuItem asChild>
                        {#snippet children(props)}
                            <button
                                type="button"
                                class="{props.class} flex-col items-start gap-0.5 text-left"
                                onclick={() => {
                                    props.onClick?.();
                                    open(notification);
                                }}
                            >
                                <span class="flex w-full items-start gap-2">
                                    <span
                                        class="mt-1.5 size-2 shrink-0 rounded-full {notification.read
                                            ? 'bg-transparent'
                                            : notification.severity === 'info'
                                              ? 'bg-sky-500'
                                              : 'bg-amber-500'}"
                                        aria-hidden="true"
                                    ></span>
                                    <span class={notification.read ? 'text-muted-foreground' : ''}>{notification.message}</span>
                                </span>
                                {#if notification.created_at}
                                    <span class="pl-4 text-xs text-muted-foreground"
                                        >{dateFormatter.format(new Date(notification.created_at))}</span
                                    >
                                {/if}
                            </button>
                        {/snippet}
                    </DropdownMenuItem>
                {:else}
                    <p class="px-2 py-4 text-center text-sm text-muted-foreground">No notifications yet.</p>
                {/each}
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
{/if}
