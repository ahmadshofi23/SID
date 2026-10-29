{{-- Detail satu layanan — FR-PRT-04, BR-23. --}}
@php
    $gratis = (float) $jenis->fee <= 0;
    $biaya = $gratis ? 'Gratis' : 'Rp '.number_format((float) $jenis->fee, 0, ',', '.');
    $wajib = collect($jenis->requirements)->filter(fn ($s) => $s['required'] ?? true)->count();
@endphp

<x-portal.layout title="{{ $jenis->name }}"
                 :description="'Syarat, biaya, dan lama penyelesaian '.$jenis->name.' di Desa '.$village->name.'.'"
                 eyebrow="Standar pelayanan"
                 heading="{{ $jenis->name }}"
                 :lead="filled($jenis->description) ? $jenis->description : null"
                 :parent="['label' => 'Standar Pelayanan', 'url' => route('portal.layanan')]">

    <x-slot:actions>
        <a href="{{ route('warga.ajukan', ['jenis' => $jenis->code]) }}"
           class="touch-target group inline-flex items-center gap-2 rounded-xl bg-accent px-5 py-2.5 font-medium text-content-inverse shadow-card transition-colors hover:bg-accent-hover">
            Ajukan daring
            <svg class="size-4 transition-transform group-hover:translate-x-0.5" xmlns="http://www.w3.org/2000/svg"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 aria-hidden="true">
                <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
            </svg>
        </a>
    </x-slot:actions>

    <div class="mx-auto max-w-5xl px-4 py-10">

        {{-- ------------------------- Empat angka pokok ----------------------
          Lama penyelesaian, biaya, jumlah syarat, dan jumlah tahap: empat hal
          yang ditanyakan warga di loket sebelum apa pun yang lain. Ditaruh
          sebagai baris pertama halaman supaya tidak perlu dibaca dari paragraf.
        --}}
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['Lama penyelesaian', $jenis->sla_days.' hari kerja', false],
                ['Biaya', $biaya, $gratis],
                ['Dokumen syarat', count($jenis->requirements).' dokumen', false],
                ['Tahap pemeriksaan', $jenis->stepCount().' tahap', false],
            ] as [$label, $nilai, $hijau])
                <div class="card p-4">
                    <dt class="text-xs font-medium uppercase tracking-wider text-content-tertiary">{{ $label }}</dt>
                    <dd class="tabular mt-1 text-lg font-semibold tracking-tight {{ $hijau ? 'text-success' : '' }}">{{ $nilai }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="mt-8 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">

                {{-- ------------------------ Syarat -------------------------- --}}
                <section class="panel panel-accent" aria-labelledby="judul-syarat">
                    <div class="panel-head">
                        <h2 id="judul-syarat" class="panel-title">Syarat yang harus disiapkan</h2>
                        @if ($jenis->requirements !== [])
                            <span class="badge badge-neutral ml-auto tabular">
                                {{ $wajib }} wajib
                                @if ($wajib < count($jenis->requirements))
                                    &middot; {{ count($jenis->requirements) - $wajib }} bila ada
                                @endif
                            </span>
                        @endif
                    </div>

                    <div class="panel-body">
                        @if ($jenis->requirements === [])
                            <p class="text-content-secondary">
                                Tidak ada dokumen syarat yang ditetapkan untuk surat ini.
                            </p>
                        @else
                            <ul class="divide-y divide-line">
                                @foreach ($jenis->requirements as $syarat)
                                    @php $perlu = $syarat['required'] ?? true; @endphp
                                    <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md {{ $perlu ? 'bg-accent-soft text-accent-text' : 'bg-surface-sunken text-content-tertiary' }}"
                                              aria-hidden="true">
                                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                                 fill="none" stroke="currentColor" stroke-width="2.5"
                                                 stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M20 6 9 17l-5-5"/>
                                            </svg>
                                        </span>
                                        <span class="min-w-0 flex-1 text-sm leading-relaxed">
                                            {{ $syarat['label'] ?? 'Dokumen' }}
                                            @if (! $perlu)
                                                <span class="badge badge-neutral ml-1">bila ada</span>
                                            @endif
                                            @if (filled($syarat['note'] ?? null))
                                                <span class="mt-0.5 block text-xs text-content-tertiary">{{ $syarat['note'] }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="mt-4 border-t border-line pt-3 text-xs text-content-tertiary">
                                Bawa dokumen asli beserta fotokopinya bila mengurus di kantor desa.
                                Untuk pengajuan daring, cukup foto yang terbaca jelas.
                            </p>
                        @endif
                    </div>
                </section>

                {{-- --------------------- Catatan kebijakan ------------------ --}}
                @if (filled($jenis->warning_note))
                    {{-- Catatan ini ditampilkan APA ADANYA dari konfigurasi jenis
                         surat: isinya kebijakan desa (mis. SKTM tidak diterbitkan
                         otomatis), dan warga berhak tahu sebelum datang. --}}
                    <x-alert type="peringatan" title="Perlu diperhatikan">
                        {{ $jenis->warning_note }}
                    </x-alert>
                @endif

                {{-- ------------------------ Cara mengajukan ----------------- --}}
                <section class="panel" aria-labelledby="judul-cara">
                    <div class="panel-head">
                        <h2 id="judul-cara" class="panel-title">Cara mengajukan</h2>
                    </div>

                    <div class="panel-body">
                        {{--
                          DUA JALUR, dan keduanya tetap terbuka. Pengajuan daring
                          tidak menggantikan loket: sebagian warga tidak punya akun,
                          tidak punya ponsel yang memadai, atau memang lebih percaya
                          datang langsung (docs/03 §2.2).
                        --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-accent/30 bg-accent-soft/60 p-4">
                                <p class="flex items-center gap-2 font-semibold text-accent-text">
                                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        <rect width="14" height="20" x="5" y="2" rx="2"/><path d="M12 18h.01"/>
                                    </svg>
                                    Dari rumah
                                </p>
                                <p class="mt-1.5 text-sm leading-relaxed text-content-secondary">
                                    Isi formulir dan unggah foto syaratnya. Surat diambil di
                                    kantor desa setelah disetujui.
                                </p>
                                <a href="{{ route('warga.ajukan', ['jenis' => $jenis->code]) }}"
                                   class="touch-target mt-3 inline-flex items-center justify-center rounded-lg bg-accent px-5 text-sm font-medium text-content-inverse transition-colors hover:bg-accent-hover">
                                    Ajukan daring
                                </a>
                                @guest
                                    <p class="mt-2 text-xs text-content-tertiary">
                                        Perlu akun warga.
                                        <a href="{{ route('daftar') }}" class="text-accent hover:underline">Daftar di sini</a> —
                                        gratis, diperiksa petugas satu hari kerja.
                                    </p>
                                @endguest
                            </div>

                            <div class="rounded-xl border border-line bg-surface-sunken p-4">
                                <p class="flex items-center gap-2 font-semibold">
                                    <svg class="size-4 text-content-secondary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/>
                                    </svg>
                                    Di kantor desa
                                </p>
                                <p class="mt-1.5 text-sm leading-relaxed text-content-secondary">
                                    Tanpa akun. Bawa dokumen syarat, petugas yang mencatat
                                    pengajuan Anda dan memberi nomor tiket.
                                </p>
                                <a href="{{ route('portal.kontak') }}"
                                   class="touch-target mt-3 inline-flex items-center justify-center rounded-lg border border-line-strong bg-surface px-5 text-sm font-medium transition-colors hover:bg-surface-hover">
                                    Jam layanan
                                </a>
                            </div>
                        </div>

                        <ol class="steps mt-6">
                            @foreach ([
                                'Siapkan seluruh dokumen syarat di atas, asli dan fotokopinya.',
                                'Ajukan daring dari halaman ini, atau datang ke kantor desa pada jam layanan.',
                                'Anda menerima NOMOR TIKET begitu pengajuan tercatat.',
                                'Pantau statusnya lewat halaman Lacak Status Pengajuan — atau dari daftar pengajuan di akun warga Anda.',
                                'Surat diambil di kantor desa setelah disetujui.',
                            ] as $langkah)
                                <li class="text-content-secondary">{{ $langkah }}</li>
                            @endforeach
                        </ol>

                        <p class="mt-5 border-t border-line pt-4 text-sm">
                            <a href="{{ route('surat.lacak') }}" class="link-arrow">
                                Lacak pengajuan dengan nomor tiket
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                                </svg>
                            </a>
                        </p>
                    </div>
                </section>
            </div>

            {{-- -------------------------- Ringkasan ------------------------- --}}
            <aside class="lg:col-span-1">
                <div class="aside-sticky space-y-4">
                    <section class="panel" aria-labelledby="judul-ringkas">
                        <div class="panel-head">
                            <h2 id="judul-ringkas" class="panel-title">Ringkasan layanan</h2>
                        </div>

                        <div class="panel-body">
                            <dl>
                                <div class="spec-row">
                                    <dt class="spec-label">Lama penyelesaian</dt>
                                    <dd class="spec-value tabular">{{ $jenis->sla_days }} hari kerja</dd>
                                </div>
                                <div class="spec-row">
                                    <dt class="spec-label">Biaya</dt>
                                    <dd class="spec-value {{ $gratis ? 'text-success' : '' }}">{{ $biaya }}</dd>
                                </div>
                                @if ($jenis->validity_months)
                                    <div class="spec-row">
                                        <dt class="spec-label">Masa berlaku surat</dt>
                                        <dd class="spec-value tabular">{{ $jenis->validity_months }} bulan</dd>
                                    </div>
                                @endif
                                @if ($jenis->requires_field_check)
                                    <div class="spec-row">
                                        <dt class="spec-label">Pemeriksaan lapangan</dt>
                                        <dd class="spec-value">Diperlukan</dd>
                                    </div>
                                @endif
                                <div class="spec-row">
                                    <dt class="spec-label">Jumlah tahap pemeriksaan</dt>
                                    <dd class="spec-value tabular">{{ $jenis->stepCount() }} tahap</dd>
                                </div>
                                <div class="spec-row">
                                    <dt class="spec-label">Kode layanan</dt>
                                    <dd class="spec-value tabular">{{ $jenis->code }}</dd>
                                </div>
                            </dl>

                            {{--
                              Lama penyelesaian dihitung dalam HARI KERJA pada kalender
                              desa, bukan hari kalender UTC — pembedaan yang sama yang
                              ditegakkan App\Support\VillageTime (KEP-010). Warga perlu
                              tahu itu, karena pengajuan Jumat siang tidak selesai Sabtu.
                            --}}
                            <p class="mt-4 border-t border-line pt-3 text-xs leading-relaxed text-content-tertiary">
                                Hari kerja dihitung Senin–Jumat; akhir pekan tidak ikut dihitung.
                                Hari libur nasional belum diperhitungkan sistem, sehingga tanggal
                                selesai dapat bergeser pada pekan yang memuat hari libur.
                            </p>
                        </div>
                    </section>

                    {{--
                      Pintu pengaduan di halaman standar pelayanan, bukan hanya di
                      halaman kontak: standar ini adalah janji, dan satu-satunya
                      gunanya bagi warga adalah bila ada tempat melapor ketika
                      janjinya tidak dipenuhi (BR-23, FR-PGD-01).
                    --}}
                    <section class="panel" aria-labelledby="judul-bantuan">
                        <div class="panel-body">
                            <h2 id="judul-bantuan" class="font-semibold">Layanan tidak sesuai janji?</h2>
                            <p class="mt-1.5 text-sm leading-relaxed text-content-secondary">
                                Pengurusan melewati {{ $jenis->sla_days }} hari kerja, atau ada
                                permintaan biaya di luar daftar ini?
                            </p>
                            <a href="{{ route('pengaduan.buat') }}" class="link-arrow mt-3 text-sm">
                                Sampaikan pengaduan
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>
                                </svg>
                            </a>
                        </div>
                    </section>
                </div>
            </aside>
        </div>
    </div>
</x-portal.layout>
