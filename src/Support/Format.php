<?php

namespace ClickHouse\Support;

/**
 * ClickHouse input formats that rows can be encoded and sent in.
 *
 * @see https://clickhouse.com/docs/interfaces/formats
 */
enum Format: string
{
    case JSONEachRow = 'JSONEachRow';
}
