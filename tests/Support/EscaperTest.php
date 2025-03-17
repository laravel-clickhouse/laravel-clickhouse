<?php

namespace ClickHouse\Tests\Support;

use ClickHouse\Support\Escaper;
use ClickHouse\Tests\TestCase;

class EscaperTest extends TestCase
{
    public function testNull()
    {
        $this->assertEquals('null', (new Escaper)->escape(null));
    }

    public function testInt()
    {
        $this->assertEquals('1', (new Escaper)->escape(1));
    }

    public function testFloat()
    {
        $this->assertEquals('1.1', (new Escaper)->escape(1.1));
    }

    public function testBoolean()
    {
        $this->assertEquals('1', (new Escaper)->escape(true));
        $this->assertEquals('0', (new Escaper)->escape(false));
    }

    public function testString()
    {
        $this->assertEquals("'string'", (new Escaper)->escape('string'));
        $this->assertEquals("'str\\\\ing'", (new Escaper)->escape('str\ing'));
    }

    public function testStringable()
    {
        $this->assertEquals("'stringable'", (new Escaper)->escape(new class
        {
            public function __toString()
            {
                return 'stringable';
            }
        }));
    }

    public function testArray()
    {
        $this->assertEquals('[1, 2]', (new Escaper)->escape([1, 2]));
        $this->assertEquals("['1', '2']", (new Escaper)->escape(['1', '2']));
    }
}
