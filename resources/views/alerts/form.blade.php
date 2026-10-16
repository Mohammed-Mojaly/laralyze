@use('MohammedMojaly\Laralyze\Alerts\Filter')
@use('MohammedMojaly\Laralyze\Alerts\Rule')
@use('MohammedMojaly\Laralyze\Alerts\Metrics\Metric')
@php
    $units = ['count' => '', '%' => '%', 'ms' => 'ms', 's' => 'seconds', '$' => '$', 'min' => 'minutes'];
    $all = in_array(Rule::ALL_CHANNELS, (array) ($form['channels'] ?? []), true);
@endphp
<x-laralyze::card :title="$editing === 'new' ? 'New rule' : 'Edit rule'">
    <form wire:submit="save" class="lz-form">
        <label class="lz-field">
            <span class="lz-field-label">Name</span>
            <input type="text" wire:model="form.name" maxlength="100" required>
            @error('form.name') <span class="lz-field-error">{{ $message }}</span> @enderror
        </label>

        <label class="lz-field">
            <span class="lz-field-label">Watch</span>
            <select wire:model.live="form.metric">
                @foreach (collect($available)->groupBy(fn (Metric $option) => $option->area, true) as $area => $options)
                    <optgroup label="{{ $area }}">
                        @foreach ($options as $key => $option)
                            <option value="{{ $key }}">{{ $option->label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            @if ($metric?->hint())
                <span class="lz-field-hint">{{ $metric->hint() }}</span>
            @endif
            @error('form.metric') <span class="lz-field-error">{{ $message }}</span> @enderror
        </label>

        @if ($metric && $metric->kind() !== Metric::EVENT)
            <div class="lz-field-row">
                <label class="lz-field">
                    <span class="lz-field-label">When it's</span>
                    <select wire:model.live="form.operator">
                        @foreach (Rule::OPERATORS as $operator)
                            <option value="{{ $operator }}">{{ $operator }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="lz-field">
                    <span class="lz-field-label">Threshold @if ($units[$metric->unit] !== '') ({{ $units[$metric->unit] }}) @endif</span>
                    <input type="number" step="any" wire:model.live.debounce.500ms="form.threshold">
                    @error('form.threshold') <span class="lz-field-error">{{ $message }}</span> @enderror
                </label>
                @if ($metric->kind() === Metric::NUMBER)
                    <label class="lz-field">
                        <span class="lz-field-label">Over</span>
                        <select wire:model.live="form.window">
                            @foreach (Rule::WINDOWS as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        @endif

        @if ($preview !== '')
            <p class="lz-rule-preview" wire:loading.class="is-loading">{{ $preview }}</p>
        @endif

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
                        <fieldset class="lz-field lz-choices">
                            <legend class="lz-field-label">Values</legend>
                            @foreach ($dimension->options as $value => $label)
                                <label class="lz-check"><input type="checkbox" value="{{ $value }}" wire:model.live="form.filter_choices"> {{ $label }}</label>
                            @endforeach
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
        @endif

        @if ($metric && $metric->kind() !== Metric::EVENT && $metric->dimensions() !== [])
            <fieldset class="lz-field">
                <legend class="lz-field-label">Several items</legend>
                <label class="lz-check"><input type="radio" value="{{ Rule::TOGETHER }}" wire:model.live="form.mode"> All together, as one</label>
                <label class="lz-check"><input type="radio" value="{{ Rule::EACH }}" wire:model.live="form.mode"> Each separately, with its own state</label>
            </fieldset>
        @endif

        <fieldset class="lz-field">
            <legend class="lz-field-label">Send to</legend>
            @if ($channels === [])
                <span class="lz-field-hint">No channel is set in .env yet. The rule still fires here and in the bell.</span>
            @endif
            <label class="lz-check"><input type="checkbox" value="{{ Rule::ALL_CHANNELS }}" wire:model.live="form.channels"> Every channel set in .env</label>
            @foreach ($channels as $id => $label)
                <label class="lz-check"><input type="checkbox" value="{{ $id }}" wire:model.live="form.channels" @disabled($all)> {{ $label }}</label>
            @endforeach
            @foreach ($errors->get('form.channels.*') as $messages)
                <span class="lz-field-error">{{ $messages[0] }}</span>
            @endforeach
        </fieldset>

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
            <span class="lz-field-label">Title (optional)</span>
            <input type="text" wire:model="form.title" maxlength="150" placeholder="{rule}: {item} {value}">
            <span class="lz-field-hint">Use {rule}, {item}, {value} and {threshold}. Empty: the rule's name, the item, the value and the condition.</span>
        </label>

        <label class="lz-field">
            <span class="lz-field-label">Note (optional)</span>
            <textarea wire:model="form.note" rows="3" maxlength="1000" placeholder="What to check first"></textarea>
        </label>

        <div class="lz-form-actions">
            <button type="submit" class="lz-button lz-button-primary">Save</button>
            <button type="button" class="lz-button" wire:click="cancel">Cancel</button>
        </div>
    </form>
</x-laralyze::card>
