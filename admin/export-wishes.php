<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Metode permintaan tidak diizinkan.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/layout.php';

$ctx = admin_context($pdo);  // pastikan akun masih ada
$invitation = $ctx['invitation'];

try {
    // Hanya ucapan milik undangan yang sedang dikelola.
    $stmt = $pdo->prepare(
        'SELECT name, message, created_at
         FROM wishes
         WHERE invitation_id = :inv
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':inv' => $invitation['id']]);

    $rows = (static function () use ($stmt) {
        $i = 1;

        while ($r = $stmt->fetch()) {
            yield [$i++, $r['name'], $r['message'], $r['created_at']];
        }
    })();

    send_xlsx(
        'data-ucapan-' . $invitation['slug'] . '-' . date('Ymd-His') . '.xlsx',
        'Ucapan',
        ['No', 'Nama', 'Ucapan', 'Waktu'],
        $rows,
        [6, 30, 70, 22]
    );

} catch (Throwable $e) {
    error_log('Export wishes error: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
    }

    exit('Gagal mengekspor data ucapan.');
}
