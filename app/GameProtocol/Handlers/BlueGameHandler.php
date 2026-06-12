<?php

namespace App\GameProtocol\Handlers;

use App\Enums\TaikoGameVersion;
use App\Models\PlayerBlueBattleNpcState;
use App\Models\PlayerBlueBattleState;
use App\Models\PlayerBlueBattleTokenState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Blue (v10) dialect handler. Blue's InitialdatacheckResponse diverges from the
 * green-shaped schema, so it is hand-serialised here while every other endpoint
 * inherits the default behaviour.
 */
class BlueGameHandler extends GameHandler
{
    public function initialDataCheck(Request $request, TaikoGameVersion $game): Response
    {
        $this->parse($request, $game, 'InitialdatacheckRequest');

        return $this->blueInitialDataCheckResponse($this->releaseSongFlag($game->value));
    }

    public function battleUserData(Request $request, TaikoGameVersion $game): Response
    {
        $message = $this->parse($request, $game, 'BattleUserDataRequest');
        $baid = $message->getBaid();

        $userState = PlayerBlueBattleState::query()->where('baid', $baid)->first();
        $npcStates = PlayerBlueBattleNpcState::query()->where('baid', $baid)->orderBy('npc_id')->get();
        $tokenStates = PlayerBlueBattleTokenState::query()->where('baid', $baid)->orderBy('token_id')->get();

        $useStarterState = ($userState === null);

        $releaseInfoFlg = $this->fixedOrZero($userState?->release_info_flg, 16);
        $releaseBattleStageFlg = $this->fixedOrZero($userState?->release_battle_stage_flg, 8);

        if ($useStarterState) {
            $this->setBitIfInRange($releaseBattleStageFlg, 1);
        }
        $this->setBitIfInRange($releaseBattleStageFlg, $userState?->last_battle_stage_id);
        $this->setBitIfInRange($releaseBattleStageFlg, $userState?->assign_stage_id);

        $npcs = [];
        if ($npcStates->isEmpty()) {
            $npcs[] = $this->writer->fill($this->messages->make($game, 'BattleUserDataResponse\\BattleUserNpcData'), [
                'setNpcId' => 0,
                'setTotalExp' => '0',
                'setMaxDpn' => 0,
                'setNpcCostumeId' => 0,
                'setNpcCostumeFlg' => $this->buildNpcCostumeFlg(null, 0),
                'setLastSelectSpecial1' => 1,
                'setLastSelectSpecial2' => 0,
                'setLastSelectSpecial3' => 0,
                'setReleaseSpecialFlg' => $this->buildReleaseSpecialFlg(null, [1]),
            ]);
        } else {
            foreach ($npcStates as $row) {
                $npcs[] = $this->writer->fill($this->messages->make($game, 'BattleUserDataResponse\\BattleUserNpcData'), [
                    'setNpcId' => (int) $row->npc_id,
                    'setTotalExp' => (string) $row->total_exp,
                    'setMaxDpn' => (int) $row->max_dpn,
                    'setNpcCostumeId' => (int) $row->npc_costume_id,
                    'setNpcCostumeFlg' => $this->buildNpcCostumeFlg($row->npc_costume_flg, (int) $row->npc_costume_id),
                    'setLastSelectSpecial1' => (int) $row->selected_special_id_1,
                    'setLastSelectSpecial2' => (int) $row->selected_special_id_2,
                    'setLastSelectSpecial3' => (int) $row->selected_special_id_3,
                    'setReleaseSpecialFlg' => $this->buildReleaseSpecialFlg($row->release_special_flg, [
                        (int) $row->selected_special_id_1,
                        (int) $row->selected_special_id_2,
                        (int) $row->selected_special_id_3,
                    ]),
                ]);
            }
        }

        $tokens = [];
        $hasIntroToken = false;
        foreach ($tokenStates as $state) {
            if ((int) $state->token_id === 0) {
                $hasIntroToken = true;
            }
            $tokens[] = $this->writer->fill($this->messages->make($game, 'BattleUserDataResponse\\BattleUserTokenData'), [
                'setTokenId' => (int) $state->token_id,
                'setTokenValue' => (int) $state->token_value,
            ]);
        }
        if (! $hasIntroToken) {
            $introToken = $this->writer->fill($this->messages->make($game, 'BattleUserDataResponse\\BattleUserTokenData'), [
                'setTokenId' => 0,
                'setTokenValue' => 0,
            ]);
            array_unshift($tokens, $introToken);
        }

        return $this->payloads->response(
            $this->writer->fill($this->messages->make($game, 'BattleUserDataResponse'), [
                'setResult' => 1,
                'setReleaseInfoFlg' => $releaseInfoFlg,
                'setReleaseBattleStageFlg' => $releaseBattleStageFlg,
                'setLastBattleStageId' => $userState?->last_battle_stage_id ?? 0,
                'setLastBossLife' => $userState?->last_boss_life ?? 0,
                'setLastNpcId' => $userState?->last_npc_id ?? ($npcs[0]->getNpcId() ?? 0),
                'setNpcData' => $npcs,
                'setAryTokenData' => $tokens,
                'setAssignStageId' => $userState?->assign_stage_id ?? 1,
            ])
        );
    }

    private function fixedOrZero(?string $source, int $byteCount): string
    {
        if ($source === null) {
            return str_repeat("\x00", $byteCount);
        }
        $len = strlen($source);
        if ($len === $byteCount) {
            return $source;
        }
        if ($len > $byteCount) {
            return substr($source, 0, $byteCount);
        }

        return str_pad($source, $byteCount, "\x00");
    }

    private function setBitIfInRange(string &$result, ?int $id): void
    {
        if ($id === null || $id === 0 || $id >= strlen($result) * 8) {
            return;
        }
        $byteIndex = $id >> 3;
        $bitIndex = $id & 7;
        $byteVal = ord($result[$byteIndex]);
        $byteVal |= (1 << $bitIndex);
        $result[$byteIndex] = chr($byteVal);
    }

    private function buildNpcCostumeFlg(?string $source, int $selectedCostumeId): string
    {
        $result = $this->fixedOrZero($source, 4);
        if ($selectedCostumeId < 32) {
            $byteIndex = $selectedCostumeId >> 3;
            $bitIndex = $selectedCostumeId & 7;
            $byteVal = ord($result[$byteIndex]);
            $byteVal |= (1 << $bitIndex);
            $result[$byteIndex] = chr($byteVal);
        }

        return $result;
    }

    private function buildReleaseSpecialFlg(?string $source, array $selectedSpecials): string
    {
        $enabledIds = [1, 120];
        if ($source !== null) {
            $enabledIds = array_merge($enabledIds, $this->scoreMapper->flagBytesToIds($source));
        }
        foreach ($selectedSpecials as $id) {
            if ($id > 0) {
                $enabledIds[] = $id;
            }
        }

        return $this->scoreMapper->idFlagBytes(array_unique($enabledIds), 16);
    }

    private function blueInitialDataCheckResponse(string $releaseSongFlag): Response
    {
        $body = $this->protobufVarintField(1, 1)
            .$this->protobufVarintField(2, 1)
            .$this->protobufBytesField(3, $releaseSongFlag)
            .$this->protobufBytesField(4, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(5, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(6, $this->protobufMessage([
                $this->protobufVarintField(1, 1),
                $this->protobufVarintField(2, 2),
            ]))
            .$this->protobufVarintField(10, 1)
            .$this->protobufVarintField(11, 0)
            .$this->protobufVarintField(12, 0)
            .$this->protobufVarintField(14, 1)
            .$this->protobufBytesField(15, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufBytesField(16, $this->scoreMapper->emptyFlagBytes())
            .$this->protobufVarintField(17, 0);

        return response($body, 200, ['Content-Type' => 'application/protobuf']);
    }

    private function protobufVarintField(int $field, int $value): string
    {
        return $this->protobufVarint(($field << 3) | 0).$this->protobufVarint($value);
    }

    private function protobufBytesField(int $field, string $value): string
    {
        return $this->protobufVarint(($field << 3) | 2).$this->protobufVarint(strlen($value)).$value;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function protobufMessage(array $fields): string
    {
        return implode('', $fields);
    }

    private function protobufVarint(int $value): string
    {
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
    }
}
