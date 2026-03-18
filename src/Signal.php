<?php

namespace Laravelldone\SqlToSignal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use Laravelldone\SqlToSignal\Contracts\SignalContract;

final class Signal implements JsonSerializable, SignalContract
{
    /**
     * @param  Collection<int, mixed>  $data
     * @param  array<int, mixed>  $bindings
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected Collection $data,
        protected string $query,
        protected array $bindings,
        protected ?string $modelClass,
        protected ?string $connectionName,
        protected array $config,
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
        $connection = $this->connectionName
            ? DB::connection($this->connectionName)
            : DB::connection();

        $rows = $connection->select($this->query, $this->bindings);

        if ($this->modelClass !== null && class_exists($this->modelClass)) {
            /** @var Model $model */
            $model = new $this->modelClass;
            $data = collect($rows)->map(fn ($row) => $model->newFromBuilder((array) $row));
        } else {
            $data = collect($rows);
        }

        return new self(
            $data,
            $this->query,
            $this->bindings,
            $this->modelClass,
            $this->connectionName,
            $this->config,
        );
    }

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
            ],
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    // ── Livewire Wireable ──────────────────────────────────────────────────────

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
        );
    }
}
