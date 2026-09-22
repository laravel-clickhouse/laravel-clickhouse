<?php

namespace ClickHouse\Tests\Core\Unit\Client\Concerns;

use ReflectionProperty;

trait InspectsTransportClients
{
    /**
     * Read a transport's inner HTTP client off its protected property —
     * the only way to observe what the factory wired in.
     */
    private function innerClient(object $transport): mixed
    {
        return (new ReflectionProperty($transport::class, 'client'))->getValue($transport);
    }
}
