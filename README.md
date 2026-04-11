# sql-to-signal

Call `->toSignal()` on any Eloquent or Query Builder chain and get back a reactive `Signal` object — ready to wire into Livewire 3/4 components and Alpine.js without any boilerplate.

```php
$signal = User::where('active', true)->toSignal();
```

---

## Why not just `clone $query`?

The clone pattern is the typical workaround when you need to reuse a query builder — but it falls apart quickly in Livewire and Alpine.js contexts.

### Reusing a query in a Livewire component

**Without `toSignal()` — clone pattern**

```php
class OrderDashboard extends Component
{
    // You can't store a QueryBuilder as a public property.
    // Livewire can't serialize it — it will throw or silently drop it.
    // So you have to rebuild the query from scratch on every request.

    public array $orders = [];    // you lose Collection methods
    public int   $count  = 0;
    public ?array $first = null;

    private function baseQuery(): Builder
    {
        // Duplicated every time: if filters change you must update in multiple places
        return DB::table('orders')
            ->where('status', $this->status)
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');
    }

    public function mount(): void
    {
        $q = $this->baseQuery();
        $this->orders = $q->get()->toArray();       // hit 1
        $this->count  = (clone $q)->count();        // hit 2  ← extra query
        $this->first  = (clone $q)->first();        // hit 3  ← extra query
    }

    public function refresh(): void
    {
        // Rebuild everything again — same 3 queries
        $q = $this->baseQuery();
        $this->orders = $q->get()->toArray();
        $this->count  = (clone $q)->count();
        $this->first  = (clone $q)->first();
    }
}
```

Problems:
- `clone` only works within the same request — you can't put a `Builder` in a Livewire property
- The query definition is repeated or called through a private helper — easy to drift out of sync
- 3 separate database hits to get the same data
- `toArray()` discards the model — you get raw stdClass, no Eloquent methods on rows

---

**With `toSignal()`**

```php
class OrderDashboard extends Component
{
    public Signal $orders;  // serializes/hydrates automatically between requests

    public function mount(): void
    {
        // One query, one database hit. count/first/pluck come for free.
        $this->orders = DB::table('orders')
            ->where('status', $this->status)
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->toSignal();
    }

    public function refresh(): void
    {
        // Re-runs the exact same SQL — no need to rebuild the query
        $this->orders = $this->orders->refresh();
    }
}
```

```blade
Total: {{ $orders->count() }}        {{-- no extra query --}}
First: {{ $orders->first()->id }}    {{-- no extra query --}}
```

---

### Passing data to Alpine.js

**Without `toSignal()`**

```php
// Controller / Livewire component
$rows     = DB::table('orders')->where(...)->get()->toArray();
$count    = DB::table('orders')->where(...)->count();   // cloned query, second hit
$interval = config('dashboard.polling_interval');       // manually forwarded

return view('dashboard', compact('rows', 'count', 'interval'));
```

```blade
<div x-data="{
    rows:     {{ json_encode($rows) }},
    count:    {{ $count }},
    interval: {{ $interval }}
}">
```

Gotchas:
- Two database hits for the same filter
- You manually `json_encode` each piece
- Polling interval is a magic number hard to change in one place
- No single object you can pass to a sub-component or an API response

---

**With `toSignal()`**

```php
$signal = DB::table('orders')->where(...)->toSignal();

return view('dashboard', compact('signal'));
```

```blade
<div x-data="{ signal: @js($signal) }">
    {{-- signal.data, signal.meta.count, signal.meta.polling_interval --}}
    {{-- all in one place, one query, zero manual wiring --}}
</div>
```

---

### Summary

| | `clone $query` | `->toSignal()` |
|---|---|---|
| Survives Livewire serialization | No — Builder can't be a public property | Yes — Signal hydrates/dehydrates cleanly |
| Database hits for count + first | Extra query each | Zero — derived from the same Collection |
| Refresh in Livewire | Rebuild query from scratch | `$signal->refresh()` |
| Alpine.js wiring | Manual `json_encode` per variable | `@js($signal)` — data + meta in one shot |
| Model hydration on refresh | Lost — raw `stdClass` | Preserved — Eloquent models rebuilt |
| Polling interval in sync | Hard-coded in JS | Carried in `signal.meta.polling_interval` |

---

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Livewire 3 or 4

---

## Installation

```bash
composer require laravelldone/sql-to-signal
```

The service provider is auto-discovered. To publish the config file:

```bash
php artisan vendor:publish --tag="sql-to-signal-config"
```

---

## Configuration

`config/sql-to-signal.php`:

```php
return [
    'cache' => [
        'enabled' => false,
        'ttl'     => 60, // seconds
    ],

    // Passed as meta for Alpine.js polling wiring
    'polling_interval' => 2000, // milliseconds

    // true = getData() returns a Collection, false = plain array
    'as_collection' => true,

    // Max rows allowed in a Signal (null = unlimited)
    'max_rows' => 1000,
];
```

---

## Basic Usage

### Query Builder

```php
use Illuminate\Support\Facades\DB;

$signal = DB::table('orders')
    ->where('status', 'pending')
    ->orderBy('created_at', 'desc')
    ->toSignal();

// $signal is a Signal instance
$signal->getQuery();
// "select * from `orders` where `status` = ? order by `created_at` desc"

$signal->getBindings();
// ["pending"]

$signal->count();
// 3

$signal->getData();
// Illuminate\Support\Collection {
//   0 => { "id": 1, "status": "pending", "total": 120.00, ... },
//   1 => { "id": 2, "status": "pending", "total": 89.50,  ... },
//   2 => { "id": 3, "status": "pending", "total": 45.00,  ... },
// }
```

### Eloquent Builder

```php
$signal = Order::query()
    ->with('customer')
    ->where('status', 'pending')
    ->toSignal();

$signal->getModelClass();
// "App\Models\Order"

$signal->first();
// App\Models\Order { #id: 1, #status: "pending", ... }

$signal->pluck('total');
// Illuminate\Support\Collection [120.00, 89.50, 45.00]
```

### Override config per call

```php
$signal = Product::active()->toSignal([
    'polling_interval' => 5000,
    'max_rows'         => 50,
]);

$signal->toArray();
// [
//   "data" => [ ... up to 50 products ... ],
//   "meta" => [
//     "count"            => 12,
//     "model_class"      => "App\Models\Product",
//     "polling_interval" => 5000,   // <-- overridden
//     "pagination"       => null,   // null when not paginated
//   ]
// ]
```

---

## Using in Livewire

Declare a `Signal` as a public property — it serializes/hydrates automatically via the built-in Livewire synthesizer:

```php
use Livewire\Component;
use Laravelldone\SqlToSignal\Signal;

class OrderDashboard extends Component
{
    public Signal $orders;

    public function mount(): void
    {
        $this->orders = Order::pending()->toSignal();
    }

    public function refresh(): void
    {
        $this->orders = $this->orders->refresh();
        // Re-runs the original SQL with the same bindings.
        // No need to rebuild the query from scratch.
    }

    public function render()
    {
        return view('livewire.order-dashboard');
    }
}
```

```blade
<div>
    <button wire:click="refresh">Refresh</button>

    @foreach ($orders->getData() as $order)
        <div>{{ $order->id }} — {{ $order->status }}</div>
    @endforeach

    <p>Total: {{ $orders->count() }}</p>
</div>
```

**What Livewire sends over the wire** (dehydrated payload):

```json
{
    "data":            [{ "id": 1, "status": "pending" }, ...],
    "query":           "select * from `orders` where `status` = ?",
    "bindings":        ["pending"],
    "model_class":     "App\\Models\\Order",
    "connection_name": "mysql",
    "config":          { "polling_interval": 2000, "max_rows": 1000 },
    "pagination_meta": null
}
```

For a paginated Signal, `pagination_meta` carries the full page state:

```json
{
    "pagination_meta": {
        "total": 87, "per_page": 15, "current_page": 2,
        "last_page": 6, "from": 16, "to": 30
    }
}
```

On the next request Livewire hydrates this back into a full `Signal` — no database hit until you call `refresh()`.

### Auto-polling with Livewire

```blade
<div wire:poll.5000ms="refresh">
    @foreach ($orders->getData() as $order)
        <div>{{ $order->id }} — {{ $order->status }}</div>
    @endforeach
</div>
```

Every 5 seconds Livewire calls `refresh()`, re-executes the query, and re-renders only the changed rows.

---

## Using with Alpine.js

`Signal` implements `JsonSerializable`, so you can pass it directly to `@js` or an API endpoint:

```blade
<div x-data="{ signal: @js($orders) }">
    <template x-for="row in signal.data" :key="row.id">
        <div x-text="row.id + ' — ' + row.status"></div>
    </template>
    <p>Total: <span x-text="signal.meta.count"></span></p>
</div>
```

`@js($orders)` renders:

```json
{
    "data": [
        { "id": 1, "status": "pending", "total": "120.00" },
        { "id": 2, "status": "pending", "total": "89.50"  },
        { "id": 3, "status": "pending", "total": "45.00"  }
    ],
    "meta": {
        "count":            3,
        "model_class":      "App\\Models\\Order",
        "polling_interval": 2000
    }
}
```

Use `signal.meta.polling_interval` to drive a JS polling interval without hard-coding it:

```js
setInterval(() => fetch('/orders').then(r => r.json()).then(d => signal = d),
            signal.meta.polling_interval);
```

---

## Pagination

Unlike Livewire's built-in `WithPagination` (which only works inside `render()`), Signal pagination works anywhere — including `mount()`.

```php
public function mount(): void
{
    $this->orders = Order::pending()
        ->orderBy('created_at', 'desc')
        ->toSignal(['per_page' => 15, 'page' => 1]);
}

public function nextPage(): void { $this->orders = $this->orders->nextPage(); }
public function prevPage(): void { $this->orders = $this->orders->prevPage(); }
public function goToPage(int $page): void { $this->orders = $this->orders->goToPage($page); }
```

```blade
@foreach ($orders->getData() as $order)
    <div>{{ $order->id }} — {{ $order->status }}</div>
@endforeach

<div>
    Page {{ $orders->getCurrentPage() }} of {{ $orders->getLastPage() }}
    &nbsp;·&nbsp; {{ $orders->getTotal() }} total
</div>

<button wire:click="prevPage" @disabled($orders->getCurrentPage() === 1)>← Prev</button>
<button wire:click="nextPage" @disabled($orders->getCurrentPage() === $orders->getLastPage())>Next →</button>
```

Pagination meta is carried in `toArray()` and survives the Livewire wire round-trip:

```json
{
    "data": [ ... ],
    "meta": {
        "count": 15,
        "polling_interval": 2000,
        "pagination": {
            "total": 87,
            "per_page": 15,
            "current_page": 1,
            "last_page": 6,
            "from": 1,
            "to": 15
        }
    }
}
```

Pass it to Alpine.js for client-side pagination controls without any extra wiring:

```blade
<div x-data="{ signal: @js($orders) }">
    <span x-text="`Page ${signal.meta.pagination.current_page} of ${signal.meta.pagination.last_page}`"></span>
</div>
```

### Pagination API

| Method | Return type | Description |
|---|---|---|
| `isPaginated()` | `bool` | `true` when created with `per_page` |
| `getTotal()` | `int` | Total rows across all pages |
| `getPerPage()` | `int` | Rows per page |
| `getCurrentPage()` | `int` | Current page number |
| `getLastPage()` | `int` | Last page number |
| `nextPage()` | `Signal` | Signal for the next page (clamped at last page) |
| `prevPage()` | `Signal` | Signal for the previous page (clamped at page 1) |
| `goToPage(int $page)` | `Signal` | Signal for an arbitrary page |

---

## Signal API

| Method | Return type | Description |
|---|---|---|
| `getData()` | `Collection` | Full result set for the current page |
| `getQuery()` | `string` | Base SQL with `?` placeholders (no LIMIT/OFFSET) |
| `getBindings()` | `array` | Ordered binding values |
| `getModelClass()` | `string\|null` | Eloquent model class, or `null` for raw queries |
| `getConnectionName()` | `string\|null` | Database connection name |
| `refresh()` | `Signal` | Re-runs the query; re-runs the same page if paginated |
| `count()` | `int` | Row count for the current page |
| `isEmpty()` | `bool` | `true` when the current page is empty |
| `first()` | `mixed` | First row/model on the current page, or `null` |
| `pluck(key, value?)` | `Collection` | Delegates to `Collection::pluck()` |
| `toArray()` | `array` | `['data' => [...], 'meta' => [...]]` |
| `toLivewire()` | `array` | Full serialized payload for Livewire transport |
| `Signal::fromLivewire($value)` | `Signal` | Reconstructs a `Signal` from a Livewire payload |

---

## Safety: max_rows

To prevent accidentally serializing large datasets through Livewire's JSON cycle, an `OverflowException` is thrown when the result count exceeds `max_rows`:

```php
// Table has 1 500 rows — this throws immediately
$signal = Report::query()->toSignal(['max_rows' => 500]);

// OverflowException: Signal result set exceeds the configured max_rows limit
// of 500. Got 1500 rows.
```

Scope your query before calling `toSignal()`, or set `max_rows` to `null` to disable the limit entirely:

```php
// Safe — scoped
$signal = Report::thisMonth()->toSignal(['max_rows' => 500]);

// Unlimited — use with care
$signal = Report::query()->toSignal(['max_rows' => null]);
```

---

[![Support me on Ko-fi](https://ko-fi.com)](https://ko-fi.com)

## License

MIT — see [LICENSE](LICENSE).
