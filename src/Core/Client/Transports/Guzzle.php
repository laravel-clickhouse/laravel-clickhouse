<?php

namespace ClickHouse\Core\Client\Transports;

use ClickHouse\Core\Client\Contracts\Transport;
use ClickHouse\Core\Client\Response;
use ClickHouse\Core\Exceptions\ParallelQueryException;
use ClickHouse\Core\Exceptions\QueryException;
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
            $request = $this->createRequest($sql);
            $response = $this->client->send($request);

            return $this->parseResponse($sql, $response);
        } catch (RequestException $e) {
            // Prefer the full error message from the response body over
            // Guzzle's 120-character summary in $e->getMessage().
            $exception = $e->getResponse()
                ? $this->extractErrorMessage((string) $e->getResponse()->getBody())
                : null;

            if ($exception !== null) {
                throw new QueryException('ClickHouse query error: '.$exception, previous: $e);
            }

            throw new QueryException('ClickHouse request failed: '.$e->getMessage(), previous: $e);
        } catch (GuzzleException $e) {
            throw new QueryException('ClickHouse connection failed: '.$e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw new QueryException($e->getMessage(), previous: $e);
        }
    }

    public function executeParallelly(array $sqls): array
    {
        $requests = array_map(fn ($sql) => $this->createRequest($sql), $sqls);

        /** @var array<int|string, Response> $responses */
        $responses = [];

        /** @var array<int|string, Throwable> $errors */
        $errors = [];

        $pool = new Pool($this->client, $requests, [
            'concurrency' => static::CLICKHOUSE_CONCURRENT_REQUESTS,
            'fulfilled' => function ($response, $key) use ($sqls, &$responses) {
                $responses[$key] = $this->parseResponse($sqls[$key], $response);
            },
            'rejected' => function ($e, $key) use ($sqls, &$responses, &$errors) {
                $response = null;

                if ($e instanceof RequestException && $e->getResponse()) {
                    // parseResponse() throws QueryException when the error
                    // body is plain text (ClickHouse <= 23 style). Capture
                    // it as this key's error instead of letting it escape
                    // the pool callback — an escape would abort the whole
                    // collection and discard every other query's result.
                    try {
                        $responses[$key] = $response = $this->parseResponse($sqls[$key], $e->getResponse());
                    } catch (QueryException $parseException) {
                        $errors[$key] = $parseException;

                        return;
                    }
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

    protected function createRequest(string $sql): Request
    {
        return new Request('POST', $this->buildRequestUri(), $this->getAuthHeaders(), $sql);
    }

    protected function buildRequestUri(): string
    {
        $protocol = $this->https ? 'https' : 'http';
        $baseUrl = "{$protocol}://{$this->host}:{$this->port}/";

        $params = [
            'database' => $this->database,
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

    protected function parseResponse(string $sql, ResponseInterface $response): Response
    {
        $contentType = $response->getHeaderLine('Content-Type');
        $body = $response->getBody()->getContents();

        if (! str_contains($contentType, 'application/json') && preg_match(static::CLICKHOUSE_ERROR_REGEX, $body)) {
            throw new QueryException('ClickHouse query error: '.$body);
        }

        // ClickHouse 24+ reports errors as a JSON body whose `exception`
        // field carries the full message. Without this branch the error
        // would surface truncated: the JSON boilerplate exhausts Guzzle's
        // 120-character body summary before the message begins, and
        // parseRecords() would discard the field by reading `data` only.
        $exception = $this->parseJsonExceptionMessage($body);

        if ($exception !== null) {
            throw new QueryException('ClickHouse query error: '.$exception);
        }

        $records = $this->parseRecords($body);
        $affectedRows = $records === null ? $this->parseAffectedRows($response) : null;

        if ($records === null && $affectedRows === null && str_contains($contentType, 'application/json') && trim($body) !== '') {
            throw new QueryException('ClickHouse response parsing error: '.$body);
        }

        return new Response(
            $sql,
            $affectedRows,
            $records,
        );
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
     * Extract the full ClickHouse error message from an error response
     * body of either style: the JSON `exception` field (ClickHouse 24+)
     * or the plain-text `DB::Exception` body (23.x).
     */
    protected function extractErrorMessage(string $body): ?string
    {
        $exception = $this->parseJsonExceptionMessage($body);

        if ($exception !== null) {
            return $exception;
        }

        return preg_match(static::CLICKHOUSE_ERROR_REGEX, $body) ? trim($body) : null;
    }

    /**
     * Extract the full error message from a ClickHouse JSON error body.
     */
    protected function parseJsonExceptionMessage(string $body): ?string
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['exception']) || ! is_string($data['exception'])) {
            return null;
        }

        return $data['exception'];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function parseRecords(string $body): ?array
    {
        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return null;
        }

        return $data['data'];
    }
}
