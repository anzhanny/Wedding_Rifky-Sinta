<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../config/guest_import.php';  // guest_clean_name()

$ctx = admin_context($pdo);
require_admin_role($ctx, 'admin');

if (empty($_SESSION['invitations_csrf'])) {
    $_SESSION['invitations_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['invitations_csrf'];

function redirect_invitations(): void
{
    header('Location: invitations.php');
    exit;
}

function inv_flash(string $type, string $message): void
{
    $_SESSION['invitations_flash'] = [$type, $message];
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

    // ----- Buat undangan (opsional sekaligus akun mempelai) -----
    if ($action === 'create') {

        $coupleName = guest_clean_name((string)($_POST['couple_name'] ?? ''));
        $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($coupleName === '') {
            inv_flash('error', 'Nama pasangan wajib diisi.');
            redirect_invitations();
        }

        if ($slug === '') {
            $slug = invitation_slugify($coupleName);
        }

        if (!invitation_slug_is_valid($slug)) {
            inv_flash('error', 'Alamat link harus 3-60 karakter: huruf kecil, angka, dan strip (bukan nama sistem seperti admin/api).');
            redirect_invitations();
        }

        $withAccount = ($username !== '' || $password !== '');

        if ($withAccount) {
            if (!preg_match('/\A[A-Za-z0-9._-]{3,50}\z/', $username)) {
                inv_flash('error', 'Username 3-50 karakter: huruf, angka, titik, strip, atau garis bawah.');
                redirect_invitations();
            }

            if (strlen($password) < 12 || strlen($password) > 4096) {
                inv_flash('error', 'Password akun mempelai minimal 12 karakter.');
                redirect_invitations();
            }
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO invitations (slug, couple_name) VALUES (:slug, :name)'
            )->execute([':slug' => $slug, ':name' => $coupleName]);

            $newId = (int)$pdo->lastInsertId();

            if ($withAccount) {
                $pdo->prepare(
                    "INSERT INTO admins (username, password_hash, role, invitation_id)
                     VALUES (:u, :h, 'klien', :inv)"
                )->execute([
                    ':u' => $username,
                    ':h' => password_hash($password, PASSWORD_DEFAULT),
                    ':inv' => $newId,
                ]);
            }

            $pdo->commit();

            inv_flash(
                'success',
                'Undangan "' . $coupleName . '" dibuat dengan alamat /' . $slug
                . ($withAccount ? ' dan akun mempelai "' . $username . '".' : '.')
            );
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ((string)$e->getCode() === '23000') {
                inv_flash('error', 'Alamat link atau username sudah dipakai.');
            } else {
                error_log('Invitation create error: ' . $e->getMessage());
                inv_flash('error', 'Gagal membuat undangan.');
            }
        }

        redirect_invitations();
    }

    // ----- Ubah nama pasangan -----
    if ($action === 'rename') {

        $id = (int)($_POST['invitation_id'] ?? 0);
        $coupleName = guest_clean_name((string)($_POST['couple_name'] ?? ''));

        if ($id <= 0 || $coupleName === '') {
            inv_flash('error', 'Nama pasangan wajib diisi.');
            redirect_invitations();
        }

        try {
            $pdo->prepare('UPDATE invitations SET couple_name = :n WHERE id = :id')
                ->execute([':n' => $coupleName, ':id' => $id]);

            inv_flash('success', 'Nama pasangan diperbarui.');
        } catch (Throwable $e) {
            error_log('Invitation rename error: ' . $e->getMessage());
            inv_flash('error', 'Gagal memperbarui nama.');
        }

        redirect_invitations();
    }

    // ----- Hapus undangan beserta seluruh datanya -----
    if ($action === 'delete') {

        $id = (int)($_POST['invitation_id'] ?? 0);

        try {
            $target = $id > 0 ? invitation_by_id($pdo, $id) : null;

            if ($target === null) {
                inv_flash('error', 'Undangan tidak ditemukan.');
                redirect_invitations();
            }

            if (count(invitation_all($pdo)) <= 1) {
                inv_flash('error', 'Undangan terakhir tidak bisa dihapus.');
                redirect_invitations();
            }

            $pdo->beginTransaction();

            foreach ([
                'DELETE FROM invitation_guests WHERE invitation_id = :id',
                'DELETE FROM rsvps WHERE invitation_id = :id',
                'DELETE FROM wishes WHERE invitation_id = :id',
                'DELETE FROM invitation_settings WHERE id = :id',
                "DELETE FROM admins WHERE role = 'klien' AND invitation_id = :id",
                'DELETE FROM invitations WHERE id = :id',
            ] as $sql) {
                $pdo->prepare($sql)->execute([':id' => $id]);
            }

            $pdo->commit();

            if ((int)($_SESSION['admin_invitation_id'] ?? 0) === $id) {
                unset($_SESSION['admin_invitation_id']);
            }

            inv_flash('success', 'Undangan "' . $target['couple_name'] . '" dan seluruh datanya dihapus.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Invitation delete error: ' . $e->getMessage());
            inv_flash('error', 'Gagal menghapus undangan.');
        }

        redirect_invitations();
    }

    redirect_invitations();
}

// --------------------------------------------------
// DATA (pantauan semua undangan)
// --------------------------------------------------
$flash = $_SESSION['invitations_flash'] ?? null;
unset($_SESSION['invitations_flash']);

$rows = $pdo->query("
    SELECT
        i.id, i.slug, i.couple_name, i.created_at,
        (SELECT COUNT(*) FROM invitation_guests g
          WHERE g.invitation_id = i.id) AS tamu,
        (SELECT COUNT(*) FROM invitation_guests g
          WHERE g.invitation_id = i.id AND g.invitation_sent = 1) AS terkirim,
        (SELECT COUNT(*) FROM rsvps r
          WHERE r.invitation_id = i.id AND r.attendance = 'Hadir') AS hadir,
        (SELECT COUNT(*) FROM rsvps r
          WHERE r.invitation_id = i.id AND r.attendance = 'Tidak Hadir') AS tidak_hadir,
        (SELECT COUNT(*) FROM wishes w
          WHERE w.invitation_id = i.id) AS ucapan
    FROM invitations i
    ORDER BY i.id ASC
")->fetchAll();

$accountsByInvitation = [];

foreach ($pdo->query(
    "SELECT invitation_id, username FROM admins
     WHERE role = 'klien' AND invitation_id IS NOT NULL
     ORDER BY username"
) as $a) {
    $accountsByInvitation[(int)$a['invitation_id']][] = (string)$a['username'];
}

$totals = ['tamu' => 0, 'terkirim' => 0, 'hadir' => 0, 'ucapan' => 0];

foreach ($rows as $r) {
    $totals['tamu'] += (int)$r['tamu'];
    $totals['terkirim'] += (int)$r['terkirim'];
    $totals['hadir'] += (int)$r['hadir'];
    $totals['ucapan'] += (int)$r['ucapan'];
}

$baseUrl = admin_base_url();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Undangan | Admin</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    background: #f3f6fb;
    color: #1f2d3d;
    font-family: Arial, sans-serif;
}

.container { max-width: 1200px; margin: 0 auto; padding: 25px; }

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

.totals {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 22px;
}

.total {
    background: #fff;
    border: 1px solid #dde6f2;
    border-radius: 12px;
    padding: 16px 18px;
}

.total small { color: #64748b; font-size: 12px; }
.total b { display: block; font-size: 26px; margin-top: 4px; }

label { display: block; font-size: 13px; margin: 0 0 5px; }

input[type="text"], input[type="password"] {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #cfd9e6;
    border-radius: 7px;
    font-size: 14px;
    font-family: inherit;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
    margin-bottom: 14px;
}

.note { font-size: 12px; color: #6b7a90; margin: 4px 0 14px; }

.btn {
    border: 0;
    padding: 9px 14px;
    border-radius: 7px;
    font-size: 13px;
    cursor: pointer;
    color: #fff;
    background: #2a62b8;
    text-decoration: none;
    display: inline-block;
}

.btn-light { background: #e3ebf6; color: #1f4e96; }
.btn-red { background: #d9534f; }

.table-wrap { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; min-width: 880px; }
th, td {
    text-align: left;
    padding: 12px 10px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
    vertical-align: middle;
}
th { background: #f4f7fc; }

.num { text-align: center; }
.link a { color: #2b6cb0; word-break: break-all; }
.pill {
    display: inline-block;
    background: #e6eef9;
    color: #1f4e96;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 12px;
    margin: 1px 3px 1px 0;
}
.pill.none { background: #e8eef7; color: #4a617e; }

.inline { display: flex; gap: 6px; margin: 0; }
.actions { display: flex; flex-wrap: wrap; gap: 6px; }
.actions form { margin: 0; }

@media (max-width: 700px) {
    .container { padding: 15px; }
    .totals { grid-template-columns: 1fr 1fr; }
    .form-grid { grid-template-columns: 1fr; }
}
</style>
</head>

<body>

<?php admin_nav($ctx, 'invitations'); ?>

<div class="container">

    <h1>Undangan</h1>
    <p class="sub">
        Pantauan semua undangan. Setiap undangan punya tamu, RSVP, dan ucapan sendiri,
        dan hanya bisa dilihat oleh mempelai pemiliknya.
    </p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash[0] === 'success' ? 'success' : 'error' ?>">
            <?= adm_h($flash[1]) ?>
        </div>
    <?php endif; ?>

    <div class="totals">
        <div class="total"><small>Undangan</small><b><?= count($rows) ?></b></div>
        <div class="total"><small>Total tamu</small><b><?= $totals['tamu'] ?></b></div>
        <div class="total"><small>Undangan terkirim</small><b><?= $totals['terkirim'] ?></b></div>
        <div class="total"><small>Konfirmasi hadir</small><b><?= $totals['hadir'] ?></b></div>
    </div>

    <div class="card">
        <h2>Semua Undangan</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Pasangan</th>
                        <th>Link</th>
                        <th class="num">Tamu</th>
                        <th class="num">Terkirim</th>
                        <th class="num">Hadir</th>
                        <th class="num">Tidak</th>
                        <th class="num">Ucapan</th>
                        <th>Akun Mempelai</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $id = (int)$r['id'];
                    $link = $baseUrl . $r['slug'];
                    $accounts = $accountsByInvitation[$id] ?? [];
                    ?>
                    <tr>
                        <td>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="invitation_id" value="<?= $id ?>">
                                <input type="text" name="couple_name" maxlength="150"
                                       value="<?= adm_h($r['couple_name']) ?>" required
                                       style="min-width:120px">
                                <button type="submit" class="btn btn-light">Simpan</button>
                            </form>
                        </td>

                        <td class="link">
                            <a href="<?= adm_h($link) ?>" target="_blank" rel="noopener">
                                /<?= adm_h($r['slug']) ?>
                            </a>
                        </td>

                        <td class="num"><?= (int)$r['tamu'] ?></td>
                        <td class="num"><?= (int)$r['terkirim'] ?></td>
                        <td class="num"><?= (int)$r['hadir'] ?></td>
                        <td class="num"><?= (int)$r['tidak_hadir'] ?></td>
                        <td class="num"><?= (int)$r['ucapan'] ?></td>

                        <td>
                            <?php if ($accounts): ?>
                                <?php foreach ($accounts as $username): ?>
                                    <span class="pill"><?= adm_h($username) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="pill none">belum ada akun</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="actions">
                                <?php foreach ([['dashboard.php', 'Dashboard'], ['guests.php', 'Tamu']] as [$page, $label]): ?>
                                    <form method="POST" action="switch.php">
                                        <input type="hidden" name="csrf" value="<?= adm_h($_SESSION['nav_csrf']) ?>">
                                        <input type="hidden" name="invitation_id" value="<?= $id ?>">
                                        <input type="hidden" name="back" value="<?= adm_h($page) ?>">
                                        <button type="submit" class="btn btn-light"><?= adm_h($label) ?></button>
                                    </form>
                                <?php endforeach; ?>

                                <?php if (count($rows) > 1): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Hapus undangan ini BESERTA seluruh tamu, RSVP, ucapan, dan akun mempelainya? Tindakan ini tidak bisa dibatalkan.')">
                                        <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="invitation_id" value="<?= $id ?>">
                                        <button type="submit" class="btn btn-red">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2>Buat Undangan Baru</h2>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= adm_h($csrf) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div>
                    <label for="couple_name">Nama pasangan</label>
                    <input type="text" id="couple_name" name="couple_name"
                           maxlength="150" required placeholder="contoh: Andi &amp; Rina">
                </div>

                <div>
                    <label for="slug">Alamat link (boleh dikosongkan)</label>
                    <input type="text" id="slug" name="slug" maxlength="60"
                           placeholder="otomatis: andi-rina">
                </div>
            </div>

            <p class="note">
                Link undangan menjadi <strong><?= adm_h($baseUrl) ?>alamat-link</strong>,
                dan link tamu <strong><?= adm_h($baseUrl) ?>alamat-link/nama-tamu</strong>.
                Alamat link tidak bisa diubah setelah dibuat.
            </p>

            <h2 style="font-size:15px;margin:18px 0 10px">
                Akun mempelai (opsional, bisa dibuat nanti di Kelola Akun)
            </h2>

            <div class="form-grid">
                <div>
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username"
                           maxlength="50" placeholder="contoh: andi-rina">
                </div>

                <div>
                    <label for="password">Password (minimal 12 karakter)</label>
                    <input type="password" id="password" name="password"
                           autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn">+ Buat Undangan</button>
        </form>
    </div>

</div>

<?php require __DIR__ . '/password-toggle.php'; ?>
</body>
</html>
