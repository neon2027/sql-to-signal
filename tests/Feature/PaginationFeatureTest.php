<?php

use Illuminate\Support\Facades\DB;
use Laravelldone\SqlToSignal\Tests\Models\User;

// Seed 25 users before each test in this file
beforeEach(function () {
    for ($i = 1; $i <= 25; $i++) {
        DB::table('users')->insert(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
    }
});

test('toSignal with per_page returns paginated Signal', function () {
    $signal = DB::table('users')->toSignal(['per_page' => 10, 'page' => 1]);

    expect($signal->isPaginated())->toBeTrue()
        ->and($signal->getTotal())->toBe(25)
        ->and($signal->getPerPage())->toBe(10)
        ->and($signal->getCurrentPage())->toBe(1)
        ->and($signal->getLastPage())->toBe(3)
        ->and($signal->count())->toBe(10);
});

test('last page has remaining rows', function () {
    $signal = DB::table('users')->toSignal(['per_page' => 10, 'page' => 3]);

    expect($signal->count())->toBe(5)
        ->and($signal->getCurrentPage())->toBe(3);
});

test('Eloquent Builder toSignal paginates with model hydration', function () {
    $signal = User::query()->toSignal(['per_page' => 10, 'page' => 1]);

    expect($signal->isPaginated())->toBeTrue()
        ->and($signal->getModelClass())->toBe(User::class)
        ->and($signal->count())->toBe(10)
        ->and($signal->first())->toBeInstanceOf(User::class);
});

test('nextPage moves to the next page', function () {
    $page1 = DB::table('users')->toSignal(['per_page' => 10, 'page' => 1]);
    $page2 = $page1->nextPage();

    expect($page2->getCurrentPage())->toBe(2)
        ->and($page2->count())->toBe(10);
});

test('prevPage moves to the previous page', function () {
    $page2 = DB::table('users')->toSignal(['per_page' => 10, 'page' => 2]);
    $page1 = $page2->prevPage();

    expect($page1->getCurrentPage())->toBe(1);
});

test('nextPage clamps at last page', function () {
    $last = DB::table('users')->toSignal(['per_page' => 10, 'page' => 3]);
    $still = $last->nextPage();

    expect($still->getCurrentPage())->toBe(3);
});

test('prevPage clamps at page 1', function () {
    $first = DB::table('users')->toSignal(['per_page' => 10, 'page' => 1]);
    $still = $first->prevPage();

    expect($still->getCurrentPage())->toBe(1);
});

test('goToPage jumps to an arbitrary page', function () {
    $signal = DB::table('users')->toSignal(['per_page' => 10, 'page' => 1]);
    $page3 = $signal->goToPage(3);

    expect($page3->getCurrentPage())->toBe(3)
        ->and($page3->count())->toBe(5);
});

test('refresh on paginated signal re-runs same page', function () {
    $signal = DB::table('users')->toSignal(['per_page' => 10, 'page' => 2]);

    // Insert more rows — refresh should reflect the new total
    DB::table('users')->insert(['name' => 'Extra', 'email' => 'extra@example.com']);

    $refreshed = $signal->refresh();

    expect($refreshed->getCurrentPage())->toBe(2)
        ->and($refreshed->getTotal())->toBe(26);
});

test('toArray pagination meta has correct shape', function () {
    $signal = DB::table('users')->toSignal(['per_page' => 10, 'page' => 1]);
    $meta = $signal->toArray()['meta']['pagination'];

    expect($meta)->toMatchArray([
        'total' => 25,
        'per_page' => 10,
        'current_page' => 1,
        'last_page' => 3,
        'from' => 1,
        'to' => 10,
    ]);
});
