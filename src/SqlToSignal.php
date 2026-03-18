<?php

namespace Laravelldone\SqlToSignal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use OverflowException;

class SqlToSignal
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = []) {}

    /** @param array<string, mixed> $options */
    public function fromQueryBuilder(QueryBuilder $builder, array $options = []): Signal
    {
        $config = array_merge($this->config, $options);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $conn = $builder->getConnection();
        $connectionName = $conn instanceof Connection ? $conn->getName() : null;

        if (isset($config['per_page'])) {
            [$results, $paginationMeta] = $this->paginate($builder, $config);
        } else {
            $results = collect($builder->get());
            $paginationMeta = null;
        }

        $this->enforceMaxRows($results, $config);

        return new Signal(
            $results,
            $sql,
            $bindings,
            null,
            $connectionName,
            $config,
            $paginationMeta,
        );
    }

    /**
     * @param  EloquentBuilder<Model>  $builder
     * @param  array<string, mixed>  $options
     */
    public function fromEloquentBuilder(EloquentBuilder $builder, array $options = []): Signal
    {
        $config = array_merge($this->config, $options);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();
        $modelClass = get_class($builder->getModel());
        $connectionName = $builder->getModel()->getConnectionName();

        if (isset($config['per_page'])) {
            [$results, $paginationMeta] = $this->paginate($builder->toBase(), $config);
            $model = $builder->getModel();
            $results = $results->map(fn ($row) => $model->newFromBuilder((array) $row));
        } else {
            $results = $builder->get();
            $paginationMeta = null;
        }

        $this->enforceMaxRows($results, $config);

        return new Signal(
            $results,
            $sql,
            $bindings,
            $modelClass,
            $connectionName,
            $config,
            $paginationMeta,
        );
    }

    /**
     * @param  Collection<int, mixed>  $results
     * @param  array<string, mixed>  $config
     */
    public function enforceMaxRows(Collection $results, array $config): void
    {
        $max = $config['max_rows'] ?? null;

        if ($max !== null && $results->count() > $max) {
            throw new OverflowException(
                "Signal result set exceeds the configured max_rows limit of {$max}. Got {$results->count()} rows."
            );
        }
    }

    /**
     * Run a paginated query against a base QueryBuilder.
     * Returns the page's Collection and the pagination metadata array.
     *
     * @param  array<string, mixed>  $config
     * @return array{Collection<int, mixed>, array{total: int, per_page: int, current_page: int, last_page: int, from: int|null, to: int|null}}
     */
    private function paginate(QueryBuilder $builder, array $config): array
    {
        $perPage = max(1, (int) $config['per_page']);
        $page = max(1, (int) ($config['page'] ?? 1));

        $total = (clone $builder)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $results = collect((clone $builder)->forPage($page, $perPage)->get());

        $paginationMeta = [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $total > 0 ? ($page - 1) * $perPage + 1 : null,
            'to' => $total > 0 ? min($page * $perPage, $total) : null,
        ];

        return [$results, $paginationMeta];
    }
}
