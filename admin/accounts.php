<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

$ctx = admin_context($pdo);
require_admin_role($ctx, 'admin');

if (empty($_SESSION['accounts_csrf'])) {
    $_SESSION['accounts_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['accounts_csrf'];

// Daftar undangan untuk pilihan (akun Mempelai wajib terhubung ke satu undangan).
$invitationList = $ctx['invitations'];
$invitationIds = array_column($invitationList, 'id');
$invitationNames = array_column($invitationList, 'couple_name', 'id');

function redirect_accounts(): void
{
    header('Location: accounts.php');
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['accounts_flash'] = [$type, $message];
}

// --------------------------------------------------
// AKSI
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan refresh halaman.');
    }

    $action = (string)($_POST['action'] ?? '');

    // ----- Tambah akun -----
    if ($action === 'add') {

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'klien');
        $invitationId = (int)($_POST['invitation_id'] ?? 0);

        if (!preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $username)) {
            flash('error', 'Username 3-50 karakter: huruf, angka, titik, strip, atau garis bawah.');
            redirect_accounts();
        }

        if (strlen($password) < 12 || strlen($password) > 4096) {
            flash('error', 'Password minimal 12 karakter.');
            redirect_accounts();
        }

        if (!isset(ADMIN_ROLES[$role])) {
            flash('error', 'Peran tidak valid.');
            redirect_accounts();
        }

        // Mempelai wajib terhubung ke satu undangan; admin tidak terikat undangan.
        if ($role === 'klien' && !in_array($invitationId, $invitationIds, true)) {
            flash('error', 'Pilih undangan untuk akun Mempelai.');
            redirect_accounts();
        }

        if ($role === 'admin') {
            $invitationId = 0;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, role, invitation_id)
                 VALUES (:username, :hash, :role, :inv)'
            );

            $stmt->execute([
                ':username' => $username,
                ':hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':inv' => $invitationId > 0 ? $invitationId : null,
            ]);

            flash('success', 'Akun "' . $username . '" berhasil dibuat.');
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                flash('error', 'Username sudah dipakai.');
            } else {
                error_log('Account add error: ' . $e->getMessage());
                flash('error', 'Gagal membuat akun.');
            }
        }

        redirect_accounts();
    }

    // ----- Ganti password -----
    if ($action === 'password') {

        $id = (int)($_POST['account_id'] ?? 0);
        $password = (string)($_POST['password'] ?? '');

        if ($id <= 0 || strlen($password) < 12 || strlen($password) > 4096) {
            flash('error', 'Password baru minimal 12 karakter.');
            redirect_accounts();
        }

        try {
            $stmt = $pdo->prepare('SELECT username FROM admins WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $username = $stmt->fetchColumn();

            if ($username === false) {
                flash('error', 'Akun tidak ditemukan.');
                redirect_accounts();
            }

            $pdo->prepare('UPDATE admins SET password_hash = :hash WHERE id = :id')
                ->execute([
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id' => $id,
                ]);

            // Buka kunci percobaan login akun ini bila sempat terkunci.
            $pdo->prepare('DELETE FROM login_attempts WHERE username = :u')
                ->execute([':u' => strtolower((string)$username)]);

            flash('success', 'Password akun "' . $username . '" berhasil diganti.');
        } catch (Throwable $e) {
            error_log('Account password error: ' . $e->getMessage());
            flash('error', 'Gagal mengganti password.');
        }

        redirect_accounts();
    }

    // ----- Ubah peran -----
    if ($action === 'role') {

        $id = (int)($_POST['account_id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');
        $invitationId = (int)($_POST['invitation_id'] ?? 0);

        if ($id <= 0 || !isset(ADMIN_ROLES[$role])) {
            flash('error', 'Data peran tidak valid.');
            redirect_accounts();
        }

        if ($role === 'klien' && !in_array($invitationId, $invitationIds, true)) {
            flash('error', 'Pilih undangan untuk akun Mempelai.');
            redirect_accounts();
        }

        if ($role === 'admin') {
            $invitationId = 0;
        }

        // Cegah terkunci: peran akun sendiri tidak bisa diubah di sini.
        if ($id === $ctx['id']) {
            flash('error', 'Peran akun Anda sendiri tidak bisa diubah.');
            redirect_accounts();
        }

        try {
            $stmt = $pdo->prepare('SELECT username FROM admins WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $username = $stmt->fetchColumn();

            if ($username === false) {
                flash('error', 'Akun tidak ditemukan.');
                redirect_accounts();
            }

            $pdo->prepare('UPDATE admins SET role = :role, invitation_id = :inv WHERE id = :id')
                ->execute([
                    ':role' => $role,
                    ':inv' => $invitationId > 0 ? $invitationId : null,
                    ':id' => $id,
                ]);

            flash('success', 'Akun "' . $username . '" diubah menjadi '
                . ADMIN_ROLES[$role]
                . ($role === 'klien' ? ' (' . $invitationNames[$invitationId] . ')' : '') . '.');
        } catch (Throwable $e) {
            error_log('Account role error: ' . $e->getMessage());
            flash('error', 'Gagal mengubah peran.');
        }

        redirect_accounts();
    }

    // ----- Hapus akun -----
    if ($action === 'delete') {

        $id = (int)($_POST['account_id'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Akun tidak valid.');
            redirect_accounts();
        }

        if ($id === $ctx['id']) {
            flash('error', 'Anda tidak bisa menghapus akun yang sedang dipakai.');
            redirect_accounts();
        }

        try {
            $stmt = $pdo->prepare('SELECT username, role FROM admins WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $target = $stmt->fetch();

            if (!$target) {
                flash('error', 'Akun tidak ditemukan.');
                redirect_accounts();
            }

            // Selalu sisakan minimal satu admin.
            if ($target['role'] === 'admin') {
                $others = $pdo->prepare(
                    "SELECT COUNT(*) FROM admins WHERE role = 'admin' AND id <> :id"
                );
                $others->execute([':id' => $id]);

                if ((int)$others->fetchColumn() < 1) {
                    flash('error', 'Tidak bisa menghapus admin terakhir.');
                    redirect_accounts();
                }
            }

            $pdo->prepare('DELETE FROM admins WHERE id = :id')->execute([':id' => $id]);

            flash('success', 'Akun "' . $target['username'] . '" dihapus.');
        } catch (Throwable $e) {
            error_log('Account delete error: ' . $e->getMessage());
            flash('error', 'Gagal menghapus akun.');
        }

        redirect_accounts();
    }

    redirect_accounts();
}

// --------------------------------------------------
// DATA
// --------------------------------------------------
$flash = $_SESSION['accounts_flash'] ?? null;
unset($_SESSION['accounts_flash']);

$accounts = $pdo->query(
    'SELECT id, username, role, invitation_id, created_at FROM admins ORDER BY id ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kelola Akun | Admin</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #f3f6fb;
    color: #1f2d3d;
    font-family: Arial, sans-serif;
}

.container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 25px;
}

h1 { font-family: Georgia, serif; margin: 0 0 6px; }
.sub { color: #64748b; font-size: 14px; margin: 0 0 22px; }

.card {
    background: #fff;
    border: 1px solid #dde6f2;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
}

.card h2 { margin: 0 0 14px; font-size: 18px; }

.alert { padding: 13px 16px; border-radius: 8px; margin-bottom: 18px; }
.alert-success { background: #dff2e5; color: #24613a; }
.alert-error { background: #f8e0e0; color: #912f2f; }

label { display: block; font-size: 13px; margin: 0 0 5px; }

input[type="text"], input[type="password"], select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #cfd9e6;
    border-radius: 7px;
    font-size: 14px;
    font-family: inherit;
    background: #fff;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 14px;
}

.note { font-size: 12px; color: #6b7a90; margin: 0 0 14px; }

.btn {
    border: 0;
    padding: 10px 15px;
    border-radius: 7px;
    font-size: 13px;
    cursor: pointer;
    color: #fff;
    background: #2a62b8;
}

.btn-light { background: #e3ebf6; color: #1f4e96; }
.btn-red { background: #d9534f; }

.table-wrap { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; min-width: 760px; }
th, td {
    text-align: left;
    padding: 12px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
    vertical-align: middle;
}
th { background: #f4f7fc; }

.pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 12px;
    background: #e6eef9;
    color: #1f4e96;
}
.pill.admin { background: #dcecfb; color: #1f4e8c; }

.inline { display: flex; gap: 6px; }
.inline input { min-width: 150px; }

@media (max-width: 700px) {
    .container { padding: 15px; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<?php admin_nav($ctx, 'accounts'); ?>

<div class="container">

    <h1>Kelola Akun</h1>
    <p class="sub">
        Buat akun untuk mempelai (CPP/CPW) agar mereka bisa mengisi daftar tamu
        dan melihat data RSVP.
    </p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash[0] === 'success' ? 'success' : 'error' ?>">
            <?= adm_h($flash[1]) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Tambah Akun</h2>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
            <input type="hidden" name="action" value="add">

            <div class="form-grid">
                <div>
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           maxlength="50" required
                           placeholder="contoh: rifky.cpp">
                </div>

                <div>
                    <label for="password">Password (minimal 12 karakter)</label>
                    <input type="password" id="password" name="password"
                           minlength="12" required autocomplete="new-password">
                </div>

                <div>
                    <label for="role">Peran</label>
                    <select id="role" name="role">
                        <option value="klien">Mempelai (CPP/CPW)</option>
                        <option value="admin">Admin (akses penuh)</option>
                    </select>
                </div>

                <div>
                    <label for="invitation_id">Undangan (untuk Mempelai)</label>
                    <select id="invitation_id" name="invitation_id">
                        <?php foreach ($invitationList as $item): ?>
                            <option value="<?= (int)$item['id'] ?>"><?= adm_h($item['couple_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (count($invitationList) < 2): ?>
                        <p class="note" style="margin:6px 0 0">
                            Baru ada satu undangan.
                            <a href="invitations.php" style="color:#2b6cb0">Buat undangan lain</a>
                            untuk akun pasangan berbeda.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <p class="note">
                <strong>Mempelai</strong>: hanya melihat dan mengelola data <em>undangannya sendiri</em>
                (dashboard, RSVP, ucapan, unduh Excel, daftar tamu).
                <strong>Admin</strong>: semua undangan, halaman Undangan, dan Kelola Akun.
            </p>

            <button type="submit" class="btn">+ Buat Akun</button>
        </form>
    </div>

    <div class="card">
        <h2>Daftar Akun</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Peran</th>
                        <th>Dibuat</th>
                        <th>Ganti Password</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <?php $isSelf = (int)$account['id'] === $ctx['id']; ?>
                    <tr>
                        <td>
                            <strong><?= adm_h($account['username']) ?></strong>
                            <?php if ($isSelf): ?>
                                <span class="pill">Anda</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isSelf): ?>
                                <span class="pill admin">
                                    <?= adm_h(ADMIN_ROLES[$account['role']] ?? $account['role']) ?>
                                </span>
                            <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                    <select name="role" style="min-width:120px">
                                        <?php foreach (ADMIN_ROLES as $key => $label): ?>
                                            <option value="<?= adm_h($key) ?>"
                                                <?= $account['role'] === $key ? 'selected' : '' ?>>
                                                <?= adm_h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="invitation_id" style="min-width:140px" title="Undangan (untuk Mempelai)">
                                        <?php foreach ($invitationList as $item): ?>
                                            <option value="<?= (int)$item['id'] ?>"
                                                <?= (int)$account['invitation_id'] === $item['id'] ? 'selected' : '' ?>>
                                                <?= adm_h($item['couple_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-light">Ubah</button>
                                </form>
                            <?php endif; ?>
                        </td>

                        <td><?= adm_h($account['created_at']) ?></td>

                        <td>
                            <form method="POST" class="inline" autocomplete="off">
                                <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
                                <input type="hidden" name="action" value="password">
                                <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                <input type="password" name="password" minlength="12"
                                       placeholder="Password baru" required
                                       autocomplete="new-password">
                                <button type="submit" class="btn btn-light">Simpan</button>
                            </form>
                        </td>

                        <td>
                            <?php if (!$isSelf): ?>
                                <form method="POST"
                                      onsubmit="return confirm('Hapus akun ini? Akun tidak bisa login lagi.')">
                                    <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                    <button type="submit" class="btn btn-red">Hapus</button>
                                </form>
                            <?php else: ?>
                                <span class="note">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
(function () {
    var role = document.getElementById('role');
    var inv = document.getElementById('invitation_id');
    if (!role || !inv) return;
    function sync() { inv.disabled = role.value === 'admin'; }
    role.addEventListener('change', sync);
    sync();
})();
</script>

<?php require __DIR__ . '/password-toggle.php'; ?>
</body>
</html>
