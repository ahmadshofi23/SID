@extends('layouts.admin')
@section('title', $resident ? 'Ubah Data Penduduk' : 'Tambah Penduduk')

@section('content')
<nav class="sid-breadcrumb" aria-label="Remah roti">
    <a href="{{ route('penduduk.index') }}">Data Penduduk</a> ›
    {{ $resident ? $resident->full_name : 'Tambah' }}
</nav>

<h1>{{ $resident ? 'Ubah data '.$resident->full_name : 'Tambah penduduk' }}</h1>

<form method="POST"
      action="{{ $resident ? route('penduduk.update', $resident->id) : route('penduduk.store') }}">
    @csrf
    @if ($resident) @method('PUT') @endif

    <fieldset class="sid-fieldset">
        <legend>Identitas</legend>
        <div class="sid-form-grid">
            @if ($resident)
                {{-- NIK tidak di-prefill: nilai aslinya tidak boleh masuk ke HTML
                     halaman (RULE-07). Dibiarkan kosong = tidak diubah. --}}
                <x-field name="nik" label="NIK" inputmode="numeric"
                         hint="NIK saat ini: {{ $resident->nik_masked }}. Biarkan kosong bila tidak ingin mengubahnya." />
            @else
                <x-field name="nik" label="NIK" :required="true" inputmode="numeric"
                         hint="16 angka sesuai KTP." />
            @endif
            <x-field name="full_name" label="Nama lengkap" :required="true"
                     :value="$resident?->full_name"
                     hint="Tulis sesuai dokumen resmi, termasuk gelar bila ada." />
            <x-field name="birth_place" label="Tempat lahir" :value="$resident?->birth_place" />
            <x-field name="birth_date" label="Tanggal lahir" type="date" :required="true"
                     :value="$resident?->birth_date?->format('Y-m-d')" />

            <x-select name="gender" label="Jenis kelamin" :required="true"
                      :options="['L' => 'Laki-laki', 'P' => 'Perempuan']"
                      :selected="$resident?->gender" />
            <x-select name="religion_code" label="Agama"
                      :options="$references['religions']" :selected="$resident?->religion_code" />
            <x-select name="marital_status_code" label="Status perkawinan"
                      :options="$references['marital_statuses']"
                      :selected="$resident?->marital_status_code" />
            <x-select name="blood_type_code" label="Golongan darah"
                      :options="$references['blood_types']" :selected="$resident?->blood_type_code" />
        </div>
    </fieldset>

    <fieldset class="sid-fieldset">
        <legend>Keluarga &amp; wilayah</legend>
        <div class="sid-form-grid">
            <x-select name="family_id" label="Kartu keluarga"
                      :options="collect($families)->mapWithKeys(fn ($f) => [
                          $f->id => $f->kk_number_masked.' — '.$f->address
                      ])->all()"
                      :selected="$selectedFamilyId"
                      placeholder="— Belum tergabung —"
                      hint="Belum ada? Buat kartu keluarga lebih dulu." />

            <x-select name="family_relation_code" label="Hubungan dalam keluarga" :required="true"
                      :options="$references['family_relations']"
                      :selected="$resident?->family_relation_code" />

            <x-select name="region_id" label="Wilayah (RT)"
                      :options="$regions" :selected="$resident?->region_id" />

            <x-field name="address" label="Alamat" :value="$resident?->address" />
        </div>
    </fieldset>

    <fieldset class="sid-fieldset">
        <legend>Pendidikan, pekerjaan &amp; kontak</legend>
        <div class="sid-form-grid">
            <x-select name="education_code" label="Pendidikan"
                      :options="$references['educations']" :selected="$resident?->education_code" />
            <x-select name="occupation_code" label="Pekerjaan"
                      :options="$references['occupations']" :selected="$resident?->occupation_code" />
            <x-field name="father_name" label="Nama ayah" :value="$resident?->father_name" />
            <x-field name="mother_name" label="Nama ibu" :value="$resident?->mother_name" />
            <x-field name="phone" label="Nomor telepon" type="tel" inputmode="tel"
                     :value="$resident?->phone" />
            <x-field name="email" label="Email" type="email" :value="$resident?->email" />
            <x-select name="disability_code" label="Disabilitas"
                      :options="$references['disabilities']" :selected="$resident?->disability_code"
                      placeholder="— Tidak ada —" />
        </div>

        <div class="sid-field sid-checkbox">
            <input type="checkbox" id="is_poor" name="is_poor" value="1"
                   @checked(old('is_poor', $resident?->is_poor))>
            <label for="is_poor">Termasuk keluarga tidak mampu</label>
        </div>

        <div class="sid-field">
            <label class="sid-label" for="notes">Catatan</label>
            <textarea id="notes" name="notes" class="sid-textarea"
                      maxlength="2000">{{ old('notes', $resident?->notes) }}</textarea>
        </div>
    </fieldset>

    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="sid-btn sid-btn--primary">
            {{ $resident ? 'Simpan perubahan' : 'Simpan penduduk' }}
        </button>
        <a href="{{ $resident ? route('penduduk.show', $resident->id) : route('penduduk.index') }}"
           class="sid-btn sid-btn--secondary">Batal</a>
    </div>
</form>
@endsection
