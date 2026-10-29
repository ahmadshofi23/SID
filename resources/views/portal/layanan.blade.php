{{--
  Standar pelayanan — FR-PRT-04, BR-23. Halaman terpenting portal (docs/07 §2.1).

  Isinya adalah JANJI desa kepada warga: syarat, biaya, dan lama penyelesaian.
  Karena itu angkanya diambil dari konfigurasi jenis surat yang sama yang
  dipakai mesin status dan penghitung SLA — bukan dari teks yang diketik ulang
  di halaman portal. Dua sumber angka berarti satu di antaranya akan basi, dan
  yang basi itulah yang dibaca warga.
--}}
@php
    /*
     * Tiga angka ringkasan dihitung dari koleksi yang SAMA yang digambar di
     * bawah, bukan dari kueri kedua. Ringkasan yang tidak cocok dengan daftar
     * di bawahnya lebih buruk daripada tidak ada ringkasan sama sekali.
     */
    $gratis = $layanan->filter(fn ($j) => (float) $j->fee <= 0)->count();
    $slaTercepat = (int) ($layanan->min('sla_days') ?? 0);
@endphp

<x-portal.layout title="Standar Pelayanan"
                 eyebrow="Janji layanan desa"
                 heading="Standar Pelayanan Surat"
                 lead="Syarat, biaya, dan lama penyelesaian untuk setiap surat yang dilayani Desa {{ $village->name }}.">

    @if ($layanan->isNotEmpty())
        <x-slot:actions>
            <a href="{{ route('surat.lacak') }}"
               class="touch-target inline-flex items-center gap-2 rounded-xl border border-line-strong bg-surface px-5 py-2.5 text-sm font-medium shadow-card transition-colors hover:bg-surface-hover">
                <svg class="size-4 text-accent" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                </svg>
                Lacak pengajuan
            </a>
        </x-slot:actions>
    @endif

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($layanan->isEmpty())
            <x-alert type="info" title="Belum ada layanan yang dibuka">
                Jenis surat yang dapat diajukan warga belum diaktifkan petugas desa.
                Silakan hubungi kantor desa untuk keperluan surat Anda.
            </x-alert>
        @else
            {{-- ----------------------- Tiga angka janji -----------------------
              Diletakkan di atas daftar, bukan di bawahnya: pertanyaan pertama
              warga yang membuka halaman ini adalah "berapa lama" dan "berapa
              biayanya", bukan "surat apa saja yang ada".
            --}}
            <dl class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['Jenis surat dilayani', number_format($layanan->count(), 0, ',', '.'), 'jenis', '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>'],
                    ['Tanpa biaya', number_format($gratis, 0, ',', '.').' dari '.$layanan->count(), 'jenis surat', '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'],
                    ['Paling cepat selesai', (string) $slaTercepat, 'hari kerja', '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'],
                ] as [$label, $nilai, $satuan, $ikon])
                    <div class="card flex items-center gap-4 p-4">
                        <span class="icon-chip">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true">{!! $ikon !!}</svg>
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                            <dd class="tabular mt-0.5 font-semibold">
                                {{ $nilai }}
                                <span class="text-sm font-normal text-content-secondary">{{ $satuan }}</span>
                            </dd>
                        </div>
                    </div>
                @endforeach
            </dl>

            <x-alert type="info" class="mt-4" title="Biaya layanan">
                Seluruh surat yang tercantum di bawah tidak dipungut biaya kecuali
                disebutkan lain. Bila ada petugas yang meminta biaya di luar daftar
                ini, laporkan ke kantor desa.
            </x-alert>

            {{-- -------------------------- Daftar layanan ---------------------- --}}
            <h2 class="heading-rule mt-10 text-xl font-semibold tracking-tight">Daftar surat yang dilayani</h2>

            <ul class="mt-7 grid gap-4 sm:grid-cols-2">
                @foreach ($layanan as $jenis)
                    <li>
                        <a href="{{ route('portal.layanan.detail', $jenis->code) }}"
                           class="card card-lift group flex h-full flex-col p-5 hover:bg-surface-hover">
                            <div class="flex items-start gap-4">
                                <span class="icon-chip">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/>
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4"/>
                                        <path d="M9 13h6"/><path d="M9 17h3"/>
                                    </svg>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold leading-snug group-hover:underline">{{ $jenis->name }}</p>
                                    {{-- Kode surat ditampilkan: inilah yang disebut
                                         petugas di loket ("minta SKTM"), dan warga
                                         yang menelepon kantor desa memakainya. --}}
                                    <p class="tabular mt-0.5 text-xs text-content-tertiary">{{ $jenis->code }}</p>
                                </div>

                                <span class="badge {{ (float) $jenis->fee > 0 ? 'badge-neutral' : 'badge-success' }}">
                                    {{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}
                                </span>
                            </div>

                            @if (filled($jenis->description))
                                <p class="mt-3 flex-1 text-sm leading-relaxed text-content-secondary">{{ $jenis->description }}</p>
                            @else
                                <span class="flex-1" aria-hidden="true"></span>
                            @endif

                            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                                <span class="chip tabular">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         aria-hidden="true">
                                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                                    </svg>
                                    {{ $jenis->sla_days }} hari kerja
                                </span>
                                <span class="chip tabular">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true">
                                        <path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                                    </svg>
                                    {{ count($jenis->requirements) }} syarat
                                </span>

                                <span class="link-arrow ml-auto text-sm">
                                    Lihat syarat
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                        <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                                    </svg>
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            {{--
              Pintu luring disebut di halaman ini, bukan hanya di halaman kontak.
              Warga yang membaca daftar syarat sampai bawah adalah orang yang
              sedang bersiap mengurus surat — dan sebagian dari mereka tidak akan
              mengurusnya daring (docs/03 §2.2).
            --}}
            <div class="card mt-10 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">Tidak mengurus dari rumah?</p>
                    <p class="mt-1 text-sm text-content-secondary">
                        Seluruh surat di atas tetap dapat diurus di kantor desa pada jam
                        layanan, tanpa akun dan tanpa perangkat apa pun.
                    </p>
                </div>
                <a href="{{ route('portal.kontak') }}"
                   class="touch-target inline-flex shrink-0 items-center justify-center rounded-xl border border-line-strong bg-surface px-5 py-2.5 text-sm font-medium transition-colors hover:bg-surface-hover">
                    Jam layanan &amp; alamat kantor
                </a>
            </div>
        @endif
    </div>
</x-portal.layout>
