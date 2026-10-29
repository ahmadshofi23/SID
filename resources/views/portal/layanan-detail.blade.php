{{-- Detail satu layanan — FR-PRT-04, BR-23. --}}
<x-portal.layout title="{{ $jenis->name }}"
                 :description="'Syarat, biaya, dan lama penyelesaian '.$jenis->name.' di Desa '.$village->name.'.'"
                 heading="{{ $jenis->name }}"
                 :lead="filled($jenis->description) ? $jenis->description : null">

    <div class="mx-auto max-w-5xl px-4 py-10">
        <p class="mb-6 text-sm">
            <a href="{{ route('portal.layanan') }}" class="font-medium text-accent hover:underline">
                &larr; Semua layanan
            </a>
        </p>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">

                {{-- ------------------------ Syarat -------------------------- --}}
                <section aria-labelledby="judul-syarat">
                    <h2 id="judul-syarat" class="text-xl font-semibold">Syarat yang harus disiapkan</h2>

                    @if ($jenis->requirements === [])
                        <p class="mt-3 text-content-secondary">
                            Tidak ada dokumen syarat yang ditetapkan untuk surat ini.
                        </p>
                    @else
                        <ul class="mt-3 space-y-2">
                            @foreach ($jenis->requirements as $syarat)
                                <li class="card flex items-start gap-3 p-4">
                                    <svg class="mt-0.5 size-5 shrink-0 {{ ($syarat['required'] ?? true) ? 'text-accent' : 'text-content-tertiary' }}"
                                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true">
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/>
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4"/>
                                    </svg>
                                    <span class="min-w-0 flex-1 text-sm">
                                        {{ $syarat['label'] ?? 'Dokumen' }}
                                        @if (! ($syarat['required'] ?? true))
                                            <span class="ml-1 text-xs text-content-tertiary">(bila ada)</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
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
                <section aria-labelledby="judul-cara">
                    <h2 id="judul-cara" class="text-xl font-semibold">Cara mengajukan</h2>

                    {{--
                      DUA JALUR, dan keduanya tetap terbuka. Pengajuan daring
                      tidak menggantikan loket: sebagian warga tidak punya akun,
                      tidak punya ponsel yang memadai, atau memang lebih percaya
                      datang langsung (docs/03 §2.2).
                    --}}
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div class="card p-4">
                            <p class="font-medium">Dari rumah</p>
                            <p class="mt-1 text-sm text-content-secondary">
                                Isi formulir dan unggah foto syaratnya. Surat diambil di
                                kantor desa setelah disetujui.
                            </p>
                            <a href="{{ route('warga.ajukan', ['jenis' => $jenis->code]) }}"
                               class="touch-target mt-3 inline-flex items-center justify-center rounded-lg bg-accent px-5 font-medium text-content-inverse transition-colors hover:bg-accent-hover">
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

                        <div class="card p-4">
                            <p class="font-medium">Di kantor desa</p>
                            <p class="mt-1 text-sm text-content-secondary">
                                Tanpa akun. Bawa dokumen syarat, petugas yang mencatat
                                pengajuan Anda dan memberi nomor tiket.
                            </p>
                            <a href="{{ route('portal.kontak') }}"
                               class="touch-target mt-3 inline-flex items-center justify-center rounded-lg border border-line-strong bg-surface px-5 font-medium transition-colors hover:bg-surface-hover">
                                Jam layanan
                            </a>
                        </div>
                    </div>

                    <ol class="mt-5 space-y-3">
                        @foreach ([
                            'Siapkan seluruh dokumen syarat di atas, asli dan fotokopinya.',
                            'Ajukan daring dari halaman ini, atau datang ke kantor desa pada jam layanan.',
                            'Anda menerima NOMOR TIKET begitu pengajuan tercatat.',
                            'Pantau statusnya lewat halaman Lacak Status Pengajuan — atau dari daftar pengajuan di akun warga Anda.',
                            'Surat diambil di kantor desa setelah disetujui.',
                        ] as $nomor => $langkah)
                            <li class="flex gap-3">
                                <span class="tabular flex size-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-semibold text-accent-text"
                                      aria-hidden="true">{{ $nomor + 1 }}</span>
                                <span class="pt-0.5 text-content-secondary">{{ $langkah }}</span>
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-5 text-sm">
                        <a href="{{ route('surat.lacak') }}" class="font-medium text-accent hover:underline">
                            Lacak pengajuan dengan nomor tiket &rarr;
                        </a>
                    </p>
                </section>
            </div>

            {{-- -------------------------- Ringkasan ------------------------- --}}
            <aside>
                <section class="card p-5" aria-labelledby="judul-ringkas">
                    <h2 id="judul-ringkas" class="font-semibold">Ringkasan layanan</h2>
                    <dl class="mt-3 space-y-3 text-sm">
                        <div>
                            <dt class="text-content-tertiary">Lama penyelesaian</dt>
                            <dd class="tabular font-medium">{{ $jenis->sla_days }} hari kerja</dd>
                        </div>
                        <div>
                            <dt class="text-content-tertiary">Biaya</dt>
                            <dd class="font-medium {{ (float) $jenis->fee > 0 ? '' : 'text-success' }}">
                                {{ (float) $jenis->fee > 0 ? 'Rp '.number_format((float) $jenis->fee, 0, ',', '.') : 'Gratis' }}
                            </dd>
                        </div>
                        @if ($jenis->validity_months)
                            <div>
                                <dt class="text-content-tertiary">Masa berlaku surat</dt>
                                <dd class="tabular font-medium">{{ $jenis->validity_months }} bulan</dd>
                            </div>
                        @endif
                        @if ($jenis->requires_field_check)
                            <div>
                                <dt class="text-content-tertiary">Pemeriksaan lapangan</dt>
                                <dd class="font-medium">Diperlukan</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-content-tertiary">Jumlah tahap pemeriksaan</dt>
                            <dd class="tabular font-medium">{{ $jenis->stepCount() }} tahap</dd>
                        </div>
                    </dl>

                    {{--
                      Lama penyelesaian dihitung dalam HARI KERJA pada kalender
                      desa, bukan hari kalender UTC — pembedaan yang sama yang
                      ditegakkan App\Support\VillageTime (KEP-010). Warga perlu
                      tahu itu, karena pengajuan Jumat siang tidak selesai Sabtu.
                    --}}
                    <p class="mt-4 border-t border-line pt-3 text-xs text-content-tertiary">
                        Hari kerja dihitung Senin–Jumat; akhir pekan tidak ikut dihitung.
                        Hari libur nasional belum diperhitungkan sistem, sehingga tanggal
                        selesai dapat bergeser pada pekan yang memuat hari libur.
                    </p>
                </section>
            </aside>
        </div>
    </div>
</x-portal.layout>
