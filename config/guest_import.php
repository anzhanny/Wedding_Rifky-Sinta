<?php
declare(strict_types=1);

/**
 * Membaca daftar nama tamu dari file unggahan Excel (.xlsx) atau CSV.
 * Dipakai oleh admin/guests.php. Tidak ada library tambahan:
 * .xlsx dibaca langsung lewat ZipArchive + SimpleXML.
 */

const GUEST_XML_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

/**
 * Merapikan satu nama tamu: hapus karakter kontrol, rapikan spasi, batasi 150 karakter.
 */
function guest_clean_name(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';

    return mb_substr(trim($name), 0, 150);
}

/**
 * Dari baris-baris tabel ambil kolom nama tamu.
 * - Jika ada baris judul dengan kolom "Nama" / "Nama Tamu" / "Name", kolom itu dipakai.
 * - Jika tidak, kolom pertama (A) dipakai.
 *
 * @param array<int, array<int, string>> $rows Setiap baris: indeks kolom (0 = A) => teks
 * @return string[]
 */
function guest_names_from_rows(array $rows): array
{
    $headerNames = ['nama', 'nama tamu', 'nama lengkap', 'name', 'tamu', 'guest', 'guest name'];
    $column = 0;
    $startIndex = 0;

    foreach ($rows as $i => $row) {
        $hasContent = false;

        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (!$hasContent) {
            continue;
        }

        // Baris berisi pertama: apakah ini judul kolom?
        foreach ($row as $col => $value) {
            if (in_array(mb_strtolower(trim((string)$value), 'UTF-8'), $headerNames, true)) {
                $column = (int)$col;
                $startIndex = $i + 1;
                break;
            }
        }

        break;
    }

    $names = [];

    foreach ($rows as $i => $row) {
        if ($i < $startIndex) {
            continue;
        }

        $name = guest_clean_name((string)($row[$column] ?? ''));

        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Teks seluruh node <t> di bawah elemen (mendukung rich text).
 */
function guest_xml_text(SimpleXMLElement $node): string
{
    $node->registerXPathNamespace('m', GUEST_XML_NS);
    $text = '';

    foreach ($node->xpath('.//m:t') ?: [] as $t) {
        $text .= (string)$t;
    }

    return $text;
}

/**
 * Membaca file .xlsx (sheet pertama) menjadi baris-baris teks.
 *
 * @return array<int, array<int, string>>
 */
function guest_read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Ekstensi PHP zip belum aktif di server.');
    }

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException(
            'File tidak dapat dibaca. Pastikan formatnya .xlsx (bukan .xls).'
        );
    }

    $read = function (string $name) use ($zip): ?string {
        $stat = $zip->statName($name);

        if ($stat === false) {
            return null;
        }

        // Batasi ukuran isi setelah diekstrak (anti file kompresi berlebihan).
        if ($stat['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('Isi file Excel terlalu besar.');
        }

        $content = $zip->getFromName($name);

        return $content === false ? null : $content;
    };

    try {
        // Cari sheet pertama (nomor terkecil).
        $sheetName = null;
        $lowest = PHP_INT_MAX;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);

            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $entry, $m)) {
                if ((int)$m[1] < $lowest) {
                    $lowest = (int)$m[1];
                    $sheetName = $entry;
                }
            }
        }

        $sheetXml = $sheetName !== null ? $read($sheetName) : null;

        if ($sheetXml === null) {
            throw new RuntimeException('Sheet Excel tidak ditemukan. Pastikan file berformat .xlsx.');
        }

        // Teks bersama (shared strings).
        $shared = [];
        $sharedXml = $read('xl/sharedStrings.xml');

        if ($sharedXml !== null) {
            $xml = simplexml_load_string($sharedXml, 'SimpleXMLElement', LIBXML_NONET);

            if ($xml !== false) {
                $xml->registerXPathNamespace('m', GUEST_XML_NS);

                foreach ($xml->xpath('//m:si') ?: [] as $si) {
                    $shared[] = guest_xml_text($si);
                }
            }
        }

        $sheet = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);

        if ($sheet === false) {
            throw new RuntimeException('Isi sheet Excel tidak valid.');
        }

        $sheet->registerXPathNamespace('m', GUEST_XML_NS);

        $rows = [];
        $rowCount = 0;

        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
            if (++$rowCount > 5000) {
                break;
            }

            $row->registerXPathNamespace('m', GUEST_XML_NS);
            $line = [];
            $fallbackIndex = 0;

            foreach ($row->xpath('m:c') ?: [] as $cell) {
                // Posisi kolom dari atribut r="B3".
                $index = $fallbackIndex;
                $ref = (string)($cell['r'] ?? '');

                if (preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
                    $index = 0;

                    foreach (str_split($m[1]) as $ch) {
                        $index = $index * 26 + (ord($ch) - 64);
                    }

                    $index--;
                }

                $fallbackIndex = $index + 1;

                $type = (string)($cell['t'] ?? '');

                if ($type === 'inlineStr') {
                    $value = guest_xml_text($cell);
                } elseif ($type === 's') {
                    $value = $shared[(int)$cell->v] ?? '';
                } else {
                    $value = (string)$cell->v;
                }

                $line[$index] = $value;
            }

            $rows[] = $line;
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

/**
 * Membaca file .csv menjadi baris-baris teks (pemisah koma atau titik koma).
 *
 * @return array<int, array<int, string>>
 */
function guest_read_csv_rows(string $path): array
{
    $content = file_get_contents($path, false, null, 0, 2 * 1024 * 1024);

    if ($content === false) {
        throw new RuntimeException('File CSV tidak dapat dibaca.');
    }

    // Buang BOM UTF-8.
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    $firstLine = (string)($lines[0] ?? '');
    $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

    $rows = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $rows[] = array_map('strval', str_getcsv($line, $delimiter, '"', ''));

        if (count($rows) >= 5000) {
            break;
        }
    }

    return $rows;
}

/**
 * Membaca file unggahan (.xlsx atau .csv) dan mengembalikan daftar nama tamu.
 *
 * @return string[]
 */
function guest_names_from_upload(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        $rows = guest_read_xlsx_rows($tmpPath);
    } elseif ($ext === 'csv') {
        $rows = guest_read_csv_rows($tmpPath);
    } elseif ($ext === 'xls') {
        throw new RuntimeException(
            'Format .xls lama tidak didukung. Simpan ulang sebagai .xlsx di Excel.'
        );
    } else {
        throw new RuntimeException('Format file harus .xlsx atau .csv.');
    }

    return guest_names_from_rows($rows);
}
