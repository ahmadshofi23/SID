<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{
    icon: Component
    title: string
    description?: string
}>()
</script>

<template>
    <!-- Layar kosong tidak boleh menjadi jalan buntu: selalu ada penjelasan
         dan satu tindakan berikutnya (docs/07-ui-ux.md §4). -->
    <div class="card flex flex-col items-center px-6 py-16 text-center">
        <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-surface-hover">
            <component :is="icon" class="size-6 text-content-tertiary" aria-hidden="true" />
        </div>

        <h2 class="text-lg font-semibold text-content">{{ title }}</h2>
        <p v-if="description" class="mt-1.5 max-w-md text-sm text-content-secondary">
            {{ description }}
        </p>

        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
