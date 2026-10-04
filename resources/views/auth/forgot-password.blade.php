@extends('layouts.guest')
@section('title', 'Lupa Kata Sandi')

@section('content')
<div class="sid-card sid-card--auth">
    <h2 class="sid-card__title">Lupa kata sandi</h2>
    <p class="sid-card__hint">
        Masukkan email atau nomor ponsel yang terdaftar. Kami akan mengirimkan
        tautan untuk mengatur ulang kata sandi Anda.
    </p>

    <form method="POST" action="{{ route('password.email') }}" class="sid-mt-4">
        @csrf
        <x-field name="identifier" label="Email atau nomor ponsel"
                 :required="true" autocomplete="username" :autofocus="true" />
        <button type="submit" class="sid-btn sid-btn--primary sid-btn--block">
            Kirim tautan
        </button>
        <p class="sid-center sid-mt-4 sid-mb-0">
            <a href="{{ route('login') }}">Kembali ke halaman masuk</a>
        </p>
    </form>
</div>
@endsection
