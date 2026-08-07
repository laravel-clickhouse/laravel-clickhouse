<?php

namespace ClickHouse\Core\Schema;

/**
 * ClickHouse-specific schema builder behaviour shared by every framework
 * bridge. The using class must extend its framework's schema Builder,
 * whose build()/createBlueprint()/getTables()/getViews() API this trait
 * relies on.
 */
trait BuildsClickHouseSchema
{
    /**
     * Drop a table from the schema synchronously, waiting for the drop to
     * propagate across all replicas before returning.
     */
    public function dropSync(string $table): void
    {
        $blueprint = $this->createBlueprint($table);
        $blueprint->drop()->sync();

        $this->build($blueprint);
    }

    /**
     * Drop a table from the schema if it exists, synchronously waiting for
     * the drop to propagate across all replicas before returning.
     */
    public function dropIfExistsSync(string $table): void
    {
        $blueprint = $this->createBlueprint($table);
        $blueprint->dropIfExists()->sync();

        $this->build($blueprint);
    }

    /**
     * {@inheritDoc}
     *
     * ClickHouse has no foreign key mechanism. Both this and disableForeignKeyConstraints()
     * are no-ops so that DatabaseTruncation and other traits which wrap work in
     * withoutForeignKeyConstraints() do not invoke the unimplemented
     * compileEnable/DisableForeignKeyConstraints grammar methods.
     */
    public function enableForeignKeyConstraints(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function disableForeignKeyConstraints(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function dropAllTables(): void
    {
        $tables = array_column($this->getTables(), 'name');

        if (empty($tables)) {
            return;
        }

        foreach ($tables as $table) {
            $this->connection->statement(
                'DROP TABLE IF EXISTS '.$this->grammar->wrapTable($table).' SYNC'
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function dropAllViews(): void
    {
        $views = array_column($this->getViews(), 'name');

        if (empty($views)) {
            return;
        }

        foreach ($views as $view) {
            $this->connection->statement(
                'DROP VIEW IF EXISTS '.$this->grammar->wrapTable($view).' SYNC'
            );
        }
    }
}
