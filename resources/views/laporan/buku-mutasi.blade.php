{{-- Buku Mutasi Penduduk — FR-LAP-06, Permendagri 47/2016. --}}
@extends('laporan._buku')

@section('tabel')
    <table>
        <thead>
            <tr>
                <th style="width: 10mm">No</th>
                <th style="width: 28mm">Tanggal</th>
                <th style="width: 32mm">Jenis Mutasi</th>
                <th style="width: 32mm">NIK</th>
                <th style="width: 55mm">Nama Lengkap</th>
                <th style="width: 50mm">Alamat Wilayah</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($baris as $b)
                <tr>
                    <td class="no">{{ $b['no'] }}</td>
                    <td>{{ $b['tanggal'] }}</td>
                    <td>{{ $b['jenis'] }}</td>
                    <td class="mono">{{ $b['nik'] }}</td>
                    <td>{{ $b['nama'] }}</td>
                    <td>{{ $b['wilayah'] }}</td>
                    <td>{{ $b['keterangan'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
