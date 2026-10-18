@use('MohammedMojaly\Laralyze\Alerts\Filter')
@use('MohammedMojaly\Laralyze\Alerts\Rule')
@use('MohammedMojaly\Laralyze\Alerts\Metrics\Metric')
@php
    $units = ['count' => '', '%' => '%', 'ms' => 'ms', 's' => 'seconds', '$' => '$', 'min' => 'minutes'];
    $all = in_array(Rule::ALL_CHANNELS, (array) ($form['channels'] ?? []), true);
    $more = ($form['filter_by'] ?? '') !== '' || ($form['mode'] ?? Rule::TOGETHER) === Rule::EACH || (string) ($form['remind'] ?? '0') !== '0'
        || ($form['title'] ?? '') !== '' || ($form['note'] ?? '') !== ''
        || $errors->hasAny(['form.filter_values', 'form.filter_choices', 'form.title', 'form.note']);
@endphp
<x-laralyze::card :title="$editing === 'new' ? 'New rule' : 'Edit rule'">
    <form wire:submit="save" class="lz-form">
        <section class="lz-step">
            <h3 class="lz-step-title"><span class="lz-step-num">1</span> What to watch</h3>
            <label class="lz-field">
                <span class="lz-offscreen">Watch</span>
                <select wire:model.live="form.metric">
                    @foreach (collect($available)->groupBy(fn (Metric $option) => $option->area, true) as $area => $options)
                        <optgroup label="{{ $area }}">
                            @foreach ($options as $key => $option)
                                <option value="{{ $key }}">{{ $area }} · {{ $option->label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @if ($metric?->hint())
                    <span class="lz-field-hint">{{ $metric->hint() }}</span>
                @endif
                @error('form.metric') <span class="lz-field-error">{{ $message }}</span> @enderror
            </label>
        </section>

        <section class="lz-step">
            <h3 class="lz-step-title"><span class="lz-step-num">2</span> When to alert</h3>
            @if ($metric && $metric->kind() === Metric::EVENT)
                <p class="lz-field-hint">Each one is sent once, as it happens.</p>
            @elseif ($metric)
                <div class="lz-sentence">
                    <span>When it's</span>
                    <select wire:model.live="form.operator" aria-label="Condition">
                        @foreach (Rule::OPERATOR_WORDS as $operator => $word)
                            <option value="{{ $operator }}">{{ $word }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="any" wire:model.live.debounce.500ms="form.threshold" aria-label="Threshold" placeholder="0">
                    @if ($units[$metric->unit] !== '')
                        <span>{{ $units[$metric->unit] }}</span>
                    @endif
                    @if ($metric->kind() === Metric::NUMBER)
                        <span>over</span>
                        <select wire:model.live="form.window" aria-label="Over">
                            @foreach (Rule::WINDOWS as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                @error('form.threshold') <span class="lz-field-error">{{ $message }}</span> @enderror
            @endif

            @if ($preview !== '')
                <p class="lz-rule-preview" wire:loading.class="is-loading">{{ $preview }}</p>
            @endif
        </section>

        <section class="lz-step">
            <h3 class="lz-step-title"><span class="lz-step-num">3</span> Where to send it</h3>
            <div class="lz-pills" role="group" aria-label="Send to">
                <label class="lz-pill"><input type="checkbox" value="{{ Rule::ALL_CHANNELS }}" wire:model.live="form.channels"> Every channel</label>
                @foreach ($channels as $id => $label)
                    <label class="lz-pill"><input type="checkbox" value="{{ $id }}" wire:model.live="form.channels" @disabled($all)> {{ $label }}</label>
                @endforeach
            </div>
            @if ($channels === [])
                <span class="lz-field-hint">No channel is set in .env yet. The rule still shows here and in the bell.</span>
            @endif
            @foreach ($errors->get('form.channels.*') as $messages)
                <span class="lz-field-error">{{ $messages[0] }}</span>
            @endforeach
        </section>

        <label class="lz-field">
            <span class="lz-field-label">Name <span class="lz-muted">(optional)</span></span>
            <input type="text" wire:model="form.name" maxlength="100" placeholder="{{ $autoName }}">
            @error('form.name') <span class="lz-field-error">{{ $message }}</span> @enderror
        </label>

        <div class="lz-more" x-data="{ open: @js($more) }">
            <button type="button" class="lz-more-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open">
                <span class="lz-more-caret" x-bind:class="open && 'is-open'">▸</span> More options
                <span class="lz-muted">filter, reminders, message</span>
            </button>

            <div class="lz-more-body" x-show="open" x-cloak>
                @if ($metric && $metric->dimensions() !== [])
                    <div class="lz-field-row">
                        <label class="lz-field">
                            <span class="lz-field-label">Only</span>
                            <select wire:model.live="form.filter_by">
                                <option value="">Everything</option>
                                @foreach ($metric->dimensions() as $name => $option)
                                    <option value="{{ $name }}">{{ $option->label }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($dimension)
                            <label class="lz-field">
                                <span class="lz-field-label">{{ $dimension->label }}</span>
                                <select wire:model="form.filter_operator">
                                    @foreach ($dimension->operators() as $operator)
                                        <option value="{{ $operator }}">{{ Filter::OPERATORS[$operator] }}</option>
                                    @endforeach
                                </select>
                            </label>

                            @if ($dimension->type === 'list')
                                <fieldset class="lz-field lz-field-grow">
                                    <legend class="lz-field-label">Values</legend>
                                    <div class="lz-pills">
                                        @foreach ($dimension->options as $value => $label)
                                            <label class="lz-pill"><input type="checkbox" value="{{ $value }}" wire:model.live="form.filter_choices"> {{ $label }}</label>
                                        @endforeach
                                    </div>
                                    @error('form.filter_choices') <span class="lz-field-error">{{ $message }}</span> @enderror
                                </fieldset>
                            @else
                                <label class="lz-field lz-field-grow">
                                    <span class="lz-field-label">Values, comma separated</span>
                                    <input type="text" list="lz-filter-suggestions" wire:model.live.debounce.500ms="form.filter_values">
                                    <datalist id="lz-filter-suggestions">
                                        @foreach ($suggestions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </datalist>
                                    @error('form.filter_values') <span class="lz-field-error">{{ $message }}</span> @enderror
                                </label>
                            @endif
                        @endif
                    </div>

                    @if ($metric->kind() !== Metric::EVENT)
                        <fieldset class="lz-field">
                            <legend class="lz-field-label">When several match</legend>
                            <div class="lz-pills">
                                <label class="lz-pill"><input type="radio" value="{{ Rule::TOGETHER }}" wire:model.live="form.mode"> All together, as one</label>
                                <label class="lz-pill"><input type="radio" value="{{ Rule::EACH }}" wire:model.live="form.mode"> Each one separately</label>
                            </div>
                        </fieldset>
                    @endif
                @endif

                @if ($metric && $metric->kind() !== Metric::EVENT)
                    <label class="lz-field">
                        <span class="lz-field-label">Remind while it's firing</span>
                        <select wire:model="form.remind">
                            @foreach (Rule::REMINDERS as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="lz-field">
                    <span class="lz-field-label">Message title</span>
                    <input type="text" wire:model="form.title" maxlength="150" placeholder="{rule}: {item} {value}">
                    <span class="lz-field-hint">Use {rule}, {item}, {value} and {threshold}. Empty: the rule's name, the item, the value and the condition.</span>
                </label>

                <label class="lz-field">
                    <span class="lz-field-label">Note in the message</span>
                    <textarea wire:model="form.note" rows="3" maxlength="1000" placeholder="What to check first"></textarea>
                </label>
            </div>
        </div>

        <div class="lz-form-foot">
            @if ($summary !== '')
                <p class="lz-form-summary">{{ $summary }}</p>
            @endif
            <div class="lz-form-actions">
                <button type="submit" class="lz-button lz-button-primary">{{ $editing === 'new' ? 'Create rule' : 'Save changes' }}</button>
                <button type="button" class="lz-button" wire:click="cancel">Cancel</button>
            </div>
        </div>
    </form>
</x-laralyze::card>
