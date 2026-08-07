<?php

namespace ClickHouse\Core\Client\Contracts;

use ClickHouse\Core\Client\Response;
use ClickHouse\Core\Exceptions\ParallelQueryException;

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
