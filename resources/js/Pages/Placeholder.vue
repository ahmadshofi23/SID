<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Construction } from '@lucide/vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EmptyState from '@/Components/EmptyState.vue'
import AppButton from '@/Components/AppButton.vue'

defineProps<{ title: string; sprint: string }>()
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <h1 class="mb-5 text-2xl font-semibold">{{ title }}</h1>

        <EmptyState
            :icon="Construction"
            :title="`Dijadwalkan pada ${sprint}`"
            description="Halaman ini sengaja masih kosong. Rutenya sudah ada agar hak akses dan pembatasan wilayah dapat diuji sejak awal — isinya dibangun sesuai roadmap."
        >
            <AppButton href="/dashboard" variant="secondary">Kembali ke dashboard</AppButton>
        </EmptyState>
    </AppLayout>
</template>
