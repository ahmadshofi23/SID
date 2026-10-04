@props([
    'name',
    'label',
    'type' => 'text',
    'required' => false,
    'hint' => null,
    'value' => null,
    'autocomplete' => null,
    'inputmode' => null,
    'autofocus' => false,
    'inputClass' => '',
])

@php $hasError = $errors->has($name); @endphp

<div class="sid-field">
    {{-- Label DI ATAS field; placeholder tidak dipakai sebagai label --}}
    <label class="sid-label" for="{{ $name }}">
        {{ $label }}
        @if ($required)
            <span class="sid-label__required" aria-hidden="true">*</span>
            <span class="sid-sr-only">(wajib diisi)</span>
        @endif
    </label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($name, $value) }}"
        class="sid-input {{ $inputClass }} @if($hasError) sid-input--invalid @endif"
        @if ($required) required @endif
        @if ($autofocus) autofocus @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($inputmode) inputmode="{{ $inputmode }}" @endif
        @if ($hint || $hasError)
            aria-describedby="{{ $name }}-{{ $hasError ? 'error' : 'hint' }}"
        @endif
        @if ($hasError) aria-invalid="true" @endif
    >

    @if ($hasError)
        {{-- Pesan galat diumumkan ke pembaca layar (NFR-USA-03) --}}
        <p class="sid-error" id="{{ $name }}-error" role="alert">{{ $errors->first($name) }}</p>
    @elseif ($hint)
        <p class="sid-hint" id="{{ $name }}-hint">{{ $hint }}</p>
    @endif
</div>
