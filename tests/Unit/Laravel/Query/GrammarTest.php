<?php

namespace ClickHouse\Tests\Laravel\Query;

use ClickHouse\Laravel\Query\Grammar;
use ClickHouse\Tests\Unit\TestCase;

class GrammarTest extends TestCase
{
    public function testGetDateFormatIncludesMicroseconds()
    {
        $this->assertEquals('Y-m-d H:i:s.u', $this->getGrammar(Grammar::class)->getDateFormat());
    }
}
