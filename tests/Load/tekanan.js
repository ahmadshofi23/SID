/*
 * UJI TEKANAN — naikkan beban sampai gagal.
 * docs/09-testing-qa.md §4.5 baris "Tekanan"; NFR-PERF-06 ("uji sampai 200").
 *
 * KRITERIA LULUS: "degradasi bertahap, bukan macet total; pulih otomatis".
 *
 * Itu BUKAN kriteria tentang p95, dan karena itu skrip ini SENGAJA tidak
 * punya `thresholds` waktu respons. Yang dibaca dari hasilnya ada tiga:
 *
 *   1. Apakah waktu respons NAIK MULUS seiring beban, atau melompat ke
 *      puluhan detik sekaligus. Lompatan menandakan antrean yang tidak
 *      berbatas — satu permintaan lambat menahan semua yang di belakangnya.
 *   2. Apakah galat yang muncul adalah PENOLAKAN CEPAT (503/429, server masih
 *      menjawab) atau PUTUS SAMBUNGAN (server berhenti menjawab sama sekali).
 *      Yang pertama adalah degradasi bertahap; yang kedua adalah macet total.
 *   3. Apakah setelah beban turun kembali ke 10 VU, waktu responsnya kembali
 *      ke angka semula TANPA campur tangan — itulah "pulih otomatis". Tahap
 *      `pemulihan` di bawah ada khusus untuk menjawab pertanyaan ini, dan ia
 *      adalah satu-satunya alasan skrip ini tidak berhenti di puncak.
 */

import { sleep } from 'k6';
import { baca, acak } from './pustaka.js';

const data = JSON.parse(open('./data-uji.json'));

export const options = {
  scenarios: {
    tangga: {
      executor: 'ramping-vus',
      exec: 'tekan',
      startVUs: 10,
      /*
       * Tangga, bukan lereng. Setiap tingkat ditahan 1 menit agar waktu
       * responsnya sempat stabil; beban yang naik terus-menerus hanya
       * menghasilkan satu garis miring yang tidak dapat dibaca sebagai
       * "pada beban berapa ia mulai memburuk".
       */
      stages: [
        { duration: '20s', target: 10 },
        { duration: '1m', target: 10 },
        { duration: '20s', target: 50 },
        { duration: '1m', target: 50 },
        { duration: '20s', target: 100 },
        { duration: '1m', target: 100 },
        { duration: '20s', target: 200 },
        { duration: '1m', target: 200 },
        { duration: '20s', target: 400 },
        { duration: '1m', target: 400 },
        // Pemulihan — lihat keterangan kepala berkas, butir 3.
        { duration: '20s', target: 10 },
        { duration: '1m', target: 10 },
      ],
      gracefulRampDown: '10s',
    },
  },

  /*
   * TANPA `thresholds`, dan itu pilihan sadar.
   *
   * Pada tekanan penuh server BOLEH menolak permintaan; yang dinilai
   * kriteria §4.5 adalah BENTUK kurvanya, bukan satu angka yang dapat
   * dibandingkan dengan ambang. Threshold apa pun di sini akan membuat uji
   * ini "gagal" tepat ketika ia berhasil menunjukkan apa yang seharusnya
   * ditunjukkan — titik di mana aplikasi kehabisan kapasitas.
   *
   * Penilaiannya karena itu dilakukan manusia atas hasil-tekanan.json,
   * dengan tiga pertanyaan di kepala berkas ini.
   */
};

export function tekan() {
  /*
   * Hanya halaman BACA, dan campurannya tetap seperti uji beban.
   *
   * Penulisan tidak dimasukkan dengan sengaja: 400 VU yang menulis tindak
   * lanjut akan menghasilkan ratusan ribu baris `complaint_actions` dan
   * mengubah basis datanya di tengah pengukuran, sehingga tingkat terakhir
   * tangga berjalan di atas tabel yang berbeda dari tingkat pertama.
   * Pertanyaan uji ini adalah bagaimana server berperilaku saat kehabisan
   * kapasitas, dan itu dapat dijawab penuh dengan pembacaan.
   */
  baca('/', 'portal.beranda');
  baca('/statistik', 'portal.statistik');
  baca(
    '/kependudukan/penduduk?cari=' + encodeURIComponent(acak(data.kata_pencarian)),
    'penduduk.cari-anonim',
  );

  sleep(1);
}

export function handleSummary(hasil) {
  const d = hasil.metrics.http_req_duration?.values;
  const baris = [
    '',
    '=========== RINGKASAN UJI TEKANAN (docs/09 §4.5) ===========',
    `Permintaan : ${hasil.metrics.http_reqs?.values.count}`,
    `Gagal      : ${((hasil.metrics.http_req_failed?.values.rate || 0) * 100).toFixed(2)} %`,
    `Durasi     : median ${d?.med?.toFixed(0)} ms · p95 ${d?.['p(95)']?.toFixed(0)} ms · maks ${d?.max?.toFixed(0)} ms`,
    '',
    'Baca hasil rinci di tests/Load/hasil-tekanan.json dan bandingkan',
    'waktu respons antar tingkat tangga (10/50/100/200/400 VU) serta tahap',
    'pemulihan terakhir — kriteria §4.5 adalah bentuk kurvanya, bukan angkanya.',
    '============================================================',
    '',
  ].join('\n');

  return {
    stdout: baris,
    'tests/Load/hasil-tekanan.json': JSON.stringify(hasil, null, 2),
  };
}
