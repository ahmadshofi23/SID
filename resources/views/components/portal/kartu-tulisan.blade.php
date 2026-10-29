@props([
    'tulisan',
    /*
     * Varian lebar untuk tulisan teratas di halaman pertama daftar. Dipakai
     * sebagai penanda ("satu tulisan ini yang paling baru"), bukan sebagai
     * hiasan: pada daftar dua belas kartu seragam, tidak ada satu pun yang
     * menyatakan mana yang harus dibaca lebih dulu.
     */
    'utama' => false,
])

{{-- Satu kartu tulisan, dipakai beranda dan daftar berita/pengumuman. --}}
@php
    $rute = route('portal.tulisan', [$tulisan->type, $tulisan->slug]);
    $terbit = $tulisan->published_at
        ? \App\Support\VillageTime::local($tulisan->published_at)->locale('id')->translatedFormat('j F Y')
        : null;

    $gambar = $tulisan->cover_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($tulisan->cover_path)
        : null;
@endphp

<a href="{{ $rute }}"
   @class([
       'card card-lift group overflow-hidden hover:bg-surface-hover',
       'grid sm:grid-cols-2' => $utama,
       'flex h-full flex-col' => ! $utama,
   ])>
    @if ($gambar)
        {{-- Rasio tetap + lazy: tanpa width/height peramban tidak tahu tinggi
             gambar sebelum ia tiba, dan halaman melompat saat dimuat (CLS).

             Gambar tulisan utama TIDAK ber-lazy: ia berada di puncak halaman,
             dan menunda muatan yang langsung terlihat justru memperlambat LCP
             yang dibatasi 2,5 detik (NFR-PERF-03). --}}
        <span class="block overflow-hidden {{ $utama ? 'sm:h-full' : '' }}">
            <img src="{{ $gambar }}"
                 alt="" width="{{ $utama ? 960 : 640 }}" height="{{ $utama ? 540 : 360 }}"
                 @if (! $utama) loading="lazy" @endif decoding="async"
                 class="w-full object-cover transition-transform duration-300 group-hover:scale-[1.03] {{ $utama ? 'aspect-video sm:h-full' : 'aspect-video' }}">
        </span>
    @else
        {{--
          Tanpa gambar muka, kartu ini sebelumnya lebih pendek dari kartu
          bergambar di sebelahnya dan barisnya tampak patah. Bidang bermotif
          ini mengisi tempatnya — digambar CSS, tanpa satu pun permintaan
          jaringan tambahan (lihat .cover-fallback).
        --}}
        <span class="cover-fallback flex aspect-video items-center justify-center {{ $utama ? 'sm:h-full' : '' }}"
              aria-hidden="true">
            <svg class="size-14 text-accent opacity-35" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10 18v-7"/><path d="M11.12 2.198a2 2 0 0 1 1.76.006l7.866 3.847c.476.233.31.949-.22.949H3.474c-.53 0-.695-.716-.22-.949z"/>
                <path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>
            </svg>
        </span>
    @endif

    <span class="flex flex-1 flex-col {{ $utama ? 'p-6' : 'p-4' }}">
        <span class="flex flex-wrap items-center gap-2 text-xs text-content-tertiary">
            <span class="badge badge-accent">
                {{ \App\Models\Post::TYPE_LABELS[$tulisan->type] }}
            </span>
            @if ($utama)
                <span class="badge badge-gold">Terbaru</span>
            @endif
            @if ($terbit)
                <time datetime="{{ $tulisan->published_at->toIso8601String() }}">{{ $terbit }}</time>
            @endif
        </span>

        <span @class([
            'mt-2 font-semibold leading-snug group-hover:underline',
            'text-xl tracking-tight sm:text-2xl' => $utama,
        ])>{{ $tulisan->title }}</span>

        @if (filled($tulisan->excerpt))
            <span @class([
                'mt-2 flex-1 leading-relaxed text-content-secondary',
                'text-sm' => ! $utama,
            ])>{{ $utama ? \Illuminate\Support\Str::limit($tulisan->excerpt, 220) : $tulisan->excerpt }}</span>
        @else
            {{-- Tanpa ringkasan, ajakan di bawah akan menempel pada judul dan
                 kartu ini tampak setengah terisi di sebelah kartu yang penuh. --}}
            <span class="flex-1" aria-hidden="true"></span>
        @endif

        @if ($tulisan->type === \App\Models\Post::TYPE_AGENDA && $tulisan->event_start_at)
            <span class="chip mt-3 self-start">
                <svg class="size-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>
                </svg>
                {{ $tulisan->eventRangeLabel() }}
            </span>
        @endif

        {{-- Penanda "baca selengkapnya": kartu tanpa ajakan apa pun kerap
             tidak dikenali sebagai tautan oleh pengguna berusia 45+ yang
             justru menjadi sasaran rancangan ini (docs/07 §3). --}}
        <span class="link-arrow mt-4 text-sm">
            Baca selengkapnya
            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
            </svg>
        </span>
    </span>
</a>
