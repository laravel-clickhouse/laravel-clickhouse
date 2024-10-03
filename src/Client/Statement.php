<?php

namespace SwooleTW\ClickHouse\Client;

class Statement
{
    /**
     * @var mixed[]
     */
    protected array $bindings = [];

    protected Response $response;

    public function __construct(
        protected Client $client,
        protected string $query,
    ) {}

    public function bindValue(int $index, mixed $value): bool
    {
        $this->bindings[$index] = $value;

        return true;
    }

    public function execute(): bool
    {
        $this->response = $this->client->getTransport()->execute($this->toRawSql());

        return true;
    }

    /**
     * @return array<string, mixed>[]|null
     */
    public function fetchAll(): ?array
    {
        return $this->response->getRecords();
    }

    public function rowCount(): ?int
    {
        return $this->response->getAffectedRows();
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function toRawSql(): string
    {
        $bindings = array_map(fn ($value) => $this->escape($value), $this->bindings);

        $query = '';

        $isStringLiteral = false;

        for ($i = 0; $i < strlen($this->query); $i++) {
            $char = $this->query[$i];
            // @phpstan-ignore-next-line
            $nextChar = $sql[$i + 1] ?? null;

            // Single quotes can be escaped as '' according to the SQL standard while
            // MySQL uses \'. Postgres has operators like ?| that must get encoded
            // in PHP like ??|. We should skip over the escaped characters here.
            if (in_array($char.$nextChar, ["\'", "''", '??'])) {
                $query .= $char.$nextChar;
                $i += 1;
            } elseif ($char === "'") { // Starting / leaving string literal...
                $query .= $char;
                $isStringLiteral = ! $isStringLiteral;
            } elseif ($char === '?' && ! $isStringLiteral) { // Substitutable binding...
                $query .= array_shift($bindings) ?? '?';
            } else { // Normal character...
                $query .= $char;
            }
        }

        return $query;
    }

    protected function escape(mixed $value): string
    {
        return $this->client->getEscaper()->escape($value);
    }
}
