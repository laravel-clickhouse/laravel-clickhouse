<?php

namespace ClickHouse\Support;

use DateTimeInterface;
use RuntimeException;

class Escaper
{
    public function escape(mixed $value, bool $binary = false): string
    {
        if (is_array($value)) {
            return $this->escapeArray($value);
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
            $value = $value->format('Y-m-d H:i:s');
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
    public function escapeArray(array $values): string
    {
        return '['.implode(', ', array_map(fn ($value) => $this->escape($value), $values)).']';
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
