@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Slow jobs">
    @if ($jobs->isEmpty())
        <x-laralyze::empty
            :title="'No slow jobs in the '.$this->range()->label().'.'"
            hint="A job counts as slow once it runs longer than its threshold in config/laralyze.php."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Job</th>
                <th scope="col" class="lz-num">Count</th>
                <th scope="col" class="lz-num">Slowest</th>
                <th scope="col" class="lz-num">Threshold</th>
            </x-slot:head>

            @foreach ($jobs as $job)
                <tr wire:key="{{ $job->key }}">
                    <td><x-laralyze::class-name :name="$job->key" /></td>
                    <td class="lz-num lz-strong">{{ Format::number($job->count) }}</td>
                    <td class="lz-num lz-bad">{{ Format::duration($job->max) }}</td>
                    <td class="lz-num lz-muted">{{ Format::duration($job->threshold) }}</td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
