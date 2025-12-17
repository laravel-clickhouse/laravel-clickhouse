<?php

namespace ClickHouse\Tests\Laravel\Schema;

use ClickHouse\Laravel\Connection;
use ClickHouse\Laravel\Schema\Blueprint;
use ClickHouse\Laravel\Schema\Grammar;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use RuntimeException;

class GrammarTest extends TestCase
{
    public function testBasicCreateTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id')->primary();
        $blueprint->string('email');

        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255), PRIMARY KEY (id)) ENGINE = MergeTree()', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->uuid('id')->primary();

        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->andReturn(null);

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UUID, PRIMARY KEY (id)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testAutoIncrement()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->increments('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddColumnsWithMultipleAutoIncrementStartingValue()
    {
        $blueprint = new Blueprint('users');
        $blueprint->id()->from(100);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testEngineCreateTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');
        $blueprint->engine('MergeTree');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');

        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->once()->with('engine')->andReturn('MergeTree');

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree', $statements[0]);
    }

    public function testBasicCreateTableWithPrefix()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->andReturn(null);

        $statements = $blueprint->toSql($conn, $grammar);

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE prefix_users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testCreateTemporaryTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->temporary();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');

        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->andReturn(null);

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TEMPORARY TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testDropTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->drop();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE users', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->drop()->sync();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE users SYNC', $statements[0]);
    }

    public function testDropTableIfExists()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropIfExists();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE IF EXISTS users', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dropIfExists()->sync();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE IF EXISTS users SYNC', $statements[0]);
    }

    public function testDropColumn()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropColumn('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dropColumn(['foo', 'bar']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN bar', $statements[1]);

        $blueprint = new Blueprint('users');
        $blueprint->dropColumn('foo', 'bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN bar', $statements[1]);
    }

    public function testDropPrimary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropPrimary();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testDropUnique()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropUnique('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testDropIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropIndex('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users DROP INDEX foo', $statements[0]);
    }

    public function testDropSpatialIndex()
    {
        $blueprint = new Blueprint('geo');
        $blueprint->dropSpatialIndex(['coordinates']);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testDropForeign()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropForeign('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testDropTimestamps()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropTimestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN created_at', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN updated_at', $statements[1]);
    }

    public function testDropTimestampsTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropTimestampsTz();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN created_at', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN updated_at', $statements[1]);
    }

    public function testDropMorphs()
    {
        $blueprint = new Blueprint('photos');
        $blueprint->dropMorphs('imageable');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(3, $statements);
        $this->assertSame('ALTER TABLE photos DROP INDEX photos_imageable_type_imageable_id_index', $statements[0]);
        $this->assertSame('ALTER TABLE photos DROP COLUMN imageable_type', $statements[1]);
        $this->assertSame('ALTER TABLE photos DROP COLUMN imageable_id', $statements[2]);
    }

    public function testRenameTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rename('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('RENAME TABLE users TO foo', $statements[0]);
    }

    public function testRenameIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->renameIndex('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingPrimaryKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->primary('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingUniqueKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->unique('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->index(['foo', 'bar'], 'baz');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIndexWithAlgorithm()
    {
        $blueprint = new Blueprint('users');
        $blueprint->index('column', 'name', 'bloom_filter');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name column TYPE bloom_filter GRANULARITY 1', $statements[0]);
    }

    public function testAddingIndexWithMultipleColumns()
    {
        $blueprint = new Blueprint('users');
        $blueprint->index(['foo', 'bar'], 'name', 'bloom_filter');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIndexWithAlgorithmAndGranularity()
    {
        $blueprint = new Blueprint('users');
        $blueprint->index('column', 'name', 'bloom_filter')->granularity(10);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name column TYPE bloom_filter GRANULARITY 10', $statements[0]);
    }

    public function testAddingFulltextIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->fulltext('body');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingSpatialIndex()
    {
        $blueprint = new Blueprint('geo');
        $blueprint->spatialIndex('coordinates');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingFluentSpatialIndex()
    {
        $blueprint = new Blueprint('geo');
        $blueprint->geometry('coordinates', 'point')->spatialIndex();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingRawIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rawIndex('raw_index', 'name');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name raw_index', $statements[0]);
    }

    public function testAddingForeignKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->increments('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingSmallIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->smallIncrements('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingBigIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->bigIncrements('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingForeignID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreignId('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UInt64', $statements[0]);
    }

    public function testAddingForeignIdWithConstraint()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreignId('foo')->constrained();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingForeignIdWithRefenences()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreignId('foo')->references('bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingColumnInTableFirst()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('name')->first();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN name FixedString(255) FIRST', $statements[0]);
    }

    public function testAddingColumnAfterAnotherColumn()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('name')->after('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN name FixedString(255) AFTER foo', $statements[0]);
    }

    public function testAddingMultipleColumnsAfterAnotherColumn()
    {
        $blueprint = new Blueprint('users');
        $blueprint->after('foo', function ($blueprint) {
            $blueprint->string('one');
            $blueprint->string('two');
        });
        $blueprint->string('three');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN one FixedString(255) AFTER foo',
            'ALTER TABLE users ADD COLUMN two FixedString(255) AFTER one',
            'ALTER TABLE users ADD COLUMN three FixedString(255)',
        ], $statements);
    }

    public function testAddingGeneratedColumn()
    {
        $blueprint = new Blueprint('products');
        $blueprint->integer('price');
        $blueprint->integer('discounted_virtual')->virtualAs('price - 5');
        $blueprint->integer('discounted_stored')->storedAs('price - 5');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE products ADD COLUMN price Int32',
            'ALTER TABLE products ADD COLUMN discounted_virtual Int32 ALIAS price - 5',
            'ALTER TABLE products ADD COLUMN discounted_stored Int32 MATERIALIZED price - 5',
        ], $statements);
    }

    public function testAddingGeneratedColumnByExpression()
    {
        $blueprint = new Blueprint('products');
        $blueprint->integer('price');
        $blueprint->integer('discounted_virtual')->virtualAs(new Expression('price - 5'));
        $blueprint->integer('discounted_stored')->storedAs(new Expression('price - 5'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE products ADD COLUMN price Int32',
            'ALTER TABLE products ADD COLUMN discounted_virtual Int32 ALIAS price - 5',
            'ALTER TABLE products ADD COLUMN discounted_stored Int32 MATERIALIZED price - 5',
        ], $statements);
    }

    public function testAddingInvisibleColumn()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('secret', 64)->nullable(false)->invisible();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingString()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(255)', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(100)', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100)->nullable()->default('bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT 'bar'", $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100)->nullable()->default(new Expression('now()'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT now()', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100)->nullable()->default(Foo::BAR);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT 'bar'", $statements[0]);
    }

    public function testAddingText()
    {
        $blueprint = new Blueprint('users');
        $blueprint->text('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo String', $statements[0]);
    }

    public function testAddingBigInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->bigInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int64', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->bigInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->integer('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int32', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->integer('foo')->nullable()->default(0);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Nullable(Int32) DEFAULT 0', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->integer('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIncrementsWithStartingValues()
    {
        $blueprint = new Blueprint('users');
        $blueprint->id()->startingValue(1000);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingMediumInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->mediumInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int32', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->mediumInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingSmallInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->smallInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int16', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->smallInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTinyInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->tinyInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int8', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->tinyInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingFloat()
    {
        $blueprint = new Blueprint('users');
        $blueprint->float('foo', 5);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Float32', $statements[0]);
    }

    public function testAddingDouble()
    {
        $blueprint = new Blueprint('users');
        $blueprint->double('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Float64', $statements[0]);
    }

    public function testAddingDecimal()
    {
        $blueprint = new Blueprint('users');
        $blueprint->decimal('foo', 5, 2);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Decimal(5, 2)', $statements[0]);
    }

    public function testAddingBoolean()
    {
        $blueprint = new Blueprint('users');
        $blueprint->boolean('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Bool', $statements[0]);
    }

    public function testAddingEnum()
    {
        $blueprint = new Blueprint('users');
        $blueprint->enum('role', ['member', 'admin']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN role Enum('member', 'admin')", $statements[0]);
    }

    public function testAddingSet()
    {
        $blueprint = new Blueprint('users');
        $blueprint->set('role', ['member', 'admin']);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingJson()
    {
        $blueprint = new Blueprint('users');
        $blueprint->json('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingJsonb()
    {
        $blueprint = new Blueprint('users');
        $blueprint->jsonb('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingDate()
    {
        $blueprint = new Blueprint('users');
        $blueprint->date('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Date', $statements[0]);
    }

    public function testAddingYear()
    {
        $blueprint = new Blueprint('users');
        $blueprint->year('birth_year');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingDateTime()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(1)', $statements[0]);
    }

    public function testAddingDateTimeWithDefaultCurrent()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo')->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime DEFAULT now()', $statements[0]);
    }

    public function testAddingDateTimeWithOnUpdateCurrent()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo')->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingDateTimeWithDefaultCurrentAndOnUpdateCurrent()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo')->useCurrent()->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingDateTimeWithDefaultCurrentAndPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo', 3)->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(3) DEFAULT now64(3)', $statements[0]);
    }

    public function testAddingDateTimeTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTimeTz('foo', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(1)', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dateTimeTz('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime', $statements[0]);
    }

    public function testAddingTime()
    {
        $blueprint = new Blueprint('users');
        $blueprint->time('created_at');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimeWithPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->time('created_at', 1);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimeTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timeTz('created_at');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimeTzWithPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timeTz('created_at', 1);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimestamp()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime', $statements[0]);
    }

    public function testAddingTimestampWithPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1)', $statements[0]);
    }

    public function testAddingTimestampWithDefault()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at')->default('2015-07-22 11:43:17');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN created_at DateTime DEFAULT '2015-07-22 11:43:17'", $statements[0]);
    }

    public function testAddingTimestampWithDefaultCurrentSpecifyingPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1) DEFAULT now64(1)', $statements[0]);
    }

    public function testAddingTimestampWithOnUpdateCurrentSpecifyingPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimestampWithDefaultCurrentAndOnUpdateCurrentSpecifyingPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrent()->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingTimestampTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestampTz('created_at');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime', $statements[0]);
    }

    public function testAddingTimestampTzWithPrecision()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestampTz('created_at', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1)', $statements[0]);
    }

    public function testAddingTimestampTzWithDefault()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestampTz('created_at')->default('2015-07-22 11:43:17');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN created_at DateTime DEFAULT '2015-07-22 11:43:17'", $statements[0]);
    }

    public function testAddingTimestamps()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(2, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN created_at Nullable(DateTime)',
            'ALTER TABLE users ADD COLUMN updated_at Nullable(DateTime)',
        ], $statements);
    }

    public function testAddingTimestampsTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestampsTz();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(2, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN created_at Nullable(DateTime)',
            'ALTER TABLE users ADD COLUMN updated_at Nullable(DateTime)',
        ], $statements);
    }

    public function testAddingRememberToken()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rememberToken();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN remember_token Nullable(FixedString(100))', $statements[0]);
    }

    public function testAddingBinary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->binary('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo String', $statements[0]);
    }

    public function testAddingUuid()
    {
        $blueprint = new Blueprint('users');
        $blueprint->uuid('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UUID', $statements[0]);
    }

    public function testAddingUuidDefaultsColumnName()
    {
        $blueprint = new Blueprint('users');
        $blueprint->uuid();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN uuid UUID', $statements[0]);
    }

    public function testAddingForeignUuid()
    {
        $blueprint = new Blueprint('users');
        $foreignUuid = $blueprint->foreignUuid('foo');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertInstanceOf(ForeignIdColumnDefinition::class, $foreignUuid);
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UUID', $statements[0]);
    }

    public function testAddingForeignUuidWithConstraint()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreignUuid('foo')->constrained();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingForeignUuidWithRefenences()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreignUuid('foo')->references('bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    public function testAddingIpAddress()
    {
        $blueprint = new Blueprint('users');
        $blueprint->ipAddress('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(45)', $statements[0]);
    }

    public function testAddingIpAddressDefaultsColumnName()
    {
        $blueprint = new Blueprint('users');
        $blueprint->ipAddress();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN ip_address FixedString(45)', $statements[0]);
    }

    public function testAddingMacAddress()
    {
        $blueprint = new Blueprint('users');
        $blueprint->macAddress('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(17)', $statements[0]);
    }

    public function testAddingMacAddressDefaultsColumnName()
    {
        $blueprint = new Blueprint('users');
        $blueprint->macAddress();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN mac_address FixedString(17)', $statements[0]);
    }

    // public function testAddingGeometry()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometry not null', $statements[0]);
    // }
    //
    // public function testAddingGeography()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geography('coordinates');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometry srid 4326 not null', $statements[0]);
    // }
    //
    // public function testAddingPoint()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'point');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point not null', $statements[0]);
    // }
    //
    // public function testAddingPointWithSrid()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'point', 4326);
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point srid 4326 not null', $statements[0]);
    // }
    //
    // public function testAddingPointWithSridColumn()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'point', 4326)->after('id');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point srid 4326 not null after `id`', $statements[0]);
    // }
    //
    // public function testAddingLineString()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'linestring');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` linestring not null', $statements[0]);
    // }
    //
    // public function testAddingPolygon()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'polygon');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` polygon not null', $statements[0]);
    // }
    //
    // public function testAddingGeometryCollection()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'geometrycollection');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometrycollection not null', $statements[0]);
    // }
    //
    // public function testAddingMultiPoint()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'multipoint');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multipoint not null', $statements[0]);
    // }
    //
    // public function testAddingMultiLineString()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'multilinestring');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multilinestring not null', $statements[0]);
    // }
    //
    // public function testAddingMultiPolygon()
    // {
    //     $blueprint = new Blueprint('geo');
    //     $blueprint->geometry('coordinates', 'multipolygon');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multipolygon not null', $statements[0]);
    // }

    public function testAddingComment()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('foo')->comment("Escape ' when using words like it's");

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar());
    }

    // public function testAddingVector()
    // {
    //     $blueprint = new Blueprint('embeddings');
    //     $blueprint->vector('embedding', 384);
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `embeddings` add `embedding` vector(384) not null', $statements[0]);
    // }

    public function testCreateDatabase()
    {
        $connection = $this->getConnection();

        $statement = $this->getGrammar()->compileCreateDatabase('my_database_a', $connection);

        $this->assertSame('CREATE DATABASE my_database_a', $statement);
    }

    public function testCreateTableWithVirtualAsColumn()
    {
        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->string('my_column');
        $blueprint->string('my_other_column')->virtualAs('my_column');

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (my_column FixedString(255), my_other_column FixedString(255) ALIAS my_column) ENGINE = MergeTree()', $statements[0]);

        // $blueprint = new Blueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->virtualAsJson('my_json_column->some_attribute');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar());
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\"'))))", $statements[0]);

        // $blueprint = new Blueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->virtualAsJson('my_json_column->some_attribute->nested');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar());
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\".\"nested\"'))))", $statements[0]);
    }

    // public function testCreateTableWithVirtualAsColumnWhenJsonColumnHasArrayKey()
    // {
    //     $blueprint = new Blueprint('users');
    //     $blueprint->create();
    //     $blueprint->string('my_json_column')->virtualAsJson('my_json_column->foo[0][1]');
    //
    //     $conn = $this->getConnection();
    //     $conn->shouldReceive('getConfig')->andReturn(null);
    //
    //     $statements = $blueprint->toSql($conn, $this->getGrammar());
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame("create table `users` (`my_json_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"foo\"[0][1]'))))", $statements[0]);
    // }

    public function testCreateTableWithStoredAsColumn()
    {
        $conn = $this->getConnection();
        $conn->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->string('my_column');
        $blueprint->string('my_other_column')->storedAs('my_column');

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (my_column FixedString(255), my_other_column FixedString(255) MATERIALIZED my_column) ENGINE = MergeTree()', $statements[0]);

        // $blueprint = new Blueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->storedAsJson('my_json_column->some_attribute');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar());
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\"'))) stored)", $statements[0]);
        //
        // $blueprint = new Blueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->storedAsJson('my_json_column->some_attribute->nested');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar());
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\".\"nested\"'))) stored)", $statements[0]);
    }

    public function testDropDatabaseIfExists()
    {
        $statement = $this->getGrammar()->compileDropDatabaseIfExists('my_database_a');

        $this->assertSame(
            'DROP DATABASE IF EXISTS my_database_a',
            $statement
        );

        $statement = $this->getGrammar()->compileDropDatabaseIfExists('my_database_b');

        $this->assertSame(
            'DROP DATABASE IF EXISTS my_database_b',
            $statement
        );
    }

    public function testGrammarsAreMacroable()
    {
        // compileReplace macro.
        $this->getGrammar()::macro('compileReplace', function () {
            return true;
        });

        $c = $this->getGrammar()::compileReplace();

        $this->assertTrue($c);
    }

    public function testEngineCreateTableWithParams()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine("ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}', updated_at)");

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame("CREATE TABLE users (id UInt32) ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}', updated_at)", $statements[0]);
    }

    public function testPartitionByCreateTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree');
        $blueprint->partitionBy('toYYYYMM(created_at)');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree PARTITION BY toYYYYMM(created_at)', $statements[0]);
    }

    public function testOrderByCreateTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree');
        $blueprint->orderBy(['id', 'email']);

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree ORDER BY (id, email)', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree');
        $blueprint->orderBy('id', 'email');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree ORDER BY (id, email)', $statements[0]);
    }

    public function testAddingLowCardinality()
    {
        $blueprint = new Blueprint('users');
        $blueprint->text('foo')->lowCardinality();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo LowCardinality(String)', $statements[0]);
    }

    public function testAddingArray()
    {
        $blueprint = new Blueprint('users');
        $blueprint->array('foo', 'UInt32');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Array(UInt32)', $statements[0]);
    }

    private function getConnection()
    {
        return $this->mock(Connection::class);
    }

    private function getGrammar()
    {
        return new Grammar;
    }
}

enum Foo: string
{
    case BAR = 'bar';
}
