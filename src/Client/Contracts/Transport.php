<?php

namespace ClickHouse\Client\Contracts;

use ClickHouse\Client\Response;
use ClickHouse\Exceptions\ParallelQueryException;

interface Transport
{
    public function execute(string $sql): Response;

    /**
     * @param  array<int|string, string>  $sql
     * @return array<int|string, array<string, mixed>[]>
     *
     * @throws ParallelQueryException<array<string, mixed>[]>
     */
    public function executeParallelly(array $sql): array;
}
