<script setup lang="ts">
import { computed, useId } from 'vue'
import { ChevronDown } from '@lucide/vue'
import type { Option } from '@/types'

const props = withDefaults(defineProps<{
    modelValue: string | null
    label: string
    options: Option[] | Record<string, string>
    required?: boolean
    hint?: string
    error?: string
    placeholder?: string
    disabled?: boolean
    srOnlyLabel?: boolean
}>(), { placeholder: '— Pilih —' })

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()

/* Menerima dua bentuk agar sisi Laravel bebas mengirim map kode => label
   (bentuk alami tabel referensi) maupun daftar objek. */
const items = computed<Option[]>(() =>
    Array.isArray(props.options)
        ? props.options
        : Object.entries(props.options).map(([value, label]) => ({ value, label })),
)

const describedBy = computed(() =>
    props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined)
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-content"
               :class="srOnlyLabel ? 'sr-only' : ''">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </label>

        <div class="relative">
            <select
                :id="id"
                :value="modelValue ?? ''"
                :required="required"
                :disabled="disabled"
                :aria-invalid="error ? true : undefined"
                :aria-describedby="describedBy"
                class="h-11 w-full appearance-none rounded-lg border bg-surface pl-3 pr-10
                       text-base text-content transition-colors duration-200 cursor-pointer
                       focus:border-accent focus:outline-none
                       disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
                :class="error ? 'border-danger' : 'border-line-control hover:border-content-tertiary'"
                @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
            >
                <option value="">{{ placeholder }}</option>
                <option v-for="item in items" :key="item.value" :value="item.value">
                    {{ item.label }}
                </option>
            </select>

            <ChevronDown
                class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2
                       text-content-tertiary"
                aria-hidden="true"
            />
        </div>

        <p v-if="error" :id="`${id}-error`" role="alert"
           class="mt-1.5 text-sm font-medium text-danger">{{ error }}</p>
        <p v-else-if="hint" :id="`${id}-hint`"
           class="mt-1.5 text-sm text-content-tertiary">{{ hint }}</p>
    </div>
</template>
