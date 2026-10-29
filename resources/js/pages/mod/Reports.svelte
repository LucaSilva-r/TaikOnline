<script module lang="ts">
    import reportsForLayout from '@/routes/mod/reports';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Reports',
                href: reportsForLayout.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button, buttonVariants } from '@/components/ui/button';
    import { toUrl } from '@/lib/utils';
    import moderationPlayers from '@/routes/mod/players';
    import moderationPlays from '@/routes/mod/plays';
    import { show as playsShow } from '@/routes/plays';
    import { taikoRouteParam } from '@/lib/taiko-version';

    type ReportedPlay = {
        id: string;
        chart: string;
        difficulty: string | null;
        course: number;
        score: number;
        played_at: string | null;
        hidden_at: string | null;
        player: { baid: number; mydon_name: string | null; user: { name: string } | null } | null;
        reports: {
            id: number;
            reason: string;
            comment: string | null;
            created_at: string | null;
            reporter: { name: string; baid: number | null; filed: number; dismissed: number };
        }[];
    };

    let { plays }: { plays: ReportedPlay[] } = $props();

    const taikoParam = taikoRouteParam();
    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
    const numberFormatter = new Intl.NumberFormat('en-US');
    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function date(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }

    function playHref(id: string): string {
        // Play pages live in the Waddamburo scope.
        return toUrl(playsShow({ taikoVersion: 'waddamburo', id }));
    }

    function playerHref(baid: number): string {
        return toUrl(moderationPlayers.show({ ...taikoParam, player: baid }));
    }

    function dismiss(play: ReportedPlay) {
        const reason = prompt(
            `Dismiss the ${play.reports.length} report(s) on this play? Reason (optional):`,
        );
        if (reason !== null) {
            router.patch(
                moderationPlays.dismissReports({ ...taikoParam, play: play.id }).url,
                { reason },
                { preserveScroll: true },
            );
        }
    }
</script>

<AppHead title="Reports" />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Reports</h1>
        <p class="text-sm text-muted-foreground">
            Plays players reported, the most reported first. Open the play to
            watch it and check its timeline, then dismiss the reports, hide
            the play or sanction the player (both close them). Act in batches,
            not right after a report. A reporter who keeps filing reports that
            get dismissed can be blocked from reporting on their player page.
        </p>
    </div>

    {#each plays as play (play.id)}
        <section class="flex flex-col gap-3 rounded-md border p-4">
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="secondary" class="border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                    >{play.reports.length} report{play.reports.length === 1 ? '' : 's'}</Badge
                >
                <span class="font-medium">
                    {play.player?.user?.name ?? play.player?.mydon_name ?? 'Unknown player'}
                </span>
                <span class="text-sm text-muted-foreground">
                    {play.chart} · {play.difficulty ?? courses[play.course] ?? ''} ·
                    {numberFormatter.format(play.score)} · {date(play.played_at)}
                </span>
                {#if play.hidden_at}
                    <Badge variant="secondary">hidden</Badge>
                {/if}
                <div class="ml-auto flex flex-wrap gap-2">
                    <Link
                        href={playHref(play.id)}
                        class={buttonVariants({ size: 'sm' })}>Watch &amp; inspect</Link
                    >
                    {#if play.player}
                        <Link
                            href={playerHref(play.player.baid)}
                            class={buttonVariants({ variant: 'outline', size: 'sm' })}
                            >Player</Link
                        >
                    {/if}
                    <Button size="sm" variant="outline" onclick={() => dismiss(play)}
                        >Dismiss</Button
                    >
                </div>
            </div>
            <ul class="flex flex-col gap-1.5 text-sm">
                {#each play.reports as report (report.id)}
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{report.reason}</span>
                        {#if report.comment}
                            <span class="break-words">“{report.comment}”</span>
                        {/if}
                        <span class="text-xs text-muted-foreground">
                            by
                            {#if report.reporter.baid !== null}
                                <Link href={playerHref(report.reporter.baid)} class="underline"
                                    >{report.reporter.name}</Link
                                >
                            {:else}
                                {report.reporter.name}
                            {/if}
                            ({report.reporter.filed} filed, {report.reporter.dismissed}
                            dismissed) · {date(report.created_at)}
                        </span>
                    </li>
                {/each}
            </ul>
        </section>
    {:else}
        <p class="rounded-md border p-6 text-center text-sm text-muted-foreground">
            No open reports.
        </p>
    {/each}
</div>
