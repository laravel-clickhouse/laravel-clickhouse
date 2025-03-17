<?php

namespace ClickHouse\Client;

use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Transports\Curl;
use InvalidArgumentException;

class TransportFactory
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
    ) {}

    public function make(string $name): Transport
    {
        return match ($name) {
            'curl' => $this->createCurlTransport(),
            default => throw new InvalidArgumentException("Unsupported transport: [{$name}]"),
        };
    }

    protected function createCurlTransport(): Transport
    {
        return new Curl($this->host, $this->port, $this->database, $this->username, $this->password);
    }
}
