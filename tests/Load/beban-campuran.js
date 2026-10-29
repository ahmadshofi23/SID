/*
 * UJI BEBAN — 50 pengguna bersamaan, campuran baca/tulis realistis.
 * docs/09-testing-qa.md §4.5 baris "Beban"; NFR-PERF-01/02/06.
 *
 * KRITERIA LULUS (ditegakkan `thresholds` di bawah, bukan dibaca manusia):
 *   p95 baca  ≤ 500 ms
 *   p95 tulis ≤ 1.000 ms
 *   galat     < 0,1%
 *
 * KOMPOSISI BEBAN, DAN MENGAPA BUKAN RATA
 * ---------------------------------------
 * Pembagian pengguna meniru satu hari kerja di desa, bukan pembagian yang
 * rapi: portal publik dibaca jauh lebih sering daripada panel petugas,
 * karena warga membukanya dari ponsel sementara petugas desa berjumlah
 * belasan orang. Beban yang rata di antara semua halaman akan menyembunyikan
 * halaman yang benar-benar dipakai di balik halaman yang hampir tidak pernah
 * dibuka.
 *
 *   30 VU  warga/anonim  — portal, statistik, berita, layanan, lacak
 *   15 VU  petugas baca  — dashboard, daftar & pencarian penduduk,
 *                          antrean surat, antrean pengaduan, log aktivitas
 *    5 VU  petugas tulis — tindak lanjut pengaduan (+ kiriman publik)
 *
 * CARA JALAN — lihat tests/Load/README.md.
 */

import { sleep } from 'k6';
import { BASE, baca, tulis, masuk, acak } from './pustaka.js';

const data = JSON.parse(open('./data-uji.json'));

const DURASI = __ENV.DURASI || '30m';
const SANDI = __ENV.SANDI_DEMO || 'kata-sandi-demo-2026';

export const options = {
  scenarios: {
    warga: {
      executor: 'constant-vus',
      exec: 'wargaAnonim',
      vus: 30,
      duration: DURASI,
    },
    petugas_baca: {
      executor: 'constant-vus',
      exec: 'petugasBaca',
      vus: 15,
      duration: DURASI,
    },
    petugas_tulis: {
      executor: 'constant-vus',
      exec: 'petugasTulis',
      vus: 5,
      duration: DURASI,
    },
  },

  thresholds: {
    'sid_baca_ms': ['p(95)<500'],
    'sid_tulis_ms': ['p(95)<1000'],
    'sid_galat': ['rate<0.001'],
  },

  /*
   * Aset statis TIDAK diminta.
   *
   * Yang diuji di sini adalah waktu respons API/halaman (NFR-PERF-01/02).
   * Berkas JS/CSS dilayani server statis dan di-cache peramban; memintanya
   * ulang pada setiap iterasi akan menenggelamkan p95 halaman di bawah
   * ratusan permintaan berkas yang selalu cepat, sehingga angkanya terlihat
   * jauh lebih baik daripada yang dirasakan pengguna. Ukuran transfer
   * halaman pertama (NFR-PERF-04) diuji terpisah dengan Lighthouse.
   */
  discardResponseBodies: false,
  noVUConnectionReuse: false,
};

/* ------------------------- 1. Warga & publik ------------------------- */

export function wargaAnonim() {
  baca('/', 'portal.beranda');
  baca('/statistik', 'portal.statistik');
  baca('/berita', 'portal.berita');
  baca('/layanan', 'portal.layanan');
  baca('/pengumuman', 'portal.pengumuman');
  baca('/profil/wilayah', 'portal.wilayah');

  /*
   * Jeda 1–3 detik meniru orang yang membaca halamannya.
   *
   * Tanpa jeda, 30 VU menjadi 30 utas yang menekan server tanpa henti —
   * itu uji TEKANAN (tests/Load/tekanan.js), bukan uji beban 50 pengguna.
   * Angka p95 tanpa jeda akan selalu lebih buruk dan tidak dapat
   * dibandingkan dengan kriteria yang berbicara tentang "pengguna".
   */
  sleep(1 + Math.random() * 2);
}

/* ---------------------- 2. Petugas desa — baca ---------------------- */

export function petugasBaca() {
  if (__ITER === 0) {
    masuk('operator@teluksentosa.desa.id', SANDI);
  }

  baca('/dashboard', 'dashboard');
  baca('/kependudukan/penduduk', 'penduduk.daftar');

  const kata = acak(data.kata_pencarian);
  baca('/kependudukan/penduduk?cari=' + encodeURIComponent(kata), 'penduduk.cari');

  baca(
    '/kependudukan/penduduk?region_id=' + encodeURIComponent(acak(data.wilayah)),
    'penduduk.saring-wilayah',
  );

  baca('/surat', 'surat.antrean');
  baca('/pengaduan/antrean', 'pengaduan.antrean');
  baca('/kependudukan/statistik', 'statistik.internal');

  /*
   * Halaman Laporan setiap iterasi KEEMPAT, bukan setiap iterasi.
   *
   * Ia dibuka beberapa kali sehari (rekap untuk rapat atau kecamatan), bukan
   * terus-menerus seperti antrean kerja — memasukkannya sesering antrean akan
   * membuat p95 baca didominasi halaman yang paling jarang dibuka.
   *
   * Ia TETAP harus ada di dalam campuran: sampai Sprint 7 halaman inilah
   * satu-satunya yang melewati NFR-PERF-01 pada volume sasaran (755 ms,
   * KEP-019), dan ia melewatinya tanpa pernah terlihat karena basis data
   * pengembangan hanya berisi 500 penduduk. Campuran beban yang tidak
   * memuatnya akan luput lagi.
   */
  if (__ITER % 4 === 0) {
    baca('/laporan', 'laporan.index');
  }

  sleep(1 + Math.random() * 2);
}

/* --------------------- 3. Petugas desa — tulis ---------------------- */

export function petugasTulis() {
  if (__ITER === 0) {
    /*
     * Kasi Pemerintahan, BUKAN operator: `pengaduan.tindaklanjut` tidak
     * dimiliki Operator Desa (RULE-06 — entri data tanpa hak menyetujui).
     * Memakai operator di sini menghasilkan 403 pada setiap penulisan, dan
     * 403 yang konsisten mudah disalahartikan sebagai "penulisan cepat".
     */
    masuk('kasi.pem@teluksentosa.desa.id', SANDI);
  }

  const id = acak(data.pengaduan_terbuka);

  // Halaman detail dibaca lebih dulu — petugas tidak menulis tanpa membaca.
  baca('/pengaduan/antrean/' + id, 'pengaduan.detail');

  tulis(
    '/pengaduan/antrean/' + id + '/tindak-lanjut',
    {
      note: 'Dicek petugas pada iterasi uji beban ' + __ITER + ' (VU ' + __VU + ').',
      is_visible_to_reporter: '1',
    },
    'pengaduan.tindak-lanjut',
  );

  /*
   * Satu dari sepuluh iterasi mengirim pengaduan publik baru.
   *
   * Lebih sering tidak masuk akal DAN tidak dapat diukur: `throttle:5,60`
   * plus hitungan baris per IP di ComplaintService membatasi lima pengaduan
   * per jam per jaringan (FR-PGD-08). 429 karena itu diterima sebagai
   * jawaban yang benar, bukan galat — lihat pustaka.js.
   */
  if (__ITER % 10 === 0) {
    tulis(
      '/pengaduan',
      {
        category_code: 'infrastruktur',
        title: 'Jalan rusak (uji beban VU ' + __VU + ')',
        body: 'Pengaduan sintetis dari uji beban k6. Lihat docs/09 §4.5.',
        is_anonymous: '1',
        location_text: 'Dusun uji',
      },
      'pengaduan.kirim-publik',
      true,
    );
  }

  sleep(2 + Math.random() * 2);
}

export function handleSummary(data) {
  const p95baca = data.metrics.sid_baca_ms?.values['p(95)'];
  const p95tulis = data.metrics.sid_tulis_ms?.values['p(95)'];
  const rate = data.metrics.sid_galat?.values.rate;

  const ringkasan = [
    '',
    '=========== RINGKASAN UJI BEBAN (docs/09 §4.5) ===========',
    `Sasaran   : ${BASE}`,
    `Durasi    : ${DURASI}  (50 VU: 30 warga + 15 petugas baca + 5 petugas tulis)`,
    '',
    `p95 BACA  : ${p95baca?.toFixed(1)} ms   (batas 500 ms)   ${p95baca < 500 ? 'LULUS' : 'TIDAK LULUS'}`,
    `p95 TULIS : ${p95tulis?.toFixed(1)} ms   (batas 1000 ms)  ${p95tulis < 1000 ? 'LULUS' : 'TIDAK LULUS'}`,
    `Galat     : ${(rate * 100).toFixed(4)} %   (batas 0,1 %)    ${rate < 0.001 ? 'LULUS' : 'TIDAK LULUS'}`,
    '==========================================================',
    '',
  ].join('\n');

  return {
    stdout: ringkasan,
    'tests/Load/hasil-beban.json': JSON.stringify(data, null, 2),
  };
}
