{{-- Agenda kegiatan desa — FR-PRT-03. --}}
@php
    /*
     * Blok tanggal dibangun di sini, bukan dari eventRangeLabel(): label itu
     * adalah satu baris lengkap ("Senin, 4 Mei 2026 · 09.00") yang memang
     * diperlukan di dalam kartu, sementara yang dicari mata pada daftar
     * agenda adalah TANGGALNYA. Keduanya membaca kolom yang sama, jadi tidak
     * ada angka kedua yang dapat menyimpang.
     */
    $blokTanggal = function ($kegiatan): ?array {
        if ($kegiatan->event_start_at === null) {
            return null;
        }

        $mulai = \App\Support\VillageTime::local($kegiatan->event_start_at)->locale('id');

        return [
            'hari' => $mulai->translatedFormat('j'),
            'bulan' => $mulai->translatedFormat('M'),
            'iso' => $kegiatan->event_start_at->toIso8601String(),
        ];
    };
@endphp

<x-portal.layout title="Agenda Kegiatan"
                 eyebrow="Kabar desa"
                 heading="Agenda Kegiatan"
                 :lead="'Kegiatan yang akan dan telah berlangsung di Desa '.$village->name.'.'">

    <div class="mx-auto max-w-5xl px-4 py-10">

        <section aria-labelledby="judul-mendatang">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="judul-mendatang" class="heading-rule text-xl font-semibold tracking-tight">Kegiatan mendatang</h2>
                @if ($mendatang->isNotEmpty())
                    <p class="tabular text-sm text-content-tertiary">{{ $mendatang->count() }} kegiatan terjadwal</p>
                @endif
            </div>

            @if ($mendatang->isEmpty())
                <x-alert type="info" class="mt-6" title="Belum ada kegiatan terjadwal">
                    Agenda kegiatan desa akan tampil di sini setelah diterbitkan petugas.
                </x-alert>
            @else
                {{-- Diurutkan MENAIK: yang paling dekat paling berguna. --}}
                <ul class="mt-7 grid gap-4 sm:grid-cols-2">
                    @foreach ($mendatang as $kegiatan)
                        @php $tgl = $blokTanggal($kegiatan); @endphp
                        <li>
                            <a href="{{ route('portal.tulisan', [$kegiatan->type, $kegiatan->slug]) }}"
                               class="card card-lift group flex h-full items-start gap-4 p-5 hover:bg-surface-hover">
                                @if ($tgl)
                                    <time class="date-block" datetime="{{ $tgl['iso'] }}">
                                        <span class="date-day">{{ $tgl['hari'] }}</span>
                                        <span class="date-month">{{ $tgl['bulan'] }}</span>
                                    </time>
                                @endif

                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold leading-snug group-hover:underline">{{ $kegiatan->title }}</span>

                                    <span class="mt-1.5 block text-sm text-content-secondary">{{ $kegiatan->eventRangeLabel() }}</span>

                                    @if (filled($kegiatan->event_location))
                                        <span class="chip mt-3">
                                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                 stroke-linejoin="round" aria-hidden="true">
                                                <path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>
                                            </svg>
                                            {{ $kegiatan->event_location }}
                                        </span>
                                    @endif

                                    @if (filled($kegiatan->excerpt))
                                        <span class="mt-3 block text-sm leading-relaxed text-content-secondary">{{ \Illuminate\Support\Str::limit($kegiatan->excerpt, 110) }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($lewat->isNotEmpty())
            <section class="mt-12" aria-labelledby="judul-lewat">
                <h2 id="judul-lewat" class="heading-rule text-xl font-semibold tracking-tight">Sudah berlangsung</h2>
                <p class="mt-4 text-sm text-content-secondary">
                    Disimpan sebagai catatan kegiatan desa, bukan undangan.
                </p>

                <ul class="panel mt-5 divide-y divide-line">
                    @foreach ($lewat as $kegiatan)
                        <li>
                            <a href="{{ route('portal.tulisan', [$kegiatan->type, $kegiatan->slug]) }}"
                               class="group flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5 transition-colors hover:bg-surface-hover">
                                <span class="font-medium group-hover:underline">{{ $kegiatan->title }}</span>
                                <span class="ml-auto text-sm text-content-tertiary">{{ $kegiatan->eventRangeLabel() }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-portal.layout>
