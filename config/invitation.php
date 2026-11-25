<?php
declare(strict_types=1);

/**
 * Multi-undangan: satu sistem untuk banyak klien.
 *
 * Setiap undangan punya:
 *  - slug (bagian link, mis. "rifky-sinta"  =>  domain.com/rifky-sinta/nama-tamu)
 *  - tamu, RSVP, ucapan, dan pengaturan sendiri (kolom invitation_id)
 *
 * Akun "klien" terhubung ke satu undangan dan hanya melihat datanya.
 * Akun "admin" melihat semua undangan.
 */

require_once __DIR__ . '/guest_helpers.php';

/** Slug yang tidak boleh dipakai karena bentrok dengan folder/alamat sistem. */
const RESERVED_INVITATION_SLUGS = [
    'admin', 'api', 'assets', 'css', 'js', 'config', 'database', 'i',
    'index', 'img', 'images', 'static', 'vendor', 'uploads', 'login',
    'logout', 'public', 'storage', 'tmp',
];

/**
 * Membuat slug undangan dari nama pasangan.
 * "Rifky & Sinta" => "rifky-sinta"
 */
function invitation_slugify(string $text): string
{
    $slug = mb_strtolower(trim($text), 'UTF-8');

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    if (is_string($ascii) && $ascii !== '') {
        $slug = $ascii;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return rtrim(substr($slug, 0, 60), '-');
}

/**
 * Slug undangan valid: huruf kecil/angka dipisah strip, 3-60 karakter, bukan nama sistem.
 */
function invitation_slug_is_valid(string $slug): bool
{
    return strlen($slug) >= 3
        && strlen($slug) <= 60
        && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1
        && !in_array($slug, RESERVED_INVITATION_SLUGS, true);
}

function invitation_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function invitation_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute([':t' => $table, ':i' => $index]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Menyiapkan struktur multi-undangan pada database lama (sekali saja, idempotent).
 * Data yang sudah ada otomatis dimasukkan ke undangan bawaan (id terkecil).
 * Penanda selesai: kolom wishes.invitation_id (langkah terakhir).
 */
function ensure_invitation_schema(PDO $pdo): void
{
    if (invitation_column_exists($pdo, 'wishes', 'invitation_id')) {
        return;
    }

    ensure_guest_schema($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invitations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            couple_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );

    if ((int)$pdo->query('SELECT COUNT(*) FROM invitations')->fetchColumn() === 0) {
        $pdo->exec(
            "INSERT INTO invitations (slug, couple_name)
             VALUES ('rifky-sinta', 'Rifky & Sinta')"
        );
    }

    $defaultId = (int)$pdo->query('SELECT MIN(id) FROM invitations')->fetchColumn();

    // Akun: admin = NULL (semua undangan), klien = satu undangan.
    if (!invitation_column_exists($pdo, 'admins', 'invitation_id')) {
        $pdo->exec('ALTER TABLE admins ADD COLUMN invitation_id INT UNSIGNED NULL DEFAULT NULL');
    }

    // Tamu.
    if (!invitation_column_exists($pdo, 'invitation_guests', 'invitation_id')) {
        $pdo->exec('ALTER TABLE invitation_guests ADD COLUMN invitation_id INT UNSIGNED NULL');
    }
    $pdo->exec("UPDATE invitation_guests SET invitation_id = {$defaultId} WHERE invitation_id IS NULL");
    $pdo->exec('ALTER TABLE invitation_guests MODIFY invitation_id INT UNSIGNED NOT NULL');

    // Slug tamu unik per undangan (bukan global).
    if (invitation_index_exists($pdo, 'invitation_guests', 'slug')) {
        $pdo->exec('ALTER TABLE invitation_guests DROP INDEX slug');
    }
    if (!invitation_index_exists($pdo, 'invitation_guests', 'inv_slug')) {
        $pdo->exec('ALTER TABLE invitation_guests ADD UNIQUE KEY inv_slug (invitation_id, slug)');
    }

    // Pengaturan (template WhatsApp): id = id undangan.
    $pdo->exec('ALTER TABLE invitation_settings MODIFY id INT UNSIGNED NOT NULL');

    // RSVP.
    if (!invitation_column_exists($pdo, 'rsvps', 'invitation_id')) {
        $pdo->exec('ALTER TABLE rsvps ADD COLUMN invitation_id INT UNSIGNED NULL');
    }
    $pdo->exec("UPDATE rsvps SET invitation_id = {$defaultId} WHERE invitation_id IS NULL");
    $pdo->exec('ALTER TABLE rsvps MODIFY invitation_id INT UNSIGNED NOT NULL');
    if (!invitation_index_exists($pdo, 'rsvps', 'idx_rsvps_invitation')) {
        $pdo->exec('ALTER TABLE rsvps ADD KEY idx_rsvps_invitation (invitation_id, created_at, id)');
    }

    // Ucapan (langkah terakhir = penanda selesai).
    if (!invitation_column_exists($pdo, 'wishes', 'invitation_id')) {
        $pdo->exec('ALTER TABLE wishes ADD COLUMN invitation_id INT UNSIGNED NULL');
    }
    $pdo->exec("UPDATE wishes SET invitation_id = {$defaultId} WHERE invitation_id IS NULL");
    $pdo->exec('ALTER TABLE wishes MODIFY invitation_id INT UNSIGNED NOT NULL');
    if (!invitation_index_exists($pdo, 'wishes', 'idx_wishes_invitation')) {
        $pdo->exec('ALTER TABLE wishes ADD KEY idx_wishes_invitation (invitation_id, created_at, id)');
    }
}

/** @return array{id:int,slug:string,couple_name:string}|null */
function invitation_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, couple_name FROM invitations WHERE slug = :slug LIMIT 1'
    );
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();

    return $row ? ['id' => (int)$row['id'], 'slug' => (string)$row['slug'], 'couple_name' => (string)$row['couple_name']] : null;
}

/** @return array{id:int,slug:string,couple_name:string}|null */
function invitation_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, slug, couple_name FROM invitations WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ? ['id' => (int)$row['id'], 'slug' => (string)$row['slug'], 'couple_name' => (string)$row['couple_name']] : null;
}

/**
 * Undangan bawaan (id terkecil), dipakai bila alamat tidak menyebut undangan.
 *
 * @return array{id:int,slug:string,couple_name:string}|null
 */
function invitation_default(PDO $pdo): ?array
{
    $row = $pdo->query(
        'SELECT id, slug, couple_name FROM invitations ORDER BY id ASC LIMIT 1'
    )->fetch();

    return $row ? ['id' => (int)$row['id'], 'slug' => (string)$row['slug'], 'couple_name' => (string)$row['couple_name']] : null;
}

/**
 * Semua undangan (untuk admin).
 *
 * @return array<int, array{id:int,slug:string,couple_name:string}>
 */
function invitation_all(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT id, slug, couple_name FROM invitations ORDER BY id ASC'
    )->fetchAll();

    return array_map(
        fn($r) => ['id' => (int)$r['id'], 'slug' => (string)$r['slug'], 'couple_name' => (string)$r['couple_name']],
        $rows
    );
}
