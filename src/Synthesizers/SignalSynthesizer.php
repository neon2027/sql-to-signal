<?php

namespace Laravelldone\SqlToSignal\Synthesizers;

use Laravelldone\SqlToSignal\Signal;
use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;

class SignalSynthesizer extends Synth
{
    public static string $key = 'sql-signal';

    public static function match(mixed $target): bool
    {
        return $target instanceof Signal;
    }

    /**
     * @param  Signal  $target
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    public function dehydrate(mixed $target, \Closure $dehydrateChild): array
    {
        return [$target->toLivewire(), []];
    }

    /** @param array<string, mixed> $value */
    public function hydrate(mixed $value, mixed $meta, \Closure $hydrateChild): Signal
    {
        return Signal::fromLivewire($value);
    }
}
