<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

// Ganti undangan yang sedang dikelola. Hanya admin.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

$ctx = admin_context($pdo);
require_admin_role($ctx, 'admin');

$token = (string)($_POST['csrf'] ?? '');
$sessionToken = (string)($_SESSION['nav_csrf'] ?? '');

if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    exit('Permintaan tidak valid.');
}

$id = (int)($_POST['invitation_id'] ?? 0);

if ($id > 0 && invitation_by_id($pdo, $id) !== null) {
    $_SESSION['admin_invitation_id'] = $id;
}

// Kembali ke halaman asal (hanya halaman yang dikenal).
$allowed = ['dashboard.php', 'guests.php', 'invitations.php', 'accounts.php'];
$back = (string)($_POST['back'] ?? 'dashboard.php');

header('Location: ' . (in_array($back, $allowed, true) ? $back : 'dashboard.php'));
exit;
