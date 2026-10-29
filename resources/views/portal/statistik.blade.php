{{--
  Statistik penduduk publik — FR-PRT-05, BR-21, ADR-008.

  TIGA HAL YANG MENJAGA HALAMAN INI TETAP AMAN
  --------------------------------------------
  1. Angkanya dibaca dari tabel ringkasan `population_stats`, tidak pernah
     dari `residents`. Endpoint ini karena itu tidak punya jalan menuju data
     pribadi sama sekali, bahkan bila kodenya kelak keliru.
  2. Penyamaran sel < 5 orang dinyalakan oleh CONTROLLER secara tetap dan tidak
     pernah diambil dari permintaan. Pada layar petugas penyamaran justru
     dimatikan bagi pemegang izin laporan; bila halaman ini membaca penanda
     yang sama dari query string, siapa pun dapat mematikannya.
  3. Tidak ada saringan per wilayah di sini. Agregat se-desa tidak dapat
     dipersempit sampai satu orang; agregat per RT bisa, dan itulah bentuk
     identifikasi ulang yang hendak dicegah ADR-008. Rincian per wilayah
     tetap ada, tetapi di panel petugas yang berizin (FR-PDK-15).

  MENGAPA GRAFIKNYA CSS, BUKAN PUSTAKA GRAFIK: docs/07 §7 mewajibkan halaman
  portal berfungsi tanpa JavaScript, dan §8 membatasi transfer awal 500 KB.
  Batang yang digambar dengan lebar persen bekerja di kedua keadaan itu, dan
  tabel di bawah setiap grafik membuat angkanya terbaca pembaca layar — grafik
  kanvas tidak.
--}}
@php
    /**
     * Lebar batang dalam persen. Nilai tersamar ("< 5") digambar sebagai
     * garis tipis: ia memang ada, tetapi besarnya sengaja tidak dinyatakan.
     */
    $lebar = function (int|string $nilai, int $maksimum): float {
        if (! is_int($nilai)) {
            return 2.0;
        }

        return $maksimum > 0 ? round($nilai / $maksimum * 100, 1) : 0.0;
    };

    /** @param list<array{total: int|string, ...}> $baris */
    $puncak = fn (array $baris, string $kunci = 'total'): int => (int) collect($baris)
        ->pluck($kunci)
        ->filter(fn ($n) => is_int($n))
        ->max();

    /**
     * Bagian dari total yang TERBACA, bukan dari total penduduk desa:
     * sebagian sel disamarkan ("< 5") dan tidak dapat dijumlahkan, sehingga
     * persen yang dihitung dari total desa akan selalu kurang dan tampak
     * seperti data yang hilang. Persen di bawah karena itu menyatakan
     * proporsi di antara kelompok yang angkanya memang ditampilkan.
     *
     * @param list<array{total: int|string, ...}> $baris
     */
    $jumlahTerbaca = fn (array $baris): int => (int) collect($baris)
        ->pluck('total')
        ->filter(fn ($n) => is_int($n))
        ->sum();

    $puncakPiramida = max(1, max(
        $puncak($stats['pyramid'], 'L'),
        $puncak($stats['pyramid'], 'P'),
    ));
@endphp

<x-portal.layout title="Statistik Penduduk"
                 eyebrow="Data agregat"
                 heading="Statistik Penduduk Desa {{ $village->name }}"
                 lead="Data agregat penduduk desa. Tidak memuat nama, NIK, maupun alamat perorangan.">

    <div class="mx-auto max-w-5xl px-4 py-10">

        @if ($stats['computed_at'] === null)
            <x-alert type="info" title="Statistik belum disusun">
                Ringkasan penduduk belum pernah disegarkan oleh petugas desa, sehingga
                belum ada angka yang dapat ditampilkan di sini.
            </x-alert>
        @else
            {{-- ----------------------- Ringkasan ------------------------- --}}
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['Jiwa', $angka['penduduk'], '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>'],
                    ['Keluarga', $angka['keluarga'], '<path d="M3 9.5 12 3l9 6.5"/><path d="M5 10v11h14V10"/><path d="M9 21v-6h6v6"/>'],
                    ['Dusun', $angka['dusun'], '<path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>'],
                    ['RT', $angka['rt'], '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>'],
                ] as [$label, $nilai, $ikon])
                    <div class="card p-4">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                            <svg class="size-4 shrink-0 text-accent" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true">{!! $ikon !!}</svg>
                        </div>
                        <dd class="tabular mt-1 text-2xl font-semibold tracking-tight">{{ number_format($nilai, 0, ',', '.') }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-content-tertiary">
                <span class="chip">
                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                    </svg>
                    Disusun {{ \App\Support\VillageTime::local(\Illuminate\Support\Carbon::parse($stats['computed_at']))->locale('id')->translatedFormat('j F Y, H:i') }}
                </span>
                <span>
                    Kelompok yang berisi kurang dari {{ $ambangSelKecil }} orang ditulis
                    &ldquo;&lt; {{ $ambangSelKecil }}&rdquo; agar orangnya tidak dapat dikenali kembali.
                </span>
            </p>

            {{-- -------------------- Piramida penduduk -------------------- --}}
            <section class="panel mt-8" aria-labelledby="judul-piramida">
                <div class="panel-head">
                    <h2 id="judul-piramida" class="panel-title">Kelompok umur &amp; jenis kelamin</h2>

                    {{-- Keterangan warna: batang kiri dan kanan dibedakan warna,
                         dan warna saja tidak boleh menjadi satu-satunya penanda
                         (WCAG 1.4.1) — karena itu tetap ada judul kolom di tabel. --}}
                    <p class="ml-auto flex items-center gap-3 text-xs text-content-secondary">
                        <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap">
                            <span class="size-2.5 rounded-sm bg-accent" aria-hidden="true"></span> Laki-laki
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap">
                            <span class="size-2.5 rounded-sm bg-gold-500" aria-hidden="true"></span> Perempuan
                        </span>
                    </p>
                </div>

                <div class="panel-body">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Jumlah penduduk per kelompok umur dan jenis kelamin</caption>
                        <thead>
                            <tr class="text-xs uppercase tracking-wide text-content-tertiary">
                                <th scope="col" class="pb-2 text-right font-medium">Laki-laki</th>
                                <th scope="col" class="pb-2 text-center font-medium">Umur</th>
                                <th scope="col" class="pb-2 text-left font-medium">Perempuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stats['pyramid'] as $kelompok)
                                <tr class="border-t border-line">
                                    <td class="w-[42%] py-2">
                                        <div class="flex items-center justify-end gap-2">
                                            <span class="tabular shrink-0 text-xs text-content-secondary">{{ is_int($kelompok['L']) ? number_format($kelompok['L'], 0, ',', '.') : $kelompok['L'] }}</span>
                                            <span class="bar-track bar-track-rtl" aria-hidden="true">
                                                <span class="bar-fill bar-fill-flat" style="width: {{ $lebar($kelompok['L'], $puncakPiramida) }}%"></span>
                                            </span>
                                        </div>
                                    </td>

                                    <th scope="row" class="tabular w-16 px-2 py-2 text-center text-xs font-medium whitespace-nowrap">
                                        {{ $kelompok['group'] }}
                                    </th>

                                    <td class="w-[42%] py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="bar-track" aria-hidden="true">
                                                <span class="bar-fill bar-fill-gold" style="width: {{ $lebar($kelompok['P'], $puncakPiramida) }}%"></span>
                                            </span>
                                            <span class="tabular shrink-0 text-xs text-content-secondary">{{ is_int($kelompok['P']) ? number_format($kelompok['P'], 0, ',', '.') : $kelompok['P'] }}</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ------------------------ Komposisi ------------------------ --}}
            <h2 class="heading-rule mt-10 text-xl font-semibold tracking-tight">Komposisi penduduk</h2>

            {{-- `items-start`: tanpa itu panel pendek ikut ditinggikan sampai
                 setinggi tetangganya, dan separuh isinya menjadi ruang kosong.
                 Pekerjaan memakai dua kolom karena barisnya sepuluh. --}}
            <div class="mt-7 grid items-start gap-6 lg:grid-cols-2">
                @foreach ([
                    ['Jenis kelamin', $stats['by_gender'], ''],
                    ['Agama', $stats['by_religion'], ''],
                    ['Pendidikan terakhir', $stats['by_education'], ''],
                    ['Status perkawinan', $stats['by_marital_status'], ''],
                    ['Pekerjaan (10 terbanyak)', $stats['by_occupation'], 'lg:col-span-2'],
                ] as [$judul, $baris, $rentang])
                    <section class="panel {{ $rentang }}" aria-labelledby="judul-{{ \Illuminate\Support\Str::slug($judul) }}">
                        <div class="panel-head">
                            <h2 id="judul-{{ \Illuminate\Support\Str::slug($judul) }}" class="panel-title">{{ $judul }}</h2>
                        </div>

                        <div class="panel-body">
                            @if ($baris === [])
                                <p class="text-sm text-content-tertiary">Belum ada data.</p>
                            @else
                                @php
                                    $maks = max(1, $puncak($baris));
                                    $terbaca = $jumlahTerbaca($baris);
                                @endphp
                                <table class="w-full text-sm">
                                    <caption class="sr-only">Komposisi penduduk menurut {{ \Illuminate\Support\Str::lower($judul) }}</caption>
                                    <tbody>
                                        @foreach ($baris as $item)
                                            <tr class="border-t border-line first:border-t-0">
                                                <th scope="row" class="w-[38%] py-2.5 pr-3 text-left align-middle font-normal">{{ $item['label'] }}</th>
                                                <td class="py-2.5 align-middle">
                                                    <div class="flex items-center gap-2.5">
                                                        <span class="bar-track" aria-hidden="true">
                                                            <span class="bar-fill" style="width: {{ $lebar($item['total'], $maks) }}%"></span>
                                                        </span>
                                                        <span class="tabular w-14 shrink-0 text-right text-xs font-medium">
                                                            {{ is_int($item['total']) ? number_format($item['total'], 0, ',', '.') : $item['total'] }}
                                                        </span>
                                                        <span class="tabular w-12 shrink-0 text-right text-xs text-content-tertiary">
                                                            {{ is_int($item['total']) && $terbaca > 0 ? number_format($item['total'] / $terbaca * 100, 1, ',', '.').'%' : '' }}
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>

            {{--
              Persen dijelaskan sekali di bawah seluruh grafik, bukan diulang di
              tiap panel: pembaca yang bertanya "persen dari apa" bertanya satu
              kali, dan catatan yang sama lima kali hanya menambah keriuhan.
            --}}
            <p class="mt-6 text-xs leading-relaxed text-content-tertiary">
                Persentase dihitung di antara kelompok yang angkanya ditampilkan pada
                tabel yang sama, bukan terhadap seluruh penduduk desa — kelompok yang
                disamarkan tidak dapat dijumlahkan. Rincian per dusun, RW, dan RT tidak
                dipublikasikan di sini karena dapat menunjuk kembali ke orang tertentu.
            </p>
        @endif
    </div>
</x-portal.layout>
