<script setup lang="ts">
import { ref } from 'vue'
import { Eye, EyeOff, LoaderCircle } from '@lucide/vue'

const props = defineProps<{
    masked: string
    revealUrl?: string | null
    label?: string
    canReveal?: boolean
}>()

const revealed = ref<string | null>(null)
const loading = ref(false)
const failed = ref(false)

/**
 * Pembukaan data pribadi — RULE-07.
 *
 * Nilai penuh TIDAK PERNAH ikut dalam data halaman. Ia diambil lewat
 * permintaan tersendiri yang memerlukan izin `data_pribadi.lihat_penuh`
 * dan tercatat di audit log. Akibatnya, membuka "view source" atau menyimpan
 * halaman tidak membocorkan apa pun.
 */
async function toggle(): Promise<void> {
    if (revealed.value !== null) {
        revealed.value = null
        return
    }

    if (!props.revealUrl) return

    loading.value = true
    failed.value = false

    try {
        const token = document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''

        const response = await fetch(props.revealUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        })

        if (!response.ok) throw new Error('gagal')

        const data = await response.json() as { nik?: string; nomor_kk?: string }
        revealed.value = data.nik ?? data.nomor_kk ?? null
    } catch {
        failed.value = true
        setTimeout(() => { failed.value = false }, 2500)
    } finally {
        loading.value = false
    }
}
</script>

<template>
    <span class="inline-flex items-center gap-2 font-mono text-sm tabular">
        <span :class="revealed ? 'text-content' : 'text-content-secondary'">
            {{ revealed ?? masked }}
        </span>

        <button
            v-if="canReveal && revealUrl"
            type="button"
            class="inline-flex size-7 items-center justify-center rounded-md border border-line
                   text-content-tertiary transition-colors duration-200 cursor-pointer
                   hover:border-accent hover:text-accent
                   disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="loading"
            :title="revealed ? 'Sembunyikan' : 'Pembukaan ini tercatat di log aktivitas'"
            :aria-label="revealed
                ? `Sembunyikan ${label ?? 'NIK'}`
                : `Tampilkan ${label ?? 'NIK'} lengkap. Tindakan ini tercatat di log aktivitas.`"
            @click="toggle"
        >
            <LoaderCircle v-if="loading" class="size-3.5 animate-spin" aria-hidden="true" />
            <EyeOff v-else-if="revealed" class="size-3.5" aria-hidden="true" />
            <Eye v-else class="size-3.5" aria-hidden="true" />
        </button>

        <span v-if="failed" class="text-xs text-danger" role="alert">Gagal membuka</span>
    </span>
</template>
