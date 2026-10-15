<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { LoaderCircle } from '@lucide/vue'

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost'
type Size = 'sm' | 'md'

const props = withDefaults(defineProps<{
    variant?: Variant
    size?: Size
    href?: string
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
    /* Tautan biasa, bukan navigasi Inertia. Wajib untuk unduhan berkas:
       Link mengambil URL lewat XHR, dan peramban tidak akan pernah
       menyimpan berkasnya. */
    external?: boolean
}>(), {
    variant: 'secondary',
    size: 'md',
    type: 'button',
})

/* Target sentuh minimum 44px pada ukuran md — pengguna utama berusia 45+
   dan sering memakai ponsel di lapangan (WCAG touch-target-size). */
const sizeClass = computed(() => ({
    sm: 'h-9 px-3 text-sm gap-1.5',
    md: 'h-11 px-4 text-sm gap-2',
}[props.size]))

const variantClass = computed(() => ({
    primary: 'bg-accent text-content-inverse hover:bg-accent-hover shadow-sm',
    secondary: 'bg-surface text-content border border-line-control hover:bg-surface-hover',
    danger: 'bg-danger text-content-inverse hover:opacity-90 shadow-sm',
    ghost: 'text-content-secondary hover:bg-surface-hover hover:text-content',
}[props.variant]))

const classes = computed(() => [
    'inline-flex items-center justify-center rounded-lg font-medium cursor-pointer',
    'transition-colors duration-200',
    'disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none',
    sizeClass.value,
    variantClass.value,
    props.block ? 'w-full' : '',
])
</script>

<template>
    <a v-if="href && external" :href="href" :class="classes">
        <slot />
    </a>

    <Link v-else-if="href" :href="href" :class="classes">
        <slot />
    </Link>

    <button
        v-else
        :type="type"
        :class="classes"
        :disabled="disabled || loading"
        :aria-busy="loading || undefined"
    >
        <LoaderCircle v-if="loading" class="size-4 animate-spin" aria-hidden="true" />
        <slot />
    </button>
</template>
