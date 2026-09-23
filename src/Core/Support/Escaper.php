<?php

namespace ClickHouse\Core\Support;

use DateTimeInterface;
use RuntimeException;

class Escaper
{
    public function escape(mixed $value, bool $binary = false): string
    {
        if (is_array($value)) {
            return $this->escapeArray($value, $binary);
        }

        if (is_null($value)) {
            return 'null';
        }

        if ($binary) {
            return $this->escapeBinary($value);
        }

        if (is_int($value) || is_float($value)) {
            return $this->escapeNumber($value);
        }

        if (is_bool($value)) {
            return $this->escapeBool($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $this->escapeDateTime($value);
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException('Unsupported value type.');
        }

        if (str_contains($value, "\00")) {
            throw new RuntimeException('Strings with null bytes cannot be escaped. Use the binary escape option.');
        }

        if (preg_match('//u', $value) === false) {
            throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
        }

        return $this->escapeString($value);
    }

    /**
     * @param  mixed[]  $values
     */
    public function escapeArray(array $values, bool $binary = false): string
    {
        return '['.implode(', ', array_map(fn ($value) => $this->escape($value, $binary), $values)).']';
    }

    /**
     * A whole-second value becomes a plain quoted literal, which every
     * ClickHouse version accepts against both DateTime and DateTime64
     * columns. A value carrying microseconds is wrapped in
     * toDateTime64(..., 6) instead: a bare fractional literal is rejected
     * by older ClickHouse versions when compared against a DateTime column
     * and silently truncated before comparison by newer ones, while the
     * expression keeps exact comparison semantics on both column types.
     */
    public function escapeDateTime(DateTimeInterface $value): string
    {
        $escaped = $this->escapeString(DateTimeFormatter::format($value));

        if (! DateTimeFormatter::hasMicroseconds($value)) {
            return $escaped;
        }

        return sprintf('toDateTime64(%s, 6)', $escaped);
    }

    public function escapeBinary(mixed $value): string
    {
        throw new RuntimeException('The database connection does not support escaping binary values.');
    }

    public function escapeNumber(int|float $value): string
    {
        return (string) $value;
    }

    public function escapeBool(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function escapeString(string $value): string
    {
        return sprintf("'%s'", addslashes($value));
    }
}
