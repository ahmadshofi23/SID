<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(defineProps<{
    modelValue: string | null
    label: string
    rows?: number
    required?: boolean
    hint?: string
    error?: string
    placeholder?: string
    maxlength?: number
    disabled?: boolean
    srOnlyLabel?: boolean
}>(), { rows: 3 })

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const describedBy = computed(() =>
    props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined)

/* Sisa karakter ditampilkan hanya setelah pengguna melewati 80% batas:
   pencacah yang selalu tampil menjadi derau, dan baru berguna ketika
   batasnya mendekat. */
const remaining = computed(() => {
    if (!props.maxlength) return null

    const used = (props.modelValue ?? '').length

    return used > props.maxlength * 0.8 ? props.maxlength - used : null
})
</script>

<template>
    <div>
        <label
            :for="id"
            class="mb-1.5 block text-sm font-medium text-content"
            :class="srOnlyLabel ? 'sr-only' : ''"
        >
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </label>

        <textarea
            :id="id"
            :value="modelValue"
            :rows="rows"
            :required="required"
            :placeholder="placeholder"
            :maxlength="maxlength"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
            class="w-full rounded-lg border bg-surface p-3 text-base text-content
                   transition-colors duration-200 placeholder:text-content-tertiary
                   focus:border-accent focus:outline-none
                   disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
            :class="error ? 'border-danger' : 'border-line-control hover:border-content-tertiary'"
            @input="$emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        />

        <p v-if="error" :id="`${id}-error`" class="mt-1 text-sm text-danger" role="alert">
            {{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="mt-1 text-sm text-content-tertiary">
            {{ hint }}
        </p>
        <p v-if="remaining !== null" class="mt-1 text-xs tabular text-content-tertiary">
            Sisa {{ remaining }} karakter
        </p>
    </div>
</template>
