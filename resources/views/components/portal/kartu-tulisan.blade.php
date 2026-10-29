@props(['tulisan'])

{{-- Satu kartu tulisan, dipakai beranda dan daftar berita/pengumuman. --}}
@php
    $rute = route('portal.tulisan', [$tulisan->type, $tulisan->slug]);
    $terbit = $tulisan->published_at
        ? \App\Support\VillageTime::local($tulisan->published_at)->locale('id')->translatedFormat('j F Y')
        : null;
@endphp

<a href="{{ $rute }}" class="card group flex h-full flex-col overflow-hidden transition-colors hover:bg-surface-hover">
    @if ($tulisan->cover_path)
        {{-- Rasio tetap + lazy: tanpa width/height peramban tidak tahu tinggi
             gambar sebelum ia tiba, dan halaman melompat saat dimuat (CLS). --}}
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tulisan->cover_path) }}"
             alt="" width="640" height="360" loading="lazy" decoding="async"
             class="aspect-video w-full object-cover">
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

        <span class="mt-2 font-medium group-hover:underline">{{ $tulisan->title }}</span>

        @if (filled($tulisan->excerpt))
            <span class="mt-1 flex-1 text-sm text-content-secondary">{{ $tulisan->excerpt }}</span>
        @endif

        @if ($tulisan->type === \App\Models\Post::TYPE_AGENDA && $tulisan->event_start_at)
            <span class="mt-3 text-xs text-content-secondary">
                &#128197; {{ $tulisan->eventRangeLabel() }}
            </span>
        @endif
    </span>
</a>
