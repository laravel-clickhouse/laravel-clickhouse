<?php

namespace ClickHouse\Tests\Core\Unit\Client;

use ClickHouse\Core\Client\Session;
use ClickHouse\Tests\Core\Unit\TestCase;
use LogicException;

class SessionTest extends TestCase
{
    public function testStartGeneratesAUniqueHexIdentifier()
    {
        $first = Session::start(120);
        $second = Session::start(120);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(120, $first->timeout);
    }

    public function testRejectsNonPositiveTimeout()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The ClickHouse session timeout must be greater than zero.');

        new Session('session-id', 0);
    }
}
