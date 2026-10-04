@extends('layouts.admin')
@section('title', $family ? 'Ubah Kartu Keluarga' : 'Tambah Kartu Keluarga')

@section('content')
<nav class="sid-breadcrumb" aria-label="Remah roti">
    <a href="{{ route('keluarga.index') }}">Kartu Keluarga</a> ›
    {{ $family ? 'Ubah' : 'Tambah' }}
</nav>

<h1>{{ $family ? 'Ubah kartu keluarga' : 'Tambah kartu keluarga' }}</h1>

<form method="POST"
      action="{{ $family ? route('keluarga.update', $family->id) : route('keluarga.store') }}">
    @csrf
    @if ($family) @method('PUT') @endif

    <fieldset class="sid-fieldset">
        <legend>Data kartu keluarga</legend>
        <div class="sid-form-grid">
            @if ($family)
                <x-field name="kk_number" label="Nomor Kartu Keluarga" inputmode="numeric"
                         hint="Saat ini: {{ $family->kk_number_masked }}. Biarkan kosong bila tidak diubah." />
            @else
                <x-field name="kk_number" label="Nomor Kartu Keluarga" :required="true"
                         inputmode="numeric" hint="16 angka sesuai Kartu Keluarga." />
            @endif

            <x-select name="region_id" label="Wilayah (RT)" :required="true"
                      :options="$regions" :selected="$family?->region_id" />

            <x-field name="address" label="Alamat" :required="true" :value="$family?->address" />
            <x-field name="rt" label="RT" :value="$family?->rt" />
            <x-field name="rw" label="RW" :value="$family?->rw" />
            <x-field name="postal_code" label="Kode pos" :value="$family?->postal_code" />

            <x-select name="welfare_status_code" label="Status kesejahteraan"
                      :options="$references['welfare_statuses']"
                      :selected="$family?->welfare_status_code" />
            <x-select name="house_ownership_code" label="Status kepemilikan rumah"
                      :options="$references['house_ownerships']"
                      :selected="$family?->house_ownership_code" />
        </div>
    </fieldset>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="sid-btn sid-btn--primary">
            {{ $family ? 'Simpan perubahan' : 'Simpan kartu keluarga' }}
        </button>
        <a href="{{ $family ? route('keluarga.show', $family->id) : route('keluarga.index') }}"
           class="sid-btn sid-btn--secondary">Batal</a>
    </div>
</form>
@endsection
