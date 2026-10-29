{{-- Kontak & jam layanan — docs/07 §2.1. --}}
<x-portal.layout title="Kontak"
                 heading="Hubungi Pemerintah Desa {{ $village->name }}"
                 lead="Alamat kantor, jam layanan, dan cara menghubungi perangkat desa.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid gap-6 lg:grid-cols-2">

            <section class="card p-5" aria-labelledby="judul-kantor">
                <h2 id="judul-kantor" class="font-semibold">Kantor desa</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-content-tertiary">Alamat</dt>
                        <dd>
                            {{ $village->address ?: 'Belum diisi' }}<br>
                            Desa {{ $village->name }}, Kec. {{ $village->district }}<br>
                            Kab. {{ $village->regency }}@if ($village->province), {{ $village->province }}@endif
                            {{ $village->postal_code }}
                        </dd>
                    </div>
                    @if ($village->phone)
                        <div>
                            <dt class="text-content-tertiary">Telepon</dt>
                            <dd><a class="text-accent hover:underline" href="tel:{{ $village->phone }}">{{ $village->phone }}</a></dd>
                        </div>
                    @endif
                    @if ($village->email)
                        <div>
                            <dt class="text-content-tertiary">Surel</dt>
                            <dd><a class="text-accent hover:underline" href="mailto:{{ $village->email }}">{{ $village->email }}</a></dd>
                        </div>
                    @endif
                    @if ($village->latitude && $village->longitude)
                        <div>
                            <dt class="text-content-tertiary">Titik lokasi</dt>
                            <dd>
                                {{--
                                  Tautan ke peta pihak ketiga, BUKAN peta yang
                                  disematkan. Iframe peta memuat skrip dan font dari
                                  domain lain: ia akan diblokir CSP `default-src 'self'`
                                  (NFR-SEC-07), menambah ratusan KB pada halaman yang
                                  dibatasi 500 KB, dan mengirim alamat IP setiap
                                  pengunjung desa ke penyedia peta tanpa mereka minta.
                                --}}
                                <a class="text-accent hover:underline" rel="noopener noreferrer" target="_blank"
                                   href="https://www.openstreetmap.org/?mlat={{ $village->latitude }}&amp;mlon={{ $village->longitude }}#map=17/{{ $village->latitude }}/{{ $village->longitude }}">
                                    Buka di peta
                                </a>
                                <span class="tabular ml-1 text-xs text-content-tertiary">
                                    ({{ $village->latitude }}, {{ $village->longitude }})
                                </span>
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="card p-5" aria-labelledby="judul-jam">
                <h2 id="judul-jam" class="font-semibold">Jam layanan</h2>

                {{--
                  Jam layanan masih tetap di berkas tampilan, belum dapat diubah
                  petugas dari panel. Disebutkan terang-terangan di sini supaya
                  tidak terlupakan: begitu modul pengaturan desa menerima kolom
                  jam layanan, angka di bawah harus pindah ke sana — jam layanan
                  yang salah di situs resmi membuat warga datang ke kantor tutup.
                --}}
                <table class="mt-3 w-full text-sm">
                    <caption class="sr-only">Jam layanan kantor desa</caption>
                    <tbody>
                        @foreach ([
                            ['Senin – Kamis', '08.00 – 15.00'],
                            ['Jumat', '08.00 – 11.00'],
                            ['Sabtu, Minggu & hari libur', 'Tutup'],
                        ] as [$hari, $jam])
                            <tr class="border-t border-line first:border-t-0">
                                <th scope="row" class="py-2 pr-3 text-left font-normal">{{ $hari }}</th>
                                <td class="tabular py-2 text-right">{{ $jam }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="mt-4 text-sm text-content-secondary">
                    Pengajuan surat dapat dicatat petugas pada jam layanan di atas.
                    Status pengajuan dapat dipantau kapan saja lewat
                    <a class="font-medium text-accent hover:underline" href="{{ route('surat.lacak') }}">halaman pelacakan</a>.
                </p>
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
        <x-alert type="info" class="mt-6" title="Menyampaikan keluhan atau laporan">
            Keluhan dan laporan warga dapat disampaikan langsung ke kantor desa pada jam
            layanan, melalui Kepala Dusun setempat, atau lewat
            <a class="font-medium text-accent hover:underline" href="{{ route('pengaduan.buat') }}">formulir pengaduan daring</a>
            — termasuk <strong>tanpa menyebutkan nama</strong>. Pengaduan diperiksa petugas
            dalam 2 hari kerja, dan tindak lanjutnya dapat dipantau dengan kode pelacakan di
            <a class="font-medium text-accent hover:underline" href="{{ route('pengaduan.lacak') }}">halaman pelacakan pengaduan</a>.
        </x-alert>

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
            <section class="mt-8" aria-labelledby="pengaduan-selesai">
                <h2 id="pengaduan-selesai" class="text-lg font-semibold">Pengaduan yang sudah ditangani</h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Sebagian pengaduan warga yang sudah selesai ditindaklanjuti dan
                    disetujui untuk dipublikasikan.
                </p>

                <ul class="mt-4 space-y-3">
                    @foreach ($pengaduanSelesai as $pengaduan)
                        <li class="rounded-lg border border-line bg-surface p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium">{{ $pengaduan['title'] }}</p>
                                    <p class="mt-0.5 text-xs text-content-tertiary">
                                        {{ $pengaduan['category'] }}
                                        @if ($pengaduan['resolved_at'])
                                            · Selesai {{ $pengaduan['resolved_at'] }}
                                        @endif
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-md bg-success-soft px-2 py-0.5 text-xs font-medium text-success">
                                    Selesai
                                </span>
                            </div>
                            @if ($pengaduan['resolution'])
                                <p class="mt-2 text-sm text-content-secondary">{{ $pengaduan['resolution'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-portal.layout>
