<?php

namespace ClickHouse\Exceptions;

use ClickHouse\Client\Response;
use RuntimeException;
use Throwable;

class QueryException extends RuntimeException
{
    public function __construct(string $message, protected ?Response $result = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getResult(): ?Response
    {
        return $this->result;
    }
}
