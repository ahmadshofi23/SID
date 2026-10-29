<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import { CircleCheck, Copy, Check, Printer } from '@lucide/vue'
import AppAlert from '@/Components/AppAlert.vue'
import AppButton from '@/Components/AppButton.vue'
import { usePageData } from '@/composables/usePage'
import type { ComplaintReceipt } from '@/types'

const props = defineProps<{ tanda_terima: ComplaintReceipt }>()

const { village } = usePageData()
const tersalin = ref(false)

/*
 * Tombol salin ada karena kodenya 16 karakter acak.
 *
 * Kode sepanjang itu akan salah ketik bila disalin dengan tangan, dan salah
 * satu huruf berarti pelapor anonim kehilangan pengaduannya untuk selamanya.
 * Kegagalan clipboard (peramban lama, halaman tanpa HTTPS di jaringan desa)
 * ditangani diam-diam: kodenya tetap terbaca di layar dan dapat diblok manual,
 * jadi tidak ada gunanya menampilkan galat yang menakut-nakuti.
 */
/*
 * Cetak ditawarkan karena sebagian warga desa memang mencatat di kertas, dan
 * kertas tidak hilang bersama riwayat peramban di ponsel yang dipakai
 * bergantian — keadaan yang sama yang melahirkan keputusan PWA Sprint 6
 * untuk tidak menyimpan satu pun data di perangkat.
 */
function cetak(): void {
    window.print()
}

async function salin(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.tanda_terima.tracking_code)
        tersalin.value = true
        window.setTimeout(() => { tersalin.value = false }, 2500)
    } catch {
        tersalin.value = false
    }
}
</script>

<template>
    <Head title="Pengaduan Terkirim" />

    <div class="min-h-dvh bg-background px-4 py-10 text-content">
        <main class="mx-auto max-w-lg">
            <header class="mb-6 text-center">
                <span class="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-success-soft">
                    <CircleCheck class="size-6 text-success" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Pengaduan Anda sudah tercatat</h1>
                <p class="mt-1 text-sm text-content-secondary">
                    Terima kasih. Desa {{ village.name }} akan menindaklanjutinya.
                </p>
            </header>

            <section class="card p-5" aria-label="Tanda terima pengaduan">
                <p class="text-sm text-content-secondary">{{ tanda_terima.title }}</p>

                <dl class="mt-4 space-y-3 border-t border-line pt-4">
                    <div>
                        <dt class="text-xs text-content-tertiary">Nomor tiket</dt>
                        <dd class="mt-0.5 font-mono text-sm tabular">
                            {{ tanda_terima.ticket_number }}
                        </dd>
                    </div>

                    <!--
                      Kode pelacakan ditampilkan BESAR dan monospasi: ia akan
                      ditulis ulang di kertas atau difoto, dan satu karakter
                      yang salah membuat pengaduan tidak dapat dibuka lagi.
                      Abjadnya sendiri sudah membuang huruf dan angka yang
                      tertukar — lihat ComplaintService::TRACKING_ALPHABET.
                    -->
                    <div>
                        <dt class="text-xs text-content-tertiary">Kode pelacakan</dt>
                        <dd class="mt-1 flex flex-wrap items-center gap-3">
                            <code class="rounded-lg bg-surface-sunken px-3 py-2 font-mono text-lg
                                         font-semibold tracking-widest tabular">
                                {{ tanda_terima.tracking_code }}
                            </code>
                            <AppButton variant="secondary" size="sm" @click="salin">
                                <component
                                    :is="tersalin ? Check : Copy"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ tersalin ? 'Tersalin' : 'Salin kode' }}
                            </AppButton>
                        </dd>
                    </div>

                    <div v-if="tanda_terima.triage_due_at">
                        <dt class="text-xs text-content-tertiary">Diperiksa petugas paling lambat</dt>
                        <dd class="mt-0.5 text-sm">{{ tanda_terima.triage_due_at }}</dd>
                    </div>
                </dl>
            </section>

            <!--
              Peringatan berbeda menurut cara melapornya, karena akibatnya
              memang berbeda: pelapor bernama masih dapat ditolong petugas,
              pelapor anonim tidak dapat ditolong siapa pun.
            -->
            <AppAlert v-if="tanda_terima.is_anonymous" type="warning" class="mt-5">
                <p class="font-medium">Simpan kode ini sekarang</p>
                <p class="mt-1">
                    Pengaduan ini anonim, sehingga tidak ada identitas Anda yang
                    tersimpan. Artinya <strong>tidak seorang pun — termasuk petugas
                    desa — dapat mengirimkan kode ini kembali kepada Anda.</strong>
                    Catat atau foto sebelum menutup halaman ini.
                </p>
            </AppAlert>

            <AppAlert v-else type="info" class="mt-5">
                <p class="font-medium">Simpan kode ini</p>
                <p class="mt-1">
                    Kode ini membuka halaman pelacakan tanpa perlu masuk.
                    Bila Anda punya akun warga, pengaduan ini juga muncul di
                    <Link href="/warga/pengaduan" class="text-accent hover:underline">Pengaduan saya</Link>.
                </p>
            </AppAlert>

            <div class="mt-5 flex flex-wrap gap-3">
                <AppButton variant="secondary" @click="cetak">
                    <Printer class="size-4" aria-hidden="true" />
                    Cetak tanda terima
                </AppButton>
                <AppButton variant="secondary" href="/pengaduan/lacak">
                    Lacak pengaduan
                </AppButton>
            </div>

            <footer class="mt-6 text-center text-xs text-content-tertiary">
                <Link href="/" class="hover:text-content-secondary">
                    Beranda Desa {{ village.name }}
                </Link>
            </footer>
        </main>
    </div>
</template>
