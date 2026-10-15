<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import {
    Pencil, TriangleAlert, MapPinned, Globe, Hash, Search,
} from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppAlert from '@/Components/AppAlert.vue'
import TextField from '@/Components/TextField.vue'
import { usePageData } from '@/composables/usePage'

interface TypeRow {
    id: string
    code: string
    name: string
    sla_days: number
    validity_months: number | null
    step_count: number
    is_active: boolean
    is_public: boolean
    requires_field_check: boolean
    has_warning: boolean
    field_count: number
    next_number: string
}

const props = defineProps<{
    types: TypeRow[]
    counts: { active: number; total: number }
}>()

const { can } = usePageData()

const term = ref('')
const onlyActive = ref(false)

const visible = computed(() => {
    const needle = term.value.trim().toLowerCase()

    return props.types.filter((t) => {
        if (onlyActive.value && !t.is_active) return false
        if (needle === '') return true

        return t.code.toLowerCase().includes(needle)
            || t.name.toLowerCase().includes(needle)
    })
})

function toggle(row: TypeRow): void {
    router.post(`/jenis-surat/${row.id}/aktif`, { is_active: !row.is_active }, {
        preserveScroll: true,
    })
}
</script>

<template>
    <Head title="Jenis Surat" />

    <AppLayout>
        <header class="mb-5">
            <h1 class="text-2xl font-semibold">Jenis Surat</h1>
            <p class="mt-1 text-sm text-content-secondary">
                {{ counts.active }} dari {{ counts.total }} jenis surat aktif.
                Aktifkan yang dipakai desa Anda.
            </p>
        </header>

        <!--
          Peringatan pemasangan: ditampilkan di layar ini karena di sinilah
          petugas mengatur jenis surat pertama kali (docs/13 §8).
        -->
        <AppAlert type="warning" class="mb-5">
            <p class="font-medium">Sebelum layanan surat dibuka ke warga</p>
            <ol class="mt-1.5 list-decimal space-y-0.5 pl-4">
                <li>
                    Bandingkan <strong>pola penomoran</strong> dengan arsip surat asli
                    desa — mengubahnya setelah ada surat terbit menghasilkan deret
                    nomor yang tidak konsisten.
                </li>
                <li>
                    Isi <strong>kode klasifikasi arsip</strong> dari arsip desa.
                    Nilainya kami kosongkan karena berbeda antar daerah; jangan ditebak.
                </li>
                <li>
                    Terbitkan <strong>satu surat uji</strong> per jenis, cetak, dan
                    minta Sekretaris Desa memeriksa redaksinya.
                </li>
            </ol>
        </AppAlert>

        <section class="card mb-5 p-4" aria-label="Saringan">
            <div class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <TextField v-model="term" label="Cari jenis surat" placeholder="SKTM, domisili, usaha…" />
                <label class="flex h-11 items-center gap-2 text-sm">
                    <input v-model="onlyActive" type="checkbox" class="size-4 rounded border-line-control">
                    Hanya yang aktif
                </label>
            </div>
        </section>

        <div v-if="visible.length === 0" class="card p-8 text-center">
            <Search class="mx-auto mb-3 size-6 text-content-tertiary" aria-hidden="true" />
            <p class="text-sm text-content-secondary">
                Tidak ada jenis surat yang cocok dengan pencarian Anda.
            </p>
        </div>

        <ul v-else class="grid gap-3 lg:grid-cols-2">
            <li
                v-for="row in visible"
                :key="row.id"
                class="card p-4"
                :class="row.is_active ? '' : 'opacity-70'"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-1.5">
                            <span class="font-mono text-sm font-semibold">{{ row.code }}</span>
                            <span
                                v-if="row.is_active"
                                class="rounded bg-success-soft px-1.5 py-0.5 text-xs font-medium text-success"
                            >
                                Aktif
                            </span>
                            <span
                                v-else
                                class="rounded bg-surface-hover px-1.5 py-0.5 text-xs text-content-tertiary"
                            >
                                Nonaktif
                            </span>
                        </p>
                        <p class="mt-0.5 font-medium">{{ row.name }}</p>
                    </div>

                    <!-- Sakelar aktif: target sentuh penuh, bukan ikon kecil -->
                    <label
                        v-if="can('jenis_surat.kelola')"
                        class="flex h-11 shrink-0 cursor-pointer items-center gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            :checked="row.is_active"
                            class="size-4 rounded border-line-control"
                            @change="toggle(row)"
                        >
                        <span class="sr-only">
                            {{ row.is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ row.code }}
                        </span>
                    </label>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-content-tertiary">SLA</dt>
                        <dd class="tabular">{{ row.sla_days }} hari</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-content-tertiary">Tahap</dt>
                        <dd class="tabular">{{ row.step_count }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-content-tertiary">Berlaku</dt>
                        <dd class="tabular">
                            {{ row.validity_months ? `${row.validity_months} bln` : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-content-tertiary">Isian</dt>
                        <dd class="tabular">{{ row.field_count }}</dd>
                    </div>
                </dl>

                <p class="mt-3 flex items-center gap-1.5 text-xs text-content-tertiary">
                    <Hash class="size-3.5 shrink-0" aria-hidden="true" />
                    Nomor berikutnya:
                    <span class="font-mono">{{ row.next_number }}</span>
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span
                        v-if="row.is_public"
                        class="inline-flex items-center gap-1 rounded bg-info-soft px-1.5 py-0.5 text-xs text-info"
                    >
                        <Globe class="size-3" aria-hidden="true" />
                        Dapat diajukan daring
                    </span>
                    <span
                        v-if="row.requires_field_check"
                        class="inline-flex items-center gap-1 rounded bg-warning-soft px-1.5 py-0.5 text-xs text-warning"
                    >
                        <MapPinned class="size-3" aria-hidden="true" />
                        Perlu cek lapangan
                    </span>
                    <span
                        v-if="row.has_warning"
                        class="inline-flex items-center gap-1 rounded bg-warning-soft px-1.5 py-0.5 text-xs text-warning"
                    >
                        <TriangleAlert class="size-3" aria-hidden="true" />
                        Berisiko
                    </span>

                    <Link
                        v-if="can('jenis_surat.kelola')"
                        :href="`/jenis-surat/${row.id}/ubah`"
                        class="ml-auto inline-flex min-h-11 items-center gap-1.5 text-sm
                               text-accent hover:underline"
                    >
                        <Pencil class="size-4" aria-hidden="true" />
                        Ubah &amp; template
                    </Link>
                </div>
            </li>
        </ul>
    </AppLayout>
</template>
