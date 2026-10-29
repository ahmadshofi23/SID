{{--
  Satu berita / pengumuman / agenda — FR-PRT-02, FR-PRT-03, FR-PRT-08.

  Isi tulisan dicetak dengan {!! !!} secara SENGAJA: ia memang HTML. Yang
  membuatnya aman bukan pencetakan di sini melainkan HtmlSanitizer profil
  `artikel` yang membersihkannya SAAT DISIMPAN — daftar izin tag, dan skema
  URL yang dibatasi http/https/mailto/tel sehingga `javascript:` pada tautan
  tidak pernah sampai ke basis data.
--}}
@php
    $label = \App\Models\Post::TYPE_LABELS[$tulisan->type];
    $terbit = $tulisan->published_at
        ? \App\Support\VillageTime::local($tulisan->published_at)->locale('id')
        : null;

    $ringkas = $tulisan->meta_description
        ?? $tulisan->excerpt
        ?? \Illuminate\Support\Str::limit(strip_tags($tulisan->body), 160);

    /*
     * Perkiraan lama baca: 200 kata per menit, dibulatkan ke atas, minimal
     * satu menit. Disebut "perkiraan" di layar karena memang perkiraan —
     * tetapi pada pengumuman desa yang panjang, ia menjawab pertanyaan yang
     * membuat orang menunda membaca: "ini panjang atau tidak".
     */
    $jumlahKata = str_word_count(strip_tags($tulisan->body));
    $lamaBaca = max(1, (int) ceil($jumlahKata / 200));

    /*
     * Tautan bagi-ke-WhatsApp: tautan biasa, tanpa JavaScript dan tanpa
     * memuat skrip pihak ketiga. WhatsApp adalah kanal penyebaran informasi
     * desa yang sebenarnya (lihat catatan Open Graph di kerangka portal), dan
     * tombol bagi yang menuntut JS tidak akan berfungsi di portal ini.
     */
    $bagiWa = 'https://wa.me/?text='.rawurlencode($tulisan->title.' — '.url()->current());
@endphp

<x-portal.layout :title="$tulisan->meta_title ?? $tulisan->title" :description="$ringkas">

    {{-- Kop tulisan: bidang berlatar lembut yang sama dengan halaman portal
         lainnya, supaya satu tulisan tidak terasa seperti halaman dari situs
         yang berbeda. --}}
    <header class="portal-band portal-weave border-b border-line">
        <div class="mx-auto max-w-3xl px-4 py-10 sm:py-12">
            <nav class="flex flex-wrap items-center gap-1.5 text-xs text-content-tertiary" aria-label="Jejak jalan">
                <a href="{{ route('portal.beranda') }}" class="hover:text-accent hover:underline">Beranda</a>
                <span aria-hidden="true">/</span>
                <a href="{{ route('portal.'.$tulisan->type) }}" class="hover:text-accent hover:underline">{{ $label }}</a>
                <span aria-hidden="true">/</span>
                <span class="truncate font-medium text-content-secondary" aria-current="page">{{ \Illuminate\Support\Str::limit($tulisan->title, 40) }}</span>
            </nav>

            <p class="mt-4 flex flex-wrap items-center gap-2 text-sm text-content-tertiary">
                <span class="badge badge-accent">{{ $label }}</span>
                @if ($terbit)
                    <time datetime="{{ $tulisan->published_at->toIso8601String() }}">
                        {{ $terbit->translatedFormat('j F Y') }}
                    </time>
                    <span aria-hidden="true">&middot;</span>
                @endif
                <span class="tabular">± {{ $lamaBaca }} menit baca</span>
            </p>

            <h1 class="mt-3 text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{{ $tulisan->title }}</h1>

            @if (filled($tulisan->excerpt))
                <p class="mt-4 text-lg leading-relaxed text-content-secondary">{{ $tulisan->excerpt }}</p>
            @endif
        </div>
    </header>

    <article class="mx-auto max-w-3xl px-4 py-10">
        @if ($tulisan->type === \App\Models\Post::TYPE_AGENDA)
            <dl class="panel panel-accent mb-8 grid gap-4 p-5 sm:grid-cols-2">
                <div class="flex items-start gap-3">
                    <span class="icon-chip">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Waktu</dt>
                        <dd class="mt-0.5 font-medium">{{ $tulisan->eventRangeLabel() }}</dd>
                    </div>
                </div>

                @if (filled($tulisan->event_location))
                    <div class="flex items-start gap-3">
                        <span class="icon-chip">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Lokasi</dt>
                            <dd class="mt-0.5 font-medium">{{ $tulisan->event_location }}</dd>
                        </div>
                    </div>
                @endif
            </dl>
        @endif

        @if ($tulisan->cover_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tulisan->cover_path) }}"
                 alt="" width="1280" height="720" decoding="async"
                 class="mb-8 aspect-video w-full rounded-xl object-cover shadow-card ring-1 ring-line">
        @endif

        <div class="prose-sid">
            {!! $tulisan->body !!}
        </div>

        @if ($tulisan->tags)
            <ul class="mt-8 flex flex-wrap gap-2" aria-label="Label">
                @foreach ($tulisan->tags as $tag)
                    <li class="rounded-full border border-line bg-surface-sunken px-3 py-1 text-xs text-content-secondary">{{ $tag }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Kaki tulisan: jalan kembali ke daftar, dan satu jalan menyebarkan. --}}
        <div class="mt-10 flex flex-wrap items-center justify-between gap-4 border-t border-line pt-6">
            <a href="{{ route('portal.'.$tulisan->type) }}"
               class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:underline">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>
                </svg>
                Semua {{ \Illuminate\Support\Str::lower($label) }}
            </a>

            <a href="{{ $bagiWa }}" rel="noopener noreferrer" target="_blank"
               class="touch-target inline-flex items-center gap-2 rounded-xl border border-line-strong bg-surface px-4 py-2 text-sm font-medium transition-colors hover:bg-surface-hover">
                <svg class="size-4 text-accent" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>
                </svg>
                Bagikan lewat WhatsApp
            </a>
        </div>
    </article>
</x-portal.layout>
