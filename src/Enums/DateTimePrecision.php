<?php

namespace ClickHouse\Enums;

/**
 * Precision used when the package has to infer how a DateTimeInterface value
 * should be rendered — query bindings and Values-format insert values, where
 * the target column type is unknown to the driver. Channels that carry
 * explicit microsecond intent (a model's $dateFormat, Format::JSONEachRow,
 * pre-formatted strings) are not affected by this setting.
 */
enum DateTimePrecision: string
{
    case Second = 'second';

    case Microsecond = 'microsecond';
}
