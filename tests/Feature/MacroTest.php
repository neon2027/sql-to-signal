<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Laravelldone\SqlToSignal\Signal;
use Laravelldone\SqlToSignal\Tests\Models\User;

test('toSignal macro is available on Query Builder', function () {
    expect(Builder::hasMacro('toSignal'))->toBeTrue();
});

test('toSignal macro is available on Eloquent Builder', function () {
    expect(User::query()->toSignal())->toBeInstanceOf(Signal::class);
});

test('Query Builder toSignal returns correct data', function () {
    DB::table('users')->insert([
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ]);

    $signal = DB::table('users')->toSignal();

    expect($signal)->toBeInstanceOf(Signal::class)
        ->and($signal->count())->toBe(2);
});

test('Eloquent Builder toSignal returns correct data with model class', function () {
    User::create(['name' => 'Carol', 'email' => 'carol@example.com']);

    $signal = User::query()->toSignal();

    expect($signal)->toBeInstanceOf(Signal::class)
        ->and($signal->getModelClass())->toBe(User::class)
        ->and($signal->count())->toBe(1);
});

test('custom options override config in macro call', function () {
    $signal = DB::table('users')->toSignal(['polling_interval' => 9000]);

    expect($signal->toArray()['meta']['polling_interval'])->toBe(9000);
});

test('config file is publishable', function () {
    expect(function () {
        $this->artisan('vendor:publish', [
            '--tag' => 'sql-to-signal-config',
            '--force' => true,
        ])->assertExitCode(0);
    })->not->toThrow(Exception::class);
});
