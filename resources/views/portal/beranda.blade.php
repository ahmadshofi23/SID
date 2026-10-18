<x-portal.layout title="Beranda"
                 :description="'Situs resmi Pemerintah Desa '.$village->name.'. Ajukan surat, lacak status pengajuan, dan lihat statistik desa.'">

    {{-- ===================== Sambutan & tindakan utama ===================== --}}
    <section class="bg-surface-sunken">
        <div class="mx-auto max-w-5xl px-4 py-12">
            <p class="text-sm font-medium text-accent-text">Selamat datang di situs resmi</p>
            <h1 class="mt-1 text-3xl font-semibold leading-tight sm:text-4xl">
                Desa {{ $village->name ?: 'Contoh' }}
            </h1>
            <p class="mt-3 max-w-2xl text-content-secondary">
                Kecamatan {{ $village->district }}, Kabupaten {{ $village->regency }}@if ($village->province), {{ $village->province }}@endif.
                Urus surat tanpa menunggu di kantor: lihat syaratnya lebih dulu, lalu pantau
                pengajuan Anda dari rumah.
            </p>

            {{--
              Dua tindakan di atas garis lipat — docs/07 §5.5: warga datang ke
              situs desa untuk MENGURUS sesuatu, bukan membaca berita.

              Rancangan aslinya menaruh "Lapor Pengaduan" sebagai tombol kedua;
              modul pengaduan baru dibangun Sprint 7. Yang menggantikannya bukan
              tombol mati, melainkan satu-satunya layanan mandiri yang memang
              sudah berjalan hari ini: pelacakan status pengajuan (KEP-014).
            --}}
            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('portal.layanan') }}"
                   class="touch-target inline-flex items-center justify-center gap-2 rounded-lg bg-accent px-6 py-3 font-medium text-content-inverse transition-colors hover:bg-accent-hover">
                    Ajukan Surat
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                    </svg>
                </a>
                <a href="{{ route('surat.lacak') }}"
                   class="touch-target inline-flex items-center justify-center rounded-lg border border-line-strong bg-surface px-6 py-3 font-medium transition-colors hover:bg-surface-hover">
                    Lacak Status Pengajuan
                </a>
            </div>

            {{--
              Pendaftaran akun diletakkan sebagai baris ketiga, bukan tombol
              besar: warga yang datang untuk mengurus satu surat tidak boleh
              merasa wajib membuat akun lebih dulu — loket tetap terbuka.
            --}}
            <p class="mt-4 text-sm text-content-secondary">
                Ingin mengurus surat dari rumah?
                <a href="{{ route('daftar') }}" class="font-medium text-accent hover:underline">
                    Daftar akun warga
                </a>
                — gratis, diperiksa petugas desa satu hari kerja.
            </p>
        </div>
    </section>

    {{-- ========================= Layanan surat ============================ --}}
    <section class="mx-auto max-w-5xl px-4 py-10" aria-labelledby="judul-layanan">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="judul-layanan" class="text-xl font-semibold">Layanan Surat</h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Syarat, biaya, dan lama penyelesaian tiap surat.
                </p>
            </div>
            <a href="{{ route('portal.layanan') }}" class="text-sm font-medium text-accent hover:underline">
                Semua layanan ({{ $jumlahLayanan }}) &rarr;
            </a>
        </div>

        @if ($layanan->isEmpty())
            <x-alert type="info" class="mt-4" title="Belum ada layanan yang dibuka">
                Jenis surat yang dapat diajukan warga belum diaktifkan petugas desa.
            </x-alert>
        @else
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($layanan as $jenis)
                    <li>
                        <a href="{{ route('portal.layanan.detail', $jenis->code) }}"
                           class="card flex h-full flex-col p-4 transition-colors hover:bg-surface-hover">
                            <span class="font-medium">{{ $jenis->name }}</span>
                            <span class="mt-1 flex-1 text-sm text-content-secondary">
                                {{ \Illuminate\Support\Str::limit($jenis->description, 80) }}
                            </span>
                            <span class="mt-3 flex items-center gap-3 text-xs text-content-tertiary">
                                <span class="tabular">&#9201; {{ $jenis->sla_days }} hari kerja</span>
                                <span>{{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ====================== Berita & pengumuman ========================= --}}
    @if ($tulisanTerbaru->isNotEmpty())
        {{--
          Diletakkan SETELAH layanan, bukan sebelumnya. docs/07 §5.5: warga
          datang ke situs desa untuk mengurus sesuatu, bukan membaca berita.
          Blok ini hilang sama sekali bila belum ada yang terbit — kerangka
          kosong bertuliskan "belum ada berita" di halaman depan membuat situs
          desa terlihat telantar.
        --}}
        <section class="border-t border-line bg-surface" aria-labelledby="judul-kabar">
            <div class="mx-auto max-w-5xl px-4 py-10">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="judul-kabar" class="text-xl font-semibold">Kabar Desa</h2>
                    <a href="{{ route('portal.berita') }}" class="text-sm font-medium text-accent hover:underline">
                        Semua berita &rarr;
                    </a>
                </div>

                <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tulisanTerbaru as $satu)
                        <li><x-portal.kartu-tulisan :tulisan="$satu" /></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- ======================= Desa dalam angka =========================== --}}
    <section class="border-y border-line bg-surface" aria-labelledby="judul-angka">
        <div class="mx-auto max-w-5xl px-4 py-10">
            <h2 id="judul-angka" class="text-xl font-semibold">Desa dalam Angka</h2>

            @if ($angka['computed_at'] === null)
                {{-- Jujur menyatakan angka belum ada, bukan menampilkan nol
                     seolah-olah desa ini tidak berpenduduk. --}}
                <x-alert type="info" class="mt-4" title="Statistik belum disusun">
                    Ringkasan penduduk belum pernah disegarkan oleh petugas desa.
                </x-alert>
            @else
                <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ([
                        ['Jiwa', $angka['penduduk']],
                        ['Keluarga', $angka['keluarga']],
                        ['Dusun', $angka['dusun']],
                        ['RT', $angka['rt']],
                    ] as [$label, $nilai])
                        <div class="card p-4">
                            <dt class="text-sm text-content-secondary">{{ $label }}</dt>
                            <dd class="tabular mt-1 text-2xl font-semibold">{{ number_format($nilai, 0, ',', '.') }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 text-sm">
                    <a href="{{ route('portal.statistik') }}" class="font-medium text-accent hover:underline">
                        Statistik lengkap penduduk &rarr;
                    </a>
                </p>
            @endif
        </div>
    </section>

    {{-- =================== Profil & pemerintahan singkat ================== --}}
    <section class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid gap-4 sm:grid-cols-2">
            <a href="{{ route('portal.profil') }}" class="card p-5 transition-colors hover:bg-surface-hover">
                <h2 class="font-semibold">Profil Desa</h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Sejarah, visi &amp; misi, letak geografis, serta pembagian wilayah.
                </p>
            </a>
            <a href="{{ route('portal.pemerintahan') }}" class="card p-5 transition-colors hover:bg-surface-hover">
                <h2 class="font-semibold">Pemerintahan Desa</h2>
                <p class="mt-1 text-sm text-content-secondary">
                    Struktur organisasi dan perangkat desa yang bertugas.
                </p>
            </a>
        </div>
    </section>
</x-portal.layout>
