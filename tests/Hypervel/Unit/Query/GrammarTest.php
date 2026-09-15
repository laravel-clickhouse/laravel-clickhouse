<?php

namespace ClickHouse\Tests\Hypervel\Unit\Query;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Tests\Hypervel\Unit\TestCase;

class GrammarTest extends TestCase
{
    public function testGetDateFormatIncludesMicroseconds()
    {
        $this->assertEquals('Y-m-d H:i:s.u', (new Connection('default'))->getQueryGrammar()->getDateFormat());
    }
}
