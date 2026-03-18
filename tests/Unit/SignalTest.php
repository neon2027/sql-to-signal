<?php

use Illuminate\Support\Collection;
use Laravelldone\SqlToSignal\Signal;

function makeSignal(array $rows = [], array $config = []): Signal
{
    return new Signal(
        collect($rows),
        'select * from users',
        [],
        null,
        null,
        array_merge(['polling_interval' => 2000, 'max_rows' => 1000], $config),
    );
}

test('construction stores data', function () {
    $signal = makeSignal([['id' => 1, 'name' => 'Alice']]);

    expect($signal->getData())->toBeInstanceOf(Collection::class)
        ->and($signal->getData()->count())->toBe(1);
});

test('getQuery returns sql string', function () {
    $signal = makeSignal();

    expect($signal->getQuery())->toBe('select * from users');
});

test('getBindings returns array', function () {
    $signal = new Signal(collect(), 'select * from users where id = ?', [42], null, null, []);

    expect($signal->getBindings())->toBe([42]);
});

test('getModelClass returns null when not set', function () {
    expect(makeSignal()->getModelClass())->toBeNull();
});

test('toArray returns data and meta keys', function () {
    $signal = makeSignal([['id' => 1]]);
    $arr = $signal->toArray();

    expect($arr)->toHaveKeys(['data', 'meta'])
        ->and($arr['meta']['count'])->toBe(1)
        ->and($arr['meta']['polling_interval'])->toBe(2000);
});

test('jsonSerialize returns same structure as toArray', function () {
    $signal = makeSignal([['id' => 1]]);

    expect($signal->jsonSerialize())->toBe($signal->toArray());
});

test('toLivewire and fromLivewire round-trip', function () {
    $original = new Signal(
        collect([['id' => 1, 'name' => 'Alice']]),
        'select * from users',
        [1],
        'App\\Models\\User',
        'mysql',
        ['polling_interval' => 3000],
    );

    $wire = $original->toLivewire();
    $restored = Signal::fromLivewire($wire);

    expect($restored->getQuery())->toBe($original->getQuery())
        ->and($restored->getBindings())->toBe($original->getBindings())
        ->and($restored->getModelClass())->toBe($original->getModelClass())
        ->and($restored->getData()->toArray())->toBe($original->getData()->toArray());
});

test('count isEmpty first pluck conveniences', function () {
    $signal = makeSignal([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]);

    expect($signal->count())->toBe(2)
        ->and($signal->isEmpty())->toBeFalse()
        ->and($signal->first())->toBe(['id' => 1, 'name' => 'Alice'])
        ->and($signal->pluck('name')->toArray())->toBe(['Alice', 'Bob']);
});

test('isEmpty returns true for empty data', function () {
    expect(makeSignal()->isEmpty())->toBeTrue();
});
