<script setup lang="ts">
import { computed, useId } from 'vue'
import { Plus, X } from '@lucide/vue'

const props = defineProps<{
    modelValue: string[]
    label: string
    options?: string[]
    required?: boolean
    error?: string
    hint?: string
    /** Izinkan menambah nilai di luar daftar (mis. nama anggota keluarga). */
    allowCustom?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const id = useId()
const custom = defineModel<string>('custom', { default: '' })

const selected = computed(() => props.modelValue ?? [])

function toggle(option: string): void {
    emit('update:modelValue', selected.value.includes(option)
        ? selected.value.filter((v) => v !== option)
        : [...selected.value, option])
}

function addCustom(): void {
    const value = custom.value.trim()

    // Nilai ganda ditolak diam-diam: daftar anggota yang memuat nama sama
    // dua kali akan tercetak begitu juga di surat.
    if (value === '' || selected.value.includes(value)) {
        custom.value = ''

        return
    }

    emit('update:modelValue', [...selected.value, value])
    custom.value = ''
}

function remove(value: string): void {
    emit('update:modelValue', selected.value.filter((v) => v !== value))
}
</script>

<template>
    <fieldset>
        <legend class="mb-1.5 block text-sm font-medium text-content">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </legend>

        <!-- Daftar pilihan baku -->
        <div v-if="(options ?? []).length > 0" class="space-y-1.5">
            <label
                v-for="option in options"
                :key="option"
                class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-sm
                       transition-colors hover:bg-surface-hover"
            >
                <input
                    type="checkbox"
                    :checked="selected.includes(option)"
                    class="size-4 shrink-0 rounded border-line-control"
                    @change="toggle(option)"
                >
                {{ option }}
            </label>
        </div>

        <!-- Nilai bebas: dipakai untuk daftar yang tidak dapat ditentukan
             sebelumnya, misalnya anggota keluarga yang ikut pindah. -->
        <template v-if="allowCustom || (options ?? []).length === 0">
            <ul v-if="selected.length > 0" class="mb-2 flex flex-wrap gap-2">
                <li
                    v-for="value in selected"
                    :key="value"
                    class="inline-flex items-center gap-1.5 rounded-md bg-surface-hover px-2 py-1 text-sm"
                >
                    {{ value }}
                    <button
                        type="button"
                        class="cursor-pointer text-content-tertiary hover:text-danger"
                        :aria-label="`Hapus ${value}`"
                        @click="remove(value)"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>

            <div class="flex gap-2">
                <input
                    :id="id"
                    v-model="custom"
                    type="text"
                    placeholder="Tulis lalu tekan Enter"
                    :aria-describedby="error ? `${id}-error` : `${id}-hint`"
                    class="h-11 flex-1 rounded-lg border bg-surface px-3 text-base
                           transition-colors duration-200 placeholder:text-content-tertiary
                           focus:border-accent focus:outline-none"
                    :class="error ? 'border-danger' : 'border-line-control hover:border-content-tertiary'"
                    @keydown.enter.prevent="addCustom"
                >
                <button
                    type="button"
                    class="flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-lg
                           border border-line-control transition-colors hover:bg-surface-hover"
                    aria-label="Tambah"
                    @click="addCustom"
                >
                    <Plus class="size-4" aria-hidden="true" />
                </button>
            </div>
        </template>

        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-danger" role="alert">
            {{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="mt-1 text-sm text-content-tertiary">
            {{ hint }}
        </p>
    </fieldset>
</template>
