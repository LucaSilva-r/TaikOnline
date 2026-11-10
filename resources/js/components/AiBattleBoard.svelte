<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import Bot from 'lucide-svelte/icons/bot';
    import Check from 'lucide-svelte/icons/check';
    import ChevronRight from 'lucide-svelte/icons/chevron-right';
    import X from 'lucide-svelte/icons/x';
    import SideModeAccordion from '@/components/SideModeAccordion.svelte';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import { show as playsShow } from '@/routes/plays';

    export type AiBattleBoardData = {
        progress: {
            rank: number;
            points: number;
            wins: number;
            points_to_next: number;
        };
        recent: {
            play_id: string;
            title: string;
            course: number;
            stars: number | null;
            score: number;
            cleared: boolean;
            played_at: string;
            options: string[];
            verified: boolean;
            counted: boolean;
            won: boolean | null;
            sections: string;
            points: number;
            support_level: number | null;
        }[];
    };

    let { data }: { data: AiBattleBoardData } = $props();
    const taikoParam = taikoRouteParam();
    const number = new Intl.NumberFormat();
    const date = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
</script>

<SideModeAccordion
    title="AI Battle"
    description="Rank progress and recent battles"
    class="border-cyan-500/25 text-cyan-700 dark:text-cyan-300"
>
    {#snippet icon()}<Bot class="size-6" />{/snippet}
    {#snippet overview()}
        <span
            class="rounded-lg border border-cyan-500/20 bg-cyan-500/10 px-2.5 py-1.5 text-sm font-semibold tabular-nums"
            >Rank {data.progress.rank}</span
        >
    {/snippet}
    <div
        class="flex flex-wrap items-center gap-x-8 gap-y-4 bg-gradient-to-r from-cyan-500/10 via-sky-500/5 to-card px-5 py-5"
    >
        <div class="flex items-center gap-3">
            <span
                class="flex size-14 items-center justify-center rounded-xl border border-cyan-500/25 bg-background/70 text-2xl font-bold tabular-nums text-cyan-700 dark:text-cyan-300"
                >{data.progress.rank}</span
            >
            <div>
                <p class="font-semibold">AI Battle rank</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {#if data.progress.rank >= 10}
                        Rank 10 reached
                    {:else}
                        {number.format(data.progress.points_to_next)} points to rank
                        {data.progress.rank + 1}
                    {/if}
                </p>
            </div>
        </div>
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Rank points</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">
                    {number.format(data.progress.points)}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Battle wins</dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums">
                    {number.format(data.progress.wins)}
                </dd>
            </div>
        </dl>
    </div>
    <div class="flex items-center justify-between gap-3 border-y px-5 py-3">
        <h3 class="text-sm font-semibold">Recent battles</h3>
        <span class="text-xs text-muted-foreground">Latest 20</span>
    </div>
    {#if data.recent.length === 0}
        <p class="px-5 py-8 text-center text-sm text-muted-foreground">
            No AI battles to show yet.
        </p>
    {:else}
        <ul class="divide-y">
            {#each data.recent as play (play.play_id)}
                <li>
                    <Link
                        href={toUrl(
                            playsShow({ ...taikoParam, id: play.play_id }),
                        )}
                        class="group/play block px-4 py-4 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:px-5"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span
                                        class="font-semibold group-hover/play:text-primary"
                                        >{play.title}</span
                                    >
                                    <span
                                        class="rounded border bg-muted/40 px-1.5 py-0.5 text-xs text-muted-foreground"
                                        >{courses[play.course]}{play.stars !==
                                        null
                                            ? ` ★${play.stars}`
                                            : ''}</span
                                    >
                                    {#if play.support_level}<span
                                            class="text-xs text-muted-foreground"
                                            >Support {play.support_level}</span
                                        >{/if}
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <time datetime={play.played_at}
                                        >{date.format(
                                            new Date(play.played_at),
                                        )}</time
                                    >
                                    <span
                                        >· {number.format(play.score)} score</span
                                    >
                                    <span
                                        >· {play.cleared
                                            ? 'Gauge cleared'
                                            : 'Gauge failed'}</span
                                    >
                                    {#each play.options as option (option)}<span
                                            class="rounded bg-muted px-1.5"
                                            >{option}</span
                                        >{/each}
                                </div>
                            </div>
                            <ChevronRight
                                class="mt-1 size-4 shrink-0 text-muted-foreground transition-transform group-hover/play:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </div>
                        <div
                            class="mt-3 flex flex-wrap items-center justify-between gap-3"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                {#if play.verified}
                                    <span class="text-xs text-muted-foreground"
                                        >Sections</span
                                    >
                                    <ol
                                        class="flex flex-wrap gap-1.5"
                                        aria-label="Section results"
                                    >
                                        {#each play.sections.split('') as section, index (index)}
                                            <li
                                                class="flex items-center gap-1 rounded-md border px-2 py-1 text-xs font-semibold tabular-nums {section ===
                                                'W'
                                                    ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                    : 'border-rose-500/25 bg-rose-500/10 text-rose-700 dark:text-rose-300'}"
                                                aria-label={`Section ${index + 1}: ${section === 'W' ? 'won' : 'lost'}`}
                                                title={`Section ${index + 1}: ${section === 'W' ? 'won' : 'lost'}`}
                                            >
                                                {index + 1}
                                                {#if section === 'W'}<Check
                                                        class="size-3.5"
                                                        aria-hidden="true"
                                                    />{:else}<X
                                                        class="size-3.5"
                                                        aria-hidden="true"
                                                    />{/if}
                                            </li>
                                        {/each}
                                    </ol>
                                {:else}
                                    <span class="text-xs text-muted-foreground"
                                        >No verified section results</span
                                    >
                                {/if}
                            </div>
                            <span
                                class="flex flex-wrap items-center gap-2 text-xs"
                            >
                                {#if play.verified}
                                    <span
                                        class="font-semibold {play.won
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : 'text-rose-700 dark:text-rose-300'}"
                                        >{play.won
                                            ? 'Battle won'
                                            : 'Battle lost'}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >{play.counted
                                            ? `+${play.points} pts`
                                            : 'Unranked'}</span
                                    >
                                {:else}
                                    <span
                                        class="rounded border bg-muted/40 px-2 py-1 text-muted-foreground"
                                        >Unverified</span
                                    >
                                {/if}
                            </span>
                        </div>
                    </Link>
                </li>
            {/each}
        </ul>
    {/if}
</SideModeAccordion>
