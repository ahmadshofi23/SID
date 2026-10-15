@props([
    'type' => 'info',
    'title' => null,
])

{{--
  Padanan Blade dari resources/js/Components/AppAlert.vue.

  Panel administrasi memakai komponen Vue; halaman yang dilayani langsung oleh
  Blade (portal publik, halaman galat) tidak memuat Vue sama sekali. Tanpa
  padanan ini, setiap halaman Blade menuliskan ulang kelasnya sendiri dan
  tampilannya menyimpang satu demi satu.

  Nama jenis memakai istilah Indonesia (sukses/peringatan/galat) karena itulah
  yang dipakai di seluruh tampilan; padanan Inggris tetap diterima agar sama
  dengan props AppAlert.vue.
--}}
@php
    $variants = [
        'info' => [
            'cls' => 'bg-info-soft text-info border-info/25',
            'sr' => 'Informasi',
            // Ikon mengikuti set Lucide yang dipakai AppAlert.vue, digambar
            // sebagai SVG sebaris: halaman Blade tidak memuat paket ikon.
            'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        ],
        'sukses' => [
            'cls' => 'bg-success-soft text-success border-success/25',
            'sr' => 'Berhasil',
            'icon' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        ],
        'peringatan' => [
            'cls' => 'bg-warning-soft text-warning border-warning/25',
            'sr' => 'Perhatian',
            'icon' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        ],
        'galat' => [
            'cls' => 'bg-danger-soft text-danger border-danger/25',
            'sr' => 'Gagal',
            'icon' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        ],
    ];

    $aliases = [
        'success' => 'sukses',
        'warning' => 'peringatan',
        'danger' => 'galat',
        'error' => 'galat',
    ];

    $key = $aliases[$type] ?? $type;
    $variant = $variants[$key] ?? $variants['info'];

    // Hanya galat yang memakai role="alert": pembaca layar memotong apa pun
    // yang sedang dibacakan, jadi itu disimpan untuk hal yang memang mendesak.
    $role = $key === 'galat' ? 'alert' : 'status';
@endphp

<div {{ $attributes->class(['flex gap-3 rounded-lg border p-4 text-sm animate-rise', $variant['cls']]) }} role="{{ $role }}">
    <svg class="mt-0.5 size-5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
         stroke-linejoin="round" aria-hidden="true">
        {!! $variant['icon'] !!}
    </svg>

    <div class="min-w-0 flex-1">
        {{-- Makna tidak boleh bergantung warna saja (WCAG 1.4.1). --}}
        <span class="sr-only">{{ $variant['sr'] }}:</span>
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
    </div>
</div>
