<?php

namespace SwooleTW\ClickHouse\Exceptions;

use RuntimeException;
use Throwable;

/**
 * @template T
 */
class ParallelQueryException extends RuntimeException
{
    /**
     * @param  array<int|string, T>  $results
     * @param  array<int|string, Throwable>  $errors
     */
    public function __construct(protected array $results, protected array $errors)
    {
        $this->message = implode("\n---\n", array_map(function ($error) {
            return get_class($error).': '.$error->getMessage();
        }, $this->errors));
    }

    /** @return array<int|string, T> */
    public function getResults(): array
    {
        return $this->results;
    }

    /** @return array<int|string, Throwable> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
