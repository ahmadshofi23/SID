<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight, UserPlus } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import AppButton from '@/Components/AppButton.vue'
import TextField from '@/Components/TextField.vue'

/**
 * Terima tawaran mutasi kelahiran dari SKL — docs/13 §5.4, KEP-015.
 *
 * MENGAPA ADA LAYAR INI, sementara tawaran kematian cukup satu tombol: bayi
 * baru lahir belum punya NIK, dan NIK itulah yang justru sedang diurus dengan
 * surat ini. Satu kolom harus diisi petugas, dan karena ia menyangkut
 * identitas yang unik seumur hidup (RULE-01), ia tidak pantas diselipkan ke
 * dalam panel di samping tombol penerbitan surat.
 *
 * Sisa datanya DITAMPILKAN tetapi tidak dapat disunting di sini: nilainya
 * sudah tercetak di surat yang terbit. Membiarkannya disunting akan
 * menghasilkan data penduduk yang berbeda dari surat atas peristiwa yang
 * sama — dan surat itu yang dibawa warga ke Dukcapil.
 */
const props = defineProps<{
    request: {
        id: string
        ticket_number: string
        letter_number: string | null
        applicant_name: string
        family_kk_masked: string | null
        family_address: string | null
    }
    prefill: {
        full_name: string
        gender_label: string
        birth_place: string
        birth_date: string
        father_name: string
        mother_name: string
        birth_weight: string
        birth_length: string
        birth_helper: string
    }
}>()

/*
 * Nama anak IKUT dapat disunting, berbeda dari kolom lain.
 *
 * Alasannya praktis: nama pada SKL sering ditulis singkat atau dengan ejaan
 * sementara ("Bayi Ny. Siti"), lalu nama resminya baru ditetapkan saat akta
 * diurus. Yang masuk data penduduk harus nama resmi itu.
 */
const form = useForm({
    nik: '',
    full_name: props.prefill.full_name,
})

function submit(): void {
    form.post(`/surat/${props.request.id}/tawaran-mutasi`)
}
</script>

<template>
    <Head title="Tambahkan anak ke KK" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/surat" class="hover:text-content-secondary hover:underline">
                Persuratan
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <Link :href="`/surat/${request.id}`" class="hover:text-content-secondary hover:underline">
                {{ request.ticket_number }}
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">Tambahkan anak ke KK</span>
        </nav>

        <h1 class="text-2xl font-semibold">Tambahkan anak ke kartu keluarga</h1>
        <p class="mt-1 text-sm text-content-secondary">
            Dari Surat Keterangan Kelahiran
            <span v-if="request.letter_number" class="tabular">{{ request.letter_number }}</span>
            <span v-else class="tabular">{{ request.ticket_number }}</span>
        </p>

        <form class="mt-5 max-w-2xl space-y-5" @submit.prevent="submit">
            <!-- Kartu keluarga tujuan: ditampilkan, tidak dipilih.
                 Anak masuk ke KK PEMOHON; memberi pilihan di sini akan
                 membuka kemungkinan bayi masuk ke keluarga yang salah. -->
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Kartu keluarga tujuan</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-content-tertiary">Pemohon</dt>
                        <dd class="font-medium">{{ request.applicant_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Nomor KK</dt>
                        <dd class="tabular">{{ request.family_kk_masked ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-content-tertiary">Alamat</dt>
                        <dd>{{ request.family_address ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Identitas anak</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.full_name"
                        label="Nama lengkap anak"
                        required
                        :error="form.errors.full_name"
                        hint="Terisi dari surat. Perbaiki bila nama resminya berbeda."
                    />
                    <TextField
                        v-model="form.nik"
                        label="NIK anak"
                        required
                        inputmode="numeric"
                        :error="form.errors.nik"
                        hint="16 angka dari Dukcapil."
                    />
                </div>

                <AppAlert type="info" class="mt-4">
                    Bila NIK bayi belum diterbitkan Dukcapil, jangan mengisinya dengan
                    angka sementara. Kembali ke pengajuan, tolak tawaran ini, dan catat
                    kelahirannya lewat menu Kependudukan setelah akta keluar — NIK
                    melekat pada warga seumur hidup (RULE-01).
                </AppAlert>
            </fieldset>

            <!-- Disalin dari surat, tidak dapat disunting di sini -->
            <section class="card p-5">
                <h2 class="text-sm font-semibold">Disalin dari surat</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-content-tertiary">Jenis kelamin</dt>
                        <dd>{{ prefill.gender_label || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Tempat &amp; tanggal lahir</dt>
                        <dd>{{ prefill.birth_place || '—' }}, {{ prefill.birth_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Nama ayah</dt>
                        <dd>{{ prefill.father_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Nama ibu</dt>
                        <dd>{{ prefill.mother_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Berat &amp; panjang badan</dt>
                        <dd>
                            {{ prefill.birth_weight || '—' }} kg ·
                            {{ prefill.birth_length || '—' }} cm
                        </dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Penolong kelahiran</dt>
                        <dd>{{ prefill.birth_helper || '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-content-tertiary">
                    Hubungan keluarga akan tercatat sebagai <strong>anak</strong>, dan
                    wilayahnya mengikuti pemohon. Perbaikan selanjutnya dilakukan dari
                    data penduduk.
                </p>
            </section>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    <UserPlus class="size-4" aria-hidden="true" />
                    Simpan &amp; catat kelahiran
                </AppButton>
                <AppButton variant="secondary" :href="`/surat/${request.id}`">
                    Batal
                </AppButton>
            </div>
        </form>
    </AppLayout>
</template>
