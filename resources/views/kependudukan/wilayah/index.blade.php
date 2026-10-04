@extends('layouts.admin')
@section('title', 'Wilayah')

@section('content')
<div class="sid-page-head">
    <div>
        <h1>Wilayah</h1>
        <p class="sid-muted sid-mb-0">Struktur Dusun → RW → RT.</p>
    </div>
    @can('wilayah.kelola')
        <a href="{{ route('wilayah.create') }}" class="sid-btn sid-btn--primary">+ Tambah Wilayah</a>
    @endcan
</div>

<x-alert type="info" title="Mengapa struktur ini penting">
    Wilayah bukan sekadar label alamat. Ia menentukan <strong>data siapa yang dapat
    dilihat Kepala Dusun</strong>. Memindahkan atau mengubah wilayah langsung
    mengubah hak akses — periksa kembali sebelum menyimpan.
</x-alert>

@if ($tree->isEmpty())
    <div class="sid-empty">
        <div class="sid-empty__icon" aria-hidden="true">🗺</div>
        <h2>Belum ada wilayah</h2>
        <p class="sid-muted">Mulai dengan menambahkan dusun, lalu RW, lalu RT.</p>
        @can('wilayah.kelola')
            <a href="{{ route('wilayah.create') }}" class="sid-btn sid-btn--primary">+ Tambah Dusun</a>
        @endcan
    </div>
@else
    <div class="sid-card">
        <ul class="sid-tree">
            @foreach ($tree as $dusun)
                <li>
                    <div class="sid-tree__row">
                        <span class="sid-badge">Dusun</span>
                        <span class="sid-tree__name">{{ $dusun->name }}</span>
                        @if ($dusun->head_name)
                            <span class="sid-muted sid-text-sm">Kepala: {{ $dusun->head_name }}</span>
                        @endif
                        @can('wilayah.kelola')
                            <a href="{{ route('wilayah.edit', $dusun) }}" class="sid-btn sid-btn--text">Ubah</a>
                        @endcan
                    </div>

                    @if ($dusun->children->isNotEmpty())
                        <ul>
                            @foreach ($dusun->children as $rw)
                                <li>
                                    <div class="sid-tree__row">
                                        <span class="sid-badge">RW</span>
                                        <span class="sid-tree__name">{{ $rw->name }}</span>
                                        @can('wilayah.kelola')
                                            <a href="{{ route('wilayah.edit', $rw) }}" class="sid-btn sid-btn--text">Ubah</a>
                                        @endcan
                                    </div>

                                    @if ($rw->children->isNotEmpty())
                                        <ul>
                                            @foreach ($rw->children as $rt)
                                                <li>
                                                    <div class="sid-tree__row">
                                                        <span class="sid-badge">RT</span>
                                                        <span class="sid-tree__name">{{ $rt->name }}</span>
                                                        @if ($rt->head_name)
                                                            <span class="sid-muted sid-text-sm">
                                                                Ketua: {{ $rt->head_name }}
                                                            </span>
                                                        @endif
                                                        @can('wilayah.kelola')
                                                            <a href="{{ route('wilayah.edit', $rt) }}"
                                                               class="sid-btn sid-btn--text">Ubah</a>
                                                            <x-confirm-form
                                                                :action="route('wilayah.destroy', $rt)"
                                                                title="Hapus {{ $rt->name }}?"
                                                                body="Wilayah hanya dapat dihapus bila tidak dihuni penduduk dan tidak memiliki wilayah di bawahnya."
                                                                confirmLabel="Ya, hapus wilayah"
                                                                triggerLabel="Hapus"
                                                                triggerClass="sid-btn sid-btn--text"
                                                                :reasonRequired="false" />
                                                        @endcan
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
