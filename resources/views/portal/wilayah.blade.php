{{-- Pembagian wilayah: Dusun → RW → RT (FR-PRT-01, FR-PDK-14). --}}
@php
    /*
     * Dihitung dari pohon yang SAMA yang digambar di bawah, bukan dari kueri
     * hitung tersendiri: dua sumber angka berarti ringkasan di atas dapat
     * menyebut "24 RT" sementara yang terdaftar di bawahnya 23.
     */
    $jumlahRw = $dusun->sum(fn ($d) => $d->children->count());
    $jumlahRt = $dusun->sum(fn ($d) => $d->children->sum(fn ($rw) => $rw->children->count()));
@endphp

<x-portal.layout title="Pembagian Wilayah"
                 eyebrow="Mengenal desa"
                 heading="Pembagian Wilayah Desa {{ $village->name }}"
                 lead="Daftar dusun, rukun warga, dan rukun tetangga di desa ini."
                 :parent="['label' => 'Profil Desa', 'url' => route('portal.profil')]">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($dusun->isEmpty())
            <x-alert type="info" title="Data wilayah belum diisi">
                Daftar dusun, RW, dan RT akan tampil di sini setelah Admin Desa
                mengisi hierarki wilayah.
            </x-alert>
        @else
            <dl class="grid grid-cols-3 gap-3">
                @foreach ([
                    ['Dusun', $dusun->count()],
                    ['Rukun Warga', $jumlahRw],
                    ['Rukun Tetangga', $jumlahRt],
                ] as [$label, $nilai])
                    <div class="card p-4">
                        <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                        <dd class="tabular mt-1 text-2xl font-semibold tracking-tight">{{ number_format($nilai, 0, ',', '.') }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-8 space-y-5">
                @foreach ($dusun as $satuDusun)
                    <section class="panel" aria-labelledby="dusun-{{ $satuDusun->id }}">
                        <div class="panel-head">
                            <span class="icon-chip">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11"/><circle cx="12" cy="11" r="3"/>
                                </svg>
                            </span>

                            {{-- Nama wilayah pada `regions` SUDAH memuat tingkatannya
                                 ("Dusun Krajan", "RW 01", "RT 001"). Menambahkan awalan
                                 di sini menghasilkan "Dusun Dusun Krajan". --}}
                            <h2 id="dusun-{{ $satuDusun->id }}" class="panel-title">
                                {{ $satuDusun->name }}
                                <span class="tabular ml-1 text-xs font-normal text-content-tertiary">{{ $satuDusun->code }}</span>
                            </h2>

                            <p class="tabular ml-auto shrink-0 text-xs text-content-secondary">
                                {{ $satuDusun->children->count() }} RW &middot;
                                {{ $satuDusun->children->sum(fn ($rw) => $rw->children->count()) }} RT
                            </p>
                        </div>

                        <div class="panel-body">
                            @if ($satuDusun->children->isEmpty())
                                <p class="text-sm text-content-tertiary">Belum ada RW terdaftar.</p>
                            @else
                                <ul class="grid gap-3 sm:grid-cols-2">
                                    @foreach ($satuDusun->children as $rw)
                                        <li class="rounded-xl border border-line bg-surface-sunken p-4">
                                            <p class="flex items-center justify-between gap-2">
                                                <span class="font-medium">{{ $rw->name }}</span>
                                                <span class="badge badge-neutral tabular">{{ $rw->children->count() }} RT</span>
                                            </p>

                                            @if ($rw->children->isEmpty())
                                                <p class="mt-2 text-xs text-content-tertiary">Belum ada RT terdaftar.</p>
                                            @else
                                                {{-- RT sebagai keping terpisah, bukan satu baris
                                                     dipisah koma: deret "RT 001, RT 002, RT 003…"
                                                     terbaca sebagai satu paragraf panjang, dan
                                                     orang yang mencari RT-nya sendiri harus
                                                     menyusurinya kata per kata. --}}
                                                <ul class="mt-2.5 flex flex-wrap gap-1.5">
                                                    @foreach ($rw->children as $rt)
                                                        <li class="tabular rounded-md border border-line bg-surface px-2 py-0.5 text-xs text-content-secondary">
                                                            {{ $rt->name }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>

            <p class="mt-8 text-sm">
                <a href="{{ route('portal.profil') }}"
                   class="inline-flex items-center gap-1.5 font-medium text-accent hover:underline">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>
                    </svg>
                    Kembali ke profil desa
                </a>
            </p>
        @endif
    </div>
</x-portal.layout>
