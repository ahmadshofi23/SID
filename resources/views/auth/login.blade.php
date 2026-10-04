@extends('layouts.guest')
@section('title', 'Masuk')

@section('content')
<div class="sid-card sid-card--auth">
    <h2 class="sid-card__title">Masuk</h2>
    <p class="sid-card__hint">Gunakan email atau nomor ponsel yang terdaftar di kantor desa.</p>

    <form method="POST" action="{{ route('login') }}" class="sid-mt-4">
        @csrf

        <x-field
            name="identifier"
            label="Email atau nomor ponsel"
            :required="true"
            autocomplete="username"
            :autofocus="true"
            hint="Contoh: sekdes@desa.id atau 081234567890"
        />

        <div class="sid-field">
            <label class="sid-label" for="password">
                Kata sandi
                <span class="sid-label__required" aria-hidden="true">*</span>
                <span class="sid-sr-only">(wajib diisi)</span>
            </label>
            <input id="password" name="password" type="password" required
                   autocomplete="current-password"
                   class="sid-input @error('password') sid-input--invalid @enderror">
            @error('password')
                <p class="sid-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="sid-field sid-checkbox">
            <input type="checkbox" id="remember" name="remember" value="1">
            <label for="remember">Ingat saya di perangkat ini</label>
        </div>

        <button type="submit" class="sid-btn sid-btn--primary sid-btn--block">Masuk</button>

        <p class="sid-center sid-mt-4 sid-mb-0">
            <a href="{{ route('password.request') }}">Lupa kata sandi?</a>
        </p>
    </form>
</div>
@endsection
