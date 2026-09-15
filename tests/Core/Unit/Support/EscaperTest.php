<?php

namespace ClickHouse\Tests\Core\Unit\Support;

use ClickHouse\Core\Support\Escaper;
use ClickHouse\Tests\Core\Unit\TestCase;
use DateTime;
use DateTimeImmutable;
use RuntimeException;

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

    public function testDateTimeWithoutMicroseconds()
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s', '2024-01-02 03:04:05');

        $this->assertEquals("'2024-01-02 03:04:05'", (new Escaper)->escape($date));
    }

    public function testDateTimePreservesMicroseconds()
    {
        $date = DateTime::createFromFormat('Y-m-d H:i:s.u', '2024-01-02 03:04:05.123456');

        $this->assertEquals("'2024-01-02 03:04:05.123456'", (new Escaper)->escape($date));
    }

    public function testDateTimeImmutablePreservesMicroseconds()
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', '2024-01-02 03:04:05.000001');

        $this->assertEquals("'2024-01-02 03:04:05.000001'", (new Escaper)->escape($date));
    }

    public function testArray()
    {
        $this->assertEquals('[1, 2]', (new Escaper)->escape([1, 2]));
        $this->assertEquals("['1', '2']", (new Escaper)->escape(['1', '2']));
    }

    public function testBinary()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database connection does not support escaping binary values.');

        (new Escaper)->escape('binary-data', true);
    }

    /**
     * escapeArray() must propagate the $binary flag to each element instead
     * of silently falling back to plain string escaping, so binary values
     * nested in an array fail the same way a top-level binary value does.
     */
    public function testArrayPropagatesBinaryFlagToElements()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database connection does not support escaping binary values.');

        (new Escaper)->escape(['binary-data'], true);
    }

    public function testEscapeArrayPropagatesBinaryFlagToElements()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The database connection does not support escaping binary values.');

        (new Escaper)->escapeArray(['binary-data'], true);
    }
}
