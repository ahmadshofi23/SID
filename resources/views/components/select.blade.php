@props([
    'name',
    'label',
    'options' => [],
    'selected' => null,
    'required' => false,
    'placeholder' => '— Pilih —',
    'hint' => null,
    'grouped' => false,
])

@php $hasError = $errors->has($name); @endphp

<div class="sid-field">
    <label class="sid-label" for="{{ $name }}">
        {{ $label }}
        @if ($required)
            <span class="sid-label__required" aria-hidden="true">*</span>
            <span class="sid-sr-only">(wajib diisi)</span>
        @endif
    </label>

    <select id="{{ $name }}" name="{{ $name }}" class="sid-select"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
        <option value="">{{ $placeholder }}</option>

        @if ($grouped)
            @foreach ($options as $groupLabel => $groupOptions)
                <optgroup label="{{ $groupLabel }}">
                    @foreach ($groupOptions as $value => $text)
                        <option value="{{ $value }}"
                            @selected((string) old($name, $selected) === (string) $value)>{{ $text }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        @else
            @foreach ($options as $value => $text)
                <option value="{{ $value }}"
                    @selected((string) old($name, $selected) === (string) $value)>{{ $text }}</option>
            @endforeach
        @endif
    </select>

    @if ($hasError)
        <p class="sid-error" id="{{ $name }}-error" role="alert">{{ $errors->first($name) }}</p>
    @elseif ($hint)
        <p class="sid-hint">{{ $hint }}</p>
    @endif
</div>
