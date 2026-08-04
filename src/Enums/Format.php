<?php

namespace ClickHouse\Enums;

/**
 * ClickHouse input formats that rows can be encoded and sent in.
 *
 * @see https://clickhouse.com/docs/interfaces/formats
 */
enum Format: string
{
    case Values = 'Values';

    case JSONEachRow = 'JSONEachRow';
}
