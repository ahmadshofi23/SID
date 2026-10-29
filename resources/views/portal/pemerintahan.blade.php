{{--
  Struktur organisasi & perangkat desa — FR-PRT-01.

  Sumbernya `officials`, BUKAN `users`. Tabel akun memuat surel, nomor ponsel,
  keadaan 2FA, dan waktu login terakhir — tidak satu pun berkepentingan di
  halaman publik, dan membukanya berarti memberi penyerang daftar sasaran
  beserta siapa di antaranya yang belum memasang 2FA.
--}}
@php
    /*
     * Perangkat pertama ditampilkan lebih besar. Urutannya datang dari
     * `sort_order` yang disusun petugas (PortalService::organizationChart),
     * bukan dari pencocokan jabatan di tampilan: desa yang menuliskan
     * jabatannya dengan ejaan lain — "Kades", "Kepala Desa (Plt.)" — tetap
     * benar, sementara pencocokan teks akan gagal tanpa suara.
     */
    $utama = $perangkat->first();
    $lainnya = $perangkat->skip(1);
@endphp

<x-portal.layout title="Pemerintahan Desa"
                 eyebrow="Struktur organisasi"
                 heading="Pemerintahan Desa {{ $village->name }}"
                 lead="Perangkat desa yang sedang bertugas beserta jabatannya.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($perangkat->isEmpty())
            <x-alert type="info" title="Data perangkat desa belum diisi">
                Struktur organisasi akan tampil di sini setelah Admin Desa mengisi
                daftar perangkat desa.
            </x-alert>
        @else
            {{-- -------------------------- Perangkat utama -------------------- --}}
            <section class="panel panel-accent" aria-labelledby="judul-utama">
                <div class="panel-body flex flex-col items-start gap-5 sm:flex-row sm:items-center">
                    @if ($utama->photo_path)
                        {{--
                          Disk `public` secara eksplisit, sama seperti logo desa
                          pada kop surat. Lampiran syarat pengajuan memakai disk
                          PRIVAT (config/sid.php) karena berisi fotokopi KTP;
                          foto perangkat desa justru memang dipublikasikan, dan
                          menyamakan keduanya akan membuat salah satunya salah.
                        --}}
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($utama->photo_path) }}"
                             alt="Foto {{ $utama->name }}"
                             width="96" height="96" decoding="async"
                             class="size-24 shrink-0 rounded-2xl object-cover ring-1 ring-line">
                    @else
                        <span class="flex size-24 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-brand-500 to-brand-700 text-3xl font-semibold text-white ring-1 ring-brand-700/20"
                              aria-hidden="true">
                            {{ \Illuminate\Support\Str::of($utama->name)->substr(0, 1)->upper() }}
                        </span>
                    @endif

                    <div class="min-w-0">
                        <p class="badge badge-gold">{{ $utama->position }}</p>
                        <h2 id="judul-utama" class="mt-2 text-xl font-semibold tracking-tight sm:text-2xl">{{ $utama->name }}</h2>
                        @if ($utama->region)
                            <p class="mt-1 text-sm text-content-secondary">Wilayah: {{ $utama->region->name }}</p>
                        @endif
                        <p class="mt-3 max-w-xl text-sm leading-relaxed text-content-secondary">
                            Pelayanan surat, pengaduan, dan keterangan resmi desa berada di
                            bawah tanggung jawab jabatan ini.
                        </p>
                    </div>

                    <div class="sm:ml-auto sm:text-right">
                        <a href="{{ route('portal.kontak') }}" class="link-arrow text-sm">
                            Hubungi kantor desa
                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </section>

            {{-- ------------------------ Perangkat lainnya -------------------- --}}
            @if ($lainnya->isNotEmpty())
                <div class="mt-10 flex flex-wrap items-end justify-between gap-3">
                    <h2 class="heading-rule text-xl font-semibold tracking-tight">Perangkat desa</h2>
                    <p class="tabular text-sm text-content-tertiary">{{ $perangkat->count() }} orang bertugas</p>
                </div>

                <ul class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($lainnya as $orang)
                        <li class="card card-lift flex items-start gap-4 p-5">
                            @if ($orang->photo_path)
                                {{-- `loading="lazy"` + ukuran tetap: foto perangkat adalah
                                     muatan terberat halaman ini, dan portal dibatasi 500 KB
                                     pada pemuatan pertama (NFR-PERF-04). --}}
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($orang->photo_path) }}"
                                     alt="Foto {{ $orang->name }}"
                                     width="56" height="56" loading="lazy" decoding="async"
                                     class="size-14 shrink-0 rounded-xl object-cover ring-1 ring-line">
                            @else
                                <span class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-lg font-semibold text-accent-text ring-1 ring-accent/20"
                                      aria-hidden="true">
                                    {{ \Illuminate\Support\Str::of($orang->name)->substr(0, 1)->upper() }}
                                </span>
                            @endif

                            <div class="min-w-0">
                                <p class="font-semibold leading-snug">{{ $orang->name }}</p>
                                <p class="mt-0.5 text-sm text-accent-text">{{ $orang->position }}</p>
                                @if ($orang->region)
                                    <p class="chip mt-2">
                                        <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                             stroke-linejoin="round" aria-hidden="true">
                                            <path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>
                                        </svg>
                                        {{ $orang->region->name }}
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{--
              NIP/NIAP dan tanda tangan SENGAJA tidak ditampilkan. Nomor induk
              adalah pengenal kepegawaian, dan gambar tanda tangan yang beredar
              bebas dapat ditempelkan ke dokumen mana pun — padahal gambar itu
              dipakai mencetak surat desa (docs/08 §4).
            --}}
            <p class="mt-8 rounded-xl border border-line bg-surface-sunken p-4 text-sm text-content-secondary">
                Keperluan administrasi yang membutuhkan data lengkap perangkat desa
                dapat diajukan langsung ke kantor desa.
            </p>
        @endif
    </div>
</x-portal.layout>
