@use('Laralyze\Support\Chart')
@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Requests">
    @if ($total > 0)
        <div class="lz-figures">
            <p class="lz-figure">
                <span class="lz-figure-value">{{ Format::number($total) }}</span>
                <span class="lz-figure-label">requests</span>
            </p>

            <dl class="lz-legend">
                @foreach ($statuses as $class => $count)
                    <div @class(["lz-legend-item lz-s-{$class}", 'is-zero' => $count == 0])>
                        <dt><span class="lz-swatch"></span>{{ $class }}</dt>
                        <dd>
                            {{ Format::number($count) }}
                            <small>{{ Format::percent($count, $total) }}</small>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />
    @else
        <x-laralyze::empty
            :title="'No requests in the '.$this->range()->label().'.'"
            hint="Requests appear here a moment after they finish."
        />
    @endif
</x-laralyze::card>
