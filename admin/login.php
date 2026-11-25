<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['login_csrf'], $token)) {
        $error = 'Permintaan tidak valid. Muat ulang halaman.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $ipAddress = 'unknown';
        }

        // Validasi input sebelum diproses
        if (
            $username === '' ||
            strlen($username) > 50 ||
            $password === '' ||
            strlen($password) > 4096
        ) {
            $error = 'Username atau password salah.';
        } else {
            // Username dibuat konsisten untuk pencatatan percobaan.
            $trackedUsername = strtolower($username);

            try {
                $pdo->beginTransaction();

                // Buat catatan awal jika kombinasi username dan IP belum ada.
                $insert = $pdo->prepare("
                    INSERT IGNORE INTO login_attempts
                    (username, ip_address, failed_attempts, window_started_at)
                    VALUES (:username, :ip, 0, NOW())
                ");

                $insert->execute([
                    'username' => $trackedUsername,
                    'ip' => $ipAddress
                ]);

                // Kunci baris agar percobaan bersamaan diproses berurutan.
                $attemptQuery = $pdo->prepare("
                    SELECT
                        failed_attempts,
                        window_started_at,
                        locked_until,
                        CASE
                            WHEN locked_until IS NOT NULL
                             AND locked_until > NOW()
                            THEN 1
                            ELSE 0
                        END AS is_locked,
                        CASE
                            WHEN locked_until IS NOT NULL
                            THEN GREATEST(
                                0,
                                TIMESTAMPDIFF(SECOND, NOW(), locked_until)
                            )
                            ELSE 0
                        END AS remaining_seconds
                    FROM login_attempts
                    WHERE username = :username
                      AND ip_address = :ip
                    FOR UPDATE
                ");

                $attemptQuery->execute([
                    'username' => $trackedUsername,
                    'ip' => $ipAddress
                ]);

                $attempt = $attemptQuery->fetch();
                if (!$attempt) {
    throw new RuntimeException('Data login attempt tidak ditemukan.');
}

                // Jika masih diblokir, hentikan proses login.
                if ((int)$attempt['is_locked'] === 1) {
                    $pdo->commit();

                    $minutes = max(
                        1,
                        (int)ceil((int)$attempt['remaining_seconds'] / 60)
                    );

                    $error = "Terlalu banyak percobaan login. Coba lagi dalam {$minutes} menit.";
                } else {
                    // Reset hitungan jika periode 15 menit sudah berakhir.
                    $resetQuery = $pdo->prepare("
    UPDATE login_attempts
    SET failed_attempts = 0,
        window_started_at = NOW(),
        locked_until = NULL
    WHERE username = :username
      AND ip_address = :ip
      AND (
          window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          OR (
              locked_until IS NOT NULL
              AND locked_until <= NOW()
          )
      )
");

                    $resetQuery->execute([
                        'username' => $trackedUsername,
                        'ip' => $ipAddress
                    ]);

                    // Periksa kredensial admin.
                    $stmt = $pdo->prepare("
                        SELECT id, username, password_hash
                        FROM admins
                        WHERE username = :username
                        LIMIT 1
                    ");

                    $stmt->execute(['username' => $username]);
                    $admin = $stmt->fetch();

                    if (
                        $admin &&
                        password_verify($password, $admin['password_hash'])
                    ) {
                        // Hapus catatan kegagalan setelah login berhasil.
                        $deleteQuery = $pdo->prepare("
                            DELETE FROM login_attempts
                            WHERE username = :username
                              AND ip_address = :ip
                        ");

                        $deleteQuery->execute([
                            'username' => $trackedUsername,
                            'ip' => $ipAddress
                        ]);

                        $pdo->commit();

                        session_regenerate_id(true);
                        unset($_SESSION['login_csrf']);

                        $_SESSION['admin_id'] = (int)$admin['id'];
                        $_SESSION['admin_username'] = $admin['username'];
                        $_SESSION['authenticated_at'] = time();
                        $_SESSION['last_activity'] = time();

                        header('Location: dashboard.php');
                        exit;
                    }

                    // Catat percobaan login yang gagal.
                    $newAttempts = (int)$attempt['failed_attempts'] + 1;

                    $updateQuery = $pdo->prepare("
                        UPDATE login_attempts
                        SET failed_attempts = :attempts,
                            locked_until = CASE
                                WHEN :attempts_check >= 5
                                THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                ELSE NULL
                            END
                        WHERE username = :username
                          AND ip_address = :ip
                    ");

                    $updateQuery->execute([
                        'attempts' => $newAttempts,
                        'attempts_check' => $newAttempts,
                        'username' => $trackedUsername,
                        'ip' => $ipAddress
                    ]);

                    $pdo->commit();

                    if ($newAttempts >= 5) {
                        $error = 'Terlalu banyak percobaan login. Akses diblokir selama 15 menit.';
                    } else {
                        $error = 'Username atau password salah.';
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('Login processing error: ' . $e->getMessage());
                $error = 'Terjadi kesalahan. Silakan coba kembali.';
            }
        }
    }
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login Admin - Undangan</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    background: #eaf1fa;
    font-family: Arial, sans-serif;
    color: #1f4e96;
}

.card {
    background: white;
    padding: 32px;
    border-radius: 18px;
    width: min(92%, 390px);
    box-shadow: 0 12px 35px #183b2c18;
}

h1 {
    font-family: Georgia, serif;
    margin: 0 0 8px;
}

p {
    color: #5f6f86;
    font-size: 14px;
}

label {
    display: block;
    margin: 16px 0 6px;
    font-size: 13px;
}

input {
    width: 100%;
    padding: 12px;
    border: 1px solid #d5e0ee;
    border-radius: 9px;
}

button {
    width: 100%;
    margin-top: 22px;
    padding: 13px;
    border: 0;
    border-radius: 25px;
    background: #1f4e96;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

.error {
    background: #fff0f0;
    color: #a22;
    padding: 10px;
    border-radius: 8px;
    font-size: 13px;
}
</style>
</head>

<body>
<main class="card">
    <h1>Admin Undangan</h1>
    <p>Masuk untuk mengelola RSVP dan ucapan tamu.</p>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <input
            type="hidden"
            name="csrf"
            value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <label>Username</label>
        <input
            name="username"
            required
            maxlength="50"
            autocomplete="username"
        >

        <label>Password</label>
        <input
            type="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button type="submit">Login</button>
    </form>
</main>
<?php require __DIR__ . '/password-toggle.php'; ?>
</body>
</html>
