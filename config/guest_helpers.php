<?php
declare(strict_types=1);

/**
 * Fungsi bantu untuk link undangan berbasis nama tamu.
 *
 * Format link:  {domain}/{slug-undangan}/nama-tamu
 * Contoh:       https://contoh.com/rifky-sinta/bapak-andi
 */

/**
 * Mengubah nama tamu menjadi slug aman untuk URL.
 * "Bapak Andi & Ibu Siti" => "bapak-andi-dan-ibu-siti"
 */
function guest_slugify(string $name): string
{
    $slug = mb_strtolower(trim($name), 'UTF-8');
    $slug = str_replace('&', ' dan ', $slug);

    // Hilangkan aksen (é => e) bila memungkinkan.
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    if (is_string($ascii) && $ascii !== '') {
        $slug = $ascii;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    $slug = rtrim(substr($slug, 0, 60), '-');

    return $slug !== '' ? $slug : 'tamu';
}

/**
 * Nama tampilan cadangan dari slug (dipakai bila slug tidak ada di database).
 * "bapak-andi" => "Bapak Andi"
 */
function guest_name_from_slug(string $slug): string
{
    return ucwords(str_replace('-', ' ', $slug));
}

/**
 * Membuat slug unik di tabel invitation_guests.
 * Nama kembar menjadi "bapak-andi", "bapak-andi-2", "bapak-andi-3", ...
 */
function guest_unique_slug(PDO $pdo, string $name, int $invitationId): string
{
    $base = guest_slugify($name);
    $candidate = $base;
    $stmt = $pdo->prepare(
        'SELECT 1 FROM invitation_guests
         WHERE invitation_id = :inv AND slug = :slug LIMIT 1'
    );

    for ($n = 2; $n < 1000; $n++) {
        $stmt->execute([':inv' => $invitationId, ':slug' => $candidate]);

        if (!$stmt->fetchColumn()) {
            return $candidate;
        }

        $candidate = $base . '-' . $n;
    }

    throw new RuntimeException('Gagal membuat slug tamu yang unik.');
}

/**
 * Menambahkan kolom slug ke database lama secara otomatis (sekali saja).
 */
function ensure_guest_schema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM invitation_guests LIKE 'slug'");

    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE invitation_guests
         ADD COLUMN slug VARCHAR(100) NULL DEFAULT NULL AFTER guest_name,
         ADD UNIQUE KEY slug (slug)"
    );
}
