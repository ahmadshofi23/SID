<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Baby, HeartCrack, CircleCheck, CircleX, UserPlus } from '@lucide/vue'
import AppAlert from './AppAlert.vue'
import AppButton from './AppButton.vue'
import TextareaField from './TextareaField.vue'
import type { LetterMutationOffer } from '@/types'

/**
 * Tawaran pencatatan mutasi dari SKL/SKM — docs/13 §5.4 & §5.5, KEP-015.
 *
 * TAWARAN, bukan pemberitahuan bahwa sesuatu sudah terjadi. Panel ini
 * ditempatkan di kolom utama tepat di bawah blok "Surat sudah terbit", bukan
 * di antara tombol-tombol tindakan: keputusannya menyangkut DATA PENDUDUK,
 * bukan alur surat, dan menaruhnya bersebelahan dengan "Setujui & terbitkan"
 * akan membuatnya terbaca sebagai langkah lanjutan yang wajib.
 */
const props = defineProps<{
    requestId: string
    offer: LetterMutationOffer
    /** `mutasi.tambah` — menerima tawaran kematian & menolak tawaran apa pun. */
    canDecide: boolean
    /**
     * `penduduk.tambah` — IZIN TERSENDIRI, karena menerima tawaran kelahiran
     * berarti membuat data penduduk baru.
     *
     * Dua izin, bukan satu: Kasi Pemerintahan memegang `mutasi.tambah` tanpa
     * `penduduk.tambah` (lihat RoleSeeder), sehingga ia dapat mencatat
     * kematian dan menolak tawaran tetapi tidak dapat mendaftarkan bayi.
     * Menampilkan tombolnya kepada dia akan menghasilkan 403 tanpa penjelasan.
     */
    canAddResident: boolean
}>()

const acceptForm = useForm({})
const declineForm = useForm({ reason: '' })

const showDecline = ref(false)

function accept(): void {
    acceptForm.post(`/surat/${props.requestId}/tawaran-mutasi`, { preserveScroll: true })
}

function decline(): void {
    declineForm.post(`/surat/${props.requestId}/tawaran-mutasi/tolak`, {
        preserveScroll: true,
        onSuccess: () => { showDecline.value = false; declineForm.reset() },
    })
}
</script>

<template>
    <section class="card p-4" aria-label="Tawaran pencatatan mutasi">
        <h2 class="mb-1 flex items-center gap-2 font-semibold">
            <Baby v-if="offer.mutation_type === 'lahir'" class="size-4 text-accent" aria-hidden="true" />
            <HeartCrack v-else class="size-4 text-accent" aria-hidden="true" />
            Catat mutasi {{ offer.mutation_label.toLowerCase() }}?
        </h2>

        <!-- ============ Sudah diterima ============ -->
        <template v-if="offer.status === 'diterima'">
            <p class="flex items-start gap-1.5 text-sm text-content-secondary">
                <CircleCheck class="mt-0.5 size-4 shrink-0 text-success" aria-hidden="true" />
                <span>
                    Mutasi {{ offer.mutation_label.toLowerCase() }} sudah dicatat
                    oleh {{ offer.decided_by_name ?? 'petugas' }}
                    <template v-if="offer.decided_at"> pada {{ offer.decided_at }}</template>.
                </span>
            </p>

            <Link
                v-if="offer.resident_id"
                :href="`/kependudukan/penduduk/${offer.resident_id}`"
                class="mt-3 inline-flex items-center gap-1.5 text-sm text-accent hover:underline"
            >
                Lihat data penduduknya
            </Link>
        </template>

        <!-- ============ Sudah ditolak ============ -->
        <template v-else-if="offer.status === 'ditolak'">
            <p class="flex items-start gap-1.5 text-sm text-content-secondary">
                <CircleX class="mt-0.5 size-4 shrink-0 text-content-tertiary" aria-hidden="true" />
                <span>
                    Tawaran ini ditolak oleh {{ offer.decided_by_name ?? 'petugas' }}
                    <template v-if="offer.decided_at"> pada {{ offer.decided_at }}</template>.
                    <template v-if="offer.decline_reason">
                        Alasan: {{ offer.decline_reason }}
                    </template>
                </span>
            </p>
            <p class="mt-2 text-xs text-content-tertiary">
                Bila mutasinya ternyata perlu dicatat, lakukan lewat menu
                Kependudukan › Mutasi.
            </p>
        </template>

        <!-- ============ Masih menunggu keputusan ============ -->
        <template v-else>
            <!-- Surat dibatalkan: tidak lagi menjadi dasar apa pun (RULE-05) -->
            <AppAlert v-if="offer.letter_cancelled" type="warning" class="mt-3">
                Surat ini sudah dibatalkan, sehingga tidak dapat menjadi dasar mutasi.
                Catat mutasinya lewat menu Kependudukan › Mutasi bila memang perlu.
            </AppAlert>

            <template v-else-if="offer.mutation_type === 'mati'">
                <p class="mt-1 text-sm text-content-secondary">
                    Surat kematian sudah terbit, tetapi status kependudukan
                    <strong v-if="offer.target">{{ offer.target.full_name }}</strong>
                    <strong v-else>almarhum/almarhumah</strong>
                    masih <em>aktif</em>. Mencatat mutasinya akan mengubah status
                    menjadi <strong>meninggal</strong>, mengeluarkannya dari statistik
                    penduduk, dan menutup kemungkinan surat lain terbit atas namanya.
                </p>

                <p v-if="offer.target" class="mt-2 text-sm tabular text-content-tertiary">
                    {{ offer.target.nik_masked }}
                </p>

                <!-- Status sudah berubah lewat jalur lain -->
                <AppAlert v-if="offer.target && offer.target.status !== 'aktif'" type="info" class="mt-3">
                    Status {{ offer.target.full_name }} sudah
                    <strong>{{ offer.target.status.replace('_', ' ') }}</strong> —
                    kemungkinan mutasinya sudah dicatat lewat modul kependudukan.
                    Tolak tawaran ini agar tidak muncul lagi.
                </AppAlert>

                <!-- RULE-02: KK tidak boleh tanpa kepala keluarga -->
                <AppAlert v-if="offer.target?.is_head_of_family" type="warning" class="mt-3">
                    {{ offer.target.full_name }} adalah <strong>kepala keluarga</strong>.
                    Setelah mutasi dicatat, kartu keluarganya akan tanpa kepala keluarga —
                    tetapkan penggantinya
                    <Link
                        v-if="offer.target.family_id"
                        :href="`/kependudukan/keluarga/${offer.target.family_id}`"
                        class="text-accent hover:underline"
                    >pada kartu keluarga itu</Link>
                    <template v-else>pada kartu keluarganya</template>
                    agar data administrasi tetap lengkap.
                </AppAlert>

                <!-- Almarhum di luar lingkup pengguna: tanpa tombol, bukan 404 -->
                <AppAlert v-if="offer.target === null" type="info" class="mt-3">
                    Data warga yang meninggal berada di luar wilayah kewenangan Anda,
                    sehingga tawaran ini harus diputuskan petugas yang berwenang
                    atas wilayah tersebut.
                </AppAlert>
            </template>

            <template v-else>
                <p class="mt-1 text-sm text-content-secondary">
                    Surat kelahiran sudah terbit, tetapi anaknya belum ada di data
                    penduduk desa. Menambahkannya sekarang mencegah selisih yang
                    paling sering terjadi: kelahiran tercatat di surat, tidak di
                    basis data.
                </p>

                <AppAlert v-if="!offer.applicant_has_family" type="warning" class="mt-3">
                    Pemohon belum terdaftar pada satu kartu keluarga, sehingga anaknya
                    belum dapat ditambahkan ke KK. Lengkapi data keluarga pemohon
                    terlebih dahulu.
                </AppAlert>

                <p v-else class="mt-2 text-xs text-content-tertiary">
                    Data anak sudah terisi dari formulir surat. Yang masih perlu
                    Anda lengkapi hanyalah NIK bayi — bila belum diterbitkan
                    Dukcapil, tolak tawaran ini dan catat kelahirannya setelah
                    akta keluar.
                </p>
            </template>

            <!-- Tombol: hanya bagi yang berwenang mencatat mutasi -->
            <div v-if="canDecide && !offer.letter_cancelled" class="mt-4 flex flex-wrap gap-2">
                <!-- Tautan, bukan tombol kirim: kelahiran butuh satu layar
                     isian lagi (NIK bayi). AppButton dengan `href` menjadi
                     Link dan mengabaikan `disabled`, jadi ketiadaan KK
                     disaring dengan v-if — bukan tombol mati yang menipu. -->
                <AppButton
                    v-if="offer.mutation_type === 'lahir' && offer.applicant_has_family && canAddResident"
                    variant="primary"
                    :href="`/surat/${requestId}/tawaran-mutasi`"
                >
                    <UserPlus class="size-4" aria-hidden="true" />
                    Tambahkan anak ke KK
                </AppButton>

                <AppButton
                    v-else-if="offer.mutation_type === 'mati'"
                    variant="primary"
                    :disabled="offer.target === null || offer.target.status !== 'aktif'"
                    :loading="acceptForm.processing"
                    @click="accept"
                >
                    <CircleCheck class="size-4" aria-hidden="true" />
                    Catat mutasi kematian
                </AppButton>

                <AppButton @click="showDecline = !showDecline">
                    <CircleX class="size-4" aria-hidden="true" />
                    Tolak tawaran
                </AppButton>
            </div>

            <p v-else-if="!canDecide" class="mt-4 text-xs text-content-tertiary">
                Pencatatan mutasi bukan kewenangan Anda. Teruskan ke petugas
                kependudukan agar tawaran ini tidak terlewat.
            </p>

            <p
                v-if="canDecide && !canAddResident && offer.mutation_type === 'lahir'
                    && !offer.letter_cancelled && offer.applicant_has_family"
                class="mt-3 text-xs text-content-tertiary"
            >
                Menambahkan anak ke kartu keluarga memerlukan izin pendaftaran
                penduduk, yang tidak melekat pada peran Anda. Anda masih dapat
                menolak tawaran ini bila kelahirannya sudah tercatat.
            </p>

            <div v-if="showDecline" class="mt-3">
                <TextareaField
                    v-model="declineForm.reason"
                    label="Alasan menolak (opsional)"
                    :maxlength="500"
                    :error="declineForm.errors.reason"
                    placeholder="Contoh: kelahiran sudah dicatat lewat modul kependudukan."
                />
                <!-- Penolakan permanen: tawaran yang muncul berulang kali
                     akhirnya diabaikan, dan itu menghapus manfaatnya. -->
                <AppAlert type="info" class="mt-2">
                    Tawaran yang ditolak tidak muncul lagi. Penerbitan surat tidak
                    terpengaruh, dan mutasinya tetap dapat dicatat lewat menu
                    Kependudukan › Mutasi.
                </AppAlert>
                <AppButton
                    size="sm"
                    block
                    class="mt-2"
                    :loading="declineForm.processing"
                    @click="decline"
                >
                    Tolak sekarang
                </AppButton>
            </div>
        </template>
    </section>
</template>
