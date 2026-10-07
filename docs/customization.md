# Customizing the dashboard

The dashboard is Blade and Livewire: pages in a sidebar, each made of cards.

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

To change one page only, keep just that file in `resources/views/vendor/laralyze/pages` (or copy it there from the package's `resources/views/pages`). Every file you don't keep still comes from the package, with its fixes.

## Restyle a card

```bash
php artisan vendor:publish --tag=laralyze-cards
```

Edit any file in `resources/views/vendor/laralyze/cards`. Only the markup is copied; the card's logic stays in the package, so you keep getting fixes.

## Change what a card does

Extend the card and register your class under the same name:

```php
namespace App\Laralyze;

use MohammedMojaly\Laralyze\Cards\Routes as BaseRoutes;

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

Cards extend `MohammedMojaly\Laralyze\Livewire\Card` and read data for the period selected in the top bar:

| Method | Returns |
|---|---|
| `aggregate($type, ['count', 'avg', 'p95'], orderBy: 'count', limit: 100)` | One row per key |
| `total($type, ['count', 'max'])` | One row for all keys together |
| `graph($type, 'count', key: null)` | About 60 points over the period |
| `values($type, $keys)` | The latest values stored with `Laralyze::set()` |
| `counts($type)` | `[key => count]`, handy for joining columns |

Aggregates are `count`, `sum`, `min`, `max`, `avg` and percentiles like `p95` (estimates, when recorded with `histogram()`). Results are cached for five seconds.

Building blocks for card views: `x-laralyze::card`, `x-laralyze::table`, `x-laralyze::figure`, `x-laralyze::legend`, `x-laralyze::bars`, `x-laralyze::lines`, `x-laralyze::meter`, `x-laralyze::empty`, `x-laralyze::class-name`, `x-laralyze::ago`.

## Record your own metrics

```php
use MohammedMojaly\Laralyze\Facades\Laralyze;

Laralyze::record('checkout', $plan, $total)->count()->sum()->max();
Laralyze::record('import', $source, $milliseconds)->avg()->histogram(); // enables p50/p95/p99
Laralyze::set('feature_flags', 'checkout_v2', 'on');
```

Then show them with a card of your own.

## Example: an orders card

Record each order where it happens:

```php
Laralyze::record('orders', 'paid', $order->total)->count()->sum();
Laralyze::record('orders', 'declined')->count();
```

Create the card with `php artisan laralyze:make-card Orders` and replace its contents:

```blade
{{-- resources/views/components/laralyze/orders.blade.php --}}
<?php

use Livewire\Attributes\Lazy;
use MohammedMojaly\Laralyze\Livewire\Card;

new #[Lazy] class extends Card
{
    public function with(): array
    {
        $rows = $this->aggregate('orders', ['count', 'sum'])->keyBy('key');

        return [
            'counts' => [
                'paid' => (float) ($rows['paid']->count ?? 0),
                'declined' => (float) ($rows['declined']->count ?? 0),
            ],
            'revenue' => (float) ($rows['paid']->sum ?? 0),
            'series' => [
                'paid' => $this->graph('orders', 'count', key: 'paid'),
                'declined' => $this->graph('orders', 'count', key: 'declined'),
            ],
        ];
    }
};
?>

@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Orders">
    <style>
        .lz-s-paid { --lz-series: var(--lz-live); }
        .lz-s-declined { --lz-series: var(--lz-bad); }
    </style>

    @if (array_sum($counts) > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::money($revenue)" label="revenue" />
            <x-laralyze::legend :items="$counts" :total="array_sum($counts)" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />
    @else
        <x-laralyze::empty :title="'No orders in the '.$this->range()->label().'.'" />
    @endif
</x-laralyze::card>
```

- `figure` shows one big number with a label.
- `legend` shows each `name => value`, with its share of `total`.
- `bars` stacks the series of a `Chart` over the selected period; `lines` draws them as lines.
- A series gets its colour from `.lz-s-{name}`. Built-in names have one; give your own names one, as above.
- `Format` has `number()`, `money()`, `percent()` and `duration()`.

On Livewire 3, `make-card` creates a class and a view instead: return the same array from `render()` with `view(...)`, and put the markup in the view.

## Add a page

```php
// config/laralyze.php
'pages' => [
    'checkout' => ['label' => 'Checkout', 'section' => 'Business', 'view' => 'laralyze.checkout', 'icon' => 'page'],
    'servers' => false, // hide a built-in page
],
```

Built-in pages sit under Issues, Activity, Inside and Audience, with System (Servers) at the foot of the sidebar. Use one of those as `section`, or a new name to add a section before System. `icon` takes a built-in page's name (`requests`, `queries`, `ai`, `users`…), `folder`, `monitor` or `page`, the default.

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
use MohammedMojaly\Laralyze\Recorders\Recorder;

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

Rejected rows are dropped before they are written. A request, job or command whose route, class or name is rejected loses its timelines too.

## Show users your way

```php
Laralyze::user(fn (User $user) => [
    'name' => $user->full_name,
    'extra' => $user->team->name,
]);
```

## Colours and theme

The dashboard follows the visitor's light or dark preference, with a toggle in the top bar. The sidebar stays dark in both. Colours are CSS custom properties (`--lz-accent`, `--lz-ink`, `--lz-bad`, `--lz-warn`, `--lz-side-bg`…). Override them in the published layout, e.g. to use your own brand colour:

```blade
<style>
    :root { --lz-accent: #c2410c; --lz-side-accent: #fb9a6c; }
</style>
```
