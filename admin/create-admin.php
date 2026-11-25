<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$message = '';
$adminExists = false;

// Periksa apakah akun admin sudah tersedia.
try {
    $count = $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $adminExists = ((int)$count > 0);
} catch (PDOException $e) {
    error_log('Admin check error: ' . $e->getMessage());
    http_response_code(500);
    exit('Terjadi kesalahan sistem.');
}

// Jika admin sudah ada, pembuatan akun dinonaktifkan.
if ($adminExists) {
    http_response_code(403);
    exit('Akun admin sudah tersedia. Pembuatan akun baru dinonaktifkan.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['create_admin_csrf'])) {
    $_SESSION['create_admin_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['create_admin_csrf'], $token)) {
        $message = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (
            $username === '' ||
            strlen($username) > 50 ||
            strlen($password) < 12 ||
            strlen($password) > 4096
        ) {
            $message = 'Username wajib diisi (maksimal 50 karakter), password minimal 12 karakter.';
        } else {
            try {
                // Satu perintah atomik: hanya menyimpan jika belum ada admin sama sekali.
                $stmt = $pdo->prepare(
                    'INSERT INTO admins (username, password_hash)
                     SELECT :username, :hash
                     WHERE NOT EXISTS (SELECT 1 FROM admins)'
                );

                $stmt->execute([
                    'username' => $username,
                    'hash' => password_hash($password, PASSWORD_DEFAULT)
                ]);

                if ($stmt->rowCount() === 0) {
                    http_response_code(403);
                    exit('Akun admin sudah tersedia.');
                }

                unset($_SESSION['create_admin_csrf']);

                $message = 'Admin berhasil dibuat. Hapus file create-admin.php dari server sekarang.';
                $adminExists = true;

            } catch (Throwable $e) {
                error_log('Create admin error: ' . $e->getMessage());
                $message = 'Gagal membuat admin. Silakan coba lagi.';
            }
        }
    }
}
?>

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buat Admin</title>
</head>
<body style="font-family:Arial;max-width:420px;margin:50px auto;padding:20px;color:#1f4e96">

<h2>Buat Akun Admin</h2>

<p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>

<?php if (!$adminExists): ?>
<form method="post">
    <input
        type="hidden"
        name="csrf"
        value="<?= htmlspecialchars($_SESSION['create_admin_csrf'], ENT_QUOTES, 'UTF-8') ?>"
    >

    <p>
        Username<br>
        <input name="username" required maxlength="50">
    </p>

    <p>
        Password (minimal 12 karakter)<br>
        <input type="password" name="password" required minlength="12">
    </p>

    <button type="submit">Buat Admin</button>
</form>
<?php endif; ?>

<?php require __DIR__ . '/password-toggle.php'; ?>
</body>
</html>
