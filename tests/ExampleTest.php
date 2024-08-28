<?php

namespace SwooleTW\ClickHouse\Tests;

use SwooleTW\ClickHouse\Example;

class ExampleTest extends TestCase
{
    public function testHello()
    {
        $this->assertEquals('world', (new Example)->hello());
    }
}
