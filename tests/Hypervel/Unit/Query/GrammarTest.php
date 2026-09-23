<?php

namespace ClickHouse\Tests\Hypervel\Unit\Query;

use ClickHouse\Hypervel\Connection;
use ClickHouse\Tests\Hypervel\Unit\TestCase;

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
        $this->assertEquals('Y-m-d H:i:s', (new Connection('default'))->getQueryGrammar()->getDateFormat());
    }
}
