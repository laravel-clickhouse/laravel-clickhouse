<?php

namespace ClickHouse\Core\Connection;

use Closure;
use LogicException;

/**
 * ClickHouse has no transactions, so every transaction entry point throws
 * the same LogicException on every bridge — one action, one exception
 * type, one message, regardless of framework.
 *
 * The failure is loud by design: the framework testing traits default
 * their transacting connections to the default connection, so a ClickHouse
 * default aborts the very first test instead of silently running without
 * isolation.
 */
trait RejectsTransactions
{
    /**
     * {@inheritDoc}
     *
     * @param  Closure(static): mixed  $callback
     * @param  int  $attempts
     *
     * @throws LogicException
     */
    public function transaction(Closure $callback, $attempts = 1): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LogicException
     */
    public function beginTransaction(): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LogicException
     */
    public function commit(): never
    {
        $this->throwUnsupportedTransaction();
    }

    /**
     * {@inheritDoc}
     *
     * @param  int|null  $toLevel
     *
     * @throws LogicException
     */
    public function rollBack($toLevel = null): never
    {
        $this->throwUnsupportedTransaction();
    }

    private function throwUnsupportedTransaction(): never
    {
        throw new LogicException('Transactions are not supported when using ClickHouse.');
    }
}
