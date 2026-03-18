<?php

namespace Laravelldone\SqlToSignal;

use BadMethodCallException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonSerializable;
use Laravelldone\SqlToSignal\Contracts\SignalContract;

final class Signal implements JsonSerializable, SignalContract
{
    /**
     * @param  Collection<int, mixed>  $data
     * @param  array<int, mixed>  $bindings
     * @param  array<string, mixed>  $config
     * @param  array{total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}|null  $paginationMeta
     */
    public function __construct(
        protected Collection $data,
        protected string $query,
        protected array $bindings,
        protected ?string $modelClass,
        protected ?string $connectionName,
        protected array $config,
        protected ?array $paginationMeta = null,
    ) {}

    /** @return Collection<int, mixed> */
    public function getData(): Collection
    {
        return $this->data;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    /** @return array<int, mixed> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getModelClass(): ?string
    {
        return $this->modelClass;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    public function refresh(): static
    {
        if ($this->paginationMeta !== null) {
            return $this->executeAtPage($this->paginationMeta['current_page']);
        }

        $connection = $this->resolveConnection();

        $rows = $connection->select($this->query, $this->bindings);

        $data = $this->hydrateRows($rows);

        return new self(
            $data,
            $this->query,
            $this->bindings,
            $this->modelClass,
            $this->connectionName,
            $this->config,
        );
    }

    // ── Pagination ─────────────────────────────────────────────────────────────

    public function isPaginated(): bool
    {
        return $this->paginationMeta !== null;
    }

    public function getTotal(): int
    {
        return (int) ($this->paginationMeta['total'] ?? 0);
    }

    public function getPerPage(): int
    {
        return (int) ($this->paginationMeta['per_page'] ?? 0);
    }

    public function getCurrentPage(): int
    {
        return (int) ($this->paginationMeta['current_page'] ?? 1);
    }

    public function getLastPage(): int
    {
        return (int) ($this->paginationMeta['last_page'] ?? 1);
    }

    public function nextPage(): static
    {
        $this->assertPaginated('nextPage');

        return $this->executeAtPage(min($this->getCurrentPage() + 1, $this->getLastPage()));
    }

    public function prevPage(): static
    {
        $this->assertPaginated('prevPage');

        return $this->executeAtPage(max($this->getCurrentPage() - 1, 1));
    }

    public function goToPage(int $page): static
    {
        $this->assertPaginated('goToPage');

        return $this->executeAtPage($page);
    }

    // ── Convenience ────────────────────────────────────────────────────────────

    public function count(): int
    {
        return $this->data->count();
    }

    public function isEmpty(): bool
    {
        return $this->data->isEmpty();
    }

    public function first(): mixed
    {
        return $this->data->first();
    }

    /** @return Collection<int|string, mixed> */
    public function pluck(string $key, ?string $value = null): Collection
    {
        return $this->data->pluck($key, $value);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data->toArray(),
            'meta' => [
                'count' => $this->data->count(),
                'model_class' => $this->modelClass,
                'polling_interval' => $this->config['polling_interval'] ?? 2000,
                'pagination' => $this->paginationMeta,
            ],
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    // ── Livewire ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function toLivewire(): array
    {
        return [
            'data' => $this->data->toArray(),
            'query' => $this->query,
            'bindings' => $this->bindings,
            'model_class' => $this->modelClass,
            'connection_name' => $this->connectionName,
            'config' => $this->config,
            'pagination_meta' => $this->paginationMeta,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromLivewire(mixed $value): self
    {
        return new self(
            collect((array) ($value['data'] ?? [])),
            is_string($value['query'] ?? null) ? $value['query'] : '',
            is_array($value['bindings'] ?? null) ? $value['bindings'] : [],
            is_string($value['model_class'] ?? null) ? $value['model_class'] : null,
            is_string($value['connection_name'] ?? null) ? $value['connection_name'] : null,
            is_array($value['config'] ?? null) ? $value['config'] : [],
            self::parsePaginationMeta($value['pagination_meta'] ?? null),
        );
    }

    /**
     * Safely parse and cast a raw wire payload value into the typed pagination shape.
     *
     * @return array{total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}|null
     */
    private static function parsePaginationMeta(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        return [
            'total' => isset($raw['total']) ? (int) $raw['total'] : 0,
            'per_page' => isset($raw['per_page']) ? (int) $raw['per_page'] : 0,
            'current_page' => isset($raw['current_page']) ? (int) $raw['current_page'] : 1,
            'last_page' => isset($raw['last_page']) ? (int) $raw['last_page'] : 1,
            'from' => isset($raw['from']) ? (int) $raw['from'] : null,
            'to' => isset($raw['to']) ? (int) $raw['to'] : null,
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function resolveConnection(): ConnectionInterface
    {
        // Security: only allow connections explicitly defined in database config,
        // preventing an attacker from switching to an unintended database via a
        // tampered Livewire payload.
        $allowedConnections = array_keys(config('database.connections', []));

        if ($this->connectionName !== null && ! in_array($this->connectionName, $allowedConnections, true)) {
            throw new InvalidArgumentException(
                "Signal::refresh() refused an unknown connection name: [{$this->connectionName}]."
            );
        }

        return $this->connectionName
            ? DB::connection($this->connectionName)
            : DB::connection();
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return Collection<int, mixed>
     */
    private function hydrateRows(array $rows): Collection
    {
        // Security: only instantiate classes that are genuine Eloquent models,
        // preventing a tampered model_class payload from invoking arbitrary
        // PHP class constructors on the server.
        if (
            $this->modelClass !== null
            && class_exists($this->modelClass)
            && is_subclass_of($this->modelClass, Model::class)
        ) {
            /** @var Model $model */
            $model = new $this->modelClass;

            return collect($rows)->map(fn ($row) => $model->newFromBuilder((array) $row));
        }

        return collect($rows);
    }

    private function executeAtPage(int $page): self
    {
        $connection = $this->resolveConnection();
        $perPage = $this->getPerPage();

        // Get the total count by wrapping the base query in a subquery
        $countRows = $connection->select(
            'select count(*) as aggregate from ('.$this->query.') as signal_aggregate',
            $this->bindings
        );
        $total = (int) ($countRows[0]->aggregate ?? 0);

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        // Fetch the page slice by appending LIMIT/OFFSET to the base SQL
        $rows = $connection->select(
            $this->query.' limit ? offset ?',
            array_merge($this->bindings, [$perPage, $offset])
        );

        $data = $this->hydrateRows($rows);

        $paginationMeta = $this->buildPaginationMeta($total, $perPage, $page, $lastPage, $offset);

        return new self(
            $data,
            $this->query,
            $this->bindings,
            $this->modelClass,
            $this->connectionName,
            $this->config,
            $paginationMeta,
        );
    }

    /**
     * @return array{total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}
     */
    private function buildPaginationMeta(int $total, int $perPage, int $page, int $lastPage, int $offset): array
    {
        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : null,
            'to' => $total > 0 ? min($page * $perPage, $total) : null,
        ];
    }

    private function assertPaginated(string $method): void
    {
        if ($this->paginationMeta === null) {
            throw new BadMethodCallException(
                "Cannot call {$method}() on a non-paginated Signal. Pass ['per_page' => N] to toSignal()."
            );
        }
    }
}
