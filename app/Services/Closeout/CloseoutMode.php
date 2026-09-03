<?php

namespace App\Services\Closeout;

final class CloseoutMode
{
    public const Classic = 'classic';

    public const FamilyPooled = 'family_pooled';

    public const SettingsSnapshotVersion = 1;

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::Classic, self::FamilyPooled];
    }

    public static function normalize(?string $mode): string
    {
        return $mode === self::FamilyPooled ? self::FamilyPooled : self::Classic;
    }

    public static function isFamilyPooled(?string $mode): bool
    {
        return self::normalize($mode) === self::FamilyPooled;
    }

    public static function allowsExpenseBasisExclusion(?string $mode): bool
    {
        return ! self::isFamilyPooled($mode);
    }
}
