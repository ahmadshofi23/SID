@extends('layouts.app')
@section('title', $title)

@section('content')
<h1>{{ $title }}</h1>

<x-alert type="info" title="Dijadwalkan pada {{ $sprint }}">
    Halaman ini sengaja masih kosong. Rutenya sudah ada agar hak akses dan
    pembatasan wilayah dapat diuji sejak Sprint 1 — isinya dibangun pada
    {{ $sprint }} sesuai docs/11-roadmap-estimasi.md §2.
</x-alert>

<a href="{{ route('dashboard') }}" class="sid-btn sid-btn--secondary">
    Kembali ke dashboard
</a>
@endsection
