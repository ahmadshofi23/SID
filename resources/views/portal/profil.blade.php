{{--
  Profil desa — FR-PRT-01.

  MENGAPA SEJARAH DAN VISI-MISI SATU HALAMAN: docs/07 §2.1 memisahkannya
  menjadi /profil/sejarah dan /profil/visi-misi. Keduanya satu kolom teks pada
  `village_profile`, jadi memisahkannya menghasilkan dua halaman berisi satu
  paragraf masing-masing — menambah klik tanpa menambah isi. Pembagian wilayah
  tetap halamannya sendiri karena isinya daftar panjang.
--}}
<x-portal.layout title="Profil Desa"
                 eyebrow="Mengenal desa"
                 heading="Profil Desa {{ $village->name }}"
                 lead="Sejarah, visi dan misi, letak geografis, serta gambaran penduduk desa.">

    <div class="mx-auto max-w-5xl px-4 py-10">

        {{-- ------------------------ Identitas ringkas -----------------------
          Empat keterangan yang paling sering dicari instansi lain dan warga
          yang mengisi formulir: kode desa Kemendagri, jumlah jiwa, jumlah KK,
          dan pembagian wilayah. Diletakkan sebelum sejarah karena yang membuka
          halaman profil desa sebagian besar sedang MENCARI SALAH SATUNYA.
        --}}
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['Kode desa', $village->code ?: '—', 'Kemendagri'],
                ['Penduduk', $angka['computed_at'] === null ? '—' : number_format($angka['penduduk'], 0, ',', '.'), 'jiwa'],
                ['Keluarga', $angka['computed_at'] === null ? '—' : number_format($angka['keluarga'], 0, ',', '.'), 'kartu keluarga'],
                ['Wilayah', $angka['dusun'].' / '.$angka['rt'], 'dusun / RT'],
            ] as [$label, $nilai, $satuan])
                <div class="card p-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                    <dd class="tabular mt-1 text-lg font-semibold tracking-tight">{{ $nilai }}</dd>
                    <dd class="text-xs text-content-tertiary">{{ $satuan }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="mt-8 grid gap-8 lg:grid-cols-3">

            <div class="space-y-8 lg:col-span-2">
                {{-- --------------------------- Sejarah ---------------------- --}}
                <section class="panel panel-accent" aria-labelledby="judul-sejarah">
                    <div class="panel-head">
                        <h2 id="judul-sejarah" class="panel-title">Sejarah Desa</h2>
                    </div>
                    <div class="panel-body">
                        @if (filled($village->history))
                            {{-- `whitespace-pre-line`: isi kolom ini teks biasa yang
                                 diketik petugas, bukan HTML. Merendernya sebagai HTML
                                 akan membuka XML/XSS dari panel pengaturan. --}}
                            <p class="whitespace-pre-line leading-relaxed text-content-secondary">{{ $village->history }}</p>
                        @else
                            <p class="text-content-tertiary">Sejarah desa belum diisi petugas desa.</p>
                        @endif
                    </div>
                </section>

                {{-- -------------------------- Visi & misi ------------------- --}}
                <section aria-labelledby="judul-visi">
                    <h2 id="judul-visi" class="heading-rule text-xl font-semibold tracking-tight">Visi &amp; Misi</h2>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div class="card quote-panel p-5">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-accent-text">Visi</h3>
                            <p class="mt-2 whitespace-pre-line text-[1.0625rem] leading-relaxed font-medium">
                                {{ filled($village->vision) ? $village->vision : 'Belum diisi.' }}
                            </p>
                        </div>
                        <div class="card quote-panel p-5">
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-accent-text">Misi</h3>
                            <p class="mt-2 whitespace-pre-line leading-relaxed text-content-secondary">
                                {{ filled($village->mission) ? $village->mission : 'Belum diisi.' }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ------------------------- Panel ringkas ---------------------- --}}
            <aside class="space-y-4">
                <section class="panel" aria-labelledby="judul-geografis">
                    <div class="panel-head">
                        <h2 id="judul-geografis" class="panel-title">Letak &amp; wilayah</h2>
                    </div>
                    <div class="panel-body">
                        <dl>
                            <div class="spec-row">
                                <dt class="spec-label">Alamat kantor desa</dt>
                                <dd class="spec-value">{{ $village->address ?: 'Belum diisi' }}</dd>
                            </div>
                            <div class="spec-row">
                                <dt class="spec-label">Kecamatan</dt>
                                <dd class="spec-value">{{ $village->district ?: 'Belum diisi' }}</dd>
                            </div>
                            <div class="spec-row">
                                <dt class="spec-label">Kabupaten</dt>
                                <dd class="spec-value">
                                    {{ $village->regency ?: 'Belum diisi' }}@if ($village->province)<span class="block text-xs font-normal text-content-tertiary">{{ $village->province }}</span>@endif
                                </dd>
                            </div>
                            <div class="spec-row">
                                <dt class="spec-label">Kode desa (Kemendagri)</dt>
                                <dd class="spec-value tabular">{{ $village->code ?: 'Belum diisi' }}</dd>
                            </div>
                            @if ($village->latitude && $village->longitude)
                                <div class="spec-row">
                                    <dt class="spec-label">Koordinat</dt>
                                    <dd class="spec-value tabular">{{ $village->latitude }}, {{ $village->longitude }}</dd>
                                </div>
                            @endif
                            <div class="spec-row">
                                <dt class="spec-label">Pembagian wilayah</dt>
                                <dd class="spec-value tabular">{{ $angka['dusun'] }} dusun &middot; {{ $angka['rt'] }} RT</dd>
                            </div>
                        </dl>

                        <a href="{{ route('portal.wilayah') }}" class="link-arrow mt-4 text-sm">
                            Daftar dusun, RW &amp; RT
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                            </svg>
                        </a>
                    </div>
                </section>

                <section class="panel" aria-labelledby="judul-demografi">
                    <div class="panel-head">
                        <h2 id="judul-demografi" class="panel-title">Penduduk</h2>
                    </div>
                    <div class="panel-body">
                        @if ($angka['computed_at'] === null)
                            <p class="text-sm text-content-tertiary">
                                Ringkasan penduduk belum pernah disegarkan petugas desa.
                            </p>
                        @else
                            <dl>
                                <div class="spec-row">
                                    <dt class="spec-label">Jumlah jiwa</dt>
                                    <dd class="spec-value tabular">{{ number_format($angka['penduduk'], 0, ',', '.') }}</dd>
                                </div>
                                <div class="spec-row">
                                    <dt class="spec-label">Kartu keluarga</dt>
                                    <dd class="spec-value tabular">{{ number_format($angka['keluarga'], 0, ',', '.') }}</dd>
                                </div>
                            </dl>

                            <a href="{{ route('portal.statistik') }}" class="link-arrow mt-4 text-sm">
                                Statistik lengkap penduduk
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                                </svg>
                            </a>
                        @endif
                    </div>
                </section>

                <section class="card card-lift p-5" aria-labelledby="judul-perangkat">
                    <span class="icon-chip">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                            <path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </span>
                    <h2 id="judul-perangkat" class="mt-3 font-semibold">Pemerintahan</h2>
                    <p class="mt-1.5 text-sm leading-relaxed text-content-secondary">
                        Struktur organisasi dan perangkat desa yang sedang bertugas.
                    </p>
                    <a href="{{ route('portal.pemerintahan') }}" class="link-arrow mt-3 text-sm">
                        Lihat perangkat desa
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                        </svg>
                    </a>
                </section>
            </aside>
        </div>
    </div>
</x-portal.layout>
