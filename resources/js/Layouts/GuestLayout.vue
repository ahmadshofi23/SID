<script setup lang="ts">
import { Landmark, Sun, Moon } from '@lucide/vue'
import { usePageData } from '@/composables/usePage'
import { useTheme } from '@/composables/useTheme'
import FlashMessages from '@/Components/FlashMessages.vue'

defineProps<{ title: string; subtitle?: string }>()

const { village } = usePageData()
const { theme, toggle } = useTheme()
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-background text-content">
        <FlashMessages />

        <button
            type="button"
            class="fixed right-4 top-4 flex size-10 items-center justify-center rounded-lg
                   text-content-secondary transition-colors hover:bg-surface-hover cursor-pointer"
            :aria-label="theme === 'dark' ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'"
            @click="toggle"
        >
            <Sun v-if="theme === 'dark'" class="size-5" aria-hidden="true" />
            <Moon v-else class="size-5" aria-hidden="true" />
        </button>

        <main class="flex flex-1 flex-col items-center justify-center px-4 py-10">
            <div class="mb-7 text-center animate-rise">
                <span class="mx-auto mb-3 flex size-14 items-center justify-center rounded-2xl bg-accent">
                    <Landmark class="size-7 text-content-inverse" aria-hidden="true" />
                </span>
                <h1 class="text-xl font-semibold">Desa {{ village.name }}</h1>
                <p class="mt-0.5 text-sm text-content-tertiary">
                    Kec. {{ village.district }}, Kab. {{ village.regency }}
                </p>
            </div>

            <div class="card w-full max-w-md p-6 animate-rise sm:p-7">
                <h2 class="text-lg font-semibold">{{ title }}</h2>
                <p v-if="subtitle" class="mt-1 text-sm text-content-secondary">{{ subtitle }}</p>

                <div class="mt-6">
                    <slot />
                </div>
            </div>

            <p class="mt-6 text-center text-sm text-content-tertiary">
                Sistem Informasi Desa ·
                <a href="/" class="underline hover:text-content-secondary">Halaman utama</a>
            </p>
        </main>
    </div>
</template>
