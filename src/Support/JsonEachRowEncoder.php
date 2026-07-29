<?php

namespace ClickHouse\Support;

use BackedEnum;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use UnitEnum;

class JsonEachRowEncoder
{
    /**
     * Encode rows into newline-delimited JSON for the JSONEachRow input format.
     *
     * @param  array<string, mixed>[]  $rows
     *
     * @throws JsonException
     */
    public function encode(array $rows): string
    {
        return implode("\n", array_map(function ($row) {
            return json_encode(
                $this->normalizeRow($row),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }, $rows));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row): array
    {
        return array_map(fn ($value) => $this->normalizeValue($value), $row);
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            return (string) $value;
        }

        if (is_object($value) || is_resource($value)) {
            throw new RuntimeException('Unsupported value type.');
        }

        return $value;
    }
}
