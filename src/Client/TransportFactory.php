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
        protected ?float $timeout = null,
        protected ?float $connectTimeout = null,
    ) {
        if ($timeout !== null && $timeout < 0) {
            throw new InvalidArgumentException('The timeout must not be negative.');
        }

        if ($connectTimeout !== null && $connectTimeout < 0) {
            throw new InvalidArgumentException('The connect timeout must not be negative.');
        }
    }

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
        return new Curl($this->host, $this->port, $this->database, $this->username, $this->password, $this->https, sessionId: $sessionId, sessionTimeout: $sessionTimeout, timeout: $this->timeout, connectTimeout: $this->connectTimeout);
    }

    protected function createGuzzleTransport(?string $sessionId, ?int $sessionTimeout): Transport
    {
        $guzzleOptions = array_filter([
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
        ], fn ($value) => $value !== null);

        return new Guzzle($this->host, $this->port, $this->database, $this->username, $this->password, $this->https, $guzzleOptions, sessionId: $sessionId, sessionTimeout: $sessionTimeout);
    }
}
