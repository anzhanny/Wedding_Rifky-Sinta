<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/invitation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);

    try {
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        http_response_code(500);
        echo '{"success":false,"message":"Terjadi kesalahan saat memproses data."}';
    }

    exit;
}

// Endpoint hanya menerima GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Metode tidak diizinkan.'
    ]);
}

try {
    ensure_invitation_schema($pdo);

    // Ucapan hanya milik undangan yang sedang dibuka.
    $invitationSlug = strtolower(trim((string)($_GET['invitation'] ?? '')));

    $invitation = $invitationSlug !== ''
        ? invitation_by_slug($pdo, $invitationSlug)
        : invitation_default($pdo);

    if (!$invitation) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Undangan tidak ditemukan.'
        ]);
    }

    $stmt = $pdo->prepare("
        SELECT id, name, message, created_at
        FROM wishes
        WHERE invitation_id = :invitation_id
        ORDER BY created_at DESC, id DESC
        LIMIT 200
    ");

    $stmt->execute(['invitation_id' => $invitation['id']]);

    jsonResponse(200, [
        'success' => true,
        'data' => $stmt->fetchAll()
    ]);

} catch (Throwable $e) {
    error_log('Get wishes error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Ucapan belum dapat dimuat.'
    ]);
}
