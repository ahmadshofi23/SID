<script setup lang="ts">
import { computed } from 'vue'
import TextField from './TextField.vue'
import TextareaField from './TextareaField.vue'
import SelectField from './SelectField.vue'
import MultiSelectField from './MultiSelectField.vue'
import ResidentPicker from './ResidentPicker.vue'
import type { LetterFormField, LetterFormValue, LetterFormValues } from '@/types'

const props = defineProps<{
    fields: LetterFormField[]
    /** Model bersama: kunci = nama field. */
    modelValue: LetterFormValues
    /** Galat dari Laravel, berkunci `form.<nama>`. */
    errors?: Record<string, string>
}>()

const emit = defineEmits<{ 'update:modelValue': [value: LetterFormValues] }>()

function set(name: string, value: LetterFormValue): void {
    emit('update:modelValue', { ...props.modelValue, [name]: value })
}

function errorFor(name: string): string | undefined {
    return props.errors?.[`form.${name}`]
}

function asString(name: string): string {
    const value = props.modelValue[name]

    return value === null || value === undefined ? '' : String(value)
}

/*
 * Pilihan "Lainnya" yang dapat diisi bebas (`allow_other`).
 *
 * Nilai yang tidak ada di daftar pilihan berarti petugas sudah menuliskan
 * keperluan sendiri, jadi kotak teksnya harus tetap terbuka saat formulir
 * dimuat ulang setelah galat validasi — kalau tidak, apa yang sudah ditulis
 * tampak hilang.
 */
function isOtherActive(field: LetterFormField): boolean {
    if (field.allow_other !== true) return false

    const value = asString(field.name)

    return value !== '' && !(field.options ?? []).includes(value)
}

/*
 * Nilai multiselect SELALU dikembalikan sebagai array.
 *
 * Validator sisi server memberlakukan aturan `array` untuk tipe ini
 * (DynamicFormValidator), sehingga mengirim string akan menggagalkan
 * pengajuan — misalnya daftar anggota keluarga yang ikut pindah pada SKP.
 */
function asArray(name: string): string[] {
    const value = props.modelValue[name]

    if (Array.isArray(value)) return value.map(String)

    return value === null || value === undefined || value === '' ? [] : [String(value)]
}

function selectOptions(field: LetterFormField) {
    return (field.options ?? []).map((o) => ({ value: o, label: o }))
}

/* Field lebar penuh: isian panjang tidak boleh dipaksa ke satu kolom. */
const fullWidth = (field: LetterFormField): boolean =>
    field.type === 'textarea' || field.type === 'resident' || field.type === 'multiselect'

const layout = computed(() => props.fields)
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <template v-for="field in layout" :key="field.name">
            <div :class="fullWidth(field) ? 'sm:col-span-2' : ''">
                <ResidentPicker
                    v-if="field.type === 'resident'"
                    :model-value="asString(field.name) || null"
                    :label="field.label"
                    :required="field.required"
                    :error="errorFor(field.name)"
                    :hint="field.hint"
                    @update:model-value="(v) => set(field.name, v)"
                />

                <TextareaField
                    v-else-if="field.type === 'textarea'"
                    :model-value="asString(field.name)"
                    :label="field.label"
                    :required="field.required"
                    :error="errorFor(field.name)"
                    :hint="field.hint"
                    :maxlength="field.max ?? 2000"
                    :rows="4"
                    @update:model-value="(v) => set(field.name, v)"
                />

                <template v-else-if="field.type === 'select'">
                    <SelectField
                        :model-value="isOtherActive(field) ? 'Lainnya' : asString(field.name)"
                        :label="field.label"
                        :required="field.required"
                        :error="errorFor(field.name)"
                        :hint="field.hint"
                        :options="selectOptions(field)"
                        @update:model-value="(v) => set(field.name, v === 'Lainnya' && field.allow_other ? '' : v)"
                    />
                    <TextField
                        v-if="isOtherActive(field) || (field.allow_other && asString(field.name) === 'Lainnya')"
                        :model-value="isOtherActive(field) ? asString(field.name) : ''"
                        label="Tuliskan keperluannya"
                        class="mt-2"
                        :required="field.required"
                        @update:model-value="(v) => set(field.name, v)"
                    />
                </template>

                <MultiSelectField
                    v-else-if="field.type === 'multiselect'"
                    :model-value="asArray(field.name)"
                    :label="field.label"
                    :required="field.required"
                    :error="errorFor(field.name)"
                    :hint="field.hint"
                    :options="field.options"
                    allow-custom
                    @update:model-value="(v) => set(field.name, v)"
                />

                <label
                    v-else-if="field.type === 'boolean'"
                    class="flex h-11 items-center gap-2.5 text-sm"
                >
                    <input
                        type="checkbox"
                        :checked="Boolean(modelValue[field.name])"
                        class="size-4 rounded border-line-control"
                        @change="set(field.name, ($event.target as HTMLInputElement).checked)"
                    >
                    {{ field.label }}
                </label>

                <TextField
                    v-else
                    :model-value="asString(field.name)"
                    :label="field.label"
                    :required="field.required"
                    :error="errorFor(field.name)"
                    :hint="field.hint"
                    :type="field.type === 'date' ? 'date'
                        : field.type === 'time' ? 'time'
                        : field.type === 'number' || field.type === 'currency' ? 'number'
                        : 'text'"
                    :inputmode="field.type === 'nik' || field.type === 'currency'
                        || field.type === 'number' ? 'numeric' : 'text'"
                    :mono="field.type === 'nik'"
                    @update:model-value="(v) => set(field.name, v)"
                />
            </div>
        </template>
    </div>
</template>
