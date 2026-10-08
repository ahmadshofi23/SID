<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import type { Family } from '@/types'

const props = defineProps<{
    family: (Family & { region_id: string | null; welfare_status_code: string | null;
                        house_ownership_code: string | null }) | null
    regions: Record<string, string>
    references: Record<string, Record<string, string>>
}>()

const f = props.family

const form = useForm({
    kk_number: '',
    region_id: f?.region_id ?? '',
    address: f?.address ?? '',
    rt: f?.rt ?? '',
    rw: f?.rw ?? '',
    postal_code: '',
    welfare_status_code: f?.welfare_status_code ?? '',
    house_ownership_code: f?.house_ownership_code ?? '',
})

function submit(): void {
    f ? form.put(`/kependudukan/keluarga/${f.id}`) : form.post('/kependudukan/keluarga')
}
</script>

<template>
    <Head :title="f ? 'Ubah Kartu Keluarga' : 'Tambah Kartu Keluarga'" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/keluarga" class="hover:text-content-secondary hover:underline">
                Kartu Keluarga
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">{{ f ? 'Ubah' : 'Tambah' }}</span>
        </nav>

        <h1 class="mb-5 text-2xl font-semibold">
            {{ f ? 'Ubah kartu keluarga' : 'Tambah kartu keluarga' }}
        </h1>

        <form class="max-w-3xl" @submit.prevent="submit">
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Data kartu keluarga</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <TextField
                        v-model="form.kk_number"
                        label="Nomor Kartu Keluarga"
                        :required="!f"
                        mono
                        inputmode="numeric"
                        :error="form.errors.kk_number"
                        :hint="f
                            ? `Saat ini: ${f.kk_number_masked}. Biarkan kosong bila tidak diubah.`
                            : '16 angka sesuai Kartu Keluarga.'"
                    />
                    <SelectField v-model="form.region_id" label="Wilayah (RT)" required
                                 :options="regions" :error="form.errors.region_id" />
                    <TextField v-model="form.address" label="Alamat" required
                               :error="form.errors.address" />
                    <TextField v-model="form.postal_code" label="Kode pos"
                               inputmode="numeric" :error="form.errors.postal_code" />
                    <TextField v-model="form.rt" label="RT" :error="form.errors.rt" />
                    <TextField v-model="form.rw" label="RW" :error="form.errors.rw" />
                    <SelectField v-model="form.welfare_status_code" label="Status kesejahteraan"
                                 :options="references.welfare_statuses"
                                 :error="form.errors.welfare_status_code" />
                    <SelectField v-model="form.house_ownership_code" label="Status kepemilikan rumah"
                                 :options="references.house_ownerships"
                                 :error="form.errors.house_ownership_code" />
                </div>
            </fieldset>

            <div class="mt-5 flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    {{ f ? 'Simpan perubahan' : 'Simpan kartu keluarga' }}
                </AppButton>
                <AppButton variant="secondary"
                           :href="f ? `/kependudukan/keluarga/${f.id}` : '/kependudukan/keluarga'">
                    Batal
                </AppButton>
            </div>
        </form>
    </AppLayout>
</template>
