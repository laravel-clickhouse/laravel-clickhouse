<?php

namespace ClickHouse\Core\Client;

use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Client\Transports\Curl;
use ClickHouse\Core\Client\Transports\Guzzle;
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
        protected ?float $connectTimeout = null,
    ) {}

    public function make(string $name, ?Session $session = null): Transport
    {
        return match ($name) {
            'curl' => $this->createCurlTransport($session),
            'guzzle' => $this->createGuzzleTransport($session),
            default => throw new InvalidArgumentException("Unsupported transport: [{$name}]"),
        };
    }

    protected function createCurlTransport(?Session $session): Transport
    {
        return new Curl(
            $this->host,
            $this->port,
            $this->database,
            $this->username,
            $this->password,
            $this->https,
            connectTimeout: $this->connectTimeout,
            session: $session,
        );
    }

    protected function createGuzzleTransport(?Session $session): Transport
    {
        return new Guzzle(
            $this->host,
            $this->port,
            $this->database,
            $this->username,
            $this->password,
            $this->https,
            connectTimeout: $this->connectTimeout,
            session: $session,
        );
    }
}
