<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import WargaLayout from '@/Layouts/WargaLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'

interface Anggota {
    id: string
    full_name: string
    nik_masked: string
    family_relation_code: string
    gender: string
    birth_date: string
    status: string
    is_me: boolean
}

defineProps<{
    anggota: Anggota[]
    diri: { id: string; full_name: string; nik_masked: string; birth_date: string } | null
}>()

const hubungan: Record<string, string> = {
    kepala_keluarga: 'Kepala Keluarga',
    istri: 'Istri',
    suami: 'Suami',
    anak: 'Anak',
    menantu: 'Menantu',
    cucu: 'Cucu',
    orang_tua: 'Orang Tua',
    mertua: 'Mertua',
    famili_lain: 'Famili Lain',
    lainnya: 'Lainnya',
}

function tanggal(iso: string): string {
    return new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric', month: 'long', year: 'numeric',
    })
}
</script>

<template>
    <Head title="Data Keluarga" />

    <WargaLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Data Keluarga</h1>
            <p class="mt-1 text-sm text-content-secondary">
                Anggota pada satu Kartu Keluarga dengan Anda.
            </p>
        </header>

        <!--
          HANYA BACA. Koreksi data kependudukan berjalan lewat petugas, bukan
          lewat warga langsung: satu perubahan di sini mengubah isi surat yang
          terbit, statistik desa, dan laporan ke kecamatan.
        -->
        <AppAlert type="info" class="mb-5" title="Data ini hanya dapat dibaca">
            Bila ada yang keliru, sampaikan ke kantor desa dengan membawa dokumen
            pendukung. Petugas yang memperbaikinya, dan setiap perubahan tercatat.
        </AppAlert>

        <AppAlert v-if="anggota.length === 0" type="warning" title="Data keluarga belum terlihat">
            Akun Anda belum tertaut ke satu Kartu Keluarga. Hubungi kantor desa.
        </AppAlert>

        <ul v-else class="space-y-3">
            <li v-for="orang in anggota" :key="orang.id" class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">
                            {{ orang.full_name }}
                            <span v-if="orang.is_me"
                                  class="ml-1 rounded bg-accent-soft px-1.5 py-0.5 text-xs font-medium text-accent-text">
                                Anda
                            </span>
                        </p>
                        <p class="mt-0.5 text-sm text-content-secondary">
                            {{ hubungan[orang.family_relation_code] ?? orang.family_relation_code }}
                        </p>
                    </div>
                    <span v-if="orang.status !== 'aktif'"
                          class="rounded bg-surface-hover px-2 py-0.5 text-xs text-content-secondary">
                        {{ orang.status === 'meninggal' ? 'Meninggal' : 'Pindah' }}
                    </span>
                </div>

                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-content-tertiary">NIK</dt>
                        <dd class="tabular">{{ orang.nik_masked }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Tanggal lahir</dt>
                        <dd>{{ tanggal(orang.birth_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-content-tertiary">Jenis kelamin</dt>
                        <dd>{{ orang.gender === 'L' ? 'Laki-laki' : 'Perempuan' }}</dd>
                    </div>
                </dl>
            </li>
        </ul>

        <p class="mt-5 text-xs text-content-tertiary">
            NIK ditampilkan sebagian, juga untuk keluarga sendiri — layar ini sering
            dibuka di ponsel yang dipinjamkan.
        </p>
    </WargaLayout>
</template>
