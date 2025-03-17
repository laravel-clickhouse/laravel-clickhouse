<?php

namespace ClickHouse\Client;

class Response
{
    /**
     * @param  array<string, mixed>[]|null  $records
     */
    public function __construct(
        protected string $sql,
        protected bool $isSelect,
        protected ?int $affectedRows = null,
        protected ?array $records = null,
    ) {}

    public function getAffectedRows(): ?int
    {
        return $this->affectedRows;
    }

    /**
     * @return array<string, mixed>[]|null
     */
    public function getRecords(): ?array
    {
        return $this->records;
    }
}
