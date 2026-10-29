{{-- Kontak & jam layanan — docs/07 §2.1. --}}
@php
    /*
     * SATU sumber jam layanan untuk tabel DAN untuk penanda "buka sekarang".
     *
     * Sebelumnya jam layanan hanya berupa tiga baris teks di tabel. Menambahkan
     * penandanya sebagai teks kedua berarti dua tempat harus diubah bersama
     * ketika desa mengubah jam layanannya — dan yang terlupakan adalah yang
     * dibaca warga sebagai janji.
     *
     * MASIH DI BERKAS TAMPILAN, belum dapat diubah petugas dari panel.
     * Disebutkan terang-terangan supaya tidak terlupakan: begitu modul
     * pengaturan desa menerima kolom jam layanan, larik di bawah harus pindah
     * ke sana — jam layanan yang salah di situs resmi membuat warga datang ke
     * kantor yang tutup.
     *
     * Kuncinya hari ISO (1 = Senin). Jam ditulis sebagai menit sejak tengah
     * malam supaya pembandingannya tidak bergantung penguraian teks.
     */
    $jadwal = [
        1 => ['buka' => 8 * 60, 'tutup' => 15 * 60],
        2 => ['buka' => 8 * 60, 'tutup' => 15 * 60],
        3 => ['buka' => 8 * 60, 'tutup' => 15 * 60],
        4 => ['buka' => 8 * 60, 'tutup' => 15 * 60],
        5 => ['buka' => 8 * 60, 'tutup' => 11 * 60],
        6 => null,
        7 => null,
    ];

    $namaHari = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    $jam = fn (int $menit): string => sprintf('%02d.%02d', intdiv($menit, 60), $menit % 60);

    /*
     * Jam dinding KANTOR DESA, bukan jam peladen maupun jam peramban
     * pengunjung (KEP-010). Penanda "buka sekarang" yang dihitung dari UTC
     * akan menyatakan kantor desa tutup pada pukul sembilan pagi.
     */
    $sekarang = \App\Support\VillageTime::now();
    $hariIni = (int) $sekarang->isoWeekday();
    $menitIni = (int) $sekarang->format('G') * 60 + (int) $sekarang->format('i');

    $jadwalHariIni = $jadwal[$hariIni] ?? null;
    $sedangBuka = $jadwalHariIni !== null
        && $menitIni >= $jadwalHariIni['buka']
        && $menitIni < $jadwalHariIni['tutup'];

    /*
     * Hari buka berikutnya — dicari dari larik yang sama, maksimal tujuh
     * langkah, DIMULAI DARI HARI INI. Memulainya dari besok membuat halaman
     * yang dibuka pukul enam pagi Jumat menyatakan kantor buka hari Senin,
     * padahal ia buka dua jam lagi.
     */
    $hariBerikut = null;
    $bukaHariIni = false;
    for ($i = 0; $i < 7; $i++) {
        $kandidat = ($hariIni - 1 + $i) % 7 + 1;
        $jadwalKandidat = $jadwal[$kandidat] ?? null;

        if ($jadwalKandidat === null) {
            continue;
        }

        /* Hari ini hanya dihitung bila jam bukanya belum lewat. */
        if ($i === 0 && $menitIni >= $jadwalKandidat['buka']) {
            continue;
        }

        $hariBerikut = $kandidat;
        $bukaHariIni = $i === 0;
        break;
    }

    /* Tiga baris tabel: hari yang berjam sama digabung supaya tetap terbaca. */
    $barisJadwal = [
        ['Senin – Kamis', $jam($jadwal[1]['buka']).' – '.$jam($jadwal[1]['tutup']), in_array($hariIni, [1, 2, 3, 4], true)],
        ['Jumat', $jam($jadwal[5]['buka']).' – '.$jam($jadwal[5]['tutup']), $hariIni === 5],
        ['Sabtu, Minggu & hari libur', 'Tutup', in_array($hariIni, [6, 7], true)],
    ];
@endphp

<x-portal.layout title="Kontak"
                 eyebrow="Pelayanan warga"
                 heading="Hubungi Pemerintah Desa {{ $village->name }}"
                 lead="Alamat kantor, jam layanan, dan cara menghubungi perangkat desa.">

    <x-slot:actions>
        {{-- Keadaan layanan saat ini, bukan hiasan: warga yang membuka halaman
             kontak hendak memutuskan apakah berangkat sekarang. --}}
        @if ($sedangBuka)
            <span class="badge badge-success">
                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                </svg>
                Buka sekarang &middot; sampai {{ $jam($jadwalHariIni['tutup']) }}
            </span>
        @else
            <span class="badge badge-neutral">
                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                </svg>
                Tutup
                @if ($hariBerikut)
                    &middot; buka {{ $bukaHariIni ? 'hari ini' : $namaHari[$hariBerikut] }}
                    {{ $jam($jadwal[$hariBerikut]['buka']) }}
                @endif
            </span>
        @endif
    </x-slot:actions>

    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid gap-6 lg:grid-cols-2">

            <section class="panel panel-accent" aria-labelledby="judul-kantor">
                <div class="panel-head">
                    <h2 id="judul-kantor" class="panel-title">Kantor desa</h2>
                </div>

                <div class="panel-body">
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <span class="icon-chip">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Alamat</p>
                                <p class="mt-0.5 text-sm leading-relaxed">
                                    {{ $village->address ?: 'Belum diisi' }}<br>
                                    Desa {{ $village->name }}, Kec. {{ $village->district }}<br>
                                    Kab. {{ $village->regency }}@if ($village->province), {{ $village->province }}@endif
                                    {{ $village->postal_code }}
                                </p>
                            </div>
                        </li>

                        @if ($village->phone)
                            <li class="flex items-start gap-3">
                                <span class="icon-chip">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Telepon</p>
                                    <p class="mt-0.5 text-sm">
                                        <a class="tabular font-medium text-accent hover:underline" href="tel:{{ $village->phone }}">{{ $village->phone }}</a>
                                    </p>
                                </div>
                            </li>
                        @endif

                        @if ($village->email)
                            <li class="flex items-start gap-3">
                                <span class="icon-chip">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        <rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Surel</p>
                                    <p class="mt-0.5 break-words text-sm">
                                        <a class="font-medium text-accent hover:underline" href="mailto:{{ $village->email }}">{{ $village->email }}</a>
                                    </p>
                                </div>
                            </li>
                        @endif

                        @if ($village->latitude && $village->longitude)
                            <li class="flex items-start gap-3">
                                <span class="icon-chip">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        <path d="m3 11 19-9-9 19-2-8z"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-medium uppercase tracking-wider text-content-tertiary">Titik lokasi</p>
                                    <p class="mt-0.5 text-sm">
                                        {{--
                                          Tautan ke peta pihak ketiga, BUKAN peta yang
                                          disematkan. Iframe peta memuat skrip dan font dari
                                          domain lain: ia akan diblokir CSP `default-src 'self'`
                                          (NFR-SEC-07), menambah ratusan KB pada halaman yang
                                          dibatasi 500 KB, dan mengirim alamat IP setiap
                                          pengunjung desa ke penyedia peta tanpa mereka minta.
                                        --}}
                                        <a class="font-medium text-accent hover:underline" rel="noopener noreferrer" target="_blank"
                                           href="https://www.openstreetmap.org/?mlat={{ $village->latitude }}&amp;mlon={{ $village->longitude }}#map=17/{{ $village->latitude }}/{{ $village->longitude }}">
                                            Buka di peta
                                        </a>
                                        <span class="tabular ml-1 text-xs text-content-tertiary">
                                            ({{ $village->latitude }}, {{ $village->longitude }})
                                        </span>
                                    </p>
                                </div>
                            </li>
                        @endif
                    </ul>
                </div>
            </section>

            <section class="panel" aria-labelledby="judul-jam">
                <div class="panel-head">
                    <h2 id="judul-jam" class="panel-title">Jam layanan</h2>
                    <span class="ml-auto {{ $sedangBuka ? 'badge badge-success' : 'badge badge-neutral' }}">
                        {{ $sedangBuka ? 'Buka' : 'Tutup' }}
                    </span>
                </div>

                <div class="panel-body">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Jam layanan kantor desa</caption>
                        <tbody>
                            @foreach ($barisJadwal as [$hari, $rentang, $iniHariIni])
                                <tr @class([
                                        'border-t border-line first:border-t-0',
                                        'bg-accent-soft/50' => $iniHariIni,
                                    ])>
                                    <th scope="row" class="py-2.5 pl-2 pr-3 text-left font-normal">
                                        {{ $hari }}
                                        {{-- Hari ini ditandai DUA kali: latar lembut dan
                                             kata "hari ini" (WCAG 1.4.1 — warna saja tidak
                                             cukup sebagai penanda). --}}
                                        @if ($iniHariIni)
                                            <span class="ml-1 text-xs font-medium text-accent-text">&middot; hari ini</span>
                                        @endif
                                    </th>
                                    <td class="tabular py-2.5 pr-2 text-right {{ $rentang === 'Tutup' ? 'text-content-tertiary' : 'font-medium' }}">{{ $rentang }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="mt-4 border-t border-line pt-3 text-xs leading-relaxed text-content-tertiary">
                        Hari libur nasional belum diperhitungkan sistem. Pada pekan yang
                        memuat hari libur, sebaiknya hubungi kantor desa lebih dulu.
                    </p>

                    <p class="mt-4 text-sm leading-relaxed text-content-secondary">
                        Pengajuan surat dapat dicatat petugas pada jam layanan di atas.
                        Status pengajuan dapat dipantau kapan saja lewat
                        <a class="font-medium text-accent hover:underline" href="{{ route('surat.lacak') }}">halaman pelacakan</a>.
                    </p>
                </div>
            </section>
        </div>

        {{--
          Kanal pengaduan daring sudah berjalan sejak Sprint 7 (FR-PGD-01).

          Jalur ke kantor desa dan Kepala Dusun TETAP disebut lebih dulu, bukan
          digantikan: sebagian warga desa tidak memakai internet sama sekali,
          dan halaman yang menyiratkan pengaduan harus lewat formulir akan
          menutup pintu bagi justru mereka yang paling perlu mengadu.

          Pilihan anonimnya ikut disebut di sini, bukan hanya di dalam
          formulirnya. Orang yang ingin melaporkan pungutan liar tidak akan
          membuka formulir pengaduan lebih dulu untuk mencari tahu apakah
          namanya akan tercatat — ia harus tahu sebelum menekan tautannya.
        --}}
        <section class="panel mt-6" aria-labelledby="judul-mengadu">
            <div class="panel-body flex flex-col gap-5 sm:flex-row sm:items-center">
                <span class="icon-chip">
                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <h2 id="judul-mengadu" class="font-semibold">Menyampaikan keluhan atau laporan</h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-content-secondary">
                        Dapat disampaikan langsung ke kantor desa pada jam layanan, melalui
                        Kepala Dusun setempat, atau lewat formulir daring — termasuk
                        <strong class="font-semibold text-content">tanpa menyebutkan nama</strong>.
                        Pengaduan diperiksa petugas dalam 2 hari kerja, dan tindak lanjutnya
                        dapat dipantau dengan kode pelacakan.
                    </p>
                </div>

                <div class="flex shrink-0 flex-col gap-2 sm:items-end">
                    <a href="{{ route('pengaduan.buat') }}"
                       class="touch-target inline-flex items-center justify-center rounded-xl bg-accent px-5 py-2.5 text-sm font-medium text-content-inverse transition-colors hover:bg-accent-hover">
                        Sampaikan pengaduan
                    </a>
                    <a href="{{ route('pengaduan.lacak') }}" class="text-sm font-medium text-accent hover:underline">
                        Lacak dengan kode pelacakan
                    </a>
                </div>
            </div>
        </section>

        {{--
          Pengaduan yang SUDAH DITANGANI — FR-PGD-07.

          Ini bukan hiasan. Kanal pengaduan yang tidak pernah memperlihatkan
          hasil akan dianggap kotak kosong, dan warga kembali menyampaikan
          keluhannya lewat obrolan yang tidak tercatat di mana pun. Yang
          ditampilkan hanya kategori, judul, dan hasil penanganannya — tanpa
          lokasi, titik koordinat, maupun pelapor (lihat
          ComplaintPresenter::publicSummary()), karena "jalan rusak di depan
          rumah Pak Sumadi" yang terbit di situs desa menunjuk satu rumah
          tertentu, dan itu bukan keterbukaan melainkan penyingkapan.

          Seluruh baris di sini DIPILIH SATU PER SATU oleh petugas; tidak ada
          pengaduan yang terbit dengan sendirinya hanya karena selesai.
        --}}
        @if (count($pengaduanSelesai) > 0)
            <section class="mt-10" aria-labelledby="pengaduan-selesai">
                <h2 id="pengaduan-selesai" class="heading-rule text-xl font-semibold tracking-tight">Pengaduan yang sudah ditangani</h2>
                <p class="mt-4 text-sm text-content-secondary">
                    Sebagian pengaduan warga yang sudah selesai ditindaklanjuti dan
                    disetujui untuk dipublikasikan.
                </p>

                <ul class="mt-5 space-y-3">
                    @foreach ($pengaduanSelesai as $pengaduan)
                        <li class="card border-l-2 border-l-success p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold leading-snug">{{ $pengaduan['title'] }}</p>
                                    <p class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-content-tertiary">
                                        <span class="badge badge-neutral">{{ $pengaduan['category'] }}</span>
                                        @if ($pengaduan['resolved_at'])
                                            <span>Selesai {{ $pengaduan['resolved_at'] }}</span>
                                        @endif
                                    </p>
                                </div>
                                <span class="badge badge-success shrink-0">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                                        <path d="M20 6 9 17l-5-5"/>
                                    </svg>
                                    Selesai
                                </span>
                            </div>
                            @if ($pengaduan['resolution'])
                                <p class="mt-3 border-t border-line pt-3 text-sm leading-relaxed text-content-secondary">{{ $pengaduan['resolution'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-portal.layout>
