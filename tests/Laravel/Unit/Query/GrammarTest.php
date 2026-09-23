<?php

namespace ClickHouse\Tests\Laravel\Query;

use ClickHouse\Laravel\Query\Grammar;
use ClickHouse\Tests\Laravel\Unit\TestCase;

class GrammarTest extends TestCase
{
    /**
     * The inherited second-precision format is a deliberate default: older
     * ClickHouse versions reject fractional seconds when Eloquent-stringified
     * date attributes are inserted into a DateTime column. Models persisting
     * into DateTime64 columns opt into microseconds via $dateFormat.
     */
    public function testGetDateFormatDefaultsToSecondPrecision()
    {
        $this->assertEquals('Y-m-d H:i:s', $this->getGrammar(Grammar::class)->getDateFormat());
    }
}
