<?php

namespace ClickHouse\Core\Client;

use LogicException;

/**
 * A ClickHouse HTTP session: every request carrying the same id shares
 * server-side state such as temporary tables. The server drops the
 * session once $timeout seconds pass without a request; the value must
 * not exceed the server's max_session_timeout setting (3600 by default).
 */
final class Session
{
    public function __construct(
        public readonly string $id,
        public readonly int $timeout,
    ) {
        if ($timeout < 1) {
            throw new LogicException('The ClickHouse session timeout must be greater than zero.');
        }
    }

    /**
     * Begin a session under a freshly generated, collision-free id.
     */
    public static function start(int $timeout): self
    {
        return new self(bin2hex(random_bytes(16)), $timeout);
    }
}
