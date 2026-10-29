{{--
  Profil desa — FR-PRT-01.

  MENGAPA SEJARAH DAN VISI-MISI SATU HALAMAN: docs/07 §2.1 memisahkannya
  menjadi /profil/sejarah dan /profil/visi-misi. Keduanya satu kolom teks pada
  `village_profile`, jadi memisahkannya menghasilkan dua halaman berisi satu
  paragraf masing-masing — menambah klik tanpa menambah isi. Pembagian wilayah
  tetap halamannya sendiri karena isinya daftar panjang.
--}}
<x-portal.layout title="Profil Desa"
                 heading="Profil Desa {{ $village->name }}"
                 lead="Sejarah, visi dan misi, letak geografis, serta gambaran penduduk desa.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        <div class="grid gap-8 lg:grid-cols-3">

            <div class="space-y-8 lg:col-span-2">
                {{-- --------------------------- Sejarah ---------------------- --}}
                <section aria-labelledby="judul-sejarah">
                    <h2 id="judul-sejarah" class="text-xl font-semibold">Sejarah Desa</h2>
                    @if (filled($village->history))
                        {{-- `whitespace-pre-line`: isi kolom ini teks biasa yang
                             diketik petugas, bukan HTML. Merendernya sebagai HTML
                             akan membuka XML/XSS dari panel pengaturan. --}}
                        <p class="mt-3 whitespace-pre-line leading-relaxed text-content-secondary">{{ $village->history }}</p>
                    @else
                        <p class="mt-3 text-content-tertiary">Sejarah desa belum diisi petugas desa.</p>
                    @endif
                </section>

                {{-- -------------------------- Visi & misi ------------------- --}}
                <section aria-labelledby="judul-visi">
                    <h2 id="judul-visi" class="text-xl font-semibold">Visi &amp; Misi</h2>

                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div class="card p-5">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-content-tertiary">Visi</h3>
                            <p class="mt-2 whitespace-pre-line leading-relaxed">
                                {{ filled($village->vision) ? $village->vision : 'Belum diisi.' }}
                            </p>
                        </div>
                        <div class="card p-5">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-content-tertiary">Misi</h3>
                            <p class="mt-2 whitespace-pre-line leading-relaxed">
                                {{ filled($village->mission) ? $village->mission : 'Belum diisi.' }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ------------------------- Panel ringkas ---------------------- --}}
            <aside class="space-y-4">
                <section class="card p-5" aria-labelledby="judul-geografis">
                    <h2 id="judul-geografis" class="font-semibold">Letak &amp; wilayah</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-content-tertiary">Alamat kantor desa</dt>
                            <dd>{{ $village->address ?: 'Belum diisi' }}</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Kode desa (Kemendagri)</dt>
                            <dd class="tabular">{{ $village->code ?: 'Belum diisi' }}</dd>
                        </div>
                        @if ($village->latitude && $village->longitude)
                            <div>
                                <dt class="text-content-tertiary">Koordinat</dt>
                                <dd class="tabular">{{ $village->latitude }}, {{ $village->longitude }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-content-tertiary">Pembagian wilayah</dt>
                            <dd class="tabular">
                                {{ $angka['dusun'] }} dusun &middot; {{ $angka['rt'] }} RT
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-sm">
                        <a href="{{ route('portal.wilayah') }}" class="font-medium text-accent hover:underline">
                            Daftar dusun, RW &amp; RT &rarr;
                        </a>
                    </p>
                </section>

                <section class="card p-5" aria-labelledby="judul-demografi">
                    <h2 id="judul-demografi" class="font-semibold">Penduduk</h2>
                    @if ($angka['computed_at'] === null)
                        <p class="mt-3 text-sm text-content-tertiary">
                            Ringkasan penduduk belum pernah disegarkan petugas desa.
                        </p>
                    @else
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-content-tertiary">Jumlah jiwa</dt>
                                <dd class="tabular font-medium">{{ number_format($angka['penduduk'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-content-tertiary">Kartu keluarga</dt>
                                <dd class="tabular font-medium">{{ number_format($angka['keluarga'], 0, ',', '.') }}</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-sm">
                            <a href="{{ route('portal.statistik') }}" class="font-medium text-accent hover:underline">
                                Statistik lengkap &rarr;
                            </a>
                        </p>
                    @endif
                </section>

                <section class="card p-5" aria-labelledby="judul-perangkat">
                    <h2 id="judul-perangkat" class="font-semibold">Pemerintahan</h2>
                    <p class="mt-2 text-sm text-content-secondary">
                        Struktur organisasi dan perangkat desa yang sedang bertugas.
                    </p>
                    <p class="mt-3 text-sm">
                        <a href="{{ route('portal.pemerintahan') }}" class="font-medium text-accent hover:underline">
                            Lihat perangkat desa &rarr;
                        </a>
                    </p>
                </section>
            </aside>
        </div>
    </div>
</x-portal.layout>
