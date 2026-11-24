<script lang="ts">
    import { Form, Link } from '@inertiajs/svelte';
    import WaddamburoEventController from '@/actions/App/Http/Controllers/WaddamburoEventController';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { taikoRouteParam } from '@/lib/taiko-version';
    import katsuShop from '@/routes/katsu-shop';

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
        upcoming,
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
        songs: string[];
        upcoming: { name: string; starts_at: string }[];
        sheet: Sheet;
    } = $props();

    const ICON = 48;

    function spriteStyle(id: number): string {
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
    // The run ends at the start of ends_at's day: its last day is the one before.
    const lastDay = (iso: string) =>
        day(new Date(new Date(iso).getTime() - 86_400_000).toISOString());
</script>

<AppHead title="Events" />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <header
        class="overflow-hidden rounded-lg border bg-card text-card-foreground"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-5 p-5 sm:p-6"
        >
            <div class="max-w-2xl space-y-1">
                <p class="text-sm text-muted-foreground">
                    Event · {day(event.starts_at)} – {lastDay(event.ends_at)}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    {event.name}
                </h1>
            </div>
            {#if hasPlayer}
                <div
                    class="border-l-2 border-primary pl-4"
                    data-test="event-tickets"
                >
                    <p class="text-sm text-muted-foreground">Tickets</p>
                    <p class="text-2xl font-semibold tabular-nums">{tickets}</p>
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">
                    Link a card to earn and exchange tickets.
                </p>
            {/if}
        </div>
        <nav
            aria-label="Shop navigation"
            class="flex gap-5 border-t px-5 text-sm sm:px-6"
        >
            <span
                aria-current="page"
                class="border-b-2 border-primary py-3 font-semibold"
                >Events</span
            >
            <Link
                href={katsuShop.index(taikoRouteParam()).url}
                class="py-3 text-muted-foreground hover:text-foreground"
                >Katsu Shop</Link
            >
        </nav>
    </header>

    <p class="max-w-3xl text-sm text-muted-foreground">
        Green's collab campaigns come round in turn, two weeks each. While one
        runs, every Waddamburo song you play with your card from its event
        folder earns a ticket (normal play or AI battle). Exchange tickets for
        its puchi charas here; unspent tickets wait for the campaign's next run,
        and what you exchange stays yours. Exchanged puchi unlock in “Only what
        I earned”.
    </p>

    {#if puchi.length > 0}
        <section class="space-y-3">
            <h2 class="text-base font-semibold">
                Puchi charas <span class="text-sm text-muted-foreground"
                    >({puchi.length})</span
                >
            </h2>
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {#each puchi as item (item.id)}
                    <li
                        class="flex items-center gap-3 rounded-md border bg-background p-2"
                    >
                        <span
                            class="shrink-0"
                            style={spriteStyle(item.id)}
                            aria-hidden="true"
                        ></span>
                        <span
                            class="min-w-0 flex-1 truncate text-sm"
                            title={item.name}>{item.name}</span
                        >
                        {#if item.owned}
                            <span class="text-xs text-muted-foreground"
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
                            <span class="text-xs">{item.price}</span>
                        {/if}
                    </li>
                {/each}
            </ul>
        </section>
    {/if}

    {#if plays.length > 0}
        <section class="space-y-3">
            <h2 class="text-base font-semibold">Play to unlock</h2>
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {#each plays as item (item.id)}
                    <li
                        class="flex items-center gap-3 rounded-md border bg-background p-2"
                    >
                        <span
                            class="shrink-0"
                            style={spriteStyle(item.id)}
                            aria-hidden="true"
                        ></span>
                        <span class="min-w-0 flex-1 text-sm">
                            <span class="block truncate">{item.name}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >Play {item.song}</span
                            >
                        </span>
                        {#if item.owned}
                            <span class="text-xs text-muted-foreground"
                                >Owned</span
                            >
                        {/if}
                    </li>
                {/each}
            </ul>
        </section>
    {/if}

    <div class="grid gap-6 md:grid-cols-2">
        <section class="space-y-2">
            <h2 class="text-base font-semibold">
                Event songs <span class="text-sm text-muted-foreground"
                    >({songs.length})</span
                >
            </h2>
            <ul class="columns-1 text-sm sm:columns-2">
                {#each songs as song, index (index)}
                    <li class="truncate">{song}</li>
                {/each}
            </ul>
        </section>
        <section class="space-y-2">
            <h2 class="text-base font-semibold">Coming next</h2>
            <ul class="space-y-1 text-sm">
                {#each upcoming as next (next.starts_at)}
                    <li class="flex justify-between gap-4">
                        <span class="truncate">{next.name}</span>
                        <span
                            class="shrink-0 tabular-nums text-muted-foreground"
                            >{day(next.starts_at)}</span
                        >
                    </li>
                {/each}
            </ul>
        </section>
    </div>
</section>
