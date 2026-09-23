<?php

namespace ClickHouse\Core\Support;

use DateTimeInterface;

final class DateTimeFormatter
{
    private const NO_MICROSECONDS = '000000';

    private const SECOND_PRECISION_FORMAT = 'Y-m-d H:i:s';

    private const MICROSECOND_PRECISION_FORMAT = 'Y-m-d H:i:s.u';

    public static function format(DateTimeInterface $value): string
    {
        if (! self::hasMicroseconds($value)) {
            return $value->format(self::SECOND_PRECISION_FORMAT);
        }

        return $value->format(self::MICROSECOND_PRECISION_FORMAT);
    }

    public static function hasMicroseconds(DateTimeInterface $value): bool
    {
        return $value->format('u') !== self::NO_MICROSECONDS;
    }
}
