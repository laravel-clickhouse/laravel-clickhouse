<?php

namespace ClickHouse\Core\Query;

/**
 * Marker contract implemented by every framework-specific ClickHouse query
 * builder, so shared grammar code can detect a ClickHouse builder without
 * referencing any framework class.
 *
 * @property array<int, array{type: string, column: mixed, as: string|null}> $arrayJoins
 * @property array{expression: mixed, identifier: string, subquery: bool, recursive: bool}|null $withQuery
 * @property array<string, int|float|bool|string> $settings
 * @property array<int, array<string, mixed>> $prewheres
 * @property string|null $cluster
 * @property array{factor: float|int, offset: float|int|null}|null $sample
 * @property array{limit: int, columns: string[]}|null $limitBy
 */
interface ClickHouseBuilder {}
