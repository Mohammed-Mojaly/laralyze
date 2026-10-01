@props(['at'])
@if ($at)
    @php($time = \Illuminate\Support\Carbon::createFromTimestamp((int) $at, (string) config('app.timezone', 'UTC')))
    <time datetime="{{ $time->toIso8601String() }}" title="{{ $time->format('M j, H:i:s') }}">{{ $time->diffForHumans(short: true) }}</time>
@else
    <span class="lz-muted">—</span>
@endif
