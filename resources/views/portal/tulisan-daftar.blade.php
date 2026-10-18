{{-- Daftar berita atau pengumuman — FR-PRT-02, FR-PRT-03. --}}
@php
    $label = \App\Models\Post::TYPE_LABELS[$jenis];
    $penjelasan = $jenis === \App\Models\Post::TYPE_BERITA
        ? 'Kabar dan kegiatan terbaru dari Desa '.$village->name.'.'
        : 'Pengumuman resmi Pemerintah Desa '.$village->name.'.';
@endphp

<x-portal.layout :title="$label" :description="$penjelasan" :heading="$label" :lead="$penjelasan">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($tulisan->isEmpty())
            <x-alert type="info" title="Belum ada {{ \Illuminate\Support\Str::lower($label) }}">
                Belum ada yang diterbitkan petugas desa di bagian ini.
            </x-alert>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($tulisan as $satu)
                    <li><x-portal.kartu-tulisan :tulisan="$satu" /></li>
                @endforeach
            </ul>

            {{-- Penomoran halaman Blade bawaan: tautan biasa, tetap berfungsi
                 tanpa JavaScript seperti sisa portal (KEP-016). --}}
            <div class="mt-8">
                {{ $tulisan->links('vendor.pagination.portal') }}
            </div>
        @endif
    </div>
</x-portal.layout>
