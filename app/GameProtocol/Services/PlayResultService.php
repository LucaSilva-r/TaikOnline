<?php

namespace App\GameProtocol\Services;

use App\Enums\TaikoGameVersion;
use App\GameProtocol\Support\MessageWriter;
use App\GameProtocol\Support\ProtocolMessageResolver;
use App\GameProtocol\Support\ScoreMapper;
use App\Models\Player;
use App\Models\PlayerCosmetic;
use App\Models\SongBest;
use App\Models\SongPlayResult;
use Carbon\CarbonImmutable;
use Google\Protobuf\Internal\Message;

class PlayResultService
{
    public function __construct(
        private readonly ScoreMapper $scoreMapper,
        private readonly ProtocolMessageResolver $messages,
        private readonly MessageWriter $writer,
    ) {}

    public function save(Message $data, TaikoGameVersion $version): int
    {
        $player = Player::query()->find($data->getBaid());
        if (! $player instanceof Player) {
            return 1;
        }

        $gameVersion = $version->value;
        $playedAt = $this->parsePlayedAt($data->getPlayDatetime());

        foreach ($data->getAryStageInfo() as $stage) {
            if (! $stage instanceof Message) {
                continue;
            }

            $rank = $this->scoreMapper->rankForScore($stage->getPlayScore());

            SongPlayResult::query()->create([
                'baid' => $player->baid,
                'game_version' => $gameVersion,
                'chassis_id' => $data->getChassisId(),
                'shop_id' => $data->getShopId(),
                'played_at' => $playedAt,
                'is_right' => $data->getIsRight(),
                'is_two_players' => $data->getIsTwoPlayers(),
                'song_no' => $stage->getSongNo(),
                'level' => $stage->getLevel(),
                'stage_mode' => $stage->getStageMode(),
                'play_result' => $stage->getPlayResult(),
                'score' => $stage->getPlayScore(),
                'score_rank' => $rank,
                'good_count' => $stage->getGoodCnt(),
                'ok_count' => $stage->getOkCnt(),
                'miss_count' => $stage->getNgCnt(),
                'drumroll_count' => $stage->getPoundCnt(),
                'combo_count' => $stage->getComboCnt(),
                'hit_count' => $stage->getHitCnt(),
                'music_category' => $stage->getMusicCateg(),
                'selected_folder_id' => $stage->getSelectedFolderId(),
                'raw_stage' => [
                    'star_level' => $stage->getStarLevel(),
                    'support_level' => $stage->getSupportLevel(),
                    'is_favorite' => $stage->getIsFavorite(),
                    'is_recent' => $stage->getIsRecent(),
                ],
                'ghost_sections' => $this->extractGhostSections($stage),
            ]);

            $this->updateBest($player, $stage, $rank, $gameVersion);
        }

        $player->update([
            'last_played_at' => $playedAt,
            'difficulty_played_course' => $data->getDifficultyPlayedCourse(),
            'difficulty_played_star' => $data->getDifficultyPlayedStar(),
            'recent_song_numbers' => $this->recentSongs($player, $data),
            'total_credit_count' => (int) $player->total_credit_count + 1,
        ]);

        if (method_exists($data, 'getReleaseSongNo')) {
            $player->update([
                'unlocked_song_numbers' => $this->mergeIds($player->unlocked_song_numbers ?? [], $data->getReleaseSongNo()),
            ]);
        }

        $this->persistCosmetics($player, $data, $version);

        return 1;
    }

    /**
     * Persist the version-scoped cosmetic state a play result carries: the
     * costume/tone/title unlocks granted this play and the equipped costume.
     * Cosmetic ids map to different items per version, so this is keyed per
     * (baid, game_version). Getters are guarded for older dialects that omit
     * some of these fields.
     */
    private function persistCosmetics(Player $player, Message $data, TaikoGameVersion $version): void
    {
        $cosmetic = PlayerCosmetic::resolve($player->baid, $version);

        if (method_exists($data, 'getGetToneNo')) {
            $cosmetic->unlocked_tones = $this->mergeIds($cosmetic->unlocked_tones ?? [], $data->getGetToneNo());
        }

        if (method_exists($data, 'getGetTitleNo')) {
            $cosmetic->unlocked_titles = $this->mergeIds($cosmetic->unlocked_titles ?? [], $data->getGetTitleNo());
        }

        $costumes = $cosmetic->unlocked_costumes ?? [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $getter = "getGetCostumeNo{$slot}";
            if (method_exists($data, $getter)) {
                $costumes[(string) $slot] = $this->mergeIds($costumes[(string) $slot] ?? [], $data->{$getter}());
            }
        }
        $cosmetic->unlocked_costumes = $costumes;

        $this->applyEquippedCostume($cosmetic, $data);
        $this->applyDefaultSettings($cosmetic, $data);

        $cosmetic->save();
    }

    /**
     * Carry the tone and play options the player used on their last stage into
     * the cosmetic row so the cabinet pre-selects them next session. The cabinet
     * reports the tone as a bitfield (one bit = the equipped tone id) and the
     * options as a little-endian flag value.
     */
    private function applyDefaultSettings(PlayerCosmetic $cosmetic, Message $data): void
    {
        if (! method_exists($data, 'getAryStageInfo')) {
            return;
        }

        $stage = collect($data->getAryStageInfo())
            ->filter(fn (mixed $stage): bool => $stage instanceof Message)
            ->last();

        if (! $stage instanceof Message) {
            return;
        }

        if (method_exists($stage, 'getToneFlg')) {
            $toneId = $this->firstSetBit($stage->getToneFlg());
            if ($toneId !== null) {
                $cosmetic->default_tone_setting = $toneId;
            }
        }

        if (method_exists($stage, 'getOptionFlg')) {
            $cosmetic->default_option_setting = $this->leInt($stage->getOptionFlg());
        }
    }

    /**
     * Index of the lowest set bit in a flag bitfield, or null when empty.
     */
    private function firstSetBit(string $flag): ?int
    {
        $length = strlen($flag);
        for ($byte = 0; $byte < $length; $byte++) {
            $value = ord($flag[$byte]);
            if ($value === 0) {
                continue;
            }

            for ($bit = 0; $bit < 8; $bit++) {
                if (($value & (1 << $bit)) !== 0) {
                    return $byte * 8 + $bit;
                }
            }
        }

        return null;
    }

    /**
     * Decode up to four little-endian bytes into an unsigned integer.
     */
    private function leInt(string $bytes): int
    {
        $padded = str_pad(substr($bytes, 0, 4), 4, "\0");

        return unpack('V', $padded)[1];
    }

    /**
     * Mirror the cabinet's currently-worn costume into the cosmetic row so it
     * persists across sessions for this version.
     */
    private function applyEquippedCostume(PlayerCosmetic $cosmetic, Message $data): void
    {
        if (! method_exists($data, 'getAryCurrentCostume') || ! $data->hasAryCurrentCostume()) {
            return;
        }

        $costume = $data->getAryCurrentCostume();
        if (! $costume instanceof Message) {
            return;
        }

        for ($slot = 1; $slot <= 5; $slot++) {
            $cosmetic->{"costume_{$slot}"} = (int) $costume->{"getCostume{$slot}"}();
        }
    }

    /**
     * Merge incoming ids into an existing list, dropping zeros/duplicates.
     *
     * @param  array<int, int>  $existing
     * @param  iterable<int>  $incoming
     * @return array<int, int>
     */
    private function mergeIds(array $existing, iterable $incoming): array
    {
        return collect($existing)
            ->merge(collect($incoming)->map(fn (mixed $id): int => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int>  $songNumbers
     */
    public function selfBest(Player $player, int $level, TaikoGameVersion $version, iterable $songNumbers): Message
    {
        $numbers = collect($songNumbers)->map(fn (mixed $value): int => (int) $value)->filter()->values();
        $query = SongBest::query()
            ->where('baid', $player->baid)
            ->where('game_version', $version->value);

        if ($level > 0) {
            $query->where('level', $level);
        }

        if ($numbers->isNotEmpty()) {
            $query->whereIn('song_no', $numbers);
        }

        $items = $query->get()
            ->map(fn (SongBest $best): Message => $this->writer->fill(
                $this->messages->make($version, 'SelfBestResponse\\SelfBestData'),
                [
                    'setSongNo' => (int) $best->song_no,
                    'setSelfBestScore' => (int) $best->best_score,
                    'setUraBestScore' => 0,
                ],
            ))
            ->all();

        return $this->writer->fill($this->messages->make($version, 'SelfBestResponse'), [
            'setResult' => 1,
            'setLevel' => $level,
            'setArySelfbestScore' => $items,
            'setAryShinSelfbestScore' => [],
        ]);
    }

    private function updateBest(Player $player, Message $stage, int $rank, string $gameVersion): void
    {
        $best = SongBest::query()->firstOrNew([
            'baid' => $player->baid,
            'game_version' => $gameVersion,
            'song_no' => $stage->getSongNo(),
            'level' => $stage->getLevel(),
        ]);

        $dirty = ! $best->exists;

        if (! $best->exists || $stage->getPlayScore() >= (int) $best->best_score) {
            $best->fill([
                'best_score' => $stage->getPlayScore(),
                'best_score_rank' => $rank,
                'best_play_result' => $stage->getPlayResult(),
            ]);
            $dirty = true;
        }

        // Crowns improve independently of score: a later lower-scoring full combo
        // still upgrades the crown. Rank order matches the stored values
        // (0 none < 1 clear < 2 gold < 3 dondaful).
        $crown = $this->crownForPlayResult($stage->getPlayResult());
        if ($crown > (int) $best->best_crown) {
            $best->best_crown = $crown;
            $dirty = true;
        }

        if ($dirty) {
            $best->save();
        }
    }

    /**
     * Clamp the cabinet's play_result to a crown rank (1 clear, 2 gold,
     * 3 dondaful); anything else counts as no crown.
     */
    private function crownForPlayResult(int $playResult): int
    {
        return ($playResult >= 1 && $playResult <= 3) ? $playResult : 0;
    }

    private function parsePlayedAt(string $value): CarbonImmutable
    {
        if ($value === '') {
            return now()->toImmutable();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return now()->toImmutable();
        }
    }

    /**
     * @return array<int, int>
     */
    private function recentSongs(Player $player, Message $data): array
    {
        $songs = collect($data->getAryStageInfo())
            ->filter(fn (mixed $stage): bool => $stage instanceof Message)
            ->map(fn (Message $stage): int => $stage->getSongNo());

        return $songs
            ->merge($player->recent_song_numbers ?? [])
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    private function extractGhostSections(Message $stage): ?array
    {
        if (! $stage->hasGhostStagedata() || ! $stage->getGhostStagedata() instanceof Message) {
            return null;
        }

        $sections = [];
        foreach ($stage->getGhostStagedata()->getArySectionData() as $index => $section) {
            $sections[$index] = [
                'good_cnt' => (int) $section->getGoodCnt(),
                'ok_cnt' => (int) $section->getOkCnt(),
                'ng_cnt' => (int) $section->getNgCnt(),
                'pound_cnt' => (int) $section->getPoundCnt(),
            ];
        }

        return $sections ?: null;
    }
}
