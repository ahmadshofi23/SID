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

    $puncakPiramida = max(1, max(
        $puncak($stats['pyramid'], 'L'),
        $puncak($stats['pyramid'], 'P'),
    ));
@endphp

<x-portal.layout title="Statistik Penduduk"
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
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['Jiwa', $angka['penduduk']],
                    ['Keluarga', $angka['keluarga']],
                    ['Dusun', $angka['dusun']],
                    ['RT', $angka['rt']],
                ] as [$label, $nilai])
                    <div class="card p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                        <dd class="tabular mt-1 text-2xl font-semibold tracking-tight">{{ number_format($nilai, 0, ',', '.') }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 text-xs text-content-tertiary">
                Disusun pada
                {{ \App\Support\VillageTime::local(\Illuminate\Support\Carbon::parse($stats['computed_at']))->locale('id')->translatedFormat('j F Y, H:i') }}.
                Kelompok yang berisi kurang dari {{ $ambangSelKecil }} orang ditulis
                &ldquo;&lt; {{ $ambangSelKecil }}&rdquo; agar orangnya tidak dapat dikenali kembali.
            </p>

            {{-- -------------------- Piramida penduduk -------------------- --}}
            <section class="card mt-8 p-5" aria-labelledby="judul-piramida">
                <h2 id="judul-piramida" class="font-semibold">Kelompok umur &amp; jenis kelamin</h2>

                <table class="mt-4 w-full text-sm">
                    <caption class="sr-only">Jumlah penduduk per kelompok umur dan jenis kelamin</caption>
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-content-tertiary">
                            <th scope="col" class="pb-2 font-medium">Umur</th>
                            <th scope="col" class="pb-2 text-right font-medium">Laki-laki</th>
                            <th scope="col" class="pb-2 font-medium" aria-hidden="true"></th>
                            <th scope="col" class="pb-2 font-medium">Perempuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stats['pyramid'] as $kelompok)
                            <tr class="border-t border-line">
                                <th scope="row" class="py-2 pr-3 text-left font-normal whitespace-nowrap">{{ $kelompok['group'] }}</th>
                                <td class="w-2/5 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="tabular text-xs text-content-secondary">{{ is_int($kelompok['L']) ? number_format($kelompok['L'], 0, ',', '.') : $kelompok['L'] }}</span>
                                        <span class="h-3 rounded-l bg-accent" aria-hidden="true"
                                              style="width: {{ $lebar($kelompok['L'], $puncakPiramida) }}%"></span>
                                    </div>
                                </td>
                                <td class="w-1 py-2" aria-hidden="true"></td>
                                <td class="w-2/5 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="h-3 rounded-r bg-gold-500" aria-hidden="true"
                                              style="width: {{ $lebar($kelompok['P'], $puncakPiramida) }}%"></span>
                                        <span class="tabular text-xs text-content-secondary">{{ is_int($kelompok['P']) ? number_format($kelompok['P'], 0, ',', '.') : $kelompok['P'] }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            {{-- ------------------------ Komposisi ------------------------ --}}
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                @foreach ([
                    ['Jenis kelamin', $stats['by_gender']],
                    ['Agama', $stats['by_religion']],
                    ['Pendidikan terakhir', $stats['by_education']],
                    ['Status perkawinan', $stats['by_marital_status']],
                    ['Pekerjaan (10 terbanyak)', $stats['by_occupation']],
                ] as [$judul, $baris])
                    <section class="card p-5" aria-labelledby="judul-{{ \Illuminate\Support\Str::slug($judul) }}">
                        <h2 id="judul-{{ \Illuminate\Support\Str::slug($judul) }}" class="font-semibold">{{ $judul }}</h2>

                        @if ($baris === [])
                            <p class="mt-3 text-sm text-content-tertiary">Belum ada data.</p>
                        @else
                            @php $maks = max(1, $puncak($baris)); @endphp
                            <table class="mt-4 w-full text-sm">
                                <caption class="sr-only">Komposisi penduduk menurut {{ \Illuminate\Support\Str::lower($judul) }}</caption>
                                <tbody>
                                    @foreach ($baris as $item)
                                        <tr class="border-t border-line first:border-t-0">
                                            <th scope="row" class="w-1/2 py-2 pr-3 text-left font-normal">{{ $item['label'] }}</th>
                                            <td class="py-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="h-3 rounded bg-accent" aria-hidden="true"
                                                          style="width: {{ $lebar($item['total'], $maks) }}%"></span>
                                                    <span class="tabular shrink-0 text-xs text-content-secondary">
                                                        {{ is_int($item['total']) ? number_format($item['total'], 0, ',', '.') : $item['total'] }}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-portal.layout>
