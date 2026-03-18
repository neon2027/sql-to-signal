<?php

namespace Laravelldone\SqlToSignal;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravelldone\SqlToSignal\Synthesizers\SignalSynthesizer;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SqlToSignalServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sql-to-signal')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SqlToSignal::class, function ($app) {
            return new SqlToSignal(
                $app['config']->get('sql-to-signal', [])
            );
        });
    }

    public function packageBooted(): void
    {
        $this->registerMacros();
        $this->registerLivewireSynthesizer();
    }

    protected function registerMacros(): void
    {
        $app = $this->app;

        QueryBuilder::macro('toSignal', function (array $options = []) use ($app) {
            /** @var QueryBuilder $this */
            return $app->make(SqlToSignal::class)->fromQueryBuilder($this, $options);
        });

        EloquentBuilder::macro('toSignal', function (array $options = []) use ($app) {
            /** @var EloquentBuilder<Model> $this */
            return $app->make(SqlToSignal::class)->fromEloquentBuilder($this, $options);
        });
    }

    protected function registerLivewireSynthesizer(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        if (! class_exists(SignalSynthesizer::class)) {
            return;
        }

        Livewire::propertySynthesizer(
            SignalSynthesizer::class
        );
    }
}
