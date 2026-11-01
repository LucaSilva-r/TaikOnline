<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/** The tabs of the notification center, from a notification's `kind`. What fits no other tab is System. */
enum NotificationCategory: string
{
    case Moderation = 'moderation';
    case Scores = 'scores';
    case System = 'system';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return match ($this) {
            self::Moderation => ['play_reported', 'upload_warning', 'sanction', 'report_resolved'],
            self::Scores => ['play_rejected'],
            self::System => [],
        };
    }

    public static function of(?string $kind): self
    {
        foreach ([self::Moderation, self::Scores] as $category) {
            if (in_array($kind, $category->kinds(), true)) {
                return $category;
            }
        }

        return self::System;
    }

    /** Narrows a query over notifications to this category. */
    public function apply(Builder|Relation $notifications): void
    {
        $like = fn (string $kind): string => '%"kind":"'.$kind.'"%';
        if ($this === self::System) {
            foreach ([...self::Moderation->kinds(), ...self::Scores->kinds()] as $kind) {
                $notifications->where('data', 'not like', $like($kind));
            }

            return;
        }
        $notifications->where(function ($query) use ($like): void {
            foreach ($this->kinds() as $kind) {
                $query->orWhere('data', 'like', $like($kind));
            }
        });
    }
}
