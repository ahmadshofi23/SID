<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import TextField from '@/Components/TextField.vue'
import SelectField from '@/Components/SelectField.vue'
import AppButton from '@/Components/AppButton.vue'
import AppAlert from '@/Components/AppAlert.vue'
import type { Option, Region } from '@/types'

const props = defineProps<{
    region: (Region & { parent_id: string | null; head_phone: string | null }) | null
    parents: { dusun: Record<string, string>; rw: Record<string, string> }
}>()

const r = props.region

const form = useForm({
    level: r?.level ?? '',
    parent_id: r?.parent_id ?? '',
    code: r?.code ?? '',
    name: r?.name ?? '',
    head_name: r?.head_name ?? '',
    head_phone: r?.head_phone ?? '',
})

/*
 * Pilihan induk menyesuaikan tingkat yang dipilih — RW hanya boleh memilih
 * Dusun, RT hanya boleh memilih RW. Menampilkan seluruh wilayah akan
 * mengundang kesalahan yang baru ketahuan setelah disimpan.
 */
const parentOptions = computed<Option[]>(() => {
    const source = form.level === 'rw' ? props.parents.dusun
        : form.level === 'rt' ? props.parents.rw
        : {}

    return Object.entries(source).map(([value, label]) => ({ value, label }))
})

const parentHint = computed(() =>
    form.level === 'dusun' ? 'Dusun berada di tingkat teratas dan tidak memiliki induk.'
    : form.level === 'rw' ? 'Pilih Dusun yang menaungi RW ini.'
    : form.level === 'rt' ? 'Pilih RW yang menaungi RT ini.'
    : 'Pilih tingkat wilayah terlebih dahulu.')
</script>

<template>
    <Head :title="r ? 'Ubah Wilayah' : 'Tambah Wilayah'" />

    <AppLayout>
        <nav class="mb-4 flex items-center gap-1 text-sm text-content-tertiary" aria-label="Remah roti">
            <Link href="/kependudukan/wilayah" class="hover:text-content-secondary hover:underline">
                Wilayah
            </Link>
            <ChevronRight class="size-3.5" aria-hidden="true" />
            <span class="text-content-secondary">{{ r ? r.name : 'Tambah' }}</span>
        </nav>

        <h1 class="mb-5 text-2xl font-semibold">
            {{ r ? `Ubah ${r.name}` : 'Tambah wilayah' }}
        </h1>

        <form class="max-w-2xl space-y-5"
              @submit.prevent="r ? form.put(`/kependudukan/wilayah/${r.id}`)
                                 : form.post('/kependudukan/wilayah')">
            <fieldset class="card p-5">
                <legend class="px-1 text-sm font-semibold">Data wilayah</legend>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <SelectField v-model="form.level" label="Tingkat" required
                                 :options="{ dusun: 'Dusun', rw: 'RW', rt: 'RT' }"
                                 :error="form.errors.level" />

                    <SelectField
                        v-model="form.parent_id"
                        label="Wilayah induk"
                        :options="parentOptions"
                        :disabled="form.level === 'dusun' || form.level === ''"
                        :placeholder="form.level === 'dusun' ? '— Tidak ada —' : '— Pilih —'"
                        :error="form.errors.parent_id"
                        :hint="parentHint"
                    />

                    <TextField v-model="form.code" label="Kode" required
                               :error="form.errors.code"
                               hint="Contoh: 001. Harus unik dalam satu induk." />
                    <TextField v-model="form.name" label="Nama wilayah" required
                               :error="form.errors.name"
                               hint="Contoh: Dusun Krajan, RW 02, RT 003." />
                    <TextField v-model="form.head_name" label="Nama kepala/ketua wilayah"
                               :error="form.errors.head_name" />
                    <TextField v-model="form.head_phone" label="Nomor telepon" type="tel"
                               inputmode="tel" :error="form.errors.head_phone" />
                </div>
            </fieldset>

            <AppAlert v-if="r" type="warning" title="Perubahan ini mempengaruhi hak akses">
                Mengubah induk atau kode wilayah akan menghitung ulang jalur wilayah
                beserta seluruh turunannya — dan dengan itu, data siapa saja yang
                dapat dilihat Kepala Dusun.
            </AppAlert>

            <div class="flex flex-wrap gap-2">
                <AppButton type="submit" variant="primary" :loading="form.processing">
                    {{ r ? 'Simpan perubahan' : 'Simpan wilayah' }}
                </AppButton>
                <AppButton variant="secondary" href="/kependudukan/wilayah">Batal</AppButton>
            </div>
        </form>
    </AppLayout>
</template>
