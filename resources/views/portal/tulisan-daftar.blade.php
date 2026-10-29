{{-- Daftar berita atau pengumuman — FR-PRT-02, FR-PRT-03. --}}
@php
    $label = \App\Models\Post::TYPE_LABELS[$jenis];
    $penjelasan = $jenis === \App\Models\Post::TYPE_BERITA
        ? 'Kabar dan kegiatan terbaru dari Desa '.$village->name.'.'
        : 'Pengumuman resmi Pemerintah Desa '.$village->name.'.';

    /*
     * Tulisan teratas ditampilkan lebar — HANYA di halaman pertama. Pada
     * halaman kedua dan seterusnya, tulisan teratas tidak lagi "yang terbaru",
     * dan menonjolkannya di sana hanya menyarankan sesuatu yang tidak benar.
     */
    $utama = $tulisan->currentPage() === 1 ? $tulisan->first() : null;
    $sisanya = $utama ? $tulisan->getCollection()->skip(1) : $tulisan->getCollection();
@endphp

<x-portal.layout :title="$label" :description="$penjelasan" eyebrow="Kabar desa" :heading="$label" :lead="$penjelasan">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($tulisan->isEmpty())
            <x-alert type="info" title="Belum ada {{ \Illuminate\Support\Str::lower($label) }}">
                Belum ada yang diterbitkan petugas desa di bagian ini.
            </x-alert>
        @else
            @if ($utama)
                <x-portal.kartu-tulisan :tulisan="$utama" utama />
            @endif

            @if ($sisanya->isNotEmpty())
                <ul @class(['grid gap-4 sm:grid-cols-2 lg:grid-cols-3', 'mt-6' => $utama])>
                    @foreach ($sisanya as $satu)
                        <li><x-portal.kartu-tulisan :tulisan="$satu" /></li>
                    @endforeach
                </ul>
            @endif

            {{-- Penomoran halaman Blade bawaan: tautan biasa, tetap berfungsi
                 tanpa JavaScript seperti sisa portal (KEP-016). --}}
            <div class="mt-8">
                {{ $tulisan->links('vendor.pagination.portal') }}
            </div>
        @endif
    </div>
</x-portal.layout>
