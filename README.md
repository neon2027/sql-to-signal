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
```

### Eloquent Builder

```php
$signal = Order::query()
    ->with('customer')
    ->where('status', 'pending')
    ->toSignal();
```

### Override config per call

```php
$signal = Product::active()->toSignal([
    'polling_interval' => 5000,
    'max_rows'         => 50,
]);
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

### Auto-polling with Livewire

```blade
<div wire:poll.5000ms="refresh">
    @foreach ($orders->getData() as $order)
        ...
    @endforeach
</div>
```

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

JSON shape:

```json
{
    "data": [
        { "id": 1, "status": "pending" }
    ],
    "meta": {
        "count": 1,
        "model_class": "App\\Models\\Order",
        "polling_interval": 2000
    }
}
```

---

## Signal API

| Method | Description |
|---|---|
| `getData()` | Returns the result set as a `Collection` |
| `getQuery()` | Returns the raw SQL string |
| `getBindings()` | Returns the query bindings array |
| `getModelClass()` | Returns the Eloquent model class, or `null` for raw queries |
| `refresh()` | Re-executes the stored query and returns a new `Signal` |
| `count()` | Number of rows |
| `isEmpty()` | `true` if the result set is empty |
| `first()` | First item in the result set |
| `pluck(key, value?)` | Delegates to `Collection::pluck()` |
| `toArray()` | Returns `['data' => [...], 'meta' => [...]]` |
| `toLivewire()` | Serializes the Signal for Livewire transport |
| `Signal::fromLivewire($value)` | Reconstructs a Signal from Livewire payload |

---

## Safety: max_rows

To prevent accidentally serializing large datasets through Livewire's JSON cycle, an `OverflowException` is thrown when the result count exceeds `max_rows`:

```php
// Throws OverflowException if more than 500 rows are returned
$signal = Report::query()->toSignal(['max_rows' => 500]);
```

Set `max_rows` to `null` to disable the limit.

---

## License

MIT — see [LICENSE](LICENSE).
