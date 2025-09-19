<?php

namespace ClickHouse\Exceptions;

use ClickHouse\Client\Response;
use RuntimeException;
use Throwable;

class ParallelQueryException extends RuntimeException
{
    /**
     * @param  array<int|string, Response>  $responses
     * @param  array<int|string, Throwable>  $errors
     */
    public function __construct(protected array $responses, protected array $errors)
    {
        $this->message = implode("\n---\n", array_map(function ($error) {
            return get_class($error).': '.$error->getMessage();
        }, $this->errors));
    }

    /**
     * @return array<int|string, Response>
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * @return array<int|string, Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
