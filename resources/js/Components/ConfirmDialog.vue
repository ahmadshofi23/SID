<script setup lang="ts">
import { ref, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { TriangleAlert } from '@lucide/vue'
import AppButton from './AppButton.vue'

const props = withDefaults(defineProps<{
    action: string
    method?: 'delete' | 'post' | 'put'
    title: string
    body: string
    confirmLabel?: string
    triggerLabel?: string
    reasonRequired?: boolean
    reasonPlaceholder?: string
}>(), {
    method: 'delete',
    confirmLabel: 'Hapus',
    triggerLabel: 'Hapus',
    reasonRequired: true,
})

const dialog = ref<HTMLDialogElement | null>(null)
const form = useForm({ reason: '' })

async function open(): Promise<void> {
    form.reset()
    form.clearErrors()
    dialog.value?.showModal()

    // Fokus langsung ke isian alasan agar pengguna papan tombol tidak perlu
    // menelusuri dialog lebih dulu.
    await nextTick()
    dialog.value?.querySelector<HTMLTextAreaElement>('textarea')?.focus()
}

function submit(): void {
    form[props.method](props.action, {
        preserveScroll: true,
        onSuccess: () => dialog.value?.close(),
    })
}
</script>

<template>
    <AppButton variant="danger" size="sm" @click="open">{{ triggerLabel }}</AppButton>

    <!--
      <dialog> bawaan peramban dipakai, bukan div kustom: ia sudah menangani
      jebakan fokus, tombol Esc, dan inert pada latar belakang — tiga hal yang
      hampir selalu salah bila dibuat sendiri.
    -->
    <dialog
        ref="dialog"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-line
               bg-surface p-6 text-content shadow-[var(--shadow-overlay)]
               backdrop:bg-black/50 backdrop:backdrop-blur-sm"
        @close="form.reset()"
    >
        <form @submit.prevent="submit">
            <div class="flex gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-danger-soft">
                    <TriangleAlert class="size-5 text-danger" aria-hidden="true" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold">{{ title }}</h2>
                    <p class="mt-1 text-sm text-content-secondary">{{ body }}</p>
                </div>
            </div>

            <div v-if="reasonRequired" class="mt-4">
                <label :for="`${action}-reason`" class="mb-1.5 block text-sm font-medium">
                    Alasan <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <textarea
                    :id="`${action}-reason`"
                    v-model="form.reason"
                    required
                    minlength="5"
                    rows="3"
                    :placeholder="reasonPlaceholder ?? 'Contoh: data ganda hasil impor'"
                    class="w-full rounded-lg border border-line-control bg-surface p-3 text-base
                           transition-colors duration-200 placeholder:text-content-tertiary
                           focus:border-accent focus:outline-none"
                    :class="form.errors.reason ? 'border-danger' : ''"
                />
                <p v-if="form.errors.reason" role="alert"
                   class="mt-1.5 text-sm font-medium text-danger">{{ form.errors.reason }}</p>
                <p v-else class="mt-1.5 text-sm text-content-tertiary">
                    Alasan tersimpan permanen di log aktivitas.
                </p>
            </div>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <AppButton variant="secondary" @click="dialog?.close()">Batal</AppButton>
                <AppButton variant="danger" type="submit" :loading="form.processing">
                    {{ confirmLabel }}
                </AppButton>
            </div>
        </form>
    </dialog>
</template>
