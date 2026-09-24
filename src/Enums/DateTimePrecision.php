<?php

namespace ClickHouse\Enums;

/**
 * Precision used whenever the package renders a DateTimeInterface object —
 * query bindings and insert values in every input format — since the driver
 * cannot see the target column type. Values the caller already stringified
 * (a model's $dateFormat, pre-formatted strings) carry their own precision
 * and are not affected by this setting.
 */
enum DateTimePrecision: string
{
    case Second = 'second';

    case Microsecond = 'microsecond';
}
