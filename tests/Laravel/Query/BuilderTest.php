<?php

namespace SwooleTW\ClickHouse\Tests\Laravel\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Processors\Processor;
use LogicException;
use SwooleTW\ClickHouse\Laravel\Query\Builder;
use SwooleTW\ClickHouse\Laravel\Query\Grammar;
use SwooleTW\ClickHouse\Tests\TestCase;

class BuilderTest extends TestCase
{
    public function testSelect()
    {
        $this->assertEquals(
            'select * from `table`',
            $this->getBuilder()->select('*')->from('table')->toRawSql()
        );
    }

    public function testSelectDistinct()
    {
        $this->assertEquals(
            'select distinct `column` from `table`',
            $this->getBuilder()->select('column')->distinct()->from('table')->toRawSql()
        );
    }

    public function testSelectAlias()
    {
        $this->assertEquals(
            'select `column` as `alias` from `table`',
            $this->getBuilder()->select('column as alias')->from('table')->toRawSql()
        );
    }

    public function testTableWrapping()
    {
        $this->assertEquals(
            'select * from `database`.`table`',
            $this->getBuilder()->from('database.table')->toRawSql()
        );
    }

    public function testSelectFromWithSubquery()
    {
        $this->assertEquals(
            'select * from (select * from `table`)',
            $this->getBuilder()->from($this->getBuilder()->from('table'))->toRawSql()
        );
    }

    public function testSelectFromWithSubqueryAndAlias()
    {
        $this->assertEquals(
            'select * from (select * from `table`) as `alias`',
            $this->getBuilder()->from($this->getBuilder()->from('table'), 'alias')->toRawSql()
        );
    }

    public function testSelectFromFinal()
    {
        $this->assertEquals(
            'select * from `table` final',
            $this->getBuilder()->from('table', final: true)->toRawSql()
        );
    }

    public function testSelectFromFinalWithSubquery()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Select with final cannot be used with subquery.');
        $this->getBuilder()->from($this->getBuilder()->from('table'), final: true)->toRawSql();
    }

    public function testWhere()
    {
        $this->assertEquals(
            "select * from `table` where `column` = 'value'",
            $this->getBuilder()->from('table')->where('column', 'value')->toRawSql()
        );
    }

    public function testWhereArray()
    {
        $this->assertEquals(
            "select * from `table` where (`column` = 'value')",
            $this->getBuilder()->from('table')->where([['column', 'value']])->toRawSql()
        );
    }

    public function testWhereNot()
    {
        $this->assertEquals(
            "select * from `table` where not `column` = 'value'",
            $this->getBuilder()->from('table')->whereNot('column', 'value')->toRawSql()
        );
    }

    public function testWhereDate()
    {
        $this->assertEquals(
            "select * from `table` where toDate(`column`) = '2000-01-01'",
            $this->getBuilder()->from('table')->whereDate('column', '2000-01-01')->toRawSql()
        );
    }

    public function testWhereDay()
    {
        $this->assertEquals(
            'select * from `table` where toDayOfMonth(`column`) = 1',
            $this->getBuilder()->from('table')->whereDay('column', 1)->toRawSql()
        );
    }

    public function testWhereMonth()
    {
        $this->assertEquals(
            'select * from `table` where toMonth(`column`) = 1',
            $this->getBuilder()->from('table')->whereMonth('column', 1)->toRawSql()
        );
    }

    public function testWhereYear()
    {
        $this->assertEquals(
            'select * from `table` where toYear(`column`) = 2000',
            $this->getBuilder()->from('table')->whereYear('column', 2000)->toRawSql()
        );
    }

    public function testWhereTime()
    {
        $this->assertEquals(
            "select * from `table` where toTime(`column`) = toTime(toDateTime('1970-01-01 10:20:30'))",
            $this->getBuilder()->from('table')->whereTime('column', '10:20:30')->toRawSql()
        );
    }

    public function testWhereLike()
    {
        $this->assertEquals(
            "select * from `table` where `column` like 'value'",
            $this->getBuilder()->from('table')->whereLike('column', 'value')->toRawSql()
        );
    }

    public function testWhereNotLike()
    {
        $this->assertEquals(
            "select * from `table` where `column` not like 'value'",
            $this->getBuilder()->from('table')->whereNotLike('column', 'value')->toRawSql()
        );
    }

    public function testWhereBetween()
    {
        $this->assertEquals(
            'select * from `table` where `column` between 1 and 2',
            $this->getBuilder()->from('table')->whereBetween('column', [1, 2])->toRawSql()
        );
    }

    public function testWhereNotBetween()
    {
        $this->assertEquals(
            'select * from `table` where `column` not between 1 and 2',
            $this->getBuilder()->from('table')->whereNotBetween('column', [1, 2])->toRawSql()
        );
    }

    public function testWhereBetweenColumns()
    {
        $this->assertEquals(
            'select * from `table` where `column` between `from` and `to`',
            $this->getBuilder()->from('table')->whereBetweenColumns('column', ['from', 'to'])->toRawSql()
        );
    }

    public function testWhereNotBetweenColumns()
    {
        $this->assertEquals(
            'select * from `table` where `column` not between `from` and `to`',
            $this->getBuilder()->from('table')->whereNotBetweenColumns('column', ['from', 'to'])->toRawSql()
        );
    }

    public function testWhereRaw()
    {
        $this->assertEquals(
            "select * from `table` where column = 'value'",
            $this->getBuilder()->from('table')->whereRaw('column = ?', ['value'])->toRawSql()
        );
    }

    public function testWhereIn()
    {
        $this->assertEquals(
            'select * from `table` where `column` in (1, 2, 3)',
            $this->getBuilder()->from('table')->whereIn('column', [1, 2, 3])->toRawSql()
        );
    }

    public function testWhereNotIn()
    {
        $this->assertEquals(
            'select * from `table` where `column` not in (1, 2, 3)',
            $this->getBuilder()->from('table')->whereNotIn('column', [1, 2, 3])->toRawSql()
        );
    }

    public function testWhereInEmpty()
    {
        $this->assertEquals(
            'select * from `table` where 0 = 1',
            $this->getBuilder()->from('table')->whereIn('column', [])->toRawSql()
        );
    }

    public function testWhereNotInEmpty()
    {
        $this->assertEquals(
            'select * from `table` where 1 = 1',
            $this->getBuilder()->from('table')->whereNotIn('column', [])->toRawSql()
        );
    }

    public function testWhereColumn()
    {
        $this->assertEquals(
            'select * from `table` where `column_a` = `column_b`',
            $this->getBuilder()->from('table')->whereColumn('column_a', 'column_b')->toRawSql()
        );
    }

    public function testWhereColumnArray()
    {
        $this->assertEquals(
            'select * from `table` where (`column_a` = `column_b`)',
            $this->getBuilder()->from('table')->whereColumn([['column_a', 'column_b']])->toRawSql()
        );
    }

    public function testWhereAll()
    {
        $this->assertEquals(
            "select * from `table` where (`column_a` = 'value' and `column_b` = 'value')",
            $this->getBuilder()->from('table')->whereAll(['column_a', 'column_b'], 'value')->toRawSql()
        );
    }

    public function testWhereAny()
    {
        $this->assertEquals(
            "select * from `table` where (`column_a` = 'value' or `column_b` = 'value')",
            $this->getBuilder()->from('table')->whereAny(['column_a', 'column_b'], 'value')->toRawSql()
        );
    }

    public function testWhereNone()
    {
        $this->assertEquals(
            "select * from `table` where not (`column_a` = 'value' or `column_b` = 'value')",
            $this->getBuilder()->from('table')->whereNone(['column_a', 'column_b'], 'value')->toRawSql()
        );
    }

    public function testWhereNull()
    {
        $this->assertEquals(
            'select * from `table` where `column` is null',
            $this->getBuilder()->from('table')->whereNull('column')->toRawSql()
        );
    }

    public function testWhereNotNull()
    {
        $this->assertEquals(
            'select * from `table` where `column` is not null',
            $this->getBuilder()->from('table')->whereNotNull('column')->toRawSql()
        );
    }

    public function testWhereNullArray()
    {
        $this->assertEquals(
            'select * from `table` where `column_a` is null and `column_b` is null',
            $this->getBuilder()->from('table')->whereNull(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testWhereNotNullArray()
    {
        $this->assertEquals(
            'select * from `table` where `column_a` is not null and `column_b` is not null',
            $this->getBuilder()->from('table')->whereNotNull(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testWhereExists()
    {
        $this->assertEquals(
            'select * from `table_a` where exists (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->whereExists(fn ($query) => $query->from('table_b'))->toRawSql()
        );
    }

    public function testWhereEmpty()
    {
        $this->assertEquals(
            'select * from `table` where empty(`column`)',
            $this->getBuilder()->from('table')->whereEmpty('column')->toRawSql()
        );
    }

    public function testWhereEmptyWithArray()
    {
        $this->assertEquals(
            'select * from `table` where empty(`column_a`) and empty(`column_b`)',
            $this->getBuilder()->from('table')->whereEmpty(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testWhereNotEmpty()
    {
        $this->assertEquals(
            'select * from `table` where not empty(`column`)',
            $this->getBuilder()->from('table')->whereNotEmpty('column')->toRawSql()
        );
    }

    public function testOrWhereEmpty()
    {
        $this->assertEquals(
            'select * from `table` where empty(`column_a`) or empty(`column_b`)',
            $this->getBuilder()->from('table')->whereEmpty('column_a')->orWhereEmpty('column_b')->toRawSql()
        );
    }

    public function testOrWhereNotEmpty()
    {
        $this->assertEquals(
            'select * from `table` where not empty(`column_a`) or not empty(`column_b`)',
            $this->getBuilder()->from('table')->whereNotEmpty('column_a')->orWhereNotEmpty('column_b')->toRawSql()
        );
    }

    public function testGroupBy()
    {
        $this->assertEquals(
            'select * from `table` group by `column`',
            $this->getBuilder()->from('table')->groupBy('column')->toRawSql()
        );
    }

    public function testGroupByArray()
    {
        $this->assertEquals(
            'select * from `table` group by `column_a`, `column_b`',
            $this->getBuilder()->from('table')->groupBy(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testOrderby()
    {
        $this->assertEquals(
            'select * from `table` order by `column_a` asc, `column_b` desc',
            $this->getBuilder()->from('table')->orderBy('column_a')->orderBy('column_b', 'desc')->toRawSql()
        );
    }

    public function testInRandomOrder()
    {
        $this->assertEquals(
            'select * from `table` order by randCanonical()',
            $this->getBuilder()->from('table')->inRandomOrder()->toRawSql()
        );
    }

    public function testHaving()
    {
        $this->assertEquals(
            "select * from `table` having `column` = 'value'",
            $this->getBuilder()->from('table')->having('column', 'value')->toRawSql()
        );
    }

    public function testHavingBetween()
    {
        $this->assertEquals(
            'select * from `table` having `column` between 1 and 2',
            $this->getBuilder()->from('table')->havingBetween('column', [1, 2])->toRawSql()
        );
    }

    public function testHavingNull()
    {
        $this->assertEquals(
            'select * from `table` having `column` is null',
            $this->getBuilder()->from('table')->havingNull('column')->toRawSql()
        );
    }

    public function testHavingNotNull()
    {
        $this->assertEquals(
            'select * from `table` having `column` is not null',
            $this->getBuilder()->from('table')->havingNotNull('column')->toRawSql()
        );
    }

    public function testHavingRaw()
    {
        $this->assertEquals(
            "select * from `table` having column = 'value'",
            $this->getBuilder()->from('table')->havingRaw('column = ?', ['value'])->toRawSql()
        );
    }

    public function testHavingEmpty()
    {
        $this->assertEquals(
            'select * from `table` having empty(`column`)',
            $this->getBuilder()->from('table')->havingEmpty('column')->toRawSql()
        );
    }

    public function testHavingEmptyWithArray()
    {
        $this->assertEquals(
            'select * from `table` having empty(`column_a`) and empty(`column_b`)',
            $this->getBuilder()->from('table')->havingEmpty(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testHavingNotEmpty()
    {
        $this->assertEquals(
            'select * from `table` having not empty(`column`)',
            $this->getBuilder()->from('table')->havingNotEmpty('column')->toRawSql()
        );
    }

    public function testOrHavingEmpty()
    {
        $this->assertEquals(
            'select * from `table` having empty(`column_a`) or empty(`column_b`)',
            $this->getBuilder()->from('table')->havingEmpty('column_a')->orHavingEmpty('column_b')->toRawSql()
        );
    }

    public function testOrHavingNotEmpty()
    {
        $this->assertEquals(
            'select * from `table` having not empty(`column_a`) or not empty(`column_b`)',
            $this->getBuilder()->from('table')->havingNotEmpty('column_a')->orHavingNotEmpty('column_b')->toRawSql()
        );
    }

    public function testLimitAndOffset()
    {
        $this->assertEquals(
            'select * from `table` limit 1 offset 2',
            $this->getBuilder()->from('table')->limit(1)->offset(2)->toRawSql()
        );
    }

    public function testJoin()
    {
        $this->assertEquals(
            'select * from `table_a` inner join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->join('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` inner join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->joinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testInnerJoin()
    {
        $this->assertEquals(
            'select * from `table_a` inner join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->join('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testInnerJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` inner join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->joinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testInnerAnyJoin()
    {
        $this->assertEquals(
            'select * from `table_a` inner any join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->innerAnyJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testInnerAnyJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` inner any join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->innerAnyJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testLeftJoin()
    {
        $this->assertEquals(
            'select * from `table_a` left join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->leftJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testLeftAnyJoin()
    {
        $this->assertEquals(
            'select * from `table_a` left any join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->leftAnyJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testLeftAnyJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` left any join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->leftAnyJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testRightJoin()
    {
        $this->assertEquals(
            'select * from `table_a` right join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testRightAnyJoin()
    {
        $this->assertEquals(
            'select * from `table_a` right any join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightAnyJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testRightAnyJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` right any join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightAnyJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testCrossJoin()
    {
        $this->assertEquals(
            'select * from `table_a` cross join `table_b`',
            $this->getBuilder()->from('table_a')->crossJoin('table_b')->toRawSql()
        );
    }

    public function testFullJoin()
    {
        $this->assertEquals(
            'select * from `table_a` full join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->fullJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testFullJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` full join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->fullJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testSemiJoin()
    {
        $this->assertEquals(
            'select * from `table_a` semi join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->semiJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testSemiJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` semi join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->semiJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testRightSemiJoin()
    {
        $this->assertEquals(
            'select * from `table_a` right semi join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightSemiJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testRightSemiJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` right semi join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightSemiJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testAntiJoin()
    {
        $this->assertEquals(
            'select * from `table_a` anti join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->antiJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testAntiJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` anti join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->antiJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testRightAntiJoin()
    {
        $this->assertEquals(
            'select * from `table_a` right anti join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightAntiJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testRightAntiJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` right anti join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->rightAntiJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testAsofJoin()
    {
        $this->assertEquals(
            'select * from `table_a` asof join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->asofJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testAsofJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` asof join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->asofJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testLeftAsofJoin()
    {
        $this->assertEquals(
            'select * from `table_a` left asof join `table_b` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->leftAsofJoin('table_b', 'table_a.column_a', 'table_b.column_b')->toRawSql()
        );
    }

    public function testLeftAsofJoinSub()
    {
        $this->assertEquals(
            'select * from `table_a` left asof join (select * from `table_b`) as `alias` on `table_a`.`column_a` = `table_b`.`column_b`',
            $this->getBuilder()->from('table_a')->leftAsofJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias',
                fn ($query) => $query->on('table_a.column_a', '=', 'table_b.column_b')
            )->toRawSql()
        );
    }

    public function testArrayJoin()
    {
        $this->assertEquals(
            'select * from `table` array join `column`',
            $this->getBuilder()->from('table')->arrayJoin('column')->toRawSql()
        );
    }

    public function testArrayJoinWithArray()
    {
        $this->assertEquals(
            'select * from `table` array join `column_a`, `column_b`',
            $this->getBuilder()->from('table')->arrayJoin(['column_a', 'column_b'])->toRawSql()
        );
    }

    public function testArrayJoinWithAlias()
    {
        $this->assertEquals(
            'select *, `alias` from `table` array join `column` as `alias`',
            $this->getBuilder()->from('table')->arrayJoin('column', 'alias')->toRawSql()
        );
    }

    public function testMultipleArrayJoins()
    {
        $this->assertEquals(
            'select * from `table` array join `column_a`, `column_b`',
            $this->getBuilder()->from('table')->arrayJoin('column_a')->arrayJoin('column_b')->toRawSql()
        );
    }

    public function testArrayJoinWithArrayAndAlias()
    {
        $this->assertEquals(
            'select *, `alias_a`, `alias_b` from `table` array join `column_a` as `alias_a`, `column_b` as `alias_b`, `column_c`',
            $this->getBuilder()->from('table')->arrayJoin([
                'alias_a' => 'column_a',
                'alias_b' => 'column_b',
                'column_c',
            ])->toRawSql()
        );
    }

    public function testArrayJoinWithSubQuery()
    {
        $this->assertEquals(
            'select *, `alias` from `table_a` array join (select * from `table_b`) as `alias`',
            $this->getBuilder()->from('table_a')->arrayJoin(
                $this->getBuilder()->from('table_b'),
                'alias'
            )->toRawSql()
        );
    }

    public function testArrayJoinWithSubQueryButNoAliasProvided()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Array join with subquery must have an alias.');
        $this->getBuilder()->from('table_a')->arrayJoin($this->getBuilder()->from('table_b'))->toRawSql();
    }

    public function testArrayJoinWithArrayAndSubQuery()
    {
        $this->assertEquals(
            'select *, `alias` from `table_a` array join (select * from `table_b`) as `alias`',
            $this->getBuilder()->from('table_a')->arrayJoin([
                'alias' => $this->getBuilder()->from('table_b'),
            ])->toRawSql()
        );
    }

    public function testArrayJoinSub()
    {
        $this->assertEquals(
            'select *, `alias` from `table_a` array join (select * from `table_b`) as `alias`',
            $this->getBuilder()->from('table_a')->arrayJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias'
            )->toRawSql()
        );
    }

    public function testLeftArrayJoin()
    {
        $this->assertEquals(
            'select * from `table` left array join `column`',
            $this->getBuilder()->from('table')->leftArrayJoin('column')->toRawSql()
        );
    }

    public function testLeftArrayJoinSub()
    {
        $this->assertEquals(
            'select *, `alias` from `table_a` left array join (select * from `table_b`) as `alias`',
            $this->getBuilder()->from('table_a')->leftArrayJoinSub(
                $this->getBuilder()->from('table_b'),
                'alias'
            )->toRawSql()
        );
    }

    public function testUseArrayJoinAndLeftArrayJoinAtTheSameTime()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use array join and left array join at the same time.');
        $this->getBuilder()->from('table')->arrayJoin('column_a')->leftArrayJoin('column_b')->toRawSql();
    }

    public function testCount()
    {
        $expectedSql = 'select count(*) as aggregate from `table`';
        $this->getBuilder(select: $expectedSql)->from('table')->count();
    }

    public function testExists()
    {
        $expectedSql = 'select exists(select * from `table`) as `exists`';
        // NOTE: alias no function here, clickhouse's bug?
        $results = [['in(1, _subquery155282)' => 1]];
        $this->assertTrue($this->getBuilder(select: $expectedSql, result: $results)->from('table')->exists());
    }

    public function testDoesntExist()
    {
        $expectedSql = 'select exists(select * from `table`) as `exists`';
        // NOTE: alias no function here, clickhouse's bug?
        $results = [['in(1, _subquery155282)' => 1]];
        $this->assertFalse($this->getBuilder(select: $expectedSql, result: $results)->from('table')->doesntExist());
    }

    public function testMin()
    {
        $expectedSql = 'select min(`column`) as aggregate from `table`';
        $this->getBuilder(select: $expectedSql)->from('table')->min('column');
    }

    public function testMax()
    {
        $expectedSql = 'select max(`column`) as aggregate from `table`';
        $this->getBuilder(select: $expectedSql)->from('table')->max('column');
    }

    public function testSum()
    {
        $expectedSql = 'select sum(`column`) as aggregate from `table`';
        $this->getBuilder(select: $expectedSql)->from('table')->sum('column');
    }

    public function testAvg()
    {
        $expectedSql = 'select avg(`column`) as aggregate from `table`';
        $this->getBuilder(select: $expectedSql)->from('table')->avg('column');
    }

    public function testUnion()
    {
        $this->assertEquals(
            '(select * from `table_a`) union (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->union($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testUnionAll()
    {
        $this->assertEquals(
            '(select * from `table_a`) union all (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->unionAll($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testUnionDistinct()
    {
        $this->assertEquals(
            '(select * from `table_a`) union distinct (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->unionDistinct($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testUnionWithJoin()
    {
        $this->assertEquals(
            '(select * from `table_a`) union all (select * from `table_b` inner join `table_c` on `table_b`.`column_b` = `table_c`.`column_c`)',
            $this->getBuilder()->from('table_a')->unionAll(
                $this->getBuilder()->from('table_b')->join('table_c', fn ($join) => $join->on('table_b.column_b', '=', 'table_c.column_c'))
            )->toRawSql()
        );
    }

    public function testUnionWithAllAndDistinct()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use all and distinct at the same time.');
        $this->getBuilder()->from('table_a')->union($this->getBuilder()->from('table_b'), all: true, distinct: true)->toRawSql();
    }

    public function testUnionWithAggregate()
    {
        $expectedSql = 'select count(*) as aggregate from ((select * from `table_a`) union (select * from `table_b`)) as `temp_table`';
        $this->getBuilder(select: $expectedSql)->from('table_a')->union($this->getBuilder()->from('table_b'))->count();
    }

    public function testIntersect()
    {
        $this->assertEquals(
            '(select * from `table_a`) intersect (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->intersect($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testIntersectDistinct()
    {
        $this->assertEquals(
            '(select * from `table_a`) intersect distinct (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->intersectDistinct($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testExcept()
    {
        $this->assertEquals(
            '(select * from `table_a`) except (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->except($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testExceptDistinct()
    {
        $this->assertEquals(
            '(select * from `table_a`) except distinct (select * from `table_b`)',
            $this->getBuilder()->from('table_a')->exceptDistinct($this->getBuilder()->from('table_b'))->toRawSql()
        );
    }

    public function testWithQuery()
    {
        $this->assertEquals(
            "with 'value' as `alias` select * from `table`",
            $this->getBuilder()->withQuery('value', 'alias')->from('table')->toRawSql()
        );
    }

    public function testWithQueryWithScalarSubquery()
    {
        $this->assertEquals(
            'with (select * from `table_a`) as `alias` select * from `table_b`',
            $this->getBuilder()->withQuery(
                $this->getBuilder()->from('table_a'),
                'alias'
            )->from('table_b')->toRawSql()
        );
    }

    public function testWithQueryRaw()
    {
        $this->assertEquals(
            "with 'value' as `alias` select * from `table`",
            $this->getBuilder()->withQueryRaw('?', 'alias', ['value'])->from('table')->toRawSql()
        );
    }

    public function testWithQuerySub()
    {
        $this->assertEquals(
            'with `alias` as (select * from `table_a`) select * from `table_b`',
            $this->getBuilder()->withQuerySub(
                $this->getBuilder()->from('table_a'),
                'alias'
            )->from('table_b')->toRawSql()
        );
    }

    public function testWithQuerySubWithBindings()
    {
        $this->assertEquals(
            "with `alias` as (select * from `table_a` where `column_a` = 'value_a') select * from `table_b` where `column_b` = 'value_b'",
            $this->getBuilder()->withQuerySub(
                $this->getBuilder()->from('table_a')->where('column_a', 'value_a'),
                'alias'
            )->from('table_b')->where('column_b', 'value_b')->toRawSql()
        );
    }

    public function testWithQuerySubWithRecursive()
    {
        $this->assertEquals(
            'with recursive `alias` as (select * from `table_a`) select * from `table_b`',
            $this->getBuilder()->withQuerySub(
                $this->getBuilder()->from('table_a'),
                'alias',
                true
            )->from('table_b')->toRawSql()
        );
    }

    public function testWithQueryRecursive()
    {
        $this->assertEquals(
            'with recursive `alias` as (select * from `table_a`) select * from `table_b`',
            $this->getBuilder()->withQueryRecursive(
                $this->getBuilder()->from('table_a'),
                'alias'
            )->from('table_b')->toRawSql()
        );
    }

    public function testSettings()
    {
        $this->assertEquals(
            "select * from `table` settings `key` = 'value'",
            $this->getBuilder()->from('table')->settings('key', 'value')->toRawSql()
        );
    }

    public function testSettingsWithArray()
    {
        $this->assertEquals(
            "select * from `table` settings `key` = 'value'",
            $this->getBuilder()->from('table')->settings(['key' => 'value'])->toRawSql()
        );
    }

    public function testSettingsWithoutValue()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Value is required for settings.');
        $this->getBuilder()->from('table')->settings('key')->toRawSql();
    }

    public function testMultipleSettings()
    {
        $this->assertEquals(
            "select * from `table` settings `key_a` = 'value_a', `key_b` = 'value_b'",
            $this->getBuilder()->from('table')->settings('key_a', 'value_a')->settings(['key_b' => 'value_b'])->toRawSql()
        );
    }

    public function testDuplicateSettings()
    {
        $this->assertEquals(
            "select * from `table` settings `key` = 'value_b'",
            $this->getBuilder()->from('table')->settings('key', 'value_a')->settings('key', 'value_b')->toRawSql()
        );
    }

    public function testInsert()
    {
        $expectedSql = 'insert into `table` (`column`) values (?)';
        $bindings = ['value'];
        $this->getBuilder(insert: $expectedSql, bindings: $bindings)->from('table')->insert(['column' => 'value']);
    }

    public function testInsertMultiple()
    {
        $expectedSql = 'insert into `table` (`column`) values (?), (?)';
        $bindings = ['value_1', 'value_2'];
        $this->getBuilder(insert: $expectedSql, bindings: $bindings)->from('table')->insert([['column' => 'value_1'], ['column' => 'value_2']]);
    }

    public function testInsertGetId()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support insert get id.');
        $this->getBuilder()->from('table')->insertGetId(['column' => 'value']);
    }

    public function testUpdate()
    {
        $expectedSql = 'alter table `table` update `column` = ? where `column` = ?';
        $bindings = ['value_b', 'value_a'];
        $this->getBuilder(update: $expectedSql, bindings: $bindings)->from('table')->where('column', 'value_a')->update(['column' => 'value_b']);
    }

    public function testUpdateWithJoin()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support update with join, please use joinGet or dictGet instead.');
        $this->getBuilder()->from('table_a')->crossJoin('table_b')->update(['column' => 'value']);
    }

    public function testUpsert()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support upsert.');
        $this->getBuilder()->from('table')->upsert([['column' => 'value']], 'column');
    }

    public function testDelete()
    {
        $expectedSql = 'alter table `table` delete where `column` = ?';
        $bindings = ['value'];
        $builder = $this->getBuilder(delete: $expectedSql, bindings: $bindings);
        $builder->getConnection()->shouldReceive('getConfig')->with('use_lightweight_delete')->once()->andReturn(null);
        $builder->from('table')->where('column', 'value')->delete();
    }

    public function testLightweightDelete()
    {
        $expectedSql = 'delete from `table` where `column` = ?';
        $bindings = ['value'];
        $builder = $this->getBuilder(delete: $expectedSql, bindings: $bindings);
        $builder->from('table')->where('column', 'value')->delete(lightweight: true);
    }

    public function testLightweightDeleteWithConfig()
    {
        $expectedSql = 'delete from `table` where `column` = ?';
        $bindings = ['value'];
        $builder = $this->getBuilder(delete: $expectedSql, bindings: $bindings);
        $builder->getConnection()->shouldReceive('getConfig')->with('use_lightweight_delete')->once()->andReturn(true);
        $builder->from('table')->where('column', 'value')->delete();
    }

    public function testDeleteWithJoin()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support delete with join.');
        $this->getBuilder()->from('table_a')->crossJoin('table_b')->delete();
    }

    public function testTruncate()
    {
        $expectedSql = 'truncate table `table`';
        $bindings = [];
        $builder = $this->getBuilder();
        $builder->getConnection()->shouldReceive('statement')->with($expectedSql, $bindings)->once()->andReturn(true);
        $builder->from('table')->truncate();
    }

    public function testLock()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support locking feature.');
        $this->getBuilder()->from('table')->lock();
    }

    public function testUseIndex()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support specify indexes, please use preWhere instead.');
        $this->getBuilder()->from('table')->useIndex('index');
    }

    public function testForceIndex()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support specify indexes, please use preWhere instead.');
        $this->getBuilder()->from('table')->forceIndex('index');
    }

    public function testIgnoreIndex()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ClickHouse does not support specify indexes.');
        $this->getBuilder()->from('table')->ignoreIndex('index');
    }

    private function getBuilder(
        ?string $select = null,
        ?string $insert = null,
        ?string $update = null,
        ?string $delete = null,
        array $bindings = [],
        mixed $result = null
    ) {
        $connection = $this->getConnection($select, $insert, $update, $delete, $bindings, $result);
        $grammar = (new Grammar)->setConnection($connection);
        $processor = $this->getProcessor();

        return new Builder($connection, $grammar, $processor);
    }

    private function getConnection(
        ?string $select = null,
        ?string $insert = null,
        ?string $update = null,
        ?string $delete = null,
        array $bindings = [],
        mixed $result = null
    ) {
        return $this->mock(
            Connection::class,
            function ($connection) use ($select, $insert, $update, $delete, $bindings, $result) {
                $connection->shouldReceive('getDatabaseName')->andReturn('database');
                $connection->shouldReceive('prepareBindings')->andReturnUsing(fn ($bindings) => $bindings);
                $connection->shouldReceive('escape')->andReturnUsing(fn ($value) => is_string($value) ? "'{$value}'" : $value);

                if ($select) {
                    $connection->shouldReceive('select')->with($select, $bindings, true)->once()->andReturn($result);
                }

                if ($insert) {
                    $connection->shouldReceive('insert')->with($insert, $bindings)->once()->andReturn($result);
                }

                if ($update) {
                    $connection->shouldReceive('update')->with($update, $bindings)->once()->andReturn($result);
                }

                if ($delete) {
                    $connection->shouldReceive('delete')->with($delete, $bindings)->once()->andReturn($result ?? 1);
                }
            }
        );
    }

    private function getProcessor()
    {
        return $this->mock(Processor::class, function ($processor) {
            $processor->shouldReceive('processSelect')->andReturnUsing(fn ($_, $results) => $results);
        });
    }
}
