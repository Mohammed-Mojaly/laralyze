@use('MohammedMojaly\Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Jobs" :count="$jobs->count()">
    <x-slot:actions>
        <x-laralyze::search placeholder="Search jobs" />
    </x-slot:actions>

    @if ($jobs->isEmpty())
        <x-laralyze::empty
            :title="$search === '' ? 'No jobs ran in the '.$this->range()->label().'.' : 'No jobs match “'.$search.'”.'"
            :hint="$search === '' ? 'Each job class gets a row once a worker has run it.' : null"
        />
    @else
        <x-laralyze::table class="lz-table-links">
            <x-slot:head>
                <x-laralyze::sort-header :card="$this" column="key" :num="false">Job</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="count">Runs</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="failed">Failed</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="avg">Avg</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="p95">p95</x-laralyze::sort-header>
                <x-laralyze::sort-header :card="$this" column="max">Slowest</x-laralyze::sort-header>
            </x-slot:head>

            @foreach ($jobs as $job)
                <tr wire:key="{{ md5($job->key) }}">
                    <td><a class="lz-row-link" href="{{ $this->groupUrl('jobs', $job->key) }}"><x-laralyze::class-name :name="$job->key" /></a></td>
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
