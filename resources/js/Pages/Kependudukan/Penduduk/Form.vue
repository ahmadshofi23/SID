<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import type { Option } from '@/types'

interface ResidentForm {
    nik: string
    full_name: string
    birth_place: string
    birth_date: string
    gender: string
    family_id: string
    family_relation_code: string
    region_id: string
    address: string
    religion_code: string
    marital_status_code: string
    blood_type_code: string
    education_code: string
    occupation_code: string
    disability_code: string
    father_name: string
    mother_name: string
    phone: string
    email: string
    is_poor: boolean
    notes: string
    [key: string]: string | boolean
}

const props = defineProps<{
    resident: (Partial<ResidentForm> & { id: string; nik_masked: string }) | null
    families: Option[]
    selectedFamilyId: string | null
    regions: Record<string, string>
    references: Record<string, Record<string, string>>
}>()

const r = props.resident

const form = useForm<ResidentForm>({
    nik: '',
    full_name: r?.full_name ?? '',
    birth_place: r?.birth_place ?? '',
    birth_date: r?.birth_date ?? '',
    gender: r?.gender ?? '',
    family_id: props.selectedFamilyId ?? '',
    family_relation_code: r?.family_relation_code ?? '',
    region_id: r?.region_id ?? '',
    address: r?.address ?? '',
    religion_code: r?.religion_code ?? '',
    marital_status_code: r?.marital_status_code ?? '',
    blood_type_code: r?.blood_type_code ?? '',
    education_code: r?.education_code ?? '',
    occupation_code: r?.occupation_code ?? '',
    disability_code: r?.disability_code ?? '',
    father_name: r?.father_name ?? '',
    mother_name: r?.mother_name ?? '',
    phone: r?.phone ?? '',
    email: r?.email ?? '',
    is_poor: Boolean(r?.is_poor),
    notes: r?.notes ?? '',
})

function submit(): void {
    r
        ? form.put(`/kependudukan/penduduk/${r.id}`)
        : form.post('/kependudukan/penduduk')
}
</script>

<template>
    <Head :title="r ? 'Ubah Data Penduduk' : 'Tambah Penduduk'" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/penduduk" class="hover:text-content-secondary hover:underline">
                Data Penduduk
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">{{ r ? r.full_name : 'Tambah' }}</span>
        </nav>

        <h1 class="mb-5 text-2xl font-semibold">
            {{ r ? `Ubah data ${r.full_name}` : 'Tambah penduduk' }}
        </h1>

        <form class="max-w-3xl space-y-5" @submit.prevent="submit">
            <!-- Isian dikelompokkan supaya formulir panjang tidak terasa
                 menakutkan (WCAG field-grouping, progressive-disclosure). -->
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Identitas</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <!-- NIK TIDAK di-prefill saat mengubah: nilai aslinya tidak
                         boleh masuk ke dalam data halaman (RULE-07). -->
                    <TextField
                        v-model="form.nik"
                        label="NIK"
                        :required="!r"
                        mono
                        inputmode="numeric"
                        :error="form.errors.nik"
                        :hint="r
                            ? `NIK saat ini: ${r.nik_masked}. Biarkan kosong bila tidak ingin mengubahnya.`
                            : '16 angka sesuai KTP.'"
                    />
                    <TextField
                        v-model="form.full_name"
                        label="Nama lengkap"
                        required
                        :error="form.errors.full_name"
                        hint="Tulis sesuai dokumen resmi, termasuk gelar bila ada."
                    />
                    <TextField v-model="form.birth_place" label="Tempat lahir"
                               :error="form.errors.birth_place" />
                    <TextField v-model="form.birth_date" label="Tanggal lahir" type="date"
                               required :error="form.errors.birth_date" />

                    <SelectField v-model="form.gender" label="Jenis kelamin" required
                                 :options="{ L: 'Laki-laki', P: 'Perempuan' }"
                                 :error="form.errors.gender" />
                    <SelectField v-model="form.religion_code" label="Agama"
                                 :options="references.religions" :error="form.errors.religion_code" />
                    <SelectField v-model="form.marital_status_code" label="Status perkawinan"
                                 :options="references.marital_statuses"
                                 :error="form.errors.marital_status_code" />
                    <SelectField v-model="form.blood_type_code" label="Golongan darah"
                                 :options="references.blood_types"
                                 :error="form.errors.blood_type_code" />
                </div>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Keluarga &amp; wilayah</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField
                        v-model="form.family_id"
                        label="Kartu keluarga"
                        :options="families"
                        placeholder="— Belum tergabung —"
                        :error="form.errors.family_id"
                        hint="Belum ada? Buat kartu keluarga lebih dulu."
                    />
                    <SelectField v-model="form.family_relation_code" label="Hubungan dalam keluarga"
                                 required :options="references.family_relations"
                                 :error="form.errors.family_relation_code" />
                    <SelectField v-model="form.region_id" label="Wilayah (RT)"
                                 :options="regions" :error="form.errors.region_id" />
                    <TextField v-model="form.address" label="Alamat" :error="form.errors.address" />
                </div>
            </fieldset>

            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Pendidikan, pekerjaan &amp; kontak</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField v-model="form.education_code" label="Pendidikan"
                                 :options="references.educations" :error="form.errors.education_code" />
                    <SelectField v-model="form.occupation_code" label="Pekerjaan"
                                 :options="references.occupations" :error="form.errors.occupation_code" />
                    <TextField v-model="form.father_name" label="Nama ayah"
                               :error="form.errors.father_name" />
                    <TextField v-model="form.mother_name" label="Nama ibu"
                               :error="form.errors.mother_name" />
                    <TextField v-model="form.phone" label="Nomor telepon" type="tel"
                               inputmode="tel" :error="form.errors.phone" />
                    <TextField v-model="form.email" label="Email" type="email"
                               :error="form.errors.email" />
                    <SelectField v-model="form.disability_code" label="Disabilitas"
                                 :options="references.disabilities" placeholder="— Tidak ada —"
                                 :error="form.errors.disability_code" />
                </div>

                <label class="mt-4 flex min-h-11 cursor-pointer items-center gap-2.5 text-sm">
                    <input
                        v-model="form.is_poor"
                        type="checkbox"
                        class="size-4.5 rounded border-line-strong accent-[var(--color-accent)] cursor-pointer"
                    >
                    Termasuk keluarga tidak mampu
                </label>

                <div class="mt-4">
                    <label for="notes" class="mb-1.5 block text-sm font-medium">Catatan</label>
                    <textarea
                        id="notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="2000"
                        class="w-full rounded-lg border border-line-control bg-surface p-3 text-base
                               transition-colors duration-200 hover:border-content-tertiary
                               focus:border-accent focus:outline-none"
                    />
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    {{ r ? 'Simpan perubahan' : 'Simpan penduduk' }}
                </AppButton>
                <AppButton
                    variant="secondary"
                    :href="r ? `/kependudukan/penduduk/${r.id}` : '/kependudukan/penduduk'"
                >
                    Batal
                </AppButton>
            </div>
        </form>
    </AppLayout>
</template>
