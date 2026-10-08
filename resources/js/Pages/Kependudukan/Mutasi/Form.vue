<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'

const props = defineProps<{
    resident: {
        id: string
        full_name: string
        nik_masked: string
        status: string
        birth_date: string
        is_head: boolean
    }
}>()

const form = useForm({
    type: '',
    mutation_date: new Date().toISOString().slice(0, 10),
    reason: '',
    document_no: '',
    reported_by: '',
    death_cause: '',
    death_place: '',
    destination_address: '',
    origin_address: '',
    birth_weight: '',
    birth_length: '',
    birth_helper: '',
})

/*
 * Pilihan mutasi menyesuaikan status penduduk.
 *
 * Penduduk yang sudah meninggal tidak dapat dicatat meninggal lagi, dan yang
 * sudah pindah keluar hanya masuk akal untuk dicatat pindah datang. Menyaring
 * di sini mencegah petugas memilih sesuatu yang pasti ditolak server.
 */
const availableTypes = computed<Record<string, string>>(() => {
    if (props.resident.status === 'aktif') {
        return <Record<string, string>>{
            mati: 'Kematian',
            pindah_keluar: 'Pindah Keluar',
            lahir: 'Kelahiran (catat untuk bayi yang sudah didaftarkan)',
        }
    }

    if (props.resident.status === 'pindah_keluar') {
        return <Record<string, string>>{ pindah_datang: 'Pindah Datang (kembali ke desa)' }
    }

    return <Record<string, string>>{}
})
</script>

<template>
    <Head title="Catat Mutasi" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/penduduk" class="hover:text-content-secondary hover:underline">
                Data Penduduk
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <Link :href="`/kependudukan/penduduk/${resident.id}`"
                  class="hover:text-content-secondary hover:underline">
                {{ resident.full_name }}
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">Catat Mutasi</span>
        </nav>

        <h1 class="text-2xl font-semibold">Catat mutasi</h1>
        <p class="mt-1 text-sm text-content-secondary">
            {{ resident.full_name }} · {{ resident.nik_masked }}
        </p>

        <AppAlert v-if="Object.keys(availableTypes).length === 0" type="warning"
                  title="Tidak ada mutasi yang dapat dicatat" class="mt-5">
            Penduduk ini berstatus <strong>{{ resident.status.replace('_', ' ') }}</strong>,
            sehingga tidak ada jenis mutasi yang berlaku untuknya.
        </AppAlert>

        <form v-else class="mt-5 max-w-2xl space-y-5"
              @submit.prevent="form.post(`/kependudukan/penduduk/${resident.id}/mutasi`)">
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Jenis &amp; tanggal</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField v-model="form.type" label="Jenis mutasi" required
                                 :options="availableTypes" :error="form.errors.type" />
                    <TextField v-model="form.mutation_date" label="Tanggal kejadian" type="date"
                               required :error="form.errors.mutation_date" />
                </div>
            </fieldset>

            <!-- Kolom tambahan muncul sesuai jenis — pengungkapan bertahap,
                 agar formulir tidak menakutkan sejak awal (WCAG progressive-disclosure). -->
            <fieldset v-if="form.type === 'mati'" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Keterangan kematian</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField v-model="form.death_cause" label="Sebab kematian"
                                 :options="{
                                     Sakit: 'Sakit', Kecelakaan: 'Kecelakaan',
                                     'Usia lanjut': 'Usia lanjut', Lainnya: 'Lainnya',
                                 }" :error="form.errors.death_cause" />
                    <TextField v-model="form.death_place" label="Tempat meninggal"
                               :error="form.errors.death_place" />
                </div>

                <AppAlert v-if="resident.is_head" type="warning" class="mt-4">
                    Orang ini adalah <strong>kepala keluarga</strong>. Setelah mutasi
                    dicatat, kartu keluarganya akan tanpa kepala keluarga — tetapkan
                    penggantinya agar data administrasi tetap lengkap.
                </AppAlert>
            </fieldset>

            <fieldset v-else-if="form.type === 'pindah_keluar'" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Keterangan kepindahan</legend>
                <div class="mt-3">
                    <TextField v-model="form.destination_address" label="Alamat tujuan"
                               :error="form.errors.destination_address" />
                </div>
            </fieldset>

            <fieldset v-else-if="form.type === 'pindah_datang'" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Keterangan kedatangan</legend>
                <div class="mt-3">
                    <TextField v-model="form.origin_address" label="Alamat asal"
                               :error="form.errors.origin_address" />
                </div>
            </fieldset>

            <fieldset v-else-if="form.type === 'lahir'" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Keterangan kelahiran</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <TextField v-model="form.birth_weight" label="Berat badan (kg)"
                               inputmode="numeric" :error="form.errors.birth_weight" />
                    <TextField v-model="form.birth_length" label="Panjang badan (cm)"
                               inputmode="numeric" :error="form.errors.birth_length" />
                    <SelectField v-model="form.birth_helper" label="Penolong kelahiran"
                                 :options="{
                                     Dokter: 'Dokter', Bidan: 'Bidan',
                                     'Dukun bayi': 'Dukun bayi', Lainnya: 'Lainnya',
                                 }" :error="form.errors.birth_helper" />
                </div>
            </fieldset>

            <fieldset v-if="form.type" class="card p-5">
                <legend class="px-1 text-sm font-semibold">Dokumen &amp; catatan</legend>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <TextField v-model="form.document_no" label="Nomor dokumen pendukung"
                               :error="form.errors.document_no"
                               hint="Mis. nomor surat keterangan dari rumah sakit." />
                    <TextField v-model="form.reported_by" label="Dilaporkan oleh"
                               :error="form.errors.reported_by" />
                </div>
                <div class="mt-4">
                    <label for="reason" class="mb-1.5 block text-sm font-medium">Catatan</label>
                    <textarea id="reason" v-model="form.reason" rows="3" maxlength="500"
                              class="w-full rounded-lg border border-line-control bg-surface p-3
                                     text-base transition-colors duration-200
                                     hover:border-content-tertiary focus:border-accent focus:outline-none" />
                </div>
            </fieldset>

            <div v-if="form.type" class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    Simpan mutasi
                </AppButton>
                <AppButton variant="secondary" :href="`/kependudukan/penduduk/${resident.id}`">
                    Batal
                </AppButton>
            </div>
        </form>
    </AppLayout>
</template>
