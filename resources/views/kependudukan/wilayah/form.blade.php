@extends('layouts.admin')
@section('title', $region ? 'Ubah Wilayah' : 'Tambah Wilayah')

@section('content')
<nav class="sid-breadcrumb" aria-label="Remah roti">
    <a href="{{ route('wilayah.index') }}">Wilayah</a> › {{ $region ? $region->name : 'Tambah' }}
</nav>

<h1>{{ $region ? 'Ubah '.$region->name : 'Tambah wilayah' }}</h1>

<form method="POST" action="{{ $region ? route('wilayah.update', $region) : route('wilayah.store') }}">
    @csrf
    @if ($region) @method('PUT') @endif

    <fieldset class="sid-fieldset">
        <legend>Data wilayah</legend>

        <div class="sid-form-grid">
            <x-select name="level" label="Tingkat" :required="true"
                      :options="['dusun' => 'Dusun', 'rw' => 'RW', 'rt' => 'RT']"
                      :selected="$region?->level"
                      hint="Dusun berada di tingkat teratas dan tidak punya induk." />

            <x-select name="parent_id" label="Wilayah induk"
                      :options="['Dusun' => $parents['dusun'], 'RW' => $parents['rw']]"
                      :selected="$region?->parent_id"
                      :grouped="true"
                      placeholder="— Tidak ada (untuk Dusun) —"
                      hint="RW memilih Dusun; RT memilih RW." />

            <x-field name="code" label="Kode" :required="true" :value="$region?->code"
                     hint="Contoh: 001. Harus unik dalam satu induk." />
            <x-field name="name" label="Nama wilayah" :required="true" :value="$region?->name"
                     hint="Contoh: Dusun Krajan, RW 02, RT 003." />
            <x-field name="head_name" label="Nama kepala/ketua wilayah" :value="$region?->head_name" />
            <x-field name="head_phone" label="Nomor telepon" type="tel" :value="$region?->head_phone" />
        </div>
    </fieldset>

    @if ($region)
        <x-alert type="warning" title="Perubahan ini mempengaruhi hak akses">
            Mengubah induk atau kode wilayah akan menghitung ulang jalur wilayah
            beserta seluruh turunannya — dan dengan itu, data siapa saja yang dapat
            dilihat Kepala Dusun.
        </x-alert>
    @endif

    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="sid-btn sid-btn--primary">
            {{ $region ? 'Simpan perubahan' : 'Simpan wilayah' }}
        </button>
        <a href="{{ route('wilayah.index') }}" class="sid-btn sid-btn--secondary">Batal</a>
    </div>
</form>
@endsection
