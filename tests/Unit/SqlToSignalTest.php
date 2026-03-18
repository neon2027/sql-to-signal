<?php

use Illuminate\Support\Facades\DB;
use Laravelldone\SqlToSignal\Signal;
use Laravelldone\SqlToSignal\SqlToSignal;
use Laravelldone\SqlToSignal\Tests\Models\User;
use Laravelldone\SqlToSignal\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->factory = new SqlToSignal(['polling_interval' => 2000, 'max_rows' => 1000]);
});

test('fromQueryBuilder returns a Signal', function () {
    DB::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com']);

    $signal = $this->factory->fromQueryBuilder(DB::table('users'));

    expect($signal)->toBeInstanceOf(Signal::class)
        ->and($signal->count())->toBe(1);
});

test('fromEloquentBuilder returns a Signal with model class', function () {
    User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $signal = $this->factory->fromEloquentBuilder(User::query());

    expect($signal)->toBeInstanceOf(Signal::class)
        ->and($signal->getModelClass())->toBe(User::class)
        ->and($signal->count())->toBe(1);
});

test('enforceMaxRows throws OverflowException when limit exceeded', function () {
    $results = collect(array_fill(0, 5, ['id' => 1]));

    expect(fn () => $this->factory->enforceMaxRows($results, ['max_rows' => 3]))
        ->toThrow(OverflowException::class);
});

test('options override base config', function () {
    DB::table('users')->insert(['name' => 'Carol', 'email' => 'carol@example.com']);

    $signal = $this->factory->fromQueryBuilder(
        DB::table('users'),
        ['polling_interval' => 5000]
    );

    expect($signal->toArray()['meta']['polling_interval'])->toBe(5000);
});
