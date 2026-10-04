@props(['type' => 'info', 'title' => null])

@php
    // Warna TIDAK PERNAH satu-satunya pembawa makna: setiap jenis punya ikon
    // dan label teks tersendiri (WCAG 1.4.1, docs/07-ui-ux.md §3.1).
    $icon = ['info' => 'i', 'success' => '✓', 'warning' => '!', 'danger' => '×'][$type] ?? 'i';
    $label = [
        'info' => 'Informasi', 'success' => 'Berhasil',
        'warning' => 'Perhatian', 'danger' => 'Gagal',
    ][$type] ?? 'Informasi';
@endphp

<div class="sid-alert sid-alert--{{ $type }}" role="{{ $type === 'danger' ? 'alert' : 'status' }}">
    <span class="sid-alert__icon" aria-hidden="true">{{ $icon }}</span>
    <div class="sid-alert__body">
        <span class="sid-sr-only">{{ $label }}:</span>
        @if ($title)<strong>{{ $title }}</strong><br>@endif
        {{ $slot }}
    </div>
</div>
