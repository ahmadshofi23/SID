{{-- Buku Induk Penduduk — FR-LAP-06, Permendagri 47/2016. --}}
@extends('laporan._buku')

@section('tabel')
    <table>
        <thead>
            <tr>
                <th style="width: 10mm">No</th>
                <th style="width: 32mm">NIK</th>
                <th style="width: 48mm">Nama Lengkap</th>
                <th style="width: 30mm">Nomor KK</th>
                <th style="width: 10mm">L/P</th>
                <th style="width: 45mm">Tempat, Tanggal Lahir</th>
                <th style="width: 24mm">Agama</th>
                <th style="width: 28mm">Status Kawin</th>
                <th style="width: 32mm">Pekerjaan</th>
                <th>Alamat Wilayah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($baris as $b)
                <tr>
                    <td class="no">{{ $b['no'] }}</td>
                    <td class="mono">{{ $b['nik'] }}</td>
                    <td>{{ $b['nama'] }}</td>
                    <td class="mono">{{ $b['kk'] ?? '-' }}</td>
                    <td class="tengah">{{ $b['kelamin'] }}</td>
                    <td>{{ $b['lahir'] }}</td>
                    <td>{{ $b['agama'] }}</td>
                    <td>{{ $b['kawin'] }}</td>
                    <td>{{ $b['pekerjaan'] }}</td>
                    <td>{{ $b['wilayah'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
