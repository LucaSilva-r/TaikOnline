<script module lang="ts">
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';
    import uploadWarningsForLayout from '@/routes/mod/upload-warnings';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Upload warnings',
                href: uploadWarningsForLayout.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as danPlayShow } from '@/routes/dan-plays';
    import moderationPlayers from '@/routes/mod/players';
    import moderationWarnings from '@/routes/mod/upload-warnings';
    import { show as playsShow } from '@/routes/plays';

    type Warning = {
        id: number;
        kind: string;
        day: string;
        count: number;
        detail: string | null;
        last_seen_at: string;
        player: {
            baid: number;
            mydon_name: string | null;
            user: { name: string } | null;
        } | null;
        play_id: string | null;
        dan_play_id: string | null;
    };

    let { warnings }: { warnings: Warning[] } = $props();

    const taikoParam = taikoRouteParam();
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function clearAll(): void {
        if (confirm(`Clear all ${warnings.length} upload warnings?`)) {
            router.patch(
                moderationWarnings.dismissAll(taikoParam).url,
                {},
                { preserveScroll: true },
            );
        }
    }
</script>

<AppHead title="Upload warnings" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Upload warnings</h1>
        <p class="text-sm text-muted-foreground">
            Bad data players tried to send, one row per player, kind and day,
            with how many times. An honest client should never cause these,
            though a score difference can also be an engine bug. Nothing here is
            sanctioned automatically. Clearing one removes it for all staff, and
            it comes back if the player does it again.
        </p>
    </div>

    {#if warnings.length > 0}
        <div class="flex justify-end">
            <Button size="sm" variant="outline" onclick={clearAll}
                >Clear all</Button
            >
        </div>
    {/if}

    {#each warnings as warning (warning.id)}
        <section class="flex flex-col gap-2 rounded-md border p-4">
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    variant="secondary"
                    class="border-0 bg-amber-500/15 text-amber-700 dark:text-amber-400"
                    >{warning.kind}</Badge
                >
                <span class="font-medium">
                    {warning.player?.user?.name ??
                        warning.player?.mydon_name ??
                        'Cabinet or no Banapass'}
                </span>
                <span class="text-sm text-muted-foreground">
                    {warning.count}× · last {dateFormatter.format(
                        new Date(warning.last_seen_at),
                    )}
                </span>
                <div class="ml-auto flex flex-wrap gap-2">
                    {#if warning.play_id}
                        <Link
                            href={toUrl(
                                playsShow({
                                    taikoVersion: 'waddamburo',
                                    id: warning.play_id,
                                }),
                            )}
                            class={buttonVariants({ size: 'sm' })}
                            >Watch &amp; inspect</Link
                        >
                    {:else if warning.dan_play_id}
                        <Link
                            href={toUrl(
                                danPlayShow({
                                    taikoVersion: 'waddamburo',
                                    id: warning.dan_play_id,
                                }),
                            )}
                            class={buttonVariants({ size: 'sm' })}
                            >Open run</Link
                        >
                    {/if}
                    {#if warning.player}
                        <Link
                            href={toUrl(
                                moderationPlayers.show({
                                    ...taikoParam,
                                    player: warning.player.baid,
                                }),
                            )}
                            class={buttonVariants({
                                variant: 'outline',
                                size: 'sm',
                            })}>Player</Link
                        >
                    {/if}
                    <Button
                        size="sm"
                        variant="outline"
                        onclick={() =>
                            router.patch(
                                moderationWarnings.dismiss({
                                    ...taikoParam,
                                    warning: warning.id,
                                }).url,
                                {},
                                { preserveScroll: true },
                            )}>Clear</Button
                    >
                </div>
            </div>
            {#if warning.detail}
                <p class="text-sm break-all text-muted-foreground">
                    {warning.detail}
                </p>
            {/if}
        </section>
    {:else}
        <p class="text-sm text-muted-foreground">No upload warnings.</p>
    {/each}
</div>
