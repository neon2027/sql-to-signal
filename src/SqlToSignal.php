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

        $results = collect($builder->get());

        $this->enforceMaxRows($results, $config);

        return new Signal(
            $results,
            $sql,
            $bindings,
            null,
            $connectionName,
            $config,
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

        $results = $builder->get();

        $this->enforceMaxRows($results, $config);

        return new Signal(
            $results,
            $sql,
            $bindings,
            $modelClass,
            $connectionName,
            $config,
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
}
