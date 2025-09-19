<?php

namespace ClickHouse\Client\Transports;

use ClickHouse\Client\Contracts\Transport;
use ClickHouse\Client\Response;
use ClickHouse\Exceptions\ParallelQueryException;
use ClickHouse\Exceptions\QueryException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Guzzle implements Transport
{
    protected const CLICKHOUSE_ERROR_REGEX = "%Code:\s(\d+)\.\s*DB::Exception\s*:\s*(.*)(?:,\s*e\.what|\(version).*%ius";

    protected const CLICKHOUSE_CONCURRENT_REQUESTS = 10;

    protected Client $client;

    /**
     * @param  array<string, mixed>  $guzzleOptions
     */
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $database,
        protected string $username,
        protected string $password,
        protected bool $https = false,
        protected array $guzzleOptions = [],
        ?Client $client = null,
    ) {
        $this->client = $client ?? $this->getDefaultClient();
    }

    public function execute(string $sql): Response
    {
        try {
            $isSelect = $this->isSelectQuery($sql);

            $request = $this->createRequest($sql, $isSelect);
            $response = $this->client->send($request);

            return $this->parseResponse($sql, $isSelect, $response);
        } catch (RequestException $e) {
            throw new QueryException('ClickHouse request failed: '.$e->getMessage(), previous: $e);
        } catch (GuzzleException $e) {
            throw new QueryException('ClickHouse connection failed: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new QueryException($e->getMessage(), previous: $e);
        }
    }

    public function executeParallelly(array $sqls): array
    {
        $requests = array_map(fn ($sql) => $this->createRequest($sql, false), $sqls);

        /** @var array<int|string, Response> $responses */
        $responses = [];

        /** @var array<int|string, Throwable> $errors */
        $errors = [];

        $pool = new Pool($this->client, $requests, [
            'concurrency' => static::CLICKHOUSE_CONCURRENT_REQUESTS,
            'fulfilled' => function ($response, $key) use ($sqls, &$responses) {
                $responses[$key] = $this->parseResponse($sqls[$key], true, $response);
            },
            'rejected' => function ($e, $key) use ($sqls, &$responses, &$errors) {
                $response = null;

                if ($e instanceof RequestException && $e->getResponse()) {
                    $responses[$key] = $response = $this->parseResponse($sqls[$key], true, $e->getResponse());
                }

                $errors[$key] = match (true) {
                    $e instanceof RequestException => new QueryException('ClickHouse request failed: '.$e->getMessage(), $response, $e),
                    $e instanceof GuzzleException => new QueryException('ClickHouse connection failed: '.$e->getMessage(), $response, $e),
                    default => new QueryException($e->getMessage(), $response, $e),
                };
            },
        ]);

        $pool->promise()->wait();

        if (count($errors)) {
            throw new ParallelQueryException($responses, $errors);
        }

        return $responses;
    }

    protected function getDefaultClient(): Client
    {
        return new Client($this->guzzleOptions);
    }

    protected function createRequest(string $sql, bool $isSelect): Request
    {
        return new Request('POST', $this->buildRequestUri($isSelect), $this->getAuthHeaders(), $sql);
    }

    protected function buildRequestUri(bool $isSelect): string
    {
        $protocol = $this->https ? 'https' : 'http';
        $baseUrl = "{$protocol}://{$this->host}:{$this->port}/";

        $params = [
            'database' => $this->database,
            'readonly' => $isSelect ? 2 : 0,
            'default_format' => 'JSON',
        ];

        return $baseUrl.'?'.http_build_query($params);
    }

    /**
     * @return array<string, string>
     */
    protected function getAuthHeaders(): array
    {
        return [
            'X-ClickHouse-User' => $this->username,
            'X-ClickHouse-Key' => $this->password,
        ];
    }

    protected function parseResponse(string $sql, bool $isSelect, ResponseInterface $response): Response
    {
        $contentType = $response->getHeaderLine('Content-Type');
        $body = $response->getBody()->getContents();

        if (! str_contains($contentType, 'application/json') && preg_match(static::CLICKHOUSE_ERROR_REGEX, $body)) {
            throw new QueryException('ClickHouse query error: '.$body);
        }

        return new Response(
            $sql,
            $isSelect,
            $isSelect ? null : $this->parseAffectedRows($response),
            $isSelect ? $this->parseRecords($body) : null,
        );
    }

    protected function isSelectQuery(string $sql): bool
    {
        return (bool) preg_match('/^(select|with)/i', $sql) || str_contains($sql, ' union ');
    }

    protected function parseAffectedRows(ResponseInterface $response): ?int
    {
        $summaryHeader = $response->getHeaderLine('X-ClickHouse-Summary');

        if (empty($summaryHeader)) {
            return null;
        }

        $summary = json_decode($summaryHeader, true);

        if (! is_array($summary) || ! isset($summary['written_rows'])) {
            return null;
        }

        $writtenRows = $summary['written_rows'];

        return is_numeric($writtenRows) ? (int) $writtenRows : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseRecords(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            throw new QueryException('ClickHouse response parsing error: '.$body);
        }

        return $data['data'];
    }
}
