{{-- Pembagian wilayah: Dusun → RW → RT (FR-PRT-01, FR-PDK-14). --}}
<x-portal.layout title="Pembagian Wilayah"
                 heading="Pembagian Wilayah Desa {{ $village->name }}"
                 lead="Daftar dusun, rukun warga, dan rukun tetangga di desa ini.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($dusun->isEmpty())
            <x-alert type="info" title="Data wilayah belum diisi">
                Daftar dusun, RW, dan RT akan tampil di sini setelah Admin Desa
                mengisi hierarki wilayah.
            </x-alert>
        @else
            <div class="space-y-4">
                @foreach ($dusun as $satuDusun)
                    <section class="card card-lift p-5" aria-labelledby="dusun-{{ $satuDusun->id }}">
                        {{-- Nama wilayah pada `regions` SUDAH memuat tingkatannya
                             ("Dusun Krajan", "RW 01", "RT 001"). Menambahkan awalan
                             di sini menghasilkan "Dusun Dusun Krajan". --}}
                        <h2 id="dusun-{{ $satuDusun->id }}" class="font-semibold">
                            {{ $satuDusun->name }}
                            <span class="ml-1 text-sm font-normal text-content-tertiary tabular">({{ $satuDusun->code }})</span>
                        </h2>

                        @if ($satuDusun->children->isEmpty())
                            <p class="mt-2 text-sm text-content-tertiary">Belum ada RW terdaftar.</p>
                        @else
                            <ul class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($satuDusun->children as $rw)
                                    <li class="rounded-lg border border-line bg-surface-sunken p-3">
                                        <p class="text-sm font-medium">{{ $rw->name }}</p>
                                        @if ($rw->children->isEmpty())
                                            <p class="mt-1 text-xs text-content-tertiary">Belum ada RT terdaftar.</p>
                                        @else
                                            <p class="mt-1 text-xs text-content-secondary">
                                                {{ $rw->children->pluck('name')->implode(', ') }}
                                            </p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>

            <p class="mt-6 text-sm">
                <a href="{{ route('portal.profil') }}" class="font-medium text-accent hover:underline">
                    &larr; Kembali ke profil desa
                </a>
            </p>
        @endif
    </div>
</x-portal.layout>
