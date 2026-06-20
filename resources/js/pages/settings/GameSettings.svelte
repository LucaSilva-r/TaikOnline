<script module lang="ts">
    import { edit } from '@/routes/game-settings';
    import { taikoRouteParam as taikoRouteParamForLayout } from '@/lib/taiko-version';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Game settings',
                href: edit(taikoRouteParamForLayout()),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import GameSettingsController from '@/actions/App/Http/Controllers/Settings/GameSettingsController';
    import AppHead from '@/components/AppHead.svelte';
    import Heading from '@/components/Heading.svelte';
    import InputError from '@/components/InputError.svelte';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import { Label } from '@/components/ui/label';
    import { Checkbox } from '@/components/ui/checkbox';
    import { taikoRouteParam } from '@/lib/taiko-version';

    // IDs match donderhiroba `area_id` values, which the cabinet expects via setRegionId.
    // These are NOT sequential JIS codes; keep the exact id/name pairing.
    const PREFECTURES = [
        { id: 0, name: 'Not set (Default)' },
        { id: 40, name: 'Hokkaido' },
        { id: 2, name: 'Aomori' },
        { id: 6, name: 'Iwate' },
        { id: 42, name: 'Miyagi' },
        { id: 3, name: 'Akita' },
        { id: 44, name: 'Yamagata' },
        { id: 39, name: 'Fukushima' },
        { id: 5, name: 'Ibaraki' },
        { id: 28, name: 'Tochigi' },
        { id: 18, name: 'Gunma' },
        { id: 20, name: 'Saitama' },
        { id: 25, name: 'Chiba' },
        { id: 26, name: 'Tokyo' },
        { id: 14, name: 'Kanagawa' },
        { id: 34, name: 'Niigata' },
        { id: 30, name: 'Toyama' },
        { id: 4, name: 'Ishikawa' },
        { id: 37, name: 'Fukui' },
        { id: 46, name: 'Yamanashi' },
        { id: 32, name: 'Nagano' },
        { id: 15, name: 'Gifu' },
        { id: 23, name: 'Shizuoka' },
        { id: 1, name: 'Aichi' },
        { id: 41, name: 'Mie' },
        { id: 22, name: 'Shiga' },
        { id: 16, name: 'Kyoto' },
        { id: 9, name: 'Osaka' },
        { id: 35, name: 'Hyogo' },
        { id: 33, name: 'Nara' },
        { id: 47, name: 'Wakayama' },
        { id: 29, name: 'Tottori' },
        { id: 24, name: 'Shimane' },
        { id: 10, name: 'Okayama' },
        { id: 36, name: 'Hiroshima' },
        { id: 45, name: 'Yamaguchi' },
        { id: 27, name: 'Tokushima' },
        { id: 12, name: 'Kagawa' },
        { id: 7, name: 'Ehime' },
        { id: 19, name: 'Kochi' },
        { id: 38, name: 'Fukuoka' },
        { id: 21, name: 'Saga' },
        { id: 31, name: 'Nagasaki' },
        { id: 17, name: 'Kumamoto' },
        { id: 8, name: 'Oita' },
        { id: 43, name: 'Miyazaki' },
        { id: 13, name: 'Kagoshima' },
        { id: 11, name: 'Okinawa' },
    ];

    const SPEED_OPTIONS = [
        { value: 0, label: '1.0x (Default)' },
        { value: 1, label: '1.5x' },
        { value: 2, label: '2.0x' },
        { value: 3, label: '3.0x' },
    ];

    const DORON_OPTIONS = [
        { value: 0, label: 'Do not vanish (Default)' },
        { value: 1, label: 'Vanish' },
    ];

    const ABEKOBE_OPTIONS = [
        { value: 0, label: 'Do not reverse (Default)' },
        { value: 1, label: 'Reverse' },
    ];

    const RANDOM_OPTIONS = [
        { value: 0, label: 'Do not randomize (Default)' },
        { value: 1, label: 'Whim' },
        { value: 2, label: 'Random' },
    ];

    const DISP_SCORE_OPTIONS = [
        { value: 0, label: 'Oni + Ura Oni (Default)' },
        { value: 1, label: 'Oni' },
        { value: 2, label: 'Hard' },
        { value: 3, label: 'Normal' },
        { value: 4, label: 'Easy' },
        { value: 5, label: 'Do not display' },
    ];

    const DISP_DAN_OPTIONS = [
        { value: 0, label: 'Do not display (Default)' },
        { value: 1, label: 'Display' },
    ];

    const COURSE_OPTIONS = [
        { value: 0, label: 'Not set (Default)' },
        { value: 99, label: 'Set during game' },
        { value: 1, label: 'Easy' },
        { value: 2, label: 'Normal' },
        { value: 3, label: 'Hard' },
        { value: 4, label: 'Oni' },
        { value: 5, label: 'Ura Oni' },
    ];

    const SORT_OPTIONS = [
        { value: 0, label: 'Not set (Default)' },
        { value: 99, label: 'Set during game' },
        { value: 1, label: 'As usual' },
        { value: 2, label: 'Uncleared first' },
        { value: 3, label: 'Non-full-combo first' },
        { value: 4, label: 'Non-Donderful-combo first' },
    ];

    let {
        hasAccessCode,
        versionLabel,
        prefectureId = 0,
        isPublish = true,
        dispScoreType = 0,
        dispDanType = 0,
        difficultyPlayedCourse = 0,
        difficultyPlayedStar = 0,
        difficultyPlayedSort = 0,
        defaultToneSetting = 0,
        speed = 0,
        doron = 0,
        abekobe = 0,
        random = 0,
        supportsFolderSettings = true,
        supportsPlayOptions = true,
        supportsTone = true,
        supportsRankingDifficulty = true,
        supportsProfilePublicity = true,
        syncPlayOptions = true,
        syncToneSettings = true,
    }: {
        hasAccessCode: boolean;
        versionLabel: string;
        prefectureId?: number;
        isPublish?: boolean;
        dispScoreType?: number;
        dispDanType?: number;
        difficultyPlayedCourse?: number;
        difficultyPlayedStar?: number;
        difficultyPlayedSort?: number;
        defaultToneSetting?: number;
        speed?: number;
        doron?: number;
        abekobe?: number;
        random?: number;
        supportsFolderSettings?: boolean;
        supportsPlayOptions?: boolean;
        supportsTone?: boolean;
        supportsRankingDifficulty?: boolean;
        supportsProfilePublicity?: boolean;
        syncPlayOptions?: boolean;
        syncToneSettings?: boolean;
    } = $props();

    let selectedPrefecture = $state(prefectureId);
    let selectedIsPublish = $state(isPublish);
    let selectedDispScoreType = $state(dispScoreType);
    let selectedDispDanType = $state(dispDanType);
    let selectedDifficultyPlayedCourse = $state(difficultyPlayedCourse);
    let selectedDifficultyPlayedStar = $state(difficultyPlayedStar);
    let selectedDifficultyPlayedSort = $state(difficultyPlayedSort);
    let selectedDefaultToneSetting = $state(defaultToneSetting);
    let selectedSpeed = $state(speed);
    let selectedDoron = $state(doron);
    let selectedAbekobe = $state(abekobe);
    let selectedRandom = $state(random);
    let selectedSyncPlayOptions = $state(syncPlayOptions);
    let selectedSyncToneSettings = $state(syncToneSettings);
</script>

<AppHead title="Game settings" />

<h1 class="sr-only">Game settings</h1>

<div class="flex flex-col space-y-6">
    <Heading
        variant="small"
        title="Taiko Game Settings ({versionLabel})"
        description="Configure your default gameplay options, profile display preferences, and folder pre-selection. Settings are stored per-version unless noted."
    />

    {#if !hasAccessCode}
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-800">
                Link your Banapassport access code in the Profile settings to manage your game settings.
            </p>
        </div>
    {:else}
        <Form
            {...GameSettingsController.update.form(taikoRouteParam())}
            class="space-y-8"
            options={{ preserveScroll: true }}
        >
            {#snippet children({ errors, processing })}
                <!-- Shared Profile Section -->
                <div class="space-y-4 rounded-lg border p-4 bg-card">
                    <h2 class="text-base font-semibold border-b pb-2 mb-4">Profile & Display Settings (Shared)</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="prefecture_id">Prefecture</Label>
                            <select
                                id="prefecture_id"
                                name="prefecture_id"
                                bind:value={selectedPrefecture}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each PREFECTURES as pref (pref.id)}
                                    <option value={pref.id} class="bg-popover text-popover-foreground">{pref.name}</option>
                                {/each}
                            </select>
                            <InputError message={errors.prefecture_id} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="disp_dan_type">Dan-i Display Setting</Label>
                            <select
                                id="disp_dan_type"
                                name="disp_dan_type"
                                bind:value={selectedDispDanType}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each DISP_DAN_OPTIONS as opt (opt.value)}
                                    <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                {/each}
                            </select>
                            <InputError message={errors.disp_dan_type} />
                        </div>

                        {#if supportsRankingDifficulty}
                            <div class="grid gap-2">
                                <Label for="disp_score_type">Results Display Setting</Label>
                                <select
                                    id="disp_score_type"
                                    name="disp_score_type"
                                    bind:value={selectedDispScoreType}
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    {#each DISP_SCORE_OPTIONS as opt (opt.value)}
                                        <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                    {/each}
                                </select>
                                <InputError message={errors.disp_score_type} />
                            </div>
                        {/if}

                        {#if supportsProfilePublicity}
                            <div class="flex items-center gap-2 pt-6">
                                <Checkbox
                                    id="is_publish"
                                    name="is_publish"
                                    bind:checked={selectedIsPublish}
                                />
                                <Label for="is_publish" class="cursor-pointer font-medium">Public Profile</Label>
                                <input type="hidden" name="is_publish" value={selectedIsPublish ? '1' : '0'} />
                                <InputError message={errors.is_publish} />
                            </div>
                        {/if}
                    </div>
                </div>

                <!-- Default Play Options Section (Bitmask) -->
                {#if supportsPlayOptions}
                <div class="space-y-4 rounded-lg border p-4 bg-card">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 mb-4">
                        <h2 class="text-base font-semibold">Default Play Options (Version Scoped)</h2>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="sync_play_options"
                                bind:checked={selectedSyncPlayOptions}
                            />
                            <Label for="sync_play_options" class="cursor-pointer text-sm font-normal text-muted-foreground">Apply to all game versions</Label>
                            <input type="hidden" name="sync_play_options" value={selectedSyncPlayOptions ? '1' : '0'} />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="speed">Speed</Label>
                            <select
                                id="speed"
                                name="speed"
                                bind:value={selectedSpeed}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each SPEED_OPTIONS as opt (opt.value)}
                                    <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                {/each}
                            </select>
                            <InputError message={errors.speed} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="doron">Vanish / Doron</Label>
                            <select
                                id="doron"
                                name="doron"
                                bind:value={selectedDoron}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each DORON_OPTIONS as opt (opt.value)}
                                    <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                {/each}
                            </select>
                            <InputError message={errors.doron} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="abekobe">Reverse / Abekobe</Label>
                            <select
                                id="abekobe"
                                name="abekobe"
                                bind:value={selectedAbekobe}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each ABEKOBE_OPTIONS as opt (opt.value)}
                                    <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                {/each}
                            </select>
                            <InputError message={errors.abekobe} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="random">Random</Label>
                            <select
                                id="random"
                                name="random"
                                bind:value={selectedRandom}
                                class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                {#each RANDOM_OPTIONS as opt (opt.value)}
                                    <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                {/each}
                            </select>
                            <InputError message={errors.random} />
                        </div>
                    </div>
                </div>
                {/if}

                <!-- Tone & Mode Specific Section -->
                {#if supportsTone}
                <div class="space-y-4 rounded-lg border p-4 bg-card">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 mb-4">
                        <h2 class="text-base font-semibold">Default Tone (Version Scoped)</h2>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="sync_tone_settings"
                                bind:checked={selectedSyncToneSettings}
                            />
                            <Label for="sync_tone_settings" class="cursor-pointer text-sm font-normal text-muted-foreground">Apply to all game versions</Label>
                            <input type="hidden" name="sync_tone_settings" value={selectedSyncToneSettings ? '1' : '0'} />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="default_tone_setting">Default Tone ID</Label>
                            <Input
                                id="default_tone_setting"
                                name="default_tone_setting"
                                type="number"
                                min="0"
                                bind:value={selectedDefaultToneSetting}
                                placeholder="Tone ID (e.g. 0 for Taiko)"
                            />
                            <p class="text-xs text-muted-foreground">Standard Taiko sound is ID 0. Others are unlocked via gameplay.</p>
                            <InputError message={errors.default_tone_setting} />
                        </div>
                    </div>
                </div>
                {/if}

                <!-- Select by Difficulty Folders Section -->
                {#if supportsFolderSettings}
                    <div class="space-y-4 rounded-lg border p-4 bg-card">
                        <h2 class="text-base font-semibold border-b pb-2 mb-4">"Select by Difficulty" Folder Presets</h2>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label for="difficulty_played_course">Folder Difficulty</Label>
                                <select
                                    id="difficulty_played_course"
                                    name="difficulty_played_course"
                                    bind:value={selectedDifficultyPlayedCourse}
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    {#each COURSE_OPTIONS as opt (opt.value)}
                                        <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                    {/each}
                                </select>
                                <InputError message={errors.difficulty_played_course} />
                            </div>

                            <div class="grid gap-2">
                                <Label for="difficulty_played_star">Stars / Rating</Label>
                                <select
                                    id="difficulty_played_star"
                                    name="difficulty_played_star"
                                    bind:value={selectedDifficultyPlayedStar}
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    <option value={0} class="bg-popover text-popover-foreground">Not set (Default)</option>
                                    <option value={99} class="bg-popover text-popover-foreground">Set during game</option>
                                    {#each Array.from({ length: 10 }, (_, i) => i + 1) as star}
                                        <option value={star} class="bg-popover text-popover-foreground">{star} Stars</option>
                                    {/each}
                                </select>
                                <InputError message={errors.difficulty_played_star} />
                            </div>

                            <div class="grid gap-2">
                                <Label for="difficulty_played_sort">Display Order</Label>
                                <select
                                    id="difficulty_played_sort"
                                    name="difficulty_played_sort"
                                    bind:value={selectedDifficultyPlayedSort}
                                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    {#each SORT_OPTIONS as opt (opt.value)}
                                        <option value={opt.value} class="bg-popover text-popover-foreground">{opt.label}</option>
                                    {/each}
                                </select>
                                <InputError message={errors.difficulty_played_sort} />
                            </div>
                        </div>
                    </div>
                {:else}
                    <input type="hidden" name="difficulty_played_course" value={selectedDifficultyPlayedCourse} />
                    <input type="hidden" name="difficulty_played_star" value={selectedDifficultyPlayedStar} />
                    <input type="hidden" name="difficulty_played_sort" value={selectedDifficultyPlayedSort} />
                {/if}

                <div class="flex items-center gap-4">
                    <Button
                        type="submit"
                        disabled={processing}
                        data-test="update-game-settings-button"
                    >Save Game Settings</Button>
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
