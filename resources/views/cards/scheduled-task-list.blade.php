@use('Laralyze\Support\Format')
<x-laralyze::card :card="$this" title="Scheduled tasks">
    @if ($tasks->isEmpty())
        <x-laralyze::empty
            title="No scheduled task has run yet."
            hint="Tasks appear here after the scheduler runs them. Make sure your cron entry calls schedule:run every minute."
        />
    @else
        <x-laralyze::table>
            <x-slot:head>
                <th scope="col">Task</th>
                <th scope="col" class="lz-num">Runs</th>
                <th scope="col" class="lz-num">Failed</th>
                <th scope="col" class="lz-num">Skipped</th>
                <th scope="col" class="lz-num">Avg</th>
                <th scope="col">Last run</th>
                <th scope="col">Next run</th>
            </x-slot:head>

            @foreach ($tasks as $task)
                <tr wire:key="{{ $task->name }}">
                    <td>
                        <span class="lz-mono">{{ $task->name }}</span>
                        @if ($task->expression)
                            <span class="lz-sub lz-mono">{{ $task->expression }}</span>
                        @endif
                    </td>
                    <td class="lz-num lz-strong">{{ Format::number($task->runs) }}</td>
                    <td @class(['lz-num', 'lz-bad' => $task->failed > 0])>{{ Format::number($task->failed) }}</td>
                    <td @class(['lz-num', 'lz-warn' => $task->skipped > 0])>{{ Format::number($task->skipped) }}</td>
                    <td class="lz-num">{{ Format::duration($task->avg) }}</td>
                    <td>
                        @if ($task->status)
                            <span @class(['lz-status', "is-{$task->status}"])>{{ $task->status }}</span>
                        @endif
                        <x-laralyze::ago :at="$task->ran_at" />
                    </td>
                    <td><x-laralyze::ago :at="$task->next_at" /></td>
                </tr>
            @endforeach
        </x-laralyze::table>
    @endif
</x-laralyze::card>
