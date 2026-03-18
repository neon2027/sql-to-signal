<?php

use Laravelldone\SqlToSignal\Signal;
use Laravelldone\SqlToSignal\Tests\TestCase;

uses(TestCase::class);

function paginatedSignal(int $total = 25, int $perPage = 10, int $page = 1): Signal
{
    $lastPage = (int) ceil($total / $perPage);

    return new Signal(
        collect(array_fill(0, min($perPage, $total - ($page - 1) * $perPage), ['id' => 1])),
        'select * from users',
        [],
        null,
        null,
        [],
        [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ],
    );
}

test('isPaginated returns false when no pagination meta', function () {
    $signal = new Signal(collect(), 'select * from users', [], null, null, []);

    expect($signal->isPaginated())->toBeFalse();
});

test('isPaginated returns true when pagination meta is present', function () {
    expect(paginatedSignal()->isPaginated())->toBeTrue();
});

test('pagination accessors return correct values', function () {
    $signal = paginatedSignal(total: 25, perPage: 10, page: 2);

    expect($signal->getTotal())->toBe(25)
        ->and($signal->getPerPage())->toBe(10)
        ->and($signal->getCurrentPage())->toBe(2)
        ->and($signal->getLastPage())->toBe(3);
});

test('toArray includes pagination in meta', function () {
    $signal = paginatedSignal(total: 25, perPage: 10, page: 1);
    $arr = $signal->toArray();

    expect($arr['meta']['pagination'])->toBeArray()
        ->and($arr['meta']['pagination']['total'])->toBe(25)
        ->and($arr['meta']['pagination']['per_page'])->toBe(10)
        ->and($arr['meta']['pagination']['current_page'])->toBe(1)
        ->and($arr['meta']['pagination']['last_page'])->toBe(3);
});

test('toArray pagination is null when not paginated', function () {
    $signal = new Signal(collect(), 'select * from users', [], null, null, []);

    expect($signal->toArray()['meta']['pagination'])->toBeNull();
});

test('toLivewire and fromLivewire round-trip preserves pagination meta', function () {
    $original = paginatedSignal(total: 30, perPage: 10, page: 2);
    $restored = Signal::fromLivewire($original->toLivewire());

    expect($restored->isPaginated())->toBeTrue()
        ->and($restored->getTotal())->toBe(30)
        ->and($restored->getCurrentPage())->toBe(2)
        ->and($restored->getLastPage())->toBe(3);
});

test('goToPage throws BadMethodCallException on non-paginated signal', function () {
    $signal = new Signal(collect(), 'select * from users', [], null, null, []);

    expect(fn () => $signal->goToPage(2))
        ->toThrow(BadMethodCallException::class);
});

test('nextPage throws BadMethodCallException on non-paginated signal', function () {
    $signal = new Signal(collect(), 'select * from users', [], null, null, []);

    expect(fn () => $signal->nextPage())
        ->toThrow(BadMethodCallException::class);
});
