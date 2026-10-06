@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Queues">
    @if ($compact)
        <x-slot:actions>
            <a class="lz-button" href="{{ route('laralyze.page', ['page' => 'jobs', ...($this->period === '1h' ? [] : ['period' => $this->period])]) }}">All jobs</a>
        </x-slot:actions>
    @endif

    @if (array_sum($totals) > 0)
        <div class="lz-figures">
            <x-laralyze::figure :value="Format::number($totals['processed'])" label="processed" />
            <x-laralyze::legend :items="['queued' => $totals['queued'], 'released' => $totals['released'], 'failed' => $totals['failed']]" />
        </div>

        <x-laralyze::bars :chart="new Chart($series, $this->range())" />

        @if ($queues->isNotEmpty())
            <x-laralyze::table>
                <x-slot:head>
                    <th scope="col">Queue</th>
                    @unless ($compact)
                        <th scope="col" class="lz-num">Queued</th>
                    @endunless
                    <th scope="col" class="lz-num">Processed</th>
                    @unless ($compact)
                        <th scope="col" class="lz-num">Released</th>
                    @endunless
                    <th scope="col" class="lz-num">Failed</th>
                    @unless ($compact)
                        <th scope="col" class="lz-num">Avg wait</th>
                    @endunless
                    <th scope="col" class="lz-num">Longest wait</th>
                </x-slot:head>

                @foreach ($queues as $queue)
                    <tr wire:key="{{ $queue->name }}">
                        <td class="lz-mono">{{ $queue->name }}</td>
                        @unless ($compact)
                            <td class="lz-num">{{ Format::number($queue->queued) }}</td>
                        @endunless
                        <td class="lz-num lz-strong">{{ Format::number($queue->processed) }}</td>
                        @unless ($compact)
                            <td class="lz-num">{{ Format::number($queue->released) }}</td>
                        @endunless
                        <td @class(['lz-num', 'lz-bad' => $queue->failed > 0])>{{ Format::number($queue->failed) }}</td>
                        @unless ($compact)
                            <td class="lz-num">{{ Format::duration($queue->wait) }}</td>
                        @endunless
                        <td class="lz-num">{{ Format::duration($queue->max_wait) }}</td>
                    </tr>
                @endforeach
            </x-laralyze::table>
        @endif
    @else
        <x-laralyze::empty
            :title="'No jobs in the '.$this->range()->label().'.'"
            hint="Jobs appear here once they are queued or a worker picks them up."
        />
    @endif
</x-laralyze::card>
