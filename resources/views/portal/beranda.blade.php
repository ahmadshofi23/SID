<x-portal.layout title="Beranda"
                 :description="'Situs resmi Pemerintah Desa '.$village->name.'. Ajukan surat, lacak status pengajuan, dan lihat statistik desa.'">

    {{-- ===================== Sambutan & tindakan utama =====================
      Bidang hero memakai .portal-band: gradien dan motif digambar CSS, bukan
      foto. Foto desa memang akan lebih "menjual", tetapi portal dibatasi 500
      KB pada pemuatan pertama (NFR-PERF-04) dan satu foto lebar menghabiskan
      separuhnya di jaringan yang justru dipakai warga desa.
    --}}
    <section class="portal-band portal-weave border-b border-line">
        <div class="mx-auto max-w-5xl px-4 py-14 sm:py-16">
            <p class="eyebrow">
                <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
                Situs resmi Pemerintah Desa
            </p>

            <h1 class="mt-4 text-4xl font-semibold leading-[1.1] tracking-tight sm:text-5xl">
                Desa {{ $village->name ?: 'Contoh' }}
            </h1>

            <p class="mt-3 text-sm font-medium text-accent-text">
                Kecamatan {{ $village->district }} &middot; Kabupaten {{ $village->regency }}@if ($village->province) &middot; {{ $village->province }}@endif
            </p>

            <p class="mt-5 max-w-2xl text-lg leading-relaxed text-content-secondary">
                Urus surat tanpa menunggu di kantor: lihat syaratnya lebih dulu, lalu
                pantau pengajuan Anda dari rumah.
            </p>

            {{--
              Dua tindakan di atas garis lipat — docs/07 §5.5: warga datang ke
              situs desa untuk MENGURUS sesuatu, bukan membaca berita.

              "Lapor Pengaduan" kini menjadi tombol kedua sebagaimana rancangan
              aslinya, karena modulnya sudah berjalan (Sprint 7, FR-PGD-01).
              Pelacakan status pengajuan turun menjadi baris tautan di bawah:
              ia tetap berguna, tetapi melacak adalah tindakan orang yang sudah
              mengurus sesuatu, sementara dua tombol di atas garis lipat harus
              menjadi tindakan orang yang BARU DATANG.
            --}}
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ auth()->check() ? route('warga.ajukan') : route('portal.layanan') }}"
                   class="touch-target group inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-6 py-3 font-medium text-content-inverse shadow-card transition-colors hover:bg-accent-hover">
                    Ajukan Surat
                    <svg class="size-4 transition-transform group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg"
                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" aria-hidden="true">
                        <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                    </svg>
                </a>
                <a href="{{ route('pengaduan.buat') }}"
                   class="touch-target inline-flex items-center justify-center gap-2 rounded-xl border border-line-strong bg-surface px-6 py-3 font-medium shadow-card transition-colors hover:bg-surface-hover">
                    <svg class="size-4 text-accent" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true">
                        <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>
                    </svg>
                    Lapor Pengaduan
                </a>
            </div>

            <p class="mt-5 text-sm text-content-secondary">
                Sudah mengurus sesuatu?
                <a class="font-medium text-accent hover:underline" href="{{ route('surat.lacak') }}">Lacak status pengajuan surat</a>
                atau
                <a class="font-medium text-accent hover:underline" href="{{ route('pengaduan.lacak') }}">lacak pengaduan</a>.
            </p>

            {{--
              Pendaftaran akun diletakkan sebagai baris ketiga, bukan tombol
              besar: warga yang datang untuk mengurus satu surat tidak boleh
              merasa wajib membuat akun lebih dulu — loket tetap terbuka.
            --}}
            <p class="mt-2 text-sm text-content-secondary">
                Ingin mengurus surat dari rumah?
                <a href="{{ route('daftar') }}" class="font-medium text-accent hover:underline">
                    Daftar akun warga
                </a>
                — gratis, diperiksa petugas desa satu hari kerja.
            </p>

            {{-- ---------------------- Desa dalam angka ----------------------
              Angka dipindahkan ke dalam hero, bukan bagian tersendiri di
              tengah halaman: ia menjawab "desa macam apa ini" pada pandangan
              pertama, dan mengulangnya dua kali di satu halaman hanya
              menambah panjang gulir. Statistik lengkap tetap halamannya
              sendiri (FR-PRT-05).
            --}}
            @if ($angka['computed_at'] === null)
                {{-- Jujur menyatakan angka belum ada, bukan menampilkan nol
                     seolah-olah desa ini tidak berpenduduk. --}}
                <h2 class="mt-12 text-xs font-semibold uppercase tracking-wider text-content-tertiary">
                    Desa dalam Angka
                </h2>

                <x-alert type="info" class="mt-3 max-w-xl" title="Statistik belum disusun">
                    Ringkasan penduduk belum pernah disegarkan oleh petugas desa.
                </x-alert>
            @else
                <h2 class="mt-12 text-xs font-semibold uppercase tracking-wider text-content-tertiary">
                    Desa dalam Angka
                </h2>

                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
                    @foreach ([
                        ['Jiwa', $angka['penduduk']],
                        ['Keluarga', $angka['keluarga']],
                        ['Dusun', $angka['dusun']],
                        ['RT', $angka['rt']],
                    ] as [$label, $nilai])
                        <div class="glass-tile p-4">
                            <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                            <dd class="tabular mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                                {{ number_format($nilai, 0, ',', '.') }}
                            </dd>
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

    {{-- ========================== Akses cepat =============================
      Empat pintu yang paling sering dicari, sebagai baris ikon besar.

      Semuanya sudah dapat dijangkau dari menu atau dari tombol hero; yang
      ditambahkan di sini adalah KETERLIHATANNYA bagi pengunjung yang membuka
      situs di ponsel, tempat menu utama tersembunyi di balik tombol.
    --}}
    <section class="border-b border-line bg-surface" aria-labelledby="judul-akses">
        <h2 id="judul-akses" class="sr-only">Akses cepat</h2>
        <ul class="mx-auto grid max-w-5xl grid-cols-2 gap-px bg-line sm:grid-cols-4">
            @foreach ([
                ['Standar Pelayanan', 'Syarat & biaya tiap surat', 'portal.layanan', '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M9 13h6"/><path d="M9 17h3"/>'],
                ['Lacak Surat', 'Cek posisi pengajuan Anda', 'surat.lacak', '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'],
                ['Pengaduan', 'Sampaikan keluhan, bisa anonim', 'pengaduan.buat', '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>'],
                ['Statistik', 'Data penduduk desa', 'portal.statistik', '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>'],
            ] as [$judul, $penjelasan, $rute, $ikon])
                <li class="bg-surface">
                    <a href="{{ route($rute) }}"
                       class="group flex h-full flex-col items-start gap-3 p-5 transition-colors hover:bg-accent-soft sm:p-6">
                        <span class="icon-chip">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true">{!! $ikon !!}</svg>
                        </span>
                        <span>
                            <span class="block font-medium group-hover:underline">{{ $judul }}</span>
                            <span class="mt-0.5 block text-sm text-content-secondary">{{ $penjelasan }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ========================= Layanan surat ============================ --}}
    <section class="mx-auto max-w-5xl px-4 py-14" aria-labelledby="judul-layanan">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="judul-layanan" class="heading-rule text-2xl font-semibold tracking-tight">Layanan Surat</h2>
                <p class="mt-3 text-content-secondary">
                    Syarat, biaya, dan lama penyelesaian tiap surat.
                </p>
            </div>
            <a href="{{ route('portal.layanan') }}"
               class="text-sm font-medium text-accent hover:underline">
                Semua layanan ({{ $jumlahLayanan }}) &rarr;
            </a>
        </div>

        @if ($layanan->isEmpty())
            <x-alert type="info" class="mt-6" title="Belum ada layanan yang dibuka">
                Jenis surat yang dapat diajukan warga belum diaktifkan petugas desa.
            </x-alert>
        @else
            <ul class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($layanan as $jenis)
                    <li>
                        <a href="{{ route('portal.layanan.detail', $jenis->code) }}"
                           class="card card-lift group flex h-full flex-col p-5 hover:bg-surface-hover">
                            <span class="icon-chip">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/>
                                    <path d="M14 2v4a2 2 0 0 0 2 2h4"/>
                                </svg>
                            </span>

                            <span class="mt-4 font-medium group-hover:underline">{{ $jenis->name }}</span>
                            <span class="mt-1.5 flex-1 text-sm leading-relaxed text-content-secondary">
                                {{ \Illuminate\Support\Str::limit($jenis->description, 80) }}
                            </span>

                            <span class="mt-4 flex items-center gap-2 border-t border-line pt-3 text-xs">
                                <span class="tabular inline-flex items-center gap-1 text-content-tertiary">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         aria-hidden="true">
                                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                                    </svg>
                                    {{ $jenis->sla_days }} hari kerja
                                </span>
                                <span class="ml-auto rounded-md px-2 py-0.5 font-medium {{ (float) $jenis->fee > 0 ? 'bg-surface-sunken text-content-secondary' : 'bg-success-soft text-success' }}">
                                    {{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}
                                </span>
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
        <section class="border-y border-line bg-surface" aria-labelledby="judul-kabar">
            <div class="mx-auto max-w-5xl px-4 py-14">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="judul-kabar" class="heading-rule text-2xl font-semibold tracking-tight">Kabar Desa</h2>
                    <a href="{{ route('portal.berita') }}" class="text-sm font-medium text-accent hover:underline">
                        Semua berita &rarr;
                    </a>
                </div>

                <ul class="mt-7 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($tulisanTerbaru as $satu)
                        <li><x-portal.kartu-tulisan :tulisan="$satu" /></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    {{-- =================== Profil, pemerintahan & wilayah ================= --}}
    <section class="mx-auto max-w-5xl px-4 py-14" aria-labelledby="judul-jelajah">
        <h2 id="judul-jelajah" class="heading-rule text-2xl font-semibold tracking-tight">Mengenal Desa</h2>

        <ul class="mt-7 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['Profil Desa', 'Sejarah, visi & misi, serta letak geografis desa.', 'portal.profil', '<path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/>'],
                ['Pemerintahan Desa', 'Struktur organisasi dan perangkat desa yang bertugas.', 'portal.pemerintahan', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
                ['Pembagian Wilayah', 'Daftar dusun, rukun warga, dan rukun tetangga.', 'portal.wilayah', '<path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>'],
            ] as [$judul, $penjelasan, $rute, $ikon])
                <li>
                    <a href="{{ route($rute) }}" class="card card-lift group flex h-full items-start gap-4 p-5 hover:bg-surface-hover">
                        <span class="icon-chip">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 aria-hidden="true">{!! $ikon !!}</svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block font-medium group-hover:underline">{{ $judul }}</span>
                            <span class="mt-1 block text-sm leading-relaxed text-content-secondary">{{ $penjelasan }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- ======================= Ajakan penutup ============================
      Pengulangan tindakan utama di kaki halaman, untuk pengunjung yang
      membaca sampai bawah dan sudah jauh dari tombol di hero.
    --}}
    <section class="border-t border-line bg-surface-sunken" aria-labelledby="judul-ajakan">
        <div class="mx-auto flex max-w-5xl flex-col gap-5 px-4 py-12 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1">
                <h2 id="judul-ajakan" class="text-xl font-semibold tracking-tight">Perlu mengurus surat?</h2>
                <p class="mt-2 text-content-secondary">
                    Lihat syarat dan lama penyelesaiannya lebih dulu, lalu ajukan dari rumah
                    atau datang ke kantor desa pada jam layanan.
                </p>
            </div>

            <div class="flex shrink-0 flex-col gap-3 sm:flex-row">
                <a href="{{ route('portal.layanan') }}"
                   class="touch-target inline-flex items-center justify-center rounded-xl bg-accent px-5 py-3 font-medium text-content-inverse transition-colors hover:bg-accent-hover">
                    Lihat standar pelayanan
                </a>
                <a href="{{ route('portal.kontak') }}"
                   class="touch-target inline-flex items-center justify-center rounded-xl border border-line-strong bg-surface px-5 py-3 font-medium transition-colors hover:bg-surface-hover">
                    Jam layanan &amp; kontak
                </a>
            </div>
        </div>
    </section>
</x-portal.layout>
