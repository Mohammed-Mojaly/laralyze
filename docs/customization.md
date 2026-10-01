# Customizing the dashboard

The dashboard is plain Blade and Livewire: pages in a sidebar, each made of cards. Everything below is code you own; nothing is configured through a UI.

## Rearrange a page

```bash
php artisan vendor:publish --tag=laralyze-views
```

This copies the layout, the components and every page to `resources/views/vendor/laralyze`. A page is a list of cards:

```blade
{{-- resources/views/vendor/laralyze/pages/requests.blade.php --}}
<x-laralyze::page>
    <livewire:laralyze.request-totals cols="6" />
    <livewire:laralyze.request-duration cols="6" />
    <livewire:laralyze.routes cols="full" />
</x-laralyze::page>
```

Cards take `cols` (1 to 12, or `full`), `rows`, `class`, `poll` (seconds between refreshes, `0` to stop) and, on list cards, `limit`.

## Restyle a card

```bash
php artisan vendor:publish --tag=laralyze-cards
```

Edit any file in `resources/views/vendor/laralyze/cards`. Only the markup is copied; the card's logic stays in the package, so you keep getting fixes.

## Change what a card does

Extend the card and register your class under the same name:

```php
namespace App\Laralyze;

use Laralyze\Cards\Routes as BaseRoutes;

class Routes extends BaseRoutes
{
    public int $limit = 20;
}
```

```php
// config/laralyze.php
'cards' => [
    'routes' => App\Laralyze\Routes::class,
],
```

## Make your own card

```bash
php artisan laralyze:make-card CheckoutFunnel
```

On Livewire 4 this creates a single-file card; on Livewire 3, a class and a view. Put it on a page with `<livewire:laralyze.checkout-funnel cols="6" />`.

Cards extend `Laralyze\Livewire\Card` and read data for the period selected in the top bar:

| Method | Returns |
|---|---|
| `aggregate($type, ['count', 'avg', 'p95'], orderBy: 'count', limit: 100)` | One row per key |
| `total($type, ['count', 'max'])` | One row for all keys together |
| `graph($type, 'count', key: null)` | About 60 points over the period |
| `values($type, $keys)` | The latest values stored with `Laralyze::set()` |
| `counts($type)` | `[key => count]`, handy for joining columns |

Aggregates are `count`, `sum`, `min`, `max`, `avg` and percentiles like `p50`, `p95`, `p99` (when recorded with `histogram()`). Results are cached for five seconds, so many people watching the dashboard share one query.

Building blocks for card views: `x-laralyze::card`, `x-laralyze::table`, `x-laralyze::figure`, `x-laralyze::legend`, `x-laralyze::bars`, `x-laralyze::lines`, `x-laralyze::meter`, `x-laralyze::empty`, `x-laralyze::class-name`, `x-laralyze::ago`.

## Record your own metrics

```php
use Laralyze\Facades\Laralyze;

Laralyze::record('checkout', $plan, $total)->count()->sum()->max();
Laralyze::record('import', $source, $milliseconds)->avg()->histogram(); // enables p50/p95/p99
Laralyze::set('feature_flags', 'checkout_v2', 'on');
```

Everything is merged in memory and written once, after the response is sent.

## Add a page

```php
// config/laralyze.php
'pages' => [
    'checkout' => ['label' => 'Checkout', 'section' => 'Business', 'view' => 'laralyze.checkout', 'icon' => 'page'],
    'servers' => false, // hide a built-in page
],
```

```blade
{{-- resources/views/laralyze/checkout.blade.php --}}
<x-laralyze::page>
    <livewire:laralyze.checkout-funnel cols="6" />
</x-laralyze::page>
```

## Write a recorder

```php
namespace App\Laralyze;

use App\Events\OrderPlaced;
use Laralyze\Recorders\Recorder;

class Orders extends Recorder
{
    protected array $listen = [OrderPlaced::class];

    public function record(OrderPlaced $event): void
    {
        $this->laralyze->record('orders', $event->order->plan, $event->order->total)->count()->sum();
    }
}
```

```php
// config/laralyze.php
'recorders' => [
    App\Laralyze\Orders::class => ['enabled' => true],
],
```

A recorder gets its config array in `$this->config`, plus `threshold()`, `shouldIgnore()`, `group()` and `shouldSample()`.

## Keep data out

```php
// In a service provider
Laralyze::filter(fn (string $type, string $key) => ! str_contains($key, '@'));
```

Rejected rows are dropped before they are written.

## Show users your way

```php
Laralyze::user(fn (User $user) => [
    'name' => $user->full_name,
    'extra' => $user->team->name,
]);
```

## Colours and theme

The dashboard follows the visitor's light or dark preference, with a toggle in the top bar. Colours are CSS custom properties (`--lz-ink`, `--lz-bad`, `--lz-warn`…). Override them in the published layout:

```blade
<style>
    :root { --lz-bad: #d0342c; }
</style>
```
