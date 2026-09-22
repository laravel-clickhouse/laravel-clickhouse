<?php

namespace ClickHouse\Client;

use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Transports\Curl;
use ClickHouse\Client\Transports\Guzzle;
use InvalidArgumentException;

class TransportFactory
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected bool $https = false,
    ) {}

    public function make(string $name, ?string $sessionId = null, ?int $sessionTimeout = null): Transport
    {
        return match ($name) {
            'curl' => $this->createCurlTransport($sessionId, $sessionTimeout),
            'guzzle' => $this->createGuzzleTransport($sessionId, $sessionTimeout),
            default => throw new InvalidArgumentException("Unsupported transport: [{$name}]"),
        };
    }

    protected function createCurlTransport(?string $sessionId, ?int $sessionTimeout): Transport
    {
        return new Curl($this->host, $this->port, $this->database, $this->username, $this->password, $this->https, sessionId: $sessionId, sessionTimeout: $sessionTimeout);
    }

    protected function createGuzzleTransport(?string $sessionId, ?int $sessionTimeout): Transport
    {
        return new Guzzle($this->host, $this->port, $this->database, $this->username, $this->password, $this->https, sessionId: $sessionId, sessionTimeout: $sessionTimeout);
    }
}
