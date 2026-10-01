@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Visitors">
    @if (collect($groups)->flatten()->sum() <= 0)
        <x-laralyze::empty
            :title="'No visitors in the '.$this->range()->label().'.'"
            hint="Devices, systems and browsers are counted once per visitor per day."
        />
    @else
        <div class="lz-columns">
            @foreach ($groups as $group => $counts)
                @php($total = array_sum($counts))
                @php(arsort($counts))
                <section>
                    <h3 class="lz-label">{{ $group }}</h3>
                    <ul class="lz-bars-list">
                        @foreach ($counts as $name => $count)
                            <li>
                                <span>{{ $name }}</span>
                                <span class="lz-num">{{ Format::percent($count, $total) }}</span>
                                <x-laralyze::meter :percent="$total ? $count / $total * 100 : 0" tone="data" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</x-laralyze::card>
