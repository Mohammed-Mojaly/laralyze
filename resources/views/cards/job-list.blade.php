@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Jobs">
    @if ($jobs->isEmpty())
        <x-laralyze::empty
            :title="'No jobs ran in the '.$this->range()->label().'.'"
            hint="Each job class gets a row once a worker has run it."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Job</th>
                <th scope="col" class="lz-num">Runs</th>
                <th scope="col" class="lz-num">Failed</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col" class="lz-num">p95</th>
                <th scope="col" class="lz-num">Slowest</th>
            </x-slot:head>

            @foreach ($jobs as $job)
                <tr wire:key="{{ $job->key }}">
                    <td><x-laralyze::class-name :name="$job->key" /></td>
                    <td class="lz-num lz-strong">{{ Format::number($job->count) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $job->failed > 0])>{{ Format::number($job->failed) }}</td>
                    <td class="lz-num">{{ Format::duration($job->avg) }}</td>
                    <td class="lz-num">{{ Format::duration($job->p95) }}</td>
                    <td class="lz-num">{{ Format::duration($job->max) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
