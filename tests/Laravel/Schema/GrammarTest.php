<?php

namespace ClickHouse\Tests\Laravel\Schema;

use ClickHouse\Laravel\Schema\Blueprint;
use ClickHouse\Laravel\Schema\Grammar;
use ClickHouse\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use RuntimeException;

class GrammarTest extends TestCase
{
    public function testBasicCreateTable()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->unsignedInteger('id')->primary();
        $blueprint->string('email');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255), PRIMARY KEY (id)) ENGINE = MergeTree()', $statements[0]);

        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->uuid('id')->primary();

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UUID, PRIMARY KEY (id)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testAutoIncrement()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->create();
        $blueprint->increments('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddColumnsWithMultipleAutoIncrementStartingValue()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->id()->from(100);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testEngineCreateTable()
    {
        $connection = $this->getConnection();

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');
        $blueprint->engine('MergeTree()');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);

        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->once()->with('engine')->andReturn('MergeTree()');

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testBasicCreateTableWithPrefix()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');

        $grammar = $this->getGrammar(Grammar::class, $connection);
        $grammar->setTablePrefix('prefix_');

        $statements = $blueprint->toSql($connection, $grammar);

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE prefix_users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testCreateTemporaryTable()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->temporary();
        $blueprint->unsignedInteger('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TEMPORARY TABLE users (id UInt32, email FixedString(255)) ENGINE = MergeTree()', $statements[0]);
    }

    public function testDropTable()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->drop();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE users', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->drop()->sync();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE users SYNC', $statements[0]);
    }

    public function testDropTableIfExists()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropIfExists();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE IF EXISTS users', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->dropIfExists()->sync();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('DROP TABLE IF EXISTS users SYNC', $statements[0]);
    }

    public function testDropColumn()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropColumn('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->dropColumn(['foo', 'bar']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN bar', $statements[1]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->dropColumn('foo', 'bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN foo', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN bar', $statements[1]);
    }

    public function testDropPrimary()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropPrimary();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testDropUnique()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropUnique('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testDropIndex()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropIndex('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users DROP INDEX foo', $statements[0]);
    }

    public function testDropSpatialIndex()
    {
        $blueprint = $this->getBlueprint('geo');
        $blueprint->dropSpatialIndex(['coordinates']);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testDropForeign()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropForeign('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testDropTimestamps()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropTimestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN created_at', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN updated_at', $statements[1]);
    }

    public function testDropTimestampsTz()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dropTimestampsTz();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(2, $statements);
        $this->assertSame('ALTER TABLE users DROP COLUMN created_at', $statements[0]);
        $this->assertSame('ALTER TABLE users DROP COLUMN updated_at', $statements[1]);
    }

    public function testDropMorphs()
    {
        $blueprint = $this->getBlueprint('photos');
        $blueprint->dropMorphs('imageable');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(3, $statements);
        $this->assertSame('ALTER TABLE photos DROP INDEX photos_imageable_type_imageable_id_index', $statements[0]);
        $this->assertSame('ALTER TABLE photos DROP COLUMN imageable_type', $statements[1]);
        $this->assertSame('ALTER TABLE photos DROP COLUMN imageable_id', $statements[2]);
    }

    public function testRenameTable()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->rename('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('RENAME TABLE users TO foo', $statements[0]);
    }

    public function testRenameIndex()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->renameIndex('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingPrimaryKey()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->primary('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingUniqueKey()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->unique('foo', 'bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIndex()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->index(['foo', 'bar'], 'baz');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIndexWithAlgorithm()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->index('column', 'name', 'bloom_filter');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name column TYPE bloom_filter GRANULARITY 1', $statements[0]);
    }

    public function testAddingIndexWithMultipleColumns()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->index(['foo', 'bar'], 'name', 'bloom_filter');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIndexWithAlgorithmAndGranularity()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->index('column', 'name', 'bloom_filter')->granularity(10);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name column TYPE bloom_filter GRANULARITY 10', $statements[0]);
    }

    public function testAddingFulltextIndex()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->fulltext('body');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingSpatialIndex()
    {
        $blueprint = $this->getBlueprint('geo');
        $blueprint->spatialIndex('coordinates');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingFluentSpatialIndex()
    {
        $blueprint = $this->getBlueprint('geo');
        $blueprint->geometry('coordinates', 'point')->spatialIndex();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingRawIndex()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->rawIndex('raw_index', 'name');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD INDEX name raw_index', $statements[0]);
    }

    public function testAddingForeignKey()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIncrementingID()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->increments('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingSmallIncrementingID()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->smallIncrements('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingBigIncrementingID()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->bigIncrements('id');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingID()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->id();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingForeignID()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreignId('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UInt64', $statements[0]);
    }

    public function testAddingForeignIdWithConstraint()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreignId('foo')->constrained();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingForeignIdWithRefenences()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreignId('foo')->references('bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingColumnInTableFirst()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->string('name')->first();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN name FixedString(255) FIRST', $statements[0]);
    }

    public function testAddingColumnAfterAnotherColumn()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->string('name')->after('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN name FixedString(255) AFTER foo', $statements[0]);
    }

    public function testAddingMultipleColumnsAfterAnotherColumn()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->after('foo', function ($blueprint) {
            $blueprint->string('one');
            $blueprint->string('two');
        });
        $blueprint->string('three');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN one FixedString(255) AFTER foo',
            'ALTER TABLE users ADD COLUMN two FixedString(255) AFTER one',
            'ALTER TABLE users ADD COLUMN three FixedString(255)',
        ], $statements);
    }

    public function testAddingGeneratedColumn()
    {
        $blueprint = $this->getBlueprint('products');
        $blueprint->integer('price');
        $blueprint->integer('discounted_virtual')->virtualAs('price - 5');
        $blueprint->integer('discounted_stored')->storedAs('price - 5');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE products ADD COLUMN price Int32',
            'ALTER TABLE products ADD COLUMN discounted_virtual Int32 ALIAS price - 5',
            'ALTER TABLE products ADD COLUMN discounted_stored Int32 MATERIALIZED price - 5',
        ], $statements);
    }

    public function testAddingGeneratedColumnByExpression()
    {
        $blueprint = $this->getBlueprint('products');
        $blueprint->integer('price');
        $blueprint->integer('discounted_virtual')->virtualAs(new Expression('price - 5'));
        $blueprint->integer('discounted_stored')->storedAs(new Expression('price - 5'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(3, $statements);
        $this->assertSame([
            'ALTER TABLE products ADD COLUMN price Int32',
            'ALTER TABLE products ADD COLUMN discounted_virtual Int32 ALIAS price - 5',
            'ALTER TABLE products ADD COLUMN discounted_stored Int32 MATERIALIZED price - 5',
        ], $statements);
    }

    public function testAddingInvisibleColumn()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->string('secret', 64)->nullable(false)->invisible();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingString()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(255)', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo', 100);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(100)', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo', 100)->nullable()->default('bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT 'bar'", $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo', 100)->nullable()->default(new Expression('now()'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT now()', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo', 100)->nullable()->default(Foo::BAR);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN foo Nullable(FixedString(100)) DEFAULT 'bar'", $statements[0]);
    }

    public function testAddingText()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->text('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo String', $statements[0]);
    }

    public function testAddingBigInteger()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->bigInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int64', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->bigInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingInteger()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->integer('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int32', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->integer('foo')->nullable()->default(0);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Nullable(Int32) DEFAULT 0', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->integer('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIncrementsWithStartingValues()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->id()->startingValue(1000);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingMediumInteger()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->mediumInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int32', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->mediumInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingSmallInteger()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->smallInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int16', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->smallInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTinyInteger()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->tinyInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Int8', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->tinyInteger('foo', true);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingFloat()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->float('foo', 5);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Float32', $statements[0]);
    }

    public function testAddingDouble()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->double('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Float64', $statements[0]);
    }

    public function testAddingDecimal()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->decimal('foo', 5, 2);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Decimal(5, 2)', $statements[0]);
    }

    public function testAddingBoolean()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->boolean('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Bool', $statements[0]);
    }

    public function testAddingEnum()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->enum('role', ['member', 'admin']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN role Enum('member', 'admin')", $statements[0]);
    }

    public function testAddingSet()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->set('role', ['member', 'admin']);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingJson()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->json('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingJsonb()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->jsonb('foo');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingDate()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->date('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Date', $statements[0]);
    }

    public function testAddingYear()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->year('birth_year');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingDateTime()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(1)', $statements[0]);
    }

    public function testAddingDateTimeWithDefaultCurrent()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo')->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime DEFAULT now()', $statements[0]);
    }

    public function testAddingDateTimeWithOnUpdateCurrent()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo')->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingDateTimeWithDefaultCurrentAndOnUpdateCurrent()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo')->useCurrent()->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingDateTimeWithDefaultCurrentAndPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTime('foo', 3)->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(3) DEFAULT now64(3)', $statements[0]);
    }

    public function testAddingDateTimeTz()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTimeTz('foo', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime64(1)', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->dateTimeTz('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo DateTime', $statements[0]);
    }

    public function testAddingTime()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->time('created_at');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimeWithPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->time('created_at', 1);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimeTz()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timeTz('created_at');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimeTzWithPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timeTz('created_at', 1);

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimestamp()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime', $statements[0]);
    }

    public function testAddingTimestampWithPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1)', $statements[0]);
    }

    public function testAddingTimestampWithDefault()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at')->default('2015-07-22 11:43:17');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN created_at DateTime DEFAULT '2015-07-22 11:43:17'", $statements[0]);
    }

    public function testAddingTimestampWithDefaultCurrentSpecifyingPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrent();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1) DEFAULT now64(1)', $statements[0]);
    }

    public function testAddingTimestampWithOnUpdateCurrentSpecifyingPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimestampWithDefaultCurrentAndOnUpdateCurrentSpecifyingPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamp('created_at', 1)->useCurrent()->useCurrentOnUpdate();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingTimestampTz()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestampTz('created_at');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime', $statements[0]);
    }

    public function testAddingTimestampTzWithPrecision()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestampTz('created_at', 1);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN created_at DateTime64(1)', $statements[0]);
    }

    public function testAddingTimestampTzWithDefault()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestampTz('created_at')->default('2015-07-22 11:43:17');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(1, $statements);
        $this->assertSame("ALTER TABLE users ADD COLUMN created_at DateTime DEFAULT '2015-07-22 11:43:17'", $statements[0]);
    }

    public function testAddingTimestamps()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(2, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN created_at Nullable(DateTime)',
            'ALTER TABLE users ADD COLUMN updated_at Nullable(DateTime)',
        ], $statements);
    }

    public function testAddingTimestampsTz()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->timestampsTz();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
        $this->assertCount(2, $statements);
        $this->assertSame([
            'ALTER TABLE users ADD COLUMN created_at Nullable(DateTime)',
            'ALTER TABLE users ADD COLUMN updated_at Nullable(DateTime)',
        ], $statements);
    }

    public function testAddingRememberToken()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->rememberToken();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN remember_token Nullable(FixedString(100))', $statements[0]);
    }

    public function testAddingBinary()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->binary('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo String', $statements[0]);
    }

    public function testAddingUuid()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->uuid('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UUID', $statements[0]);
    }

    public function testAddingUuidDefaultsColumnName()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->uuid();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN uuid UUID', $statements[0]);
    }

    public function testAddingForeignUuid()
    {
        $blueprint = $this->getBlueprint('users');
        $foreignUuid = $blueprint->foreignUuid('foo');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertInstanceOf(ForeignIdColumnDefinition::class, $foreignUuid);
        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo UUID', $statements[0]);
    }

    public function testAddingForeignUuidWithConstraint()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreignUuid('foo')->constrained();

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingForeignUuidWithRefenences()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->foreignUuid('foo')->references('bar');

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    public function testAddingIpAddress()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->ipAddress('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(45)', $statements[0]);
    }

    public function testAddingIpAddressDefaultsColumnName()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->ipAddress();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN ip_address FixedString(45)', $statements[0]);
    }

    public function testAddingMacAddress()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->macAddress('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo FixedString(17)', $statements[0]);
    }

    public function testAddingMacAddressDefaultsColumnName()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->macAddress();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN mac_address FixedString(17)', $statements[0]);
    }

    // public function testAddingGeometry()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometry not null', $statements[0]);
    // }
    //
    // public function testAddingGeography()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geography('coordinates');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometry srid 4326 not null', $statements[0]);
    // }
    //
    // public function testAddingPoint()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'point');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point not null', $statements[0]);
    // }
    //
    // public function testAddingPointWithSrid()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'point', 4326);
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point srid 4326 not null', $statements[0]);
    // }
    //
    // public function testAddingPointWithSridColumn()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'point', 4326)->after('id');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` point srid 4326 not null after `id`', $statements[0]);
    // }
    //
    // public function testAddingLineString()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'linestring');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` linestring not null', $statements[0]);
    // }
    //
    // public function testAddingPolygon()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'polygon');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` polygon not null', $statements[0]);
    // }
    //
    // public function testAddingGeometryCollection()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'geometrycollection');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` geometrycollection not null', $statements[0]);
    // }
    //
    // public function testAddingMultiPoint()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'multipoint');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multipoint not null', $statements[0]);
    // }
    //
    // public function testAddingMultiLineString()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'multilinestring');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multilinestring not null', $statements[0]);
    // }
    //
    // public function testAddingMultiPolygon()
    // {
    //     $blueprint = $this->getBlueprint('geo');
    //     $blueprint->geometry('coordinates', 'multipolygon');
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `geo` add `coordinates` multipolygon not null', $statements[0]);
    // }

    public function testAddingComment()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->string('foo')->comment("Escape ' when using words like it's");

        $this->expectException(RuntimeException::class);

        $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    }

    // public function testAddingVector()
    // {
    //     $blueprint = $this->getBlueprint('embeddings');
    //     $blueprint->vector('embedding', 384);
    //     $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame('alter table `embeddings` add `embedding` vector(384) not null', $statements[0]);
    // }

    public function testCreateDatabase()
    {
        $connection = $this->getConnection();

        $statement = $this->getGrammar(Grammar::class)->compileCreateDatabase('my_database_a', $connection);

        $this->assertSame('CREATE DATABASE my_database_a', $statement);
    }

    public function testCreateTableWithVirtualAsColumn()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->string('my_column');
        $blueprint->string('my_other_column')->virtualAs('my_column');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (my_column FixedString(255), my_other_column FixedString(255) ALIAS my_column) ENGINE = MergeTree()', $statements[0]);

        // $blueprint = $this->getBlueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->virtualAsJson('my_json_column->some_attribute');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\"'))))", $statements[0]);

        // $blueprint = $this->getBlueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->virtualAsJson('my_json_column->some_attribute->nested');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\".\"nested\"'))))", $statements[0]);
    }

    // public function testCreateTableWithVirtualAsColumnWhenJsonColumnHasArrayKey()
    // {
    //     $blueprint = $this->getBlueprint('users');
    //     $blueprint->create();
    //     $blueprint->string('my_json_column')->virtualAsJson('my_json_column->foo[0][1]');
    //
    //     $conn = $this->getConnection();
    //     $conn->shouldReceive('getConfig')->andReturn(null);
    //
    //     $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));
    //
    //     $this->assertCount(1, $statements);
    //     $this->assertSame("create table `users` (`my_json_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"foo\"[0][1]'))))", $statements[0]);
    // }

    public function testCreateTableWithStoredAsColumn()
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->once()->with('engine')->andReturn(null);

        $blueprint = $this->getBlueprint('users', $connection);
        $blueprint->create();
        $blueprint->string('my_column');
        $blueprint->string('my_other_column')->storedAs('my_column');

        $statements = $blueprint->toSql($connection, $this->getGrammar(Grammar::class, $connection));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (my_column FixedString(255), my_other_column FixedString(255) MATERIALIZED my_column) ENGINE = MergeTree()', $statements[0]);

        // $blueprint = $this->getBlueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->storedAsJson('my_json_column->some_attribute');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\"'))) stored)", $statements[0]);
        //
        // $blueprint = $this->getBlueprint('users');
        // $blueprint->create();
        // $blueprint->string('my_json_column');
        // $blueprint->string('my_other_column')->storedAsJson('my_json_column->some_attribute->nested');
        //
        // $conn = $this->getConnection();
        // $conn->shouldReceive('getConfig')->andReturn(null);
        //
        // $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));
        //
        // $this->assertCount(1, $statements);
        // $this->assertSame("create table `users` (`my_json_column` varchar(255) not null, `my_other_column` varchar(255) as (json_unquote(json_extract(`my_json_column`, '$.\"some_attribute\".\"nested\"'))) stored)", $statements[0]);
    }

    public function testDropDatabaseIfExists()
    {
        $statement = $this->getGrammar(Grammar::class)->compileDropDatabaseIfExists('my_database_a');

        $this->assertSame(
            'DROP DATABASE IF EXISTS my_database_a',
            $statement
        );

        $statement = $this->getGrammar(Grammar::class)->compileDropDatabaseIfExists('my_database_b');

        $this->assertSame(
            'DROP DATABASE IF EXISTS my_database_b',
            $statement
        );
    }

    public function testGrammarsAreMacroable()
    {
        // compileReplace macro.
        $this->getGrammar(Grammar::class)::macro('compileReplace', function () {
            return true;
        });

        $c = $this->getGrammar(Grammar::class)::compileReplace();

        $this->assertTrue($c);
    }

    public function testEngineCreateTableWithParams()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine("ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}', updated_at)");

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame("CREATE TABLE users (id UInt32) ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}', updated_at)", $statements[0]);
    }

    public function testPartitionByCreateTable()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree()');
        $blueprint->partitionBy('toYYYYMM(created_at)');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree() PARTITION BY toYYYYMM(created_at)', $statements[0]);
    }

    public function testOrderByCreateTable()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree()');
        $blueprint->orderBy(['id', 'email']);

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree() ORDER BY (id, email)', $statements[0]);

        $blueprint = $this->getBlueprint('users');
        $blueprint->create();
        $blueprint->unsignedInteger('id');
        $blueprint->engine('MergeTree()');
        $blueprint->orderBy('id', 'email');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('CREATE TABLE users (id UInt32) ENGINE = MergeTree() ORDER BY (id, email)', $statements[0]);
    }

    public function testAddingLowCardinality()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->text('foo')->lowCardinality();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo LowCardinality(String)', $statements[0]);
    }

    public function testAddingArray()
    {
        $blueprint = $this->getBlueprint('users');
        $blueprint->array('foo', 'UInt32');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar(Grammar::class));

        $this->assertCount(1, $statements);
        $this->assertSame('ALTER TABLE users ADD COLUMN foo Array(UInt32)', $statements[0]);
    }

    private function getBlueprint(string $table, ?Connection $connection = null): Blueprint
    {
        return new Blueprint($connection ?? $this->getConnection(), $table);
    }
}

enum Foo: string
{
    case BAR = 'bar';
}
