<?php

namespace ClickHouse\Tests\Laravel\Query;

use ClickHouse\Laravel\Query\Grammar;
use ClickHouse\Tests\Unit\TestCase;

class GrammarTest extends TestCase
{
    /**
     * The inherited second-precision format is a deliberate default:
     * ClickHouse 25.8 and older reject fractional seconds in every insert
     * format when the target is a second-precision DateTime column (26.x
     * merely truncates them). Models whose date columns are all DateTime64
     * opt into microseconds via $dateFormat.
     */
    public function testGetDateFormatDefaultsToSecondPrecision()
    {
        $this->assertEquals('Y-m-d H:i:s', $this->getGrammar(Grammar::class)->getDateFormat());
    }
}
