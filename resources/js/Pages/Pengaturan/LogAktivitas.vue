<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { ScrollText, Download, ShieldOff, ChevronRight } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppButton from '@/Components/AppButton.vue'
import SelectField from '@/Components/SelectField.vue'
import TextField from '@/Components/TextField.vue'
import EmptyState from '@/Components/EmptyState.vue'
import DataPagination from '@/Components/DataPagination.vue'
import { usePageData } from '@/composables/usePage'
import type { CursorPage, AuditLogRow, AuditLogFilters, Option } from '@/types'

const props = defineProps<{
    entri: CursorPage<AuditLogRow>
    filters: AuditLogFilters
    aksi: string[]
    jenis_entitas: string[]
    pelaku: Option[]
}>()

const { can } = usePageData()

const filters = ref<AuditLogFilters>({ ...props.filters })

watch(filters, (value) => {
    router.get('/pengaturan/log-aktivitas', {
        action: value.action || undefined,
        actor_id: value.actor_id || undefined,
        entity_type: value.entity_type || undefined,
        entity_id: value.entity_id || undefined,
        from: value.from || undefined,
        to: value.to || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}, { deep: true })

/*
 * Baris yang terbuka disimpan sebagai himpunan id, bukan satu id.
 *
 * Pemeriksa membandingkan dua baris — "apa yang diubah di sini, dan apa yang
 * diubah di sana" — dan panel yang menutup sendiri begitu yang berikutnya
 * dibuka memaksanya mengingat isi yang pertama.
 */
const terbuka = ref<Set<number>>(new Set())

function toggle(id: number): void {
    const next = new Set(terbuka.value)

    if (next.has(id)) {
        next.delete(id)
    } else {
        next.add(id)
    }

    terbuka.value = next
}

/** Saringan yang sedang aktif dibawa ke ekspor — lihat AuditLogController. */
function urlEkspor(): string {
    const params = new URLSearchParams()

    for (const [key, value] of Object.entries(filters.value)) {
        if (value) params.append(key, value)
    }

    const query = params.toString()

    return `/pengaturan/log-aktivitas/ekspor${query === '' ? '' : `?${query}`}`
}

const opsiAksi = (daftar: string[]): Record<string, string> =>
    Object.fromEntries(daftar.map((item) => [item, item]))

function ringkas(nilai: Record<string, unknown> | null): string {
    return nilai === null ? '' : JSON.stringify(nilai, null, 2)
}
</script>

<template>
    <Head title="Log Aktivitas" />

    <AppLayout title="Log Aktivitas">
        <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Log Aktivitas</h1>
                <p class="mt-1 max-w-2xl text-sm text-content-secondary">
                    Catatan setiap tindakan yang menyentuh data, tidak dapat diubah
                    maupun dihapus. Urutannya dari yang terbaru.
                </p>
            </div>

            <!--
              Tombol ekspor adalah tautan biasa, bukan router.visit(): balasannya
              berupa unduhan berkas, dan Inertia tidak dapat memproses balasan
              yang bukan muatan halaman.
            -->
            <a v-if="can('audit.ekspor')" :href="urlEkspor()">
                <AppButton size="sm">
                    <Download class="size-4" aria-hidden="true" />
                    Ekspor CSV
                </AppButton>
            </a>
        </header>

        <div class="card mb-5 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
            <SelectField v-model="filters.action" label="Aksi" placeholder="— Semua —"
                         :options="opsiAksi(aksi)" />
            <SelectField v-model="filters.actor_id" label="Pelaku" placeholder="— Semua —"
                         :options="pelaku" />
            <SelectField v-model="filters.entity_type" label="Jenis entitas" placeholder="— Semua —"
                         :options="opsiAksi(jenis_entitas)" />
            <TextField v-model="filters.entity_id" label="ID entitas" mono
                       placeholder="Tempelkan id lengkap"
                       hint="Pencocokan tepat — potongan id tidak ditemukan." />
            <TextField v-model="filters.from" label="Dari tanggal" type="date" />
            <TextField v-model="filters.to" label="Sampai tanggal" type="date" />
        </div>

        <EmptyState
            v-if="entri.data.length === 0"
            :icon="ScrollText"
            title="Tidak ada catatan"
            description="Belum ada tindakan yang cocok dengan saringan di atas."
        />

        <ul v-else class="space-y-2">
            <li v-for="e in entri.data" :key="e.id" class="card overflow-hidden">
                <button
                    type="button"
                    class="flex w-full items-start gap-3 p-4 text-left transition-colors hover:bg-surface-hover"
                    :aria-expanded="terbuka.has(e.id)"
                    @click="toggle(e.id)"
                >
                    <ChevronRight
                        class="mt-0.5 size-4 shrink-0 text-content-tertiary transition-transform"
                        :class="terbuka.has(e.id) ? 'rotate-90' : ''"
                        aria-hidden="true"
                    />

                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-baseline gap-x-2">
                            <span class="tabular font-medium">{{ e.action }}</span>
                            <span class="text-xs text-content-tertiary">{{ e.created_at }}</span>
                        </span>

                        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-content-secondary">
                            <!--
                              Baris tanpa pelaku dikatakan dengan kata, bukan
                              dibiarkan kosong: kolom kosong terbaca seperti data
                              yang hilang, padahal justru inilah jaminan RULE-09
                              yang sedang bekerja.
                            -->
                            <span v-if="e.actor_name === null" class="inline-flex items-center gap-1">
                                <ShieldOff class="size-3.5" aria-hidden="true" />
                                Tanpa pelaku (anonim)
                            </span>
                            <span v-else>{{ e.actor_name }}</span>

                            <span v-if="e.entity_type" class="tabular">
                                {{ e.entity_type }}<template v-if="e.entity_id">
                                    · {{ e.entity_id }}</template>
                            </span>
                        </span>
                    </span>
                </button>

                <div v-if="terbuka.has(e.id)" class="border-t border-border px-4 py-4 text-sm">
                    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                        <div v-if="e.actor_roles">
                            <dt class="text-xs text-content-tertiary">Peran pelaku</dt>
                            <dd class="mt-0.5">{{ e.actor_roles }}</dd>
                        </div>

                        <div v-if="e.ip_address">
                            <dt class="text-xs text-content-tertiary">Alamat IP</dt>
                            <dd class="tabular mt-0.5">{{ e.ip_address }}</dd>
                        </div>

                        <div v-if="e.correlation_id">
                            <dt class="text-xs text-content-tertiary">ID korelasi</dt>
                            <dd class="tabular mt-0.5 break-all">{{ e.correlation_id }}</dd>
                        </div>

                        <div v-if="e.user_agent" class="sm:col-span-2">
                            <dt class="text-xs text-content-tertiary">User agent</dt>
                            <dd class="mt-0.5 break-all text-content-secondary">{{ e.user_agent }}</dd>
                        </div>

                        <div v-if="e.changes" class="sm:col-span-2">
                            <dt class="text-xs text-content-tertiary">
                                Perubahan
                                <span class="text-content-tertiary">
                                    — data pribadi sudah disamarkan saat dicatat
                                </span>
                            </dt>
                            <dd class="mt-1">
                                <pre class="tabular overflow-x-auto rounded-md bg-surface-hover p-3 text-xs">{{ ringkas(e.changes) }}</pre>
                            </dd>
                        </div>

                        <div v-if="e.context" class="sm:col-span-2">
                            <dt class="text-xs text-content-tertiary">Konteks</dt>
                            <dd class="mt-1">
                                <pre class="tabular overflow-x-auto rounded-md bg-surface-hover p-3 text-xs">{{ ringkas(e.context) }}</pre>
                            </dd>
                        </div>
                    </dl>
                </div>
            </li>
        </ul>

        <DataPagination
            v-if="entri.data.length > 0"
            :prev-url="entri.prev_url" :next-url="entri.next_url"
            :count="entri.count" noun="catatan di halaman ini"
        />
    </AppLayout>
</template>
