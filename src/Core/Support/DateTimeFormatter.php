<?php

namespace ClickHouse\Core\Support;

use DateTimeInterface;

final class DateTimeFormatter
{
    private const MICROSECOND_PRECISION_FORMAT = 'Y-m-d H:i:s.u';

    private const NO_MICROSECONDS = '000000';

    private const SECOND_PRECISION_FORMAT = 'Y-m-d H:i:s';

    public static function format(DateTimeInterface $value): string
    {
        if ($value->format('u') === self::NO_MICROSECONDS) {
            return $value->format(self::SECOND_PRECISION_FORMAT);
        }

        return $value->format(self::MICROSECOND_PRECISION_FORMAT);
    }
}
