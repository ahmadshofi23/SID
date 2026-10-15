<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ShieldCheck, ShieldX, CircleAlert, Landmark } from '@lucide/vue'
import { usePageData } from '@/composables/usePage'

defineProps<{
    found: boolean
    code: string
    letter?: {
        letter_number: string
        letter_type: string
        resident_name: string
        issued_at: string
        valid_until: string | null
        is_expired: boolean
        status: 'sah' | 'dibatalkan'
        status_label: string
        signer_position: string
    }
}>()

const { village } = usePageData()
</script>

<template>
    <Head title="Verifikasi Keaslian Surat" />

    <!--
      Halaman PUBLIK tanpa AppLayout: pembukanya adalah pihak luar (bank,
      sekolah, kecamatan) yang tidak punya akun. Menampilkan menu panel admin
      di sini hanya akan membingungkan dan memperlihatkan struktur sistem
      tanpa alasan.
    -->
    <div class="min-h-dvh bg-background px-4 py-10 text-content">
        <main class="mx-auto max-w-lg">
            <header class="mb-6 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-accent">
                    <Landmark class="size-6 text-content-inverse" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Verifikasi Keaslian Surat</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Pemerintah Desa {{ village.name }}, Kec. {{ village.district }},
                    Kab. {{ village.regency }}
                </p>
            </header>

            <!-- ============ Surat dibatalkan ============ -->
            <section
                v-if="found && letter && letter.status === 'dibatalkan'"
                class="card border-danger/40 p-5"
                aria-label="Hasil verifikasi"
            >
                <p class="mb-3 flex items-center gap-2 font-semibold text-danger">
                    <ShieldX class="size-5 shrink-0" aria-hidden="true" />
                    Surat ini sudah DIBATALKAN
                </p>
                <p class="text-sm text-content-secondary">
                    Nomor surat ini pernah diterbitkan, tetapi telah dibatalkan oleh
                    Pemerintah Desa {{ village.name }} dan <strong>tidak berlaku</strong>.
                    Hubungi kantor desa untuk keterangan lebih lanjut.
                </p>

                <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Nomor surat</dt>
                        <dd class="text-right font-medium tabular">{{ letter.letter_number }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Jenis</dt>
                        <dd class="text-right">{{ letter.letter_type }}</dd>
                    </div>
                </dl>
            </section>

            <!-- ============ Surat sah ============ -->
            <section
                v-else-if="found && letter"
                class="card p-5"
                :class="letter.is_expired ? 'border-warning/40' : 'border-success/40'"
                aria-label="Hasil verifikasi"
            >
                <p
                    class="mb-3 flex items-center gap-2 font-semibold"
                    :class="letter.is_expired ? 'text-warning' : 'text-success'"
                >
                    <ShieldCheck class="size-5 shrink-0" aria-hidden="true" />
                    {{ letter.status_label }}
                </p>

                <p class="text-sm text-content-secondary">
                    Surat dengan nomor di bawah ini benar diterbitkan oleh Pemerintah
                    Desa {{ village.name }}.
                </p>

                <dl class="mt-4 space-y-2 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Nomor surat</dt>
                        <dd class="text-right font-medium tabular">{{ letter.letter_number }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Jenis surat</dt>
                        <dd class="text-right">{{ letter.letter_type }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Atas nama</dt>
                        <dd class="text-right">{{ letter.resident_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Tanggal terbit</dt>
                        <dd class="text-right">{{ letter.issued_at }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Berlaku sampai</dt>
                        <dd
                            class="text-right"
                            :class="letter.is_expired ? 'font-medium text-warning' : ''"
                        >
                            {{ letter.valid_until ?? 'Tanpa batas waktu' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-content-tertiary">Ditandatangani</dt>
                        <dd class="text-right">{{ letter.signer_position }}</dd>
                    </div>
                </dl>

                <!--
                  Nama ditampilkan tersamar. Siapa pun yang memegang selembar
                  surat dapat membuka halaman ini, jadi ia harus cukup untuk
                  MENCOCOKKAN dengan surat di tangan — tanpa menjadi sumber
                  data warga bagi orang yang hanya menebak kodenya.
                -->
                <p class="mt-4 text-xs text-content-tertiary">
                    Nama ditampilkan sebagian untuk melindungi data pribadi warga.
                    Cocokkan dengan nama pada surat yang Anda pegang. Halaman ini
                    tidak menampilkan NIK, alamat, maupun isi surat.
                </p>
            </section>

            <!-- ============ Tidak ditemukan ============ -->
            <section v-else class="card border-warning/40 p-5" aria-label="Hasil verifikasi">
                <p class="mb-3 flex items-center gap-2 font-semibold text-warning">
                    <CircleAlert class="size-5 shrink-0" aria-hidden="true" />
                    Surat tidak ditemukan
                </p>
                <p class="text-sm text-content-secondary">
                    Tidak ada surat dengan kode verifikasi
                    <span class="font-mono text-xs tracking-wider">{{ code }}</span>.
                </p>
                <ul class="mt-3 space-y-1.5 text-sm text-content-secondary">
                    <li>· Periksa kembali kode pada surat, termasuk huruf besar-kecilnya.</li>
                    <li>· Pindai ulang kode QR bila ada.</li>
                    <li>
                        · Bila kode sudah benar namun tetap tidak ditemukan, surat
                        tersebut <strong>tidak diterbitkan</strong> melalui sistem
                        Desa {{ village.name }}. Hubungi kantor desa.
                    </li>
                </ul>
            </section>

            <footer class="mt-6 text-center text-xs text-content-tertiary">
                <Link href="/" class="hover:text-content-secondary">
                    Beranda Desa {{ village.name }}
                </Link>
            </footer>
        </main>
    </div>
</template>
