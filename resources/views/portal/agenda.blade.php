{{-- Agenda kegiatan desa — FR-PRT-03. --}}
<x-portal.layout title="Agenda Kegiatan"
                 heading="Agenda Kegiatan"
                 :lead="'Kegiatan yang akan dan telah berlangsung di Desa '.$village->name.'.'">

    <div class="mx-auto max-w-5xl px-4 py-10">

        <section aria-labelledby="judul-mendatang">
            <h2 id="judul-mendatang" class="text-xl font-semibold">Kegiatan mendatang</h2>

            @if ($mendatang->isEmpty())
                <x-alert type="info" class="mt-4" title="Belum ada kegiatan terjadwal">
                    Agenda kegiatan desa akan tampil di sini setelah diterbitkan petugas.
                </x-alert>
            @else
                {{-- Diurutkan MENAIK: yang paling dekat paling berguna. --}}
                <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($mendatang as $kegiatan)
                        <li><x-portal.kartu-tulisan :tulisan="$kegiatan" /></li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($lewat->isNotEmpty())
            <section class="mt-10" aria-labelledby="judul-lewat">
                <h2 id="judul-lewat" class="text-xl font-semibold">Sudah berlangsung</h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Disimpan sebagai catatan kegiatan desa, bukan undangan.
                </p>

                <ul class="mt-4 space-y-2">
                    @foreach ($lewat as $kegiatan)
                        <li>
                            <a href="{{ route('portal.tulisan', [$kegiatan->type, $kegiatan->slug]) }}"
                               class="card flex flex-wrap items-center gap-x-4 gap-y-1 p-4 transition-colors hover:bg-surface-hover">
                                <span class="font-medium">{{ $kegiatan->title }}</span>
                                <span class="text-sm text-content-tertiary">{{ $kegiatan->eventRangeLabel() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-portal.layout>
