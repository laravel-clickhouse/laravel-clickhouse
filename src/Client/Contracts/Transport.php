<?php

namespace ClickHouse\Client\Contracts;

use ClickHouse\Client\Response;
use ClickHouse\Exceptions\ParallelQueryException;

interface Transport
{
    public function execute(string $sql): Response;

    /**
     * @param  array<int|string, string>  $sql
     * @return array<int|string, Response>
     *
     * @throws ParallelQueryException
     */
    public function executeParallelly(array $sql): array;
}
