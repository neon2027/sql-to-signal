# sql-to-signal

Call `->toSignal()` on any Eloquent or Query Builder chain and get back a reactive `Signal` object — ready to wire into Livewire 3/4 components and Alpine.js without any boilerplate.

```php
$signal = User::where('active', true)->toSignal();
```

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
//     "polling_interval" => 5000,       // <-- overridden
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
    "config":          { "polling_interval": 2000, "max_rows": 1000 }
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

## Signal API

| Method | Return type | Description |
|---|---|---|
| `getData()` | `Collection` | Full result set |
| `getQuery()` | `string` | Raw SQL with `?` placeholders |
| `getBindings()` | `array` | Ordered binding values |
| `getModelClass()` | `string\|null` | Eloquent model class, or `null` for raw queries |
| `getConnectionName()` | `string\|null` | Database connection name |
| `refresh()` | `Signal` | Re-runs the query, returns a fresh `Signal` |
| `count()` | `int` | Row count |
| `isEmpty()` | `bool` | `true` when result set is empty |
| `first()` | `mixed` | First row/model, or `null` |
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

## License

MIT — see [LICENSE](LICENSE).
