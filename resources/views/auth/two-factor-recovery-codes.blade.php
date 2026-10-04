@extends('layouts.app')
@section('title', 'Kode Pemulihan')

@section('content')
<div class="sid-container--narrow">
    <div class="sid-card">
        <h2 class="sid-card__title">Verifikasi dua langkah aktif</h2>

        <x-alert type="danger" title="Simpan kode ini sekarang">
            Kode berikut <strong>hanya ditampilkan satu kali</strong>. Sistem hanya
            menyimpan bentuk teracaknya, sehingga tidak dapat menampilkannya lagi.
            Catat di tempat aman — bukan di ponsel yang sama dengan aplikasi
            autentikator Anda.
        </x-alert>

        <div class="sid-codes">
            @foreach ($codes as $code)
                <span>{{ $code }}</span>
            @endforeach
        </div>

        <p class="sid-hint sid-mt-4">
            Setiap kode hanya dapat dipakai sekali, dan dipakai hanya bila ponsel Anda
            hilang atau aplikasi autentikator terhapus.
        </p>

        <a href="{{ route('dashboard') }}" class="sid-btn sid-btn--primary sid-btn--block">
            Saya sudah menyimpannya — lanjutkan
        </a>
    </div>
</div>
@endsection
