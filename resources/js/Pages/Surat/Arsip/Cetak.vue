<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ArrowLeft, Printer, CircleX, QrCode, FileDown, RotateCw, TriangleAlert, Clock } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import TextareaField from '@/Components/TextareaField.vue'
import { usePageData } from '@/composables/usePage'
import type { IssuedLetter } from '@/types'

interface PrintableLetter extends IssuedLetter {
    title_on_letter: string
    rendered_html: string
    qr_svg: string
}

const props = defineProps<{
    letter: PrintableLetter
    village: {
        name: string
        district: string
        regency: string
        province: string
        address: string | null
        postal_code: string | null
        phone: string | null
        email: string | null
        website: string | null
        logo_path: string | null
    }
}>()

const { can } = usePageData()

const showCancel = ref(false)
const cancelForm = useForm({ reason: '' })

function cancelLetter(): void {
    cancelForm.post(`/surat/arsip/${props.letter.id}/batalkan`, {
        preserveScroll: true,
        onSuccess: () => { showCancel.value = false; cancelForm.reset() },
    })
}

/*
 * QR datang dari server sebagai SVG (App\Services\Letter\LetterQrCode),
 * tidak lagi dari layanan pembuat QR pihak ketiga seperti pada Sprint 4.
 * Layanan luar menerima kode verifikasi setiap surat desa, dan suratnya
 * berhenti dapat dicetak ketika layanan itu mati. Gambar yang sama ditanam
 * ke dalam PDF, sehingga apa yang dilihat di layar = apa yang tercetak.
 */

function printLetter(): void {
    window.print()
}

const groupedCode = (props.letter.verification_code.match(/.{1,4}/g) ?? []).join(' ')

/* ---------------------------- Berkas PDF (Sprint 5) --------------------- */

const pdfForm = useForm({})

function retryPdf(): void {
    pdfForm.post(`/surat/arsip/${props.letter.id}/pdf/ulangi`, { preserveScroll: true })
}

function issuedLong(iso: string): string {
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'long' }).format(new Date(iso))
}
</script>

<template>
    <Head :title="`Surat ${letter.letter_number}`" />

    <AppLayout>
        <!-- Bilah tindakan: tidak ikut tercetak -->
        <div class="print:hidden">
            <Link
                href="/surat/arsip"
                class="mb-4 inline-flex items-center gap-1.5 text-sm text-content-secondary hover:text-content"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Kembali ke arsip
            </Link>

            <AppAlert v-if="letter.status === 'dibatalkan'" type="danger" class="mb-5">
                <p class="font-medium">Surat ini sudah DIBATALKAN</p>
                <p class="mt-1">{{ letter.cancel_reason }}</p>
                <p class="mt-1 text-xs">
                    Nomornya tidak dipakai ulang. Terbitkan surat pengganti bila diperlukan.
                </p>
            </AppAlert>

            <div class="mb-5 flex flex-wrap items-center gap-2">
                <AppButton variant="primary" @click="printLetter">
                    <Printer class="size-4" aria-hidden="true" />
                    Cetak
                </AppButton>

                <AppButton
                    v-if="can('surat.batalkan') && letter.status === 'sah'"
                    variant="danger"
                    @click="showCancel = !showCancel"
                >
                    <CircleX class="size-4" aria-hidden="true" />
                    Batalkan surat
                </AppButton>

                <!-- Berkas PDF: hanya muncul bila berkasnya memang ada.
                     Tombol unduh yang selalu menghasilkan galat lebih buruk
                     daripada tidak ada tombol sama sekali. -->
                <AppButton
                    v-if="letter.pdf_ready"
                    :href="`/surat/arsip/${letter.id}/pdf`"
                    external
                >
                    <FileDown class="size-4" aria-hidden="true" />
                    Unduh PDF
                </AppButton>

                <span
                    v-else-if="letter.pdf_status === 'menunggu' || letter.pdf_status === 'diproses'"
                    class="inline-flex items-center gap-1.5 text-sm text-content-secondary"
                >
                    <Clock class="size-4" aria-hidden="true" />
                    PDF sedang dirender — muat ulang halaman sebentar lagi.
                </span>

                <p class="ml-auto text-sm text-content-tertiary">
                    Sudah dicetak {{ letter.print_count }}&times;
                </p>
            </div>

            <!-- Kegagalan render TIDAK disembunyikan: tanpa pesan ini, surat
                 tampak "sedang diproses" selamanya dan petugas menunggu
                 sesuatu yang tidak akan datang (KEP-013). -->
            <AppAlert v-if="letter.pdf_status === 'gagal'" type="warning" class="mb-5">
                <p class="flex items-center gap-1.5 font-medium">
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    Berkas PDF surat ini gagal dibuat
                </p>
                <p class="mt-1">{{ letter.pdf_failed_reason }}</p>
                <p class="mt-1 text-xs">
                    Surat tetap sah dan tetap dapat dicetak dari halaman ini.
                    Yang belum ada hanyalah berkas PDF-nya.
                </p>
                <AppButton class="mt-3" :loading="pdfForm.processing" @click="retryPdf">
                    <RotateCw class="size-4" aria-hidden="true" />
                    Ulangi pembuatan PDF
                </AppButton>
            </AppAlert>

            <section v-if="showCancel" class="card mb-5 p-4" aria-label="Pembatalan surat">
                <h2 class="mb-2 font-semibold">Batalkan surat {{ letter.letter_number }}</h2>
                <AppAlert type="warning" class="mb-3">
                    Pembatalan tidak dapat dibatalkan. Surat tetap tersimpan di arsip
                    dengan status DIBATALKAN, dan hasil pemeriksaan QR akan menunjukkannya.
                </AppAlert>
                <TextareaField
                    v-model="cancelForm.reason"
                    label="Alasan pembatalan"
                    required
                    :maxlength="1000"
                    hint="Minimal 10 karakter. Alasan ini tersimpan permanen."
                    :error="cancelForm.errors.reason"
                />
                <AppButton
                    variant="danger"
                    class="mt-3"
                    :loading="cancelForm.processing"
                    @click="cancelLetter"
                >
                    Batalkan sekarang
                </AppButton>
            </section>
        </div>

        <!-- ================= Lembar surat =================
             Ukuran dan jarak memakai milimeter, bukan piksel: ini dokumen
             kertas F4/A4, dan `px` tidak punya arti tetap di printer. -->
        <article class="letter-sheet mx-auto bg-white text-black shadow-sm print:shadow-none">
            <header class="letter-head">
                <div class="flex items-center gap-4">
                    <img
                        v-if="village.logo_path"
                        :src="`/storage/${village.logo_path}`"
                        alt=""
                        class="h-20 w-20 object-contain"
                    >
                    <div class="flex-1 text-center leading-tight">
                        <p class="text-sm uppercase">Pemerintah Kabupaten {{ village.regency }}</p>
                        <p class="text-sm uppercase">Kecamatan {{ village.district }}</p>
                        <p class="text-xl font-bold uppercase">Desa {{ village.name }}</p>
                        <p v-if="village.address" class="text-xs">
                            {{ village.address }}
                            <span v-if="village.postal_code">— {{ village.postal_code }}</span>
                        </p>
                        <p v-if="village.phone || village.email" class="text-xs">
                            <span v-if="village.phone">Telp. {{ village.phone }}</span>
                            <span v-if="village.phone && village.email"> · </span>
                            <span v-if="village.email">{{ village.email }}</span>
                        </p>
                    </div>
                </div>
                <hr class="mt-2 border-t-2 border-black">
            </header>

            <!-- Tanda air pembatalan: surat yang dibatalkan tetap dapat dicetak
                 untuk keperluan arsip, tetapi TIDAK BOLEH tampak sah. -->
            <p v-if="letter.status === 'dibatalkan'" class="letter-void">DIBATALKAN</p>

            <div class="letter-body">
                <div class="mb-6 text-center">
                    <h1 class="text-base font-bold uppercase underline">
                        {{ letter.title_on_letter }}
                    </h1>
                    <p class="mt-1 text-sm">Nomor: {{ letter.letter_number }}</p>
                </div>

                <!--
                  BADAN SURAT APA ADANYA dari saat penerbitan (ADR-003).
                  Pembuka, blok identitas, isi pokok, dan penutup semuanya
                  disusun server oleh LetterBodyComposer dan disimpan di
                  letters.rendered_html.

                  Halaman ini SENGAJA tidak merakit ulang bagian mana pun:
                  menyunting halaman ini tidak boleh mengubah tampilan surat
                  yang sudah terbit dan sudah dipegang warga.

                  Aman untuk v-html: isinya dibersihkan App\Support\HtmlSanitizer
                  saat dirender, dan nilai variabelnya di-escape.
                -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="letter-content text-justify" v-html="letter.rendered_html" />
            </div>

            <footer class="letter-foot">
                <div class="flex items-end justify-between gap-6">
                    <!-- QR verifikasi: pihak penerima dapat memeriksa keasliannya
                         tanpa menelepon kantor desa (FR-SRT-14). -->
                    <div class="text-center text-xs">
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div class="qr mx-auto size-24" v-html="letter.qr_svg" />
                        <p class="mt-1 flex items-center justify-center gap-1">
                            <QrCode class="size-3" aria-hidden="true" />
                            Periksa keaslian
                        </p>
                        <!-- Dikelompokkan empat-empat, sama seperti di PDF:
                             kode ini diketik ulang dari kertas ketika
                             pemindai QR penerima tidak berfungsi. -->
                        <p class="font-mono text-[10px] leading-snug">
                            {{ groupedCode }}
                        </p>
                    </div>

                    <div class="text-center">
                        <p>{{ village.name }}, {{ issuedLong(letter.issued_at) }}</p>
                        <p>{{ letter.signer_position }} {{ village.name }}</p>
                        <div class="h-20" aria-hidden="true"></div>
                        <p class="font-bold underline">{{ letter.signer_name }}</p>
                    </div>
                </div>


            </footer>
        </article>
    </AppLayout>
</template>

<script lang="ts">
export default { name: 'CetakSurat' }
</script>

<style scoped>
.qr :deep(svg) { width: 100%; height: 100%; display: block; }

/*
 * Ukuran kertas dan margin dalam MILIMETER.
 *
 * Kantor desa di Indonesia umumnya memakai F4 (215 × 330 mm), bukan A4.
 * Memakai A4 membuat baris tanda tangan jatuh ke halaman kedua pada
 * sebagian surat — cacat yang hanya terlihat setelah dicetak.
 */
.letter-sheet {
    width: 215mm;
    min-height: 330mm;
    padding: 15mm 20mm;
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.5;
    position: relative;
}

.letter-void {
    position: absolute;
    inset: 40% 0 auto 0;
    text-align: center;
    font-size: 72pt;
    font-weight: bold;
    color: rgb(220 38 38 / 0.18);
    transform: rotate(-24deg);
    pointer-events: none;
    letter-spacing: 0.1em;
}

.letter-body { margin-top: 8mm; }
.letter-foot { margin-top: 12mm; }

.letter-content :deep(p) { margin-bottom: 0.75rem; }
.letter-content :deep(table) { margin: 0.5rem 0 0.75rem 1.5rem; }
.letter-content :deep(td) { padding: 0.1rem 0.4rem 0.1rem 0; vertical-align: top; }
.letter-content :deep(pre) {
    font-family: inherit;
    white-space: pre-wrap;
    margin: 0.5rem 0 0.75rem 1.5rem;
}

/* Di layar sempit, lembar dikecilkan agar tetap terbaca tanpa gulir
   mendatar — bukan dipotong (WCAG horizontal-scroll). */
@media (max-width: 230mm) {
    .letter-sheet {
        width: 100%;
        min-height: auto;
        padding: 6mm;
        font-size: 11pt;
    }
}

@media print {
    .letter-sheet {
        width: auto;
        min-height: auto;
        padding: 0;
        box-shadow: none;
    }
}
</style>
