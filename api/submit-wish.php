<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/invitation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Hanya menerima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Metode tidak diizinkan.'
    ]);
}

// Batasi ukuran request maksimal 10 KB
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 10240) {
    jsonResponse(413, [
        'success' => false,
        'message' => 'Ukuran data terlalu besar.'
    ]);
}

$rawInput = file_get_contents('php://input');

if ($rawInput === false || strlen($rawInput) > 10240) {
    jsonResponse(413, [
        'success' => false,
        'message' => 'Ukuran data terlalu besar.'
    ]);
}

// Validasi JSON
try {
    $data = json_decode(
        $rawInput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Format data tidak valid.'
    ]);
}

if (!is_array($data)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Data yang dikirim tidak valid.'
    ]);
}

// Validasi tipe data
if (
    !isset($data['name'], $data['message']) ||
    !is_string($data['name']) ||
    !is_string($data['message'])
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Nama dan ucapan wajib diisi.'
    ]);
}

$name = trim($data['name']);
$message = trim($data['message']);

if (
    $name === '' ||
    $message === '' ||
    mb_strlen($name) > 100 ||
    mb_strlen($message) > 1000
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Nama maksimal 100 karakter dan ucapan maksimal 1.000 karakter.'
    ]);
}

// Validasi IP
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Permintaan tidak valid.'
    ]);
}

// Tentukan undangan pemilik data (dari slug yang dikirim halaman undangan).
try {
    ensure_invitation_schema($pdo);

    $invitationSlug = isset($data['invitation']) && is_string($data['invitation'])
        ? strtolower(trim($data['invitation']))
        : '';

    $invitation = $invitationSlug !== ''
        ? invitation_by_slug($pdo, $invitationSlug)
        : invitation_default($pdo);
} catch (Throwable $e) {
    error_log('Resolve invitation error: ' . $e->getMessage());
    $invitation = null;
}

if (!$invitation) {
    jsonResponse(404, [
        'success' => false,
        'message' => 'Undangan tidak ditemukan.'
    ]);
}

try {
    $pdo->beginTransaction();

    // Buat catatan awal jika IP belum ada.
    $insertLimit = $pdo->prepare("
        INSERT IGNORE INTO wish_rate_limits
            (ip_address, request_count, window_started_at)
        VALUES
            (:ip, 0, NOW())
    ");

    $insertLimit->execute(['ip' => $ipAddress]);

    // Kunci baris dan periksa periode menggunakan waktu MySQL.
    $limitQuery = $pdo->prepare("
        SELECT
            request_count,
            window_started_at,
            (
                window_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ) AS window_expired
        FROM wish_rate_limits
        WHERE ip_address = :ip
        FOR UPDATE
    ");

    $limitQuery->execute(['ip' => $ipAddress]);
    $limit = $limitQuery->fetch();

    if (!$limit) {
        throw new RuntimeException('Data rate limit tidak ditemukan.');
    }

    // Reset jika periode 10 menit sudah berakhir.
    $windowExpired = (int)$limit['window_expired'] === 1;

    if ($windowExpired) {
        $currentCount = 0;

        $resetQuery = $pdo->prepare("
            UPDATE wish_rate_limits
            SET request_count = 0,
                window_started_at = NOW()
            WHERE ip_address = :ip
        ");

        $resetQuery->execute(['ip' => $ipAddress]);
    } else {
        $currentCount = (int)$limit['request_count'];
    }

    // Maksimal 10 pengiriman dalam 10 menit.
    if ($currentCount >= 10) {
        $pdo->commit();

        jsonResponse(429, [
            'success' => false,
            'message' => 'Terlalu banyak pengiriman ucapan. Silakan coba kembali dalam beberapa menit.'
        ]);
    }

    // Tambahkan jumlah request.
    $updateLimit = $pdo->prepare("
        UPDATE wish_rate_limits
        SET request_count = request_count + 1
        WHERE ip_address = :ip
    ");

    $updateLimit->execute(['ip' => $ipAddress]);

    // Simpan ucapan.
    $stmt = $pdo->prepare("
        INSERT INTO wishes (invitation_id, name, message)
        VALUES (:invitation_id, :name, :message)
    ");

    $stmt->execute([
        'invitation_id' => $invitation['id'],
        'name' => $name,
        'message' => $message
    ]);

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Ucapan berhasil dikirim. Terima kasih atas doa dan harapannya.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Save wish error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Ucapan belum dapat disimpan. Silakan coba lagi.'
    ]);
}
