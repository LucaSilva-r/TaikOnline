<?php

namespace App\Enums;

/** Bad data a player tried to send: what the moderators' upload warnings page lists. */
enum UploadWarningKind: string
{
    /** A replay that does not follow the format; refused. */
    case MalformedReplay = 'malformed_replay';

    /** The same inputs as another player's play on the chart; kept hidden. */
    case CopiedReplay = 'copied_replay';

    /** A replay the scorer could not score, or numbers no column can hold; kept off the boards. */
    case UnscorableReplay = 'unscorable_replay';

    /** Numbers the replay does not give, under the same scoring rules: a modified client or an engine bug. */
    case ScoreMismatch = 'score_mismatch';

    /** Notes that do not match their hash, or that the scorer cannot play; refused. */
    case BadChart = 'bad_chart';

    /** The private analyzer flagged the play for review. */
    case AnalyzerFlag = 'analyzer_flag';

    public function label(): string
    {
        return match ($this) {
            self::MalformedReplay => 'Malformed replay',
            self::CopiedReplay => 'Copied replay',
            self::UnscorableReplay => 'Replay could not be scored',
            self::ScoreMismatch => 'Score differs from its replay',
            self::BadChart => 'Bad chart',
            self::AnalyzerFlag => 'Flagged by the analyzer',
        };
    }
}
