<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { CircleCheck } from '@lucide/vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import AppButton from '@/Components/AppButton.vue'
</script>

<template>
    <Head title="Pendaftaran Terkirim" />

    <GuestLayout title="Pendaftaran terkirim">
        <!--
          HALAMAN INI SELALU SAMA, apa pun yang terjadi di belakangnya
          (FR-AUT-10, KEP-017): data cocok, tidak cocok, atau sudah pernah
          didaftarkan — ketiganya berakhir di sini. Karena itu isinya harus
          menyebutkan JALAN KELUAR untuk setiap kemungkinan, supaya warga
          yang sah tidak kehilangan arah hanya karena sistem tidak boleh
          memberitahunya apa yang salah.
        -->
        <div class="space-y-5">
            <div class="flex gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success-soft">
                    <CircleCheck class="size-5 text-success" aria-hidden="true" />
                </span>
                <p class="pt-2 text-content-secondary">
                    Pendaftaran Anda sudah kami terima.
                </p>
            </div>

            <div class="rounded-lg border border-line p-4 text-sm">
                <p class="font-medium">Yang terjadi selanjutnya</p>
                <ol class="mt-2 space-y-2 text-content-secondary">
                    <li>1. Petugas desa mencocokkan data Anda dengan data kependudukan.</li>
                    <li>2. Bila cocok, akun Anda diaktifkan — biasanya dalam satu hari kerja.</li>
                    <li>3. Setelah itu Anda dapat masuk dengan email atau nomor ponsel yang tadi diisi.</li>
                </ol>
            </div>

            <div class="rounded-lg border border-line p-4 text-sm">
                <p class="font-medium">Bila dua hari kerja berlalu dan Anda belum bisa masuk</p>
                <ul class="mt-2 space-y-2 text-content-secondary">
                    <li>
                        <strong class="text-content">Merasa pernah mendaftar sebelumnya?</strong>
                        Coba <Link href="/lupa-sandi" class="text-accent hover:underline">atur ulang kata sandi</Link>
                        — akun Anda mungkin sudah ada.
                    </li>
                    <li>
                        <strong class="text-content">Tidak yakin data Anda cocok?</strong>
                        Datang ke kantor desa membawa KTP dan Kartu Keluarga asli.
                        Petugas dapat memeriksa dan memperbaikinya langsung.
                    </li>
                </ul>
            </div>

            <p class="text-sm text-content-secondary">
                Perlu mengurus surat sekarang? Anda tetap dapat datang ke kantor desa
                tanpa akun, lalu memantau statusnya lewat
                <Link href="/surat/lacak" class="text-accent hover:underline">halaman pelacakan</Link>.
            </p>

            <AppButton href="/" variant="secondary" block>Kembali ke beranda</AppButton>
        </div>
    </GuestLayout>
</template>
