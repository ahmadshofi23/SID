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
                           class="card flex items-start gap-4 p-5 transition-colors hover:bg-surface-hover">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ $jenis->name }}</p>
                                @if (filled($jenis->description))
                                    <p class="mt-1 text-sm text-content-secondary">{{ $jenis->description }}</p>
                                @endif
                                <p class="mt-2 text-xs text-content-tertiary">
                                    {{ count($jenis->requirements) }} syarat dokumen
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="tabular text-sm font-medium">{{ $jenis->sla_days }} hari kerja</p>
                                <p class="mt-1 text-sm {{ (float) $jenis->fee > 0 ? 'text-content-secondary' : 'text-success' }}">
                                    {{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}
                                </p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-portal.layout>
