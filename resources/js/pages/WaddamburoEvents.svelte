<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import WaddamburoEventController from '@/actions/App/Http/Controllers/WaddamburoEventController';
    import AppHead from '@/components/AppHead.svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import SideModeAccordion from '@/components/SideModeAccordion.svelte';
    import SongCard from '@/components/SongCard.svelte';
    import type { SongCardEntry } from '@/components/SongCard.svelte';
    import { Button } from '@/components/ui/button';
    import { Progress } from '@/components/ui/progress';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import { toUrl } from '@/lib/utils';
    import songRoutes from '@/routes/songs';

    type Puchi = { id: number; name: string; price: number; owned: boolean };
    type Play = { id: number; name: string; song: string; owned: boolean };
    type SpriteItem = { id: number; x: number; y: number };
    type Sheet = {
        url: string;
        cell: number;
        width: number;
        height: number;
        slots: Record<string, SpriteItem[]>;
    } | null;

    let {
        hasPlayer,
        event,
        tickets,
        puchi,
        plays,
        songs,
        sheet,
    }: {
        hasPlayer: boolean;
        event: {
            key: string;
            name: string;
            starts_at: string;
            ends_at: string;
        };
        tickets: number;
        puchi: Puchi[];
        plays: Play[];
        songs: SongCardEntry[];
        sheet: Sheet;
    } = $props();

    const ICON = 72;

    function puchiSpriteStyle(id: number): string {
        const item = sheet?.slots['puchi']?.find((entry) => entry.id === id);

        if (!sheet || !item) {
            return `width:${ICON}px;height:${ICON}px;`;
        }

        const scale = ICON / sheet.cell;

        return `width:${ICON}px;height:${ICON}px;background-image:url(${sheet.url});background-size:${Math.round(sheet.width * scale)}px ${Math.round(sheet.height * scale)}px;background-position:-${Math.round(item.x * scale)}px -${Math.round(item.y * scale)}px;background-repeat:no-repeat;image-rendering:pixelated;`;
    }

    const day = (iso: string) =>
        new Date(iso).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        });
    // A run ends at the start of ends_at's day: its last day is the one before.
    const lastDay = (iso: string) =>
        day(new Date(new Date(iso).getTime() - 86_400_000).toISOString());
    // The run's progress in the visitor's own time zone, refreshed every minute.
    let now = $state(Date.now());
    $effect(() => {
        const timer = setInterval(() => (now = Date.now()), 60_000);

        return () => clearInterval(timer);
    });
    const startsAt = $derived(new Date(event.starts_at).getTime());
    const endsAt = $derived(new Date(event.ends_at).getTime());
    const progress = $derived(
        Math.min(1, Math.max(0, (now - startsAt) / (endsAt - startsAt))),
    );
    const endLabel = $derived(
        new Date(endsAt).toLocaleString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }),
    );
    const left = $derived.by(() => {
        const minutes = Math.max(0, Math.round((endsAt - now) / 60_000));
        const days = Math.floor(minutes / 1440);
        const hours = Math.floor((minutes % 1440) / 60);

        return days > 0
            ? `${days} day${days === 1 ? '' : 's'} ${hours} h left`
            : hours > 0
              ? `${hours} h ${minutes % 60} min left`
              : `${minutes % 60} min left`;
    });
    const owned = $derived(
        [...puchi, ...plays].filter((item) => item.owned).length,
    );
</script>

<AppHead title="Events" />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <header
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6"
        >
            <div class="max-w-2xl">
                <h1 class="text-3xl font-semibold tracking-tight">Events</h1>
            </div>
            {#if hasPlayer}
                <div
                    class="border-l-2 border-primary pl-4"
                    data-test="event-tickets"
                >
                    <p class="text-sm text-muted-foreground">Tickets</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {tickets.toLocaleString()}
                    </p>
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">
                    Link a card to earn and exchange tickets.
                </p>
            {/if}
        </div>
    </header>

    <p class="max-w-3xl text-sm text-muted-foreground">
        Green's collab campaigns come round in turn, two weeks each. While one
        runs, every Waddamburo song you play with your card from its event
        folder earns a ticket (normal play or AI battle). Exchange tickets for
        its puchi charas; unspent tickets wait for the campaign's next run, and
        what you exchange stays yours. Exchanged puchi unlock in “Only what I
        earned”.
    </p>

    <div class="grid gap-3">
        <SideModeAccordion
            class="rounded-lg"
            open
            title={event.name}
            description={`Now until ${lastDay(event.ends_at)} · ${songs.length} songs`}
        >
            {#snippet icon()}
                <AppLogoIcon class="size-7" />
            {/snippet}
            {#snippet overview()}
                {#if hasPlayer && puchi.length + plays.length > 0}
                    <span class="text-sm tabular-nums text-muted-foreground"
                        >{owned} of {puchi.length + plays.length} owned</span
                    >
                {/if}
            {/snippet}
            <div class="space-y-6 p-4 sm:p-5">
                <div class="space-y-1.5">
                    <Progress
                        class="h-2"
                        value={Math.round(progress * 100)}
                        aria-label="Event progress"
                    />
                    <div
                        class="flex flex-wrap justify-between gap-x-4 text-xs text-muted-foreground"
                    >
                        <span>Ends {endLabel} (your time)</span>
                        <span class="tabular-nums">{left}</span>
                    </div>
                </div>
                {#if puchi.length > 0}
                    <section class="space-y-3">
                        <h3 class="text-base font-semibold">
                            Puchi charas
                            <span class="text-sm text-muted-foreground"
                                >({puchi.length})</span
                            >
                        </h3>
                        <ul
                            class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            {#each puchi as item (item.id)}
                                <li
                                    class="flex min-w-0 items-center gap-3 rounded-md border bg-background p-2"
                                >
                                    <span
                                        class="shrink-0"
                                        style={puchiSpriteStyle(item.id)}
                                        aria-hidden="true"
                                    ></span>
                                    <span
                                        class="min-w-0 flex-1 truncate text-sm"
                                        title={item.name}>{item.name}</span
                                    >
                                    {#if item.owned}
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Owned</span
                                        >
                                    {:else if hasPlayer}
                                        <Form
                                            {...WaddamburoEventController.purchase.form(
                                                taikoRouteParam(),
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {#snippet children({ processing })}
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value={item.id}
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={processing ||
                                                        tickets < item.price}
                                                    >{item.price}</Button
                                                >
                                            {/snippet}
                                        </Form>
                                    {:else}
                                        <span class="text-xs">{item.price}</span
                                        >
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}

                {#if plays.length > 0}
                    <section class="space-y-3">
                        <h3 class="text-base font-semibold">
                            Play to unlock
                            <span class="text-sm text-muted-foreground"
                                >({plays.length})</span
                            >
                        </h3>
                        <ul
                            class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            {#each plays as item (item.id)}
                                <li
                                    class="flex min-w-0 items-center gap-3 rounded-md border bg-background p-2"
                                >
                                    <span
                                        class="shrink-0"
                                        style={puchiSpriteStyle(item.id)}
                                        aria-hidden="true"
                                    ></span>
                                    <span class="min-w-0 flex-1 text-sm">
                                        <span class="block truncate"
                                            >{item.name}</span
                                        >
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                            >Play {item.song}</span
                                        >
                                    </span>
                                    {#if item.owned}
                                        <span
                                            class="text-xs text-muted-foreground"
                                            >Owned</span
                                        >
                                    {/if}
                                </li>
                            {/each}
                        </ul>
                    </section>
                {/if}

                <section class="space-y-3">
                    <h3 class="text-base font-semibold">
                        Songs <span class="text-sm text-muted-foreground"
                            >({songs.length})</span
                        >
                    </h3>
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {#each songs as song (song.id)}
                            <SongCard
                                {song}
                                href={toUrl(
                                    songRoutes.show({
                                        ...taikoRouteParam(),
                                        song: song.id,
                                    }),
                                )}
                            />
                        {/each}
                    </div>
                </section>
            </div>
        </SideModeAccordion>
    </div>
</section>
