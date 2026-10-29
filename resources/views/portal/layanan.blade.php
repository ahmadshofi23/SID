{{--
  Standar pelayanan — FR-PRT-04, BR-23. Halaman terpenting portal (docs/07 §2.1).

  Isinya adalah JANJI desa kepada warga: syarat, biaya, dan lama penyelesaian.
  Karena itu angkanya diambil dari konfigurasi jenis surat yang sama yang
  dipakai mesin status dan penghitung SLA — bukan dari teks yang diketik ulang
  di halaman portal. Dua sumber angka berarti satu di antaranya akan basi, dan
  yang basi itulah yang dibaca warga.
--}}
<x-portal.layout title="Standar Pelayanan"
                 heading="Standar Pelayanan Surat"
                 lead="Syarat, biaya, dan lama penyelesaian untuk setiap surat yang dilayani Desa {{ $village->name }}.">

    <div class="mx-auto max-w-5xl px-4 py-10">
        @if ($layanan->isEmpty())
            <x-alert type="info" title="Belum ada layanan yang dibuka">
                Jenis surat yang dapat diajukan warga belum diaktifkan petugas desa.
                Silakan hubungi kantor desa untuk keperluan surat Anda.
            </x-alert>
        @else
            <x-alert type="info" title="Biaya layanan">
                Seluruh surat yang tercantum di bawah tidak dipungut biaya kecuali
                disebutkan lain. Bila ada petugas yang meminta biaya di luar daftar
                ini, laporkan ke kantor desa.
            </x-alert>

            <ul class="mt-6 space-y-3">
                @foreach ($layanan as $jenis)
                    <li>
                        <a href="{{ route('portal.layanan.detail', $jenis->code) }}"
                           class="card card-lift group flex items-start gap-4 p-5 hover:bg-surface-hover">
                            <span class="icon-chip">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true">
                                    <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/>
                                    <path d="M14 2v4a2 2 0 0 0 2 2h4"/>
                                </svg>
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-medium group-hover:underline">{{ $jenis->name }}</p>
                                @if (filled($jenis->description))
                                    <p class="mt-1 text-sm leading-relaxed text-content-secondary">{{ $jenis->description }}</p>
                                @endif
                                <p class="mt-2 text-xs text-content-tertiary">
                                    {{ count($jenis->requirements) }} syarat dokumen
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-1.5">
                                <span class="tabular inline-flex items-center gap-1 text-sm text-content-secondary">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         aria-hidden="true">
                                        <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
                                    </svg>
                                    {{ $jenis->sla_days }} hari kerja
                                </span>
                                <span class="rounded-md px-2 py-0.5 text-xs font-medium {{ (float) $jenis->fee > 0 ? 'bg-surface-sunken text-content-secondary' : 'bg-success-soft text-success' }}">
                                    {{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-portal.layout>
