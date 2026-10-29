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
@endphp

<x-portal.layout :title="$tulisan->meta_title ?? $tulisan->title" :description="$ringkas">

    <article class="mx-auto max-w-3xl px-4 py-10">
        <nav class="mb-6 text-sm" aria-label="Remah roti">
            <a href="{{ route('portal.'.$tulisan->type) }}" class="font-medium text-accent hover:underline">
                &larr; {{ $label }}
            </a>
        </nav>

        <header>
            <p class="flex flex-wrap items-center gap-2 text-sm text-content-tertiary">
                <span class="rounded bg-accent-soft px-1.5 py-0.5 text-xs font-medium text-accent-text">{{ $label }}</span>
                @if ($terbit)
                    <time datetime="{{ $tulisan->published_at->toIso8601String() }}">
                        {{ $terbit->translatedFormat('j F Y') }}
                    </time>
                @endif
            </p>

            <h1 class="mt-3 text-3xl font-semibold leading-tight">{{ $tulisan->title }}</h1>

            @if (filled($tulisan->excerpt))
                <p class="mt-3 text-lg text-content-secondary">{{ $tulisan->excerpt }}</p>
            @endif
        </header>

        @if ($tulisan->type === \App\Models\Post::TYPE_AGENDA)
            <dl class="card mt-6 grid gap-3 p-5 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-content-tertiary">Waktu</dt>
                    <dd class="font-medium">{{ $tulisan->eventRangeLabel() }}</dd>
                </div>
                @if (filled($tulisan->event_location))
                    <div>
                        <dt class="text-sm text-content-tertiary">Lokasi</dt>
                        <dd class="font-medium">{{ $tulisan->event_location }}</dd>
                    </div>
                @endif
            </dl>
        @endif

        @if ($tulisan->cover_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tulisan->cover_path) }}"
                 alt="" width="1280" height="720" decoding="async"
                 class="mt-6 aspect-video w-full rounded-xl object-cover">
        @endif

        <div class="prose-sid mt-8">
            {!! $tulisan->body !!}
        </div>

        @if ($tulisan->tags)
            <ul class="mt-8 flex flex-wrap gap-2" aria-label="Label">
                @foreach ($tulisan->tags as $tag)
                    <li class="rounded-full bg-surface-sunken px-3 py-1 text-xs text-content-secondary">{{ $tag }}</li>
                @endforeach
            </ul>
        @endif
    </article>
</x-portal.layout>
