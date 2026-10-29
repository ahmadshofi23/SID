@props(['tulisan'])

{{-- Satu kartu tulisan, dipakai beranda dan daftar berita/pengumuman. --}}
@php
    $rute = route('portal.tulisan', [$tulisan->type, $tulisan->slug]);
    $terbit = $tulisan->published_at
        ? \App\Support\VillageTime::local($tulisan->published_at)->locale('id')->translatedFormat('j F Y')
        : null;
@endphp

<a href="{{ $rute }}" class="card card-lift group flex h-full flex-col overflow-hidden hover:bg-surface-hover">
    @if ($tulisan->cover_path)
        {{-- Rasio tetap + lazy: tanpa width/height peramban tidak tahu tinggi
             gambar sebelum ia tiba, dan halaman melompat saat dimuat (CLS). --}}
        <span class="block overflow-hidden">
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tulisan->cover_path) }}"
                 alt="" width="640" height="360" loading="lazy" decoding="async"
                 class="aspect-video w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
        </span>
    @endif

    <span class="flex flex-1 flex-col p-4">
        <span class="flex items-center gap-2 text-xs text-content-tertiary">
            <span class="rounded bg-accent-soft px-1.5 py-0.5 font-medium text-accent-text">
                {{ \App\Models\Post::TYPE_LABELS[$tulisan->type] }}
            </span>
            @if ($terbit)
                <time datetime="{{ $tulisan->published_at->toIso8601String() }}">{{ $terbit }}</time>
            @endif
        </span>

        <span class="mt-2 font-medium leading-snug group-hover:underline">{{ $tulisan->title }}</span>

        @if (filled($tulisan->excerpt))
            <span class="mt-1.5 flex-1 text-sm leading-relaxed text-content-secondary">{{ $tulisan->excerpt }}</span>
        @endif

        @if ($tulisan->type === \App\Models\Post::TYPE_AGENDA && $tulisan->event_start_at)
            <span class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-surface-sunken px-2 py-1 text-xs text-content-secondary">
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
        <span class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-accent">
            Baca selengkapnya
            <svg class="size-3.5 transition-transform group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 aria-hidden="true">
                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
            </svg>
        </span>
    </span>
</a>
