@use('MohammedMojaly\Laralyze\Support\Format')
{{-- One root for Livewire whether or not there is anything to show. Hidden, it still polls, so a problem shows up without reloading. --}}
<section class="lz-card lz-span-full lz-rows-1 lz-attention" {!! $lines === [] ? 'hidden wire:poll.30s' : 'wire:poll.visible.'.$this->poll.'s' !!}>
    <header class="lz-card-head">
        <h2>Needs attention</h2>

        <div class="lz-card-actions">
            <span class="lz-took" title="Time spent reading this card's data">{{ Format::duration($this->queryTime()) }}</span>
        </div>
    </header>

    <div class="lz-card-body">
        <ul class="lz-attention-list">
            @foreach ($lines as $line)
                <li @class(['lz-attention-item', 'lz-attention-'.$line['level']]) wire:key="attention-{{ $loop->index }}">
                    <a href="{{ $line['url'] }}">{{ $line['text'] }}</a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
