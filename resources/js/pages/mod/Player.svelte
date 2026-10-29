<script module lang="ts">
    import moderationForLayout from '@/routes/mod';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Moderation',
                href: moderationForLayout.index(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form, Link, router } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import moderationPlayers from '@/routes/mod/players';
    import moderationPlays from '@/routes/mod/plays';
    import moderationSanctions from '@/routes/mod/sanctions';
    import { show as playsShow } from '@/routes/plays';
    import { toUrl } from '@/lib/utils';

    type Sanction = {
        id: number;
        type: string;
        reason: string;
        note_to_player: string | null;
        expires_at: string | null;
        created_at: string | null;
        created_by: string | null;
        revoked_at: string | null;
        revoked_by: string | null;
        active: boolean;
    };
    // Roll speeds worth a look (rollLimits, WdbPlay's): one pad's scored hits, which a hand alternating with
    // the other keeps near 10 a second, keyboard mashers too; and one pad drummed with even gaps, which
    // players manage only at a hand's pace while a macro is even at any speed.
    const flagged = (rate: number | null, limit: number): string =>
        rate !== null && rate >= limit
            ? 'font-medium text-amber-600 dark:text-amber-400'
            : '';

    type Play = {
        id: string;
        chart: string;
        difficulty: string | null;
        course: number;
        score: number;
        great: number;
        good: number;
        miss: number;
        rolls: number;
        roll_max: number | null;
        roll_hits: number | null;
        roll_peak_rate: number | null;
        roll_pad_peak_rate: number | null;
        roll_steady_rate: number | null;
        options: string[];
        played_at: string | null;
        hidden_at: string | null;
        hidden_reason: string | null;
        open_reports: number;
    };
    type PlayFilters = {
        q: string;
        show: 'all' | 'hidden' | 'reported' | 'flagged';
        sort: 'newest' | 'oldest' | 'score' | 'pad' | 'steady';
        page?: number;
    };
    type LogRow = {
        id: number;
        actor: string | null;
        action: string;
        wdb_play_id: string | null;
        play: {
            chart: string;
            difficulty: string | null;
            score: number;
        } | null;
        reason: string | null;
        created_at: string | null;
    };

    let {
        player,
        canAct,
        canBanPermanently,
        sanctionTypes,
        sanctions,
        plays,
        playFilters,
        rollLimits,
        log,
    }: {
        player: {
            baid: number;
            mydon_name: string | null;
            user: {
                id: number;
                name: string;
                username: string | null;
                role: string;
            } | null;
        };
        canAct: boolean;
        canBanPermanently: boolean;
        sanctionTypes: { value: string; label: string }[];
        sanctions: Sanction[];
        plays: {
            data: Play[];
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
        playFilters: PlayFilters;
        rollLimits: { pad: number; steady: number };
        log: LogRow[];
    } = $props();

    // Every visit carries all the filters (the pager too), so paging never drops one.
    function visitPlays(changes: Partial<PlayFilters>): void {
        const query = Object.fromEntries(
            Object.entries({
                ...playFilters,
                page: undefined,
                ...changes,
            }).filter(
                ([, value]) =>
                    value !== null &&
                    value !== undefined &&
                    value !== '' &&
                    value !== 'all' &&
                    value !== 'newest',
            ),
        );
        router.get(
            moderationPlayers.show({
                ...taikoRouteParam(),
                player: player.baid,
            }).url,
            query,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['plays', 'playFilters'],
            },
        );
    }

    const taikoParam = taikoRouteParam();
    const courses = ['Easy', 'Normal', 'Hard', 'Oni', 'Ura'];
    const durations = [
        { hours: '24', label: '1 day' },
        { hours: '72', label: '3 days' },
        { hours: '168', label: '1 week' },
        { hours: '720', label: '30 days' },
        { hours: '2160', label: '90 days' },
        { hours: '8760', label: '1 year' },
    ];
    let sanctionType = $state('shadowban');
    const permanentAllowed = $derived(
        canBanPermanently || sanctionType !== 'ban',
    );
    const name = $derived(
        player.user?.name ?? player.mydon_name ?? 'Unregistered',
    );

    const dateFormatter = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    function date(value: string | null): string {
        return value ? dateFormatter.format(new Date(value)) : '—';
    }

    function revoke(sanction: Sanction) {
        const reason = prompt(
            `Revoke this ${sanction.type}? Reason (optional):`,
        );
        if (reason === null) {
            return;
        }
        router.patch(
            moderationSanctions.revoke({
                ...taikoParam,
                sanction: sanction.id,
            }).url,
            { reason },
            { preserveScroll: true },
        );
    }

    function hide(play: Play) {
        const reason = prompt('Hide this play from every board. Reason:');
        if (!reason) {
            return;
        }
        router.patch(
            moderationPlays.hide({ ...taikoParam, play: play.id }).url,
            { reason },
            { preserveScroll: true },
        );
    }

    function restore(play: Play) {
        const reason = prompt('Restore this play? Reason (optional):');
        if (reason === null) {
            return;
        }
        router.patch(
            moderationPlays.restore({ ...taikoParam, play: play.id }).url,
            { reason },
            { preserveScroll: true },
        );
    }
</script>

<AppHead title={`${name} · Moderation`} />

<div class="flex flex-1 flex-col gap-6 p-4">
    <div>
        <h1 class="flex flex-wrap items-center gap-2 text-xl font-semibold">
            {name}
            {#each sanctions.filter((sanction) => sanction.active) as sanction (sanction.id)}
                <Badge
                    variant="secondary"
                    class="border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                    >{sanction.type}</Badge
                >
            {/each}
        </h1>
        <p class="text-sm text-muted-foreground">
            BAID {player.baid} · Don-chan {player.mydon_name ?? '—'}
            {#if player.user}
                · @{player.user.username ?? '—'} · {player.user.role}
            {:else}
                · no web account
            {/if}
        </p>
        {#if !canAct}
            <p class="mt-1 text-sm text-muted-foreground">
                You cannot act on this player (staff are sanctioned by an admin
                only, and nobody acts on themselves).
            </p>
        {/if}
    </div>

    {#if canAct}
        <Form
            {...moderationSanctions.store.form({
                ...taikoParam,
                player: player.baid,
            })}
            class="flex max-w-2xl flex-col gap-3 rounded-md border p-4"
            options={{ preserveScroll: true }}
            resetOnSuccess
        >
            {#snippet children({ errors, processing })}
                <h2 class="font-medium">New sanction</h2>
                <div class="flex flex-wrap gap-3">
                    <div class="flex flex-col gap-1.5">
                        <Label for="sanction-type">Type</Label>
                        <select
                            id="sanction-type"
                            name="type"
                            bind:value={sanctionType}
                            class="h-9 rounded-md border bg-background px-2 text-sm"
                        >
                            {#each sanctionTypes as type (type.value)}
                                <option value={type.value}>{type.label}</option>
                            {/each}
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="sanction-duration">Duration</Label>
                        <select
                            id="sanction-duration"
                            name="duration_hours"
                            class="h-9 rounded-md border bg-background px-2 text-sm"
                        >
                            {#each durations as duration (duration.hours)}
                                <option value={duration.hours}
                                    >{duration.label}</option
                                >
                            {/each}
                            {#if permanentAllowed}
                                <option value="">Permanent</option>
                            {/if}
                        </select>
                        <InputError message={errors.duration_hours} />
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="sanction-reason">Reason (staff only)</Label>
                    <textarea
                        id="sanction-reason"
                        name="reason"
                        required
                        maxlength={2000}
                        rows={2}
                        class="rounded-md border bg-background px-2 py-1 text-sm"
                    ></textarea>
                    <InputError message={errors.reason} />
                </div>
                {#if sanctionType !== 'shadowban'}
                    <div class="flex flex-col gap-1.5">
                        <Label for="sanction-note">Note to the player</Label>
                        <textarea
                            id="sanction-note"
                            name="note_to_player"
                            maxlength={2000}
                            rows={2}
                            class="rounded-md border bg-background px-2 py-1 text-sm"
                        ></textarea>
                        <InputError message={errors.note_to_player} />
                    </div>
                {/if}
                <div>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing}>Issue sanction</Button
                    >
                </div>
            {/snippet}
        </Form>
    {/if}

    <section class="flex flex-col gap-2">
        <h2 class="font-medium">Sanctions</h2>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Reason</th>
                        <th class="px-3 py-2 font-medium">Issued</th>
                        <th class="px-3 py-2 font-medium">Expires</th>
                        <th class="px-3 py-2 font-medium">Revoked</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    {#each sanctions as sanction (sanction.id)}
                        <tr
                            class="border-t {sanction.active
                                ? ''
                                : 'text-muted-foreground'}"
                        >
                            <td class="px-3 py-2">{sanction.type}</td>
                            <td class="max-w-md px-3 py-2 break-words">
                                {sanction.reason}
                                {#if sanction.note_to_player}
                                    <div class="text-xs text-muted-foreground">
                                        To player: {sanction.note_to_player}
                                    </div>
                                {/if}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{date(sanction.created_at)} · {sanction.created_by ??
                                    '—'}</td
                            >
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{sanction.expires_at
                                    ? date(sanction.expires_at)
                                    : 'Never'}</td
                            >
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{sanction.revoked_at
                                    ? `${date(sanction.revoked_at)} · ${sanction.revoked_by ?? '—'}`
                                    : '—'}</td
                            >
                            <td class="px-3 py-2 text-right">
                                {#if canAct && sanction.active}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onclick={() => revoke(sanction)}
                                        >Revoke</Button
                                    >
                                {/if}
                            </td>
                        </tr>
                    {:else}
                        <tr
                            ><td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                                >No sanctions.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
    </section>

    <section class="flex flex-col gap-2">
        <h2 class="font-medium">Waddamburo plays</h2>
        <form
            class="flex flex-wrap gap-2"
            onsubmit={(event) => {
                event.preventDefault();
                visitPlays({
                    q: String(new FormData(event.currentTarget).get('q') ?? ''),
                });
            }}
        >
            <Input
                name="q"
                value={playFilters.q}
                placeholder="Chart"
                class="max-w-xs"
            />
            <select
                class="h-9 rounded-md border bg-background px-3 text-sm"
                value={playFilters.show}
                aria-label="Show"
                onchange={(event) =>
                    visitPlays({
                        show: event.currentTarget.value as PlayFilters['show'],
                    })}
            >
                <option value="all">All plays</option>
                <option value="flagged">Flagged rolls</option>
                <option value="reported">Reported</option>
                <option value="hidden">Hidden</option>
            </select>
            <select
                class="h-9 rounded-md border bg-background px-3 text-sm"
                value={playFilters.sort}
                aria-label="Sort"
                onchange={(event) =>
                    visitPlays({
                        sort: event.currentTarget.value as PlayFilters['sort'],
                    })}
            >
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="score">Highest score</option>
                <option value="pad">Fastest on one pad</option>
                <option value="steady">Fastest steady</option>
            </select>
            <Button type="submit" variant="secondary">Search</Button>
        </form>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Played</th>
                        <th class="px-3 py-2 font-medium">Chart</th>
                        <th class="px-3 py-2 font-medium">Score</th>
                        <th class="px-3 py-2 font-medium"
                            >Great / Good / Miss</th
                        >
                        <th
                            class="px-3 py-2 font-medium"
                            title="Roll hits counted / the chart's most (at 60 a second); below, the fastest sustained drumming on one roll or balloon from every raw hit, then the same on a single pad (hits that scored), then the fastest one pad was drummed with even gaps (a macro's mark)"
                            >Rolls</th
                        >
                        <th class="px-3 py-2 font-medium">Options</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    {#each plays.data as play (play.id)}
                        <tr
                            class="border-t {play.hidden_at
                                ? 'text-muted-foreground'
                                : ''}"
                        >
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{date(play.played_at)}</td
                            >
                            <td class="px-3 py-2">
                                <Link
                                    href={toUrl(
                                        playsShow({
                                            taikoVersion: 'waddamburo',
                                            id: play.id,
                                        }),
                                    )}
                                    class="underline">{play.chart}</Link
                                >
                                {#if play.open_reports > 0}
                                    <Badge
                                        variant="secondary"
                                        class="border-0 bg-red-500/15 text-red-600 dark:text-red-400"
                                        >{play.open_reports} report{play.open_reports ===
                                        1
                                            ? ''
                                            : 's'}</Badge
                                    >
                                {/if}
                                <span class="text-xs text-muted-foreground"
                                    >{play.difficulty ??
                                        courses[play.course] ??
                                        ''}</span
                                >
                                {#if play.hidden_at}
                                    <div class="text-xs">
                                        Hidden {date(play.hidden_at)}: {play.hidden_reason}
                                    </div>
                                {/if}
                            </td>
                            <td class="px-3 py-2 tabular-nums">{play.score}</td>
                            <td class="px-3 py-2 tabular-nums"
                                >{play.great} / {play.good} / {play.miss}</td
                            >
                            <td
                                class="px-3 py-2 whitespace-nowrap tabular-nums"
                            >
                                {play.rolls} / {play.roll_max ?? '?'}
                                {#if play.roll_peak_rate != null}
                                    <div class="text-xs text-muted-foreground">
                                        {play.roll_peak_rate}/s ·
                                        <span
                                            class={flagged(
                                                play.roll_pad_peak_rate,
                                                rollLimits.pad,
                                            )}
                                            >{play.roll_pad_peak_rate ?? '—'}/s
                                            per pad</span
                                        >
                                        {#if play.roll_steady_rate != null}
                                            ·
                                            <span
                                                class={flagged(
                                                    play.roll_steady_rate,
                                                    rollLimits.steady,
                                                )}
                                                >{play.roll_steady_rate}/s
                                                steady</span
                                            >
                                        {/if}
                                    </div>
                                {/if}
                            </td>
                            <td class="px-3 py-2">{play.options.join(', ')}</td>
                            <td class="px-3 py-2 text-right">
                                {#if canAct}
                                    {#if play.hidden_at}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onclick={() => restore(play)}
                                            >Restore</Button
                                        >
                                    {:else}
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onclick={() => hide(play)}
                                            >Hide</Button
                                        >
                                    {/if}
                                {/if}
                            </td>
                        </tr>
                    {:else}
                        <tr
                            ><td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                                >No plays.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
        <div class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">
                {#if plays.total > 0}Showing {plays.from} to {plays.to} of {plays.total}
                    plays{/if}
            </p>
            {#if plays.last_page > 1}
                <div class="flex items-center gap-2">
                    {#if plays.current_page > 1}<Button
                            variant="outline"
                            size="sm"
                            onclick={() =>
                                visitPlays({ page: plays.current_page - 1 })}
                            >Previous</Button
                        >{/if}
                    <span class="text-sm text-muted-foreground"
                        >{plays.current_page} / {plays.last_page}</span
                    >
                    {#if plays.current_page < plays.last_page}<Button
                            variant="outline"
                            size="sm"
                            onclick={() =>
                                visitPlays({ page: plays.current_page + 1 })}
                            >Next</Button
                        >{/if}
                </div>
            {/if}
        </div>
    </section>

    <section class="flex flex-col gap-2">
        <h2 class="font-medium">History</h2>
        <div class="overflow-x-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">Who</th>
                        <th class="px-3 py-2 font-medium">Action</th>
                        <th class="px-3 py-2 font-medium">Reason</th>
                    </tr>
                </thead>
                <tbody>
                    {#each log as entry (entry.id)}
                        <tr class="border-t">
                            <td class="px-3 py-2 whitespace-nowrap"
                                >{date(entry.created_at)}</td
                            >
                            <td class="px-3 py-2">{entry.actor ?? '—'}</td>
                            <td class="px-3 py-2">
                                {entry.action}
                                {#if entry.play && entry.wdb_play_id}
                                    <div class="text-xs">
                                        <Link
                                            href={toUrl(
                                                playsShow({
                                                    taikoVersion: 'waddamburo',
                                                    id: entry.wdb_play_id,
                                                }),
                                            )}
                                            class="underline"
                                            >{entry.play.chart}</Link
                                        >
                                        <span class="text-muted-foreground"
                                            >{entry.play.difficulty ?? ''} · {entry
                                                .play.score}</span
                                        >
                                    </div>
                                {/if}
                            </td>
                            <td class="max-w-md px-3 py-2 break-words"
                                >{entry.reason ?? ''}</td
                            >
                        </tr>
                    {:else}
                        <tr
                            ><td
                                colspan="4"
                                class="px-3 py-6 text-center text-muted-foreground"
                                >Nothing logged yet.</td
                            ></tr
                        >
                    {/each}
                </tbody>
            </table>
        </div>
    </section>
</div>
