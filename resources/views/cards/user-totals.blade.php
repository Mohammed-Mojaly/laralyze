@use('MohammedMojaly\Laralyze\Support\Chart')
@use('MohammedMojaly\Laralyze\Support\Format')
<div class="lz-span-full lz-grid" @if ($this->poll > 0) wire:poll.visible.{{ $this->poll }}s @endif>
    <x-laralyze::card :card="$this" title="Signed-in users" cols="6">
        @if ($users > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::number($users)" :label="$users == 1 ? 'user' : 'users'" />
            </div>

            <x-laralyze::bars :chart="new Chart($usersOverTime, $this->range())" />
        @else
            <x-laralyze::empty :title="'No signed-in users in the '.$this->range()->label().'.'" hint="Users count once the app has loaded them during a request." />
        @endif
    </x-laralyze::card>

    <x-laralyze::card title="Requests" cols="6">
        @if (array_sum($requests) > 0)
            <div class="lz-figures">
                <x-laralyze::figure :value="Format::number(array_sum($requests))" label="requests" />
                <x-laralyze::legend :items="$requests" :total="array_sum($requests)" />
            </div>

            <x-laralyze::bars :chart="new Chart($requestsOverTime, $this->range())" />
        @else
            <x-laralyze::empty :title="'No requests in the '.$this->range()->label().'.'" />
        @endif
    </x-laralyze::card>
</div>
