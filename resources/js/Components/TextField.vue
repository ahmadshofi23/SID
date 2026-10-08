<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(defineProps<{
    modelValue: string | number | null
    label: string
    type?: string
    required?: boolean
    hint?: string
    error?: string
    placeholder?: string
    autocomplete?: string
    inputmode?: 'text' | 'numeric' | 'tel' | 'email' | 'search'
    autofocus?: boolean
    disabled?: boolean
    mono?: boolean
}>(), { type: 'text' })

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const describedBy = computed(() =>
    props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined)
</script>

<template>
    <div>
        <!-- Label DI ATAS field. Placeholder tidak pernah dipakai sebagai label:
             ia hilang begitu pengguna mulai mengetik (WCAG input-labels). -->
        <label :for="id" class="mb-1.5 block text-sm font-medium text-content">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(wajib diisi)</span>
        </label>

        <input
            :id="id"
            :type="type"
            :value="modelValue"
            :required="required"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :autofocus="autofocus"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="describedBy"
            class="h-11 w-full rounded-lg border bg-surface px-3 text-base text-content
                   transition-colors duration-200 placeholder:text-content-tertiary
                   focus:border-accent focus:outline-none
                   disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:opacity-60"
            :class="[
                error ? 'border-danger' : 'border-line-control hover:border-content-tertiary',
                mono ? 'font-mono tabular tracking-wide' : '',
            ]"
            @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >

        <!-- Galat ditempatkan TEPAT DI BAWAH field terkait, bukan dikumpulkan
             di atas halaman (WCAG error-placement). -->
        <p v-if="error" :id="`${id}-error`" role="alert"
           class="mt-1.5 text-sm font-medium text-danger">{{ error }}</p>
        <p v-else-if="hint" :id="`${id}-hint`"
           class="mt-1.5 text-sm text-content-tertiary">{{ hint }}</p>
    </div>
</template>
