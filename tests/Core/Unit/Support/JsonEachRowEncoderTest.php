<?php

namespace ClickHouse\Tests\Core\Unit\Support;

use Carbon\Carbon;
use ClickHouse\Core\Support\JsonEachRowEncoder;
use ClickHouse\Tests\Core\Unit\TestCase;
use JsonException;
use RuntimeException;

class JsonEachRowEncoderTest extends TestCase
{
    public function testScalars()
    {
        $this->assertEquals(
            '{"int":1,"float":1.5,"string":"value","null":null}',
            (new JsonEachRowEncoder)->encode([['int' => 1, 'float' => 1.5, 'string' => 'value', 'null' => null]])
        );
    }

    public function testMultipleRowsAreNewlineDelimited()
    {
        $this->assertEquals(
            '{"id":1}'."\n".'{"id":2}',
            (new JsonEachRowEncoder)->encode([['id' => 1], ['id' => 2]])
        );
    }

    public function testBoolean()
    {
        $this->assertEquals(
            '{"active":true,"deleted":false}',
            (new JsonEachRowEncoder)->encode([['active' => true, 'deleted' => false]])
        );
    }

    public function testDateTime()
    {
        $this->assertEquals(
            '{"created_at":"2026-07-29 12:34:56"}',
            (new JsonEachRowEncoder)->encode([['created_at' => Carbon::parse('2026-07-29 12:34:56')]])
        );
    }

    public function testDateTimePreservesMicroseconds()
    {
        $this->assertEquals(
            '{"created_at":"2026-07-29 12:34:56.123456"}',
            (new JsonEachRowEncoder)->encode([['created_at' => Carbon::parse('2026-07-29 12:34:56.123456')]])
        );
    }

    public function testBackedEnum()
    {
        $this->assertEquals(
            '{"status":"published"}',
            (new JsonEachRowEncoder)->encode([['status' => JsonEachRowEncoderTestBackedEnum::Published]])
        );
    }

    public function testUnitEnum()
    {
        $this->assertEquals(
            '{"status":"Draft"}',
            (new JsonEachRowEncoder)->encode([['status' => JsonEachRowEncoderTestUnitEnum::Draft]])
        );
    }

    public function testStringable()
    {
        $this->assertEquals(
            '{"value":"stringable"}',
            (new JsonEachRowEncoder)->encode([['value' => new class
            {
                public function __toString()
                {
                    return 'stringable';
                }
            }]])
        );
    }

    public function testNestedArray()
    {
        $this->assertEquals(
            '{"tags":["a","b"],"matrix":[[1,2],[3,4]],"dates":["2026-07-29 00:00:00"]}',
            (new JsonEachRowEncoder)->encode([[
                'tags' => ['a', 'b'],
                'matrix' => [[1, 2], [3, 4]],
                'dates' => [Carbon::parse('2026-07-29')],
            ]])
        );
    }

    public function testUnicodeIsNotEscaped()
    {
        $this->assertEquals(
            '{"name":"héllo 👋 / slash"}',
            (new JsonEachRowEncoder)->encode([['name' => 'héllo 👋 / slash']])
        );
    }

    public function testInvalidUtf8Throws()
    {
        $this->expectException(JsonException::class);

        (new JsonEachRowEncoder)->encode([['name' => "\xB1\x31"]]);
    }

    public function testUnsupportedObjectThrows()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported value type.');

        (new JsonEachRowEncoder)->encode([['value' => new \stdClass]]);
    }
}

enum JsonEachRowEncoderTestBackedEnum: string
{
    case Published = 'published';
}

enum JsonEachRowEncoderTestUnitEnum
{
    case Draft;
}
