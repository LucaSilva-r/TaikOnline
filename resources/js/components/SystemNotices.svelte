<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import CircleAlert from 'lucide-svelte/icons/circle-alert';
    import Info from 'lucide-svelte/icons/info';
    import TriangleAlert from 'lucide-svelte/icons/triangle-alert';
    import X from 'lucide-svelte/icons/x';
    import { toast } from 'svelte-sonner';
    import { listenPublic } from '@/lib/realtime';

    const storageKey = 'dismissed-system-notices';
    const notices = $derived(page.props.systemNotices?.active ?? []);
    const realtimeUrl = $derived(page.props.systemNotices?.realtimeUrl);
    let dismissed = $state<string[]>([]);

    // A viewer's own convenience: a critical notice cannot be dismissed, and a new notice always shows.
    $effect(() => {
        try {
            dismissed = JSON.parse(localStorage.getItem(storageKey) ?? '[]');
        } catch {
            dismissed = [];
        }
    });

    function dismiss(id: string): void {
        dismissed = [...dismissed, id];

        try {
            localStorage.setItem(storageKey, JSON.stringify(dismissed));
        } catch {
            // It comes back on the next page instead.
        }
    }

    // Posted or ended while the page is open: the server's list is the one shown.
    $effect(() => {
        if (!realtimeUrl) {
            return;
        }

        return listenPublic(realtimeUrl, 'wdb.notices', (event, data) => {
            if (event === 'notice') {
                toast(String(data.message ?? ''));
            }

            router.reload({ only: ['systemNotices'] });
        });
    });

    const styles: Record<string, string> = {
        info: 'border-sky-500/40 bg-sky-500/10',
        warning: 'border-amber-500/40 bg-amber-500/10',
        critical: 'border-red-500/40 bg-red-500/10',
    };
</script>

{#each notices.filter((notice) => notice.severity === 'critical' || !dismissed.includes(notice.id)) as notice (notice.id)}
    <div
        role={notice.severity === 'info' ? 'status' : 'alert'}
        class="flex items-start gap-2 border-b px-4 py-2 text-sm {styles[
            notice.severity
        ] ?? styles.info}"
    >
        {#if notice.severity === 'critical'}
            <CircleAlert
                class="mt-0.5 size-4 shrink-0 text-red-600 dark:text-red-400"
            />
        {:else if notice.severity === 'warning'}
            <TriangleAlert
                class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400"
            />
        {:else}
            <Info
                class="mt-0.5 size-4 shrink-0 text-sky-600 dark:text-sky-400"
            />
        {/if}
        <span class="min-w-0 flex-1 break-words">{notice.message}</span>
        {#if notice.severity !== 'critical'}
            <button
                type="button"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="Dismiss this notice"
                onclick={() => dismiss(notice.id)}
            >
                <X class="size-4" />
            </button>
        {/if}
    </div>
{/each}
