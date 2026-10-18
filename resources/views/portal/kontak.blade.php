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

        {{-- Pengaduan daring dibangun Sprint 7; halaman ini tidak menjanjikannya. --}}
        <x-alert type="info" class="mt-6" title="Menyampaikan keluhan atau laporan">
            Keluhan dan laporan warga saat ini disampaikan langsung ke kantor desa
            atau melalui Kepala Dusun setempat. Kanal pengaduan daring sedang disiapkan.
        </x-alert>
    </div>
</x-portal.layout>
