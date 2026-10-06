{{-- "Ask AI" about one issue, or about the whole app. Opens the assistant panel. --}}
@props(['kind' => 'general', 'key' => '', 'label' => 'Ask AI'])
@if (\MohammedMojaly\Laralyze\Livewire\AssistantPanel::allowed())
    <button type="button" {{ $attributes->class('lz-button lz-ask') }} data-laralyze-ask data-kind="{{ $kind }}" data-key="{{ $key }}"><x-laralyze::icon name="ai" /><span>{{ $label }}</span></button>
@endif
