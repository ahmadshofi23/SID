<script setup lang="ts">
import { ref, watch, useId } from 'vue'
import { Search, X, UserRound } from '@lucide/vue'

interface Suggestion {
    id: string
    nama: string
    umur: number
    nik: string
    wilayah: string
}

const props = withDefaults(defineProps<{
    modelValue: string | null
    label: string
    required?: boolean
    error?: string
    hint?: string
}>(), {})

const emit = defineEmits<{
    'update:modelValue': [value: string | null]
    picked: [resident: Suggestion | null]
}>()

const id = useId()
const term = ref('')
const results = ref<Suggestion[]>([])
const selected = ref<Suggestion | null>(null)
const searching = ref(false)
const tooShort = ref(false)

let timer: ReturnType<typeof setTimeout> | undefined
let requestToken = 0

watch(term, (value) => {
    clearTimeout(timer)

    if (value.trim().length < 3) {
        results.value = []
        tooShort.value = value.trim().length > 0

        return
    }

    tooShort.value = false
    timer = setTimeout(() => void search(value), 350)
})

async function search(value: string): Promise<void> {
    /*
     * Token permintaan: jawaban yang datang terlambat untuk kata kunci lama
     * DIBUANG. Tanpa ini, mengetik cepat dapat membuat hasil "SIT" menimpa
     * hasil "SITI AMINAH" karena urutan kedatangan jaringan tidak terjamin.
     */
    const token = ++requestToken
    searching.value = true

    try {
        const response = await fetch(
            `/kependudukan/penduduk/cari?q=${encodeURIComponent(value)}`,
            { headers: { Accept: 'application/json' } },
        )

        if (!response.ok) throw new Error('gagal')

        const body = await response.json() as { data: Suggestion[] }

        if (token === requestToken) results.value = body.data
    } catch {
        if (token === requestToken) results.value = []
    } finally {
        if (token === requestToken) searching.value = false
    }
}

function pick(resident: Suggestion): void {
    selected.value = resident
    results.value = []
    term.value = ''
    emit('update:modelValue', resident.id)
    emit('picked', resident)
}

function clear(): void {
    selected.value = null
    emit('update:modelValue', null)
    emit('picked', null)
}
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-content">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </label>

        <!-- Pilihan yang sudah dibuat ditampilkan sebagai kartu, bukan sebagai
             teks di dalam kotak pencarian: petugas harus yakin ia memilih
             orang yang benar sebelum surat terbit. -->
        <div
            v-if="selected"
            class="flex items-start gap-3 rounded-lg border border-line-control bg-surface-hover p-3"
        >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent-soft">
                <UserRound class="size-4 text-accent" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate font-medium">{{ selected.nama }}</p>
                <p class="text-xs tabular text-content-tertiary">
                    {{ selected.nik }} · {{ selected.umur }} tahun · {{ selected.wilayah }}
                </p>
            </div>
            <button
                type="button"
                class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg
                       text-content-tertiary transition-colors hover:bg-surface hover:text-content"
                aria-label="Ganti pemohon"
                @click="clear"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>

        <div v-else class="relative">
            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2">
                <Search class="size-4 text-content-tertiary" aria-hidden="true" />
            </span>
            <input
                :id="id"
                v-model="term"
                type="search"
                role="combobox"
                :aria-expanded="results.length > 0"
                aria-autocomplete="list"
                :aria-describedby="error ? `${id}-error` : `${id}-hint`"
                :aria-invalid="error ? true : undefined"
                placeholder="Cari nama atau NIK lengkap…"
                class="h-11 w-full rounded-lg border bg-surface pl-9 pr-3 text-base
                       transition-colors duration-200 placeholder:text-content-tertiary
                       focus:border-accent focus:outline-none"
                :class="error ? 'border-danger' : 'border-line-control hover:border-content-tertiary'"
            >

            <ul
                v-if="results.length > 0"
                class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border
                       border-line bg-surface shadow-lg"
                role="listbox"
            >
                <li v-for="r in results" :key="r.id" role="option" :aria-selected="false">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer flex-col items-start gap-0.5 px-3 py-2.5
                               text-left transition-colors hover:bg-surface-hover"
                        @click="pick(r)"
                    >
                        <span class="font-medium">{{ r.nama }}</span>
                        <span class="text-xs tabular text-content-tertiary">
                            {{ r.nik }} · {{ r.umur }} tahun · {{ r.wilayah }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-danger" role="alert">
            {{ error }}
        </p>
        <p v-else :id="`${id}-hint`" class="mt-1 text-sm text-content-tertiary">
            <span v-if="searching">Mencari…</span>
            <span v-else-if="tooShort">Ketik minimal 3 karakter.</span>
            <span v-else>{{ hint ?? 'Pencarian NIK harus lengkap 16 digit.' }}</span>
        </p>
    </div>
</template>
