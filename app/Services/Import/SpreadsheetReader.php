<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Pembaca berkas impor (.xlsx / .csv).
 *
 * Membaca secara BERURUTAN lewat Generator, bukan memuat seluruh berkas ke
 * memori. Berkas 20.000 baris yang dimuat sekaligus dapat menghabiskan ratusan
 * megabita — di VPS 4 GB milik desa, itu berarti proses impor mematikan
 * aplikasi yang sedang dipakai petugas lain.
 */
final class SpreadsheetReader
{
    /**
     * @return Generator<int, list<string>> nomor baris (mulai 1) => sel
     */
    public function rows(string $absolutePath): Generator
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->readCsv($absolutePath),
            'xlsx', 'xls' => $this->readSpreadsheet($absolutePath),
            default => throw new RuntimeException(
                "Format berkas .{$extension} tidak didukung. Gunakan .xlsx atau .csv."
            ),
        };
    }

    /** @return Generator<int, list<string>> */
    private function readCsv(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Berkas tidak dapat dibuka.');
        }

        try {
            // Buang BOM UTF-8 bila ada — Excel menambahkannya saat menyimpan
            // CSV, dan tanpa dibuang judul kolom pertama tidak akan cocok.
            $first = fgets($handle);

            if ($first !== false) {
                rewind($handle);

                if (str_starts_with($first, "\xEF\xBB\xBF")) {
                    fseek($handle, 3);
                }
            }

            $lineNumber = 0;

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;

                yield $lineNumber => array_map(
                    fn ($cell): string => trim((string) $cell),
                    $row,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, list<string>> */
    private function readSpreadsheet(string $path): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $reader->setReadEmptyCells(false);

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $lineNumber = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $lineNumber++;
            $cells = [];

            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                $value = $cell->getFormattedValue();
                $cells[] = trim((string) $value);
            }

            yield $lineNumber => $cells;
        }

        $spreadsheet->disconnectWorksheets();
    }
}
