<script lang="ts">
    import AppHead from '@/components/AppHead.svelte';

    type RankingEntry = {
        rank: number;
        baid: number;
        player_name: string;
        score: number;
        score_rank: number;
    };

    type RankingVersion = {
        game_version: string;
        song_no: number;
        level: number;
        entries: RankingEntry[];
    };

    type SongGroup = {
        title: string;
        versions: RankingVersion[];
    };

    let {
        songGroups,
    }: {
        songGroups: SongGroup[];
    } = $props();
</script>

<AppHead title="Rankings" />

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-8">
    <div>
        <h1 class="text-2xl font-semibold">Rankings</h1>
    </div>

    {#if songGroups.length === 0}
        <div class="rounded-md border p-6 text-sm text-muted-foreground">
            No ranked plays yet.
        </div>
    {:else}
        <div class="flex flex-col gap-4">
            {#each songGroups as song (song.title)}
                <section class="rounded-md border">
                    <div class="border-b px-4 py-3">
                        <h2 class="text-base font-semibold">{song.title}</h2>
                    </div>

                    <div class="divide-y">
                        {#each song.versions as version (`${song.title}-${version.game_version}-${version.song_no}-${version.level}`)}
                            <div
                                class="grid gap-3 p-4 lg:grid-cols-[13rem_1fr]"
                            >
                                <div class="text-sm">
                                    <div class="font-medium">
                                        {version.game_version}
                                    </div>
                                    <div class="text-muted-foreground">
                                        Song #{version.song_no} · Lv {version.level}
                                    </div>
                                </div>

                                <div class="overflow-hidden rounded-md border">
                                    <table class="w-full text-sm">
                                        <thead class="bg-muted/50 text-left">
                                            <tr>
                                                <th
                                                    class="w-16 px-3 py-2 font-medium"
                                                    >#</th
                                                >
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                    >Player</th
                                                >
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                    >Score</th
                                                >
                                                <th
                                                    class="px-3 py-2 font-medium"
                                                    >Rank</th
                                                >
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {#each version.entries as entry (entry.rank)}
                                                <tr class="border-t">
                                                    <td class="px-3 py-2"
                                                        >{entry.rank}</td
                                                    >
                                                    <td class="px-3 py-2"
                                                        >{entry.player_name}</td
                                                    >
                                                    <td
                                                        class="px-3 py-2 tabular-nums"
                                                        >{entry.score}</td
                                                    >
                                                    <td class="px-3 py-2"
                                                        >{entry.score_rank}</td
                                                    >
                                                </tr>
                                            {/each}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        {/each}
                    </div>
                </section>
            {/each}
        </div>
    {/if}
</section>
