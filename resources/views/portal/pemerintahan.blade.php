{{--
  Struktur organisasi & perangkat desa — FR-PRT-01.

  Sumbernya `officials`, BUKAN `users`. Tabel akun memuat surel, nomor ponsel,
  keadaan 2FA, dan waktu login terakhir — tidak satu pun berkepentingan di
  halaman publik, dan membukanya berarti memberi penyerang daftar sasaran
  beserta siapa di antaranya yang belum memasang 2FA.
--}}
<x-portal.layout title="Pemerintahan Desa"
                 heading="Pemerintahan Desa {{ $village->name }}"
                 lead="Perangkat desa yang sedang bertugas beserta jabatannya.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($perangkat->isEmpty())
            <x-alert type="info" title="Data perangkat desa belum diisi">
                Struktur organisasi akan tampil di sini setelah Admin Desa mengisi
                daftar perangkat desa.
            </x-alert>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($perangkat as $orang)
                    <li class="card card-lift flex items-start gap-4 p-5">
                        @if ($orang->photo_path)
                            {{--
                              Disk `public` secara eksplisit, sama seperti logo desa
                              pada kop surat. Lampiran syarat pengajuan memakai disk
                              PRIVAT (config/sid.php) karena berisi fotokopi KTP;
                              foto perangkat desa justru memang dipublikasikan, dan
                              menyamakan keduanya akan membuat salah satunya salah.

                              `loading="lazy"` + ukuran tetap: foto perangkat adalah
                              muatan terberat halaman ini, dan portal dibatasi 500 KB
                              pada pemuatan pertama (NFR-PERF-04).
                            --}}
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($orang->photo_path) }}"
                                 alt="Foto {{ $orang->name }}"
                                 width="64" height="64" loading="lazy" decoding="async"
                                 class="size-16 shrink-0 rounded-full object-cover ring-2 ring-accent-soft">
                        @else
                            <span class="flex size-16 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-brand-500 to-brand-700 text-lg font-semibold text-white ring-2 ring-accent-soft"
                                  aria-hidden="true">
                                {{ \Illuminate\Support\Str::of($orang->name)->substr(0, 1)->upper() }}
                            </span>
                        @endif

                        <div class="min-w-0">
                            <p class="font-medium">{{ $orang->name }}</p>
                            <p class="mt-0.5 text-sm text-accent-text">{{ $orang->position }}</p>
                            @if ($orang->region)
                                <p class="mt-1 text-xs text-content-tertiary">Wilayah: {{ $orang->region->name }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            {{--
              NIP/NIAP dan tanda tangan SENGAJA tidak ditampilkan. Nomor induk
              adalah pengenal kepegawaian, dan gambar tanda tangan yang beredar
              bebas dapat ditempelkan ke dokumen mana pun — padahal gambar itu
              dipakai mencetak surat desa (docs/08 §4).
            --}}
            <p class="mt-6 text-sm text-content-tertiary">
                Keperluan administrasi yang membutuhkan data lengkap perangkat desa
                dapat diajukan langsung ke kantor desa.
            </p>
        @endif
    </div>
</x-portal.layout>
