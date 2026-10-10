<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Текущее время в часовом поясе приложения; в тестах время можно зафиксировать. */
final class Clock
{
    private static ?DateTimeZone $tz = null;
    private static ?DateTimeImmutable $frozen = null;

    public static function setTimezone(DateTimeZone $tz): void
    {
        self::$tz = $tz;
    }

    public static function timezone(): DateTimeZone
    {
        return self::$tz ?? new DateTimeZone(date_default_timezone_get());
    }

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', self::timezone());
    }

    /** Строка для DATETIME-колонок. */
    public static function nowSql(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function freeze(?DateTimeImmutable $at): void
    {
        self::$frozen = $at;
    }
}
