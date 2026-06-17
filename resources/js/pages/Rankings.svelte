<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';
    import {
        Avatar,
        AvatarFallback,
        AvatarImage,
    } from '@/components/ui/avatar';
    import { getInitials } from '@/lib/initials';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as boardShow } from '@/routes/board';
    import { Link } from '@inertiajs/svelte';
    import ChevronUp from 'lucide-svelte/icons/chevron-up';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import Crown from 'lucide-svelte/icons/crown';
    import Minus from 'lucide-svelte/icons/minus';
    import Trophy from 'lucide-svelte/icons/trophy';

    type CrownCounts = {
        none: number;
        clear: number;
        gold: number;
        dondaful: number;
    };

    type RankingEntry = {
        rank: number;
        rank_change: number | null;
        user_id: number;
        player_name: string;
        avatar: string | null;
        total_score: number;
        ranked_song_count: number;
        crown_counts: CrownCounts;
    };

    let {
        gameVersion,
        entries,
    }: {
        gameVersion: { value: string; label: string };
        entries: RankingEntry[];
    } = $props();

    const taikoParam = taikoRouteParam();
    const numberFormatter = new Intl.NumberFormat('en-US');

    function boardUrl(userId: number): string {
        return toUrl(boardShow({ ...taikoParam, user: userId }));
    }

    /** Gold / silver / bronze styling for the podium, accent tint elsewhere. */
    function rankBadgeClass(rank: number): string {
        if (rank === 1) {
            return 'bg-gradient-to-br from-amber-300 to-yellow-500 text-amber-950 shadow-sm shadow-amber-500/30';
        }
        if (rank === 2) {
            return 'bg-gradient-to-br from-slate-200 to-slate-400 text-slate-800 shadow-sm shadow-slate-400/30';
        }
        if (rank === 3) {
            return 'bg-gradient-to-br from-orange-300 to-amber-700 text-orange-950 shadow-sm shadow-orange-700/30';
        }
        return 'bg-muted text-muted-foreground';
    }
</script>

<AppHead title="Rankings" />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <div
        class="relative overflow-hidden rounded-xl border bg-gradient-to-br from-[var(--taiko-accent-soft)] via-card to-card px-6 py-7"
    >
        <div
            class="pointer-events-none absolute -right-8 -top-10 size-40 rounded-full bg-[var(--taiko-accent)] opacity-15 blur-3xl"
        ></div>
        <div class="relative flex items-center gap-4">
            <div
                class="flex size-12 items-center justify-center rounded-xl bg-[var(--taiko-accent)] text-white shadow-md shadow-[var(--taiko-accent)]/30"
            >
                <Trophy class="size-6" />
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Rankings</h1>
                <p class="text-sm text-muted-foreground">
                    Global player standings ·
                    <span class="font-medium text-[var(--taiko-accent-label)]">
                        {gameVersion.label}
                    </span>
                </p>
            </div>
        </div>
    </div>

    {#if entries.length === 0}
        <div class="rounded-md border p-6 text-sm text-muted-foreground">
            No ranked players yet.
        </div>
    {:else}
        <div class="overflow-hidden rounded-xl border bg-card">
            <!-- Header row (hidden on mobile) -->
            <div
                class="hidden border-b bg-muted/40 px-4 py-3 text-xs font-medium text-muted-foreground sm:grid sm:grid-cols-[3.5rem_1fr_6rem_8rem_repeat(3,4rem)] sm:items-center sm:gap-3"
            >
                <span>#</span>
                <span>Player</span>
                <span class="text-right">Charts</span>
                <span class="text-right">Total Score</span>
                <span class="flex items-center justify-end gap-1" title="Dondaful">
                    <Crown class="size-3.5 text-fuchsia-500" />
                </span>
                <span class="flex items-center justify-end gap-1" title="Full Combo">
                    <Crown class="size-3.5 text-amber-500" />
                </span>
                <span class="flex items-center justify-end gap-1" title="Clear">
                    <Crown class="size-3.5 text-sky-500" />
                </span>
            </div>

            <div class="divide-y">
                {#each entries as entry (entry.user_id)}
                    <div
                        class="grid grid-cols-[3rem_1fr] items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40 sm:grid-cols-[3.5rem_1fr_6rem_8rem_repeat(3,4rem)] {entry.rank <=
                        3
                            ? 'bg-[var(--taiko-accent-soft)]'
                            : ''}"
                    >
                        <!-- Rank + movement -->
                        <div class="flex items-center gap-1.5">
                            <span
                                class="flex size-7 items-center justify-center rounded-md text-xs font-bold tabular-nums {rankBadgeClass(
                                    entry.rank,
                                )}"
                            >
                                {entry.rank}
                            </span>
                            {#if entry.rank_change !== null && entry.rank_change > 0}
                                <span
                                    class="flex items-center text-xs font-medium text-emerald-500"
                                >
                                    <ChevronUp class="size-3.5" />
                                    {entry.rank_change}
                                </span>
                            {:else if entry.rank_change !== null && entry.rank_change < 0}
                                <span
                                    class="flex items-center text-xs font-medium text-red-500"
                                >
                                    <ChevronDown class="size-3.5" />
                                    {Math.abs(entry.rank_change)}
                                </span>
                            {:else}
                                <span class="text-muted-foreground/40">
                                    <Minus class="size-3" />
                                </span>
                            {/if}
                        </div>

                        <!-- Player -->
                        <Link
                            href={boardUrl(entry.user_id)}
                            class="flex min-w-0 items-center gap-3"
                        >
                            <Avatar
                                class="size-9 border-2 {entry.rank === 1
                                    ? 'border-amber-400'
                                    : entry.rank === 2
                                      ? 'border-slate-300'
                                      : entry.rank === 3
                                        ? 'border-orange-400'
                                        : 'border-transparent'} bg-muted"
                            >
                                {#if entry.avatar}
                                    <AvatarImage
                                        src={entry.avatar}
                                        alt={entry.player_name}
                                        class="object-cover"
                                    />
                                {:else}
                                    <AvatarFallback class="text-xs font-semibold">
                                        {getInitials(entry.player_name)}
                                    </AvatarFallback>
                                {/if}
                            </Avatar>
                            <span class="truncate font-medium hover:underline">
                                {entry.player_name}
                            </span>
                        </Link>

                        <!-- Charts -->
                        <span
                            class="hidden text-right tabular-nums text-muted-foreground sm:block"
                        >
                            {numberFormatter.format(entry.ranked_song_count)}
                        </span>

                        <!-- Total score -->
                        <span
                            class="hidden text-right font-semibold tabular-nums sm:block"
                        >
                            {numberFormatter.format(entry.total_score)}
                        </span>

                        <!-- Crowns -->
                        <span
                            class="hidden text-right tabular-nums sm:block {entry
                                .crown_counts.dondaful > 0
                                ? 'font-medium text-fuchsia-500'
                                : 'text-muted-foreground/40'}"
                        >
                            {numberFormatter.format(entry.crown_counts.dondaful)}
                        </span>
                        <span
                            class="hidden text-right tabular-nums sm:block {entry
                                .crown_counts.gold > 0
                                ? 'font-medium text-amber-500'
                                : 'text-muted-foreground/40'}"
                        >
                            {numberFormatter.format(entry.crown_counts.gold)}
                        </span>
                        <span
                            class="hidden text-right tabular-nums sm:block {entry
                                .crown_counts.clear > 0
                                ? 'font-medium text-sky-500'
                                : 'text-muted-foreground/40'}"
                        >
                            {numberFormatter.format(entry.crown_counts.clear)}
                        </span>
                    </div>
                {/each}
            </div>
        </div>
    {/if}
</section>
