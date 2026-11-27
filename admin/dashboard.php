<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Metode permintaan tidak diizinkan.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

$ctx = admin_context($pdo);

// Semua data di halaman ini hanya milik undangan yang sedang dikelola.
$invitationId = (int)$ctx['invitation']['id'];

// Hanya admin yang boleh menghapus riwayat RSVP dan ucapan.
$isAdmin = $ctx['role'] === 'admin';

if (empty($_SESSION['dashboard_csrf'])) {
    $_SESSION['dashboard_csrf'] = bin2hex(random_bytes(32));
}

$dashCsrf = $_SESSION['dashboard_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isAdmin) {
        http_response_code(403);
        exit('Hanya admin yang dapat menghapus data.');
    }

    if (!hash_equals($dashCsrf, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan refresh halaman.');
    }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    // Daftar putih: aksi => [tabel, label]. Nama tabel tidak pernah berasal dari input.
    $targets = [
        'delete_rsvp' => ['rsvps', 'konfirmasi kehadiran', false],
        'clear_rsvp' => ['rsvps', 'konfirmasi kehadiran', true],
        'delete_wish' => ['wishes', 'ucapan', false],
        'clear_wishes' => ['wishes', 'ucapan', true],
    ];

    if (!isset($targets[$action])) {
        $_SESSION['dashboard_flash'] = ['error', 'Aksi tidak dikenal.'];
        header('Location: dashboard.php');
        exit;
    }

    [$table, $label, $all] = $targets[$action];

    try {
        if ($all) {
            // Kosongkan: hanya milik undangan yang sedang dikelola.
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE invitation_id = :inv");
            $stmt->execute([':inv' => $invitationId]);
        } else {
            if ($id <= 0) {
                $_SESSION['dashboard_flash'] = ['error', 'Data tidak valid.'];
                header('Location: dashboard.php');
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :id AND invitation_id = :inv");
            $stmt->execute([':id' => $id, ':inv' => $invitationId]);
        }

        $count = $stmt->rowCount();

        $_SESSION['dashboard_flash'] = $count > 0
            ? ['success', $all
                ? $count . ' data ' . $label . ' dihapus.'
                : 'Data ' . $label . ' dihapus.']
            : ['error', 'Data tidak ditemukan atau sudah terhapus.'];
    } catch (Throwable $e) {
        error_log('Dashboard delete error: ' . $e->getMessage());
        $_SESSION['dashboard_flash'] = ['error', 'Gagal menghapus data.'];
    }

    header('Location: dashboard.php');
    exit;
}

$flash = $_SESSION['dashboard_flash'] ?? null;
unset($_SESSION['dashboard_flash']);

// Ringkasan daftar tamu (tabel bisa belum ada di database lama).
$guestSummary = ['total' => 0, 'terkirim' => 0];

try {
    $guestStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(invitation_sent = 1), 0) AS terkirim
        FROM invitation_guests
        WHERE invitation_id = :inv
    ");
    $guestStmt->execute([':inv' => $invitationId]);
    $guestSummary = $guestStmt->fetch() ?: $guestSummary;
} catch (Throwable $e) {
    error_log('Dashboard guest summary: ' . $e->getMessage());
}

// Rekapitulasi RSVP
$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(attendance = 'Hadir'), 0) AS hadir,
        COALESCE(SUM(attendance = 'Tidak Hadir'), 0) AS tidak_hadir
    FROM rsvps
    WHERE invitation_id = :inv
");
$summaryStmt->execute([':inv' => $invitationId]);
$summary = $summaryStmt->fetch();

// Data RSVP
$rsvpStmt = $pdo->prepare("
    SELECT id, name, attendance, created_at
    FROM rsvps
    WHERE invitation_id = :inv
    ORDER BY created_at DESC, id DESC
");
$rsvpStmt->execute([':inv' => $invitationId]);
$rsvps = $rsvpStmt->fetchAll();

// Data ucapan
$wishStmt = $pdo->prepare("
    SELECT id, name, message, created_at
    FROM wishes
    WHERE invitation_id = :inv
    ORDER BY created_at DESC, id DESC
");
$wishStmt->execute([':inv' => $invitationId]);
$wishes = $wishStmt->fetchAll();

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Dashboard Admin - Undangan</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f3f6fb;
    color: #1f4e96;
    font-family: Arial, sans-serif;
}

header {
    background: #1f4e96;
    color: white;
    padding: 20px max(20px, calc((100% - 1100px)/2));
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

a {
    color: inherit;
}

.alert {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 14px;
}

.alert-success {
    background: #dff2e5;
    color: #24613a;
}

.alert-error {
    background: #f8e0e0;
    color: #912f2f;
}

.btn-del {
    border: 0;
    background: #d9534f;
    color: #fff;
    border-radius: 7px;
    padding: 6px 12px;
    font-size: 12px;
    cursor: pointer;
}

.btn-del:hover {
    background: #c0403c;
}

.btn-clear {
    border: 1px solid #d9534f;
    background: transparent;
    color: #b33;
    border-radius: 20px;
    padding: 9px 14px;
    font-size: 13px;
    cursor: pointer;
    margin: 0 6px 12px 0;
}

.btn-clear:hover {
    background: #fdeeee;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.toolbar form {
    margin: 0;
}

.logout {
    border: 1px solid #ffffff88;
    padding: 9px 14px;
    border-radius: 20px;
    background: transparent;
    color: white;
    font-size: 13px;
    cursor: pointer;
}

main {
    max-width: 1100px;
    margin: 28px auto;
    padding: 0 18px;
}

.cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}

.card, section {
    background: white;
    border: 1px solid #dde6f2;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 20px;
}

.num {
    font-size: 28px;
    font-weight: bold;
    margin-top: 8px;
}

.muted {
    color: #64748b;
    font-size: 13px;
}

h2 {
    font-family: Georgia, serif;
    margin: 0 0 16px;
}

table {
    border-collapse: collapse;
    width: 100%;
    font-size: 13px;
}

th, td {
    text-align: left;
    padding: 11px 9px;
    border-bottom: 1px solid #e9eef6;
    vertical-align: top;
}

th {
    background: #f4f7fc;
}

.table-wrap {
    overflow-x: auto;
}

.btn {
    display: inline-block;
    background: #1f4e96;
    color: white;
    text-decoration: none;
    padding: 10px 14px;
    border-radius: 20px;
    font-size: 13px;
    margin: 0 6px 12px 0;
}

.status {
    font-weight: bold;
}

.yes {
    color: #16834a;
}

.no {
    color: #b33;
}

@media(max-width: 700px) {
    .cards {
        grid-template-columns: 1fr;
    }

    header {
        align-items: flex-start;
    }
}
</style>
</head>

<body>

<?php admin_nav($ctx, 'dashboard'); ?>

<main>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash[0] === 'success' ? 'success' : 'error' ?>">
        <?= h($flash[1]) ?>
    </div>
<?php endif; ?>

<h1>Rekapitulasi Data</h1>
<p class="muted" style="margin:-6px 0 18px">
    Undangan: <strong><?= h($ctx['invitation']['couple_name']) ?></strong>
</p>

<div class="cards">

    <div class="card">
        <div class="muted">Total Konfirmasi</div>
        <div class="num"><?= (int)$summary['total'] ?></div>
    </div>

    <div class="card">
        <div class="muted">Konfirmasi Hadir</div>
        <div class="num"><?= (int)$summary['hadir'] ?></div>
    </div>

    <div class="card">
        <div class="muted">Tidak Hadir</div>
        <div class="num"><?= (int)$summary['tidak_hadir'] ?></div>
    </div>

    <div class="card">
        <div class="muted">Tamu Terdaftar</div>
        <div class="num"><?= (int)$guestSummary['total'] ?></div>
        <a class="muted" href="guests.php">Kelola daftar tamu →</a>
    </div>

    <div class="card">
        <div class="muted">Undangan Terkirim</div>
        <div class="num">
            <?= (int)$guestSummary['terkirim'] ?>
            <span class="muted">dari <?= (int)$guestSummary['total'] ?></span>
        </div>
    </div>

    <div class="card">
        <div class="muted">Ucapan Masuk</div>
        <div class="num"><?= count($wishes) ?></div>
    </div>

</div>

<section>

    <h2>Data Konfirmasi Kehadiran</h2>

    <div class="toolbar">
        <a class="btn" href="export-rsvp.php">
            ⬇ Unduh Excel (.xlsx)
        </a>

        <?php if ($isAdmin && $rsvps): ?>
            <form method="POST"
                  onsubmit="return confirm('Hapus SEMUA konfirmasi kehadiran undangan ini? Tindakan ini tidak bisa dibatalkan.')">
                <input type="hidden" name="csrf_token" value="<?= h($dashCsrf) ?>">
                <input type="hidden" name="action" value="clear_rsvp">
                <button type="submit" class="btn-clear">Kosongkan semua</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama</th>
                    <th>Status</th>
                    <th>Waktu Konfirmasi</th>
                    <?php if ($isAdmin): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>

            <tbody>

            <?php if (!$rsvps): ?>

                <tr>
                    <td colspan="<?= $isAdmin ? 5 : 4 ?>">Belum ada data RSVP.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($rsvps as $i => $r): ?>

                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($r['name']) ?></td>

                        <td class="status <?= $r['attendance'] === 'Hadir' ? 'yes' : 'no' ?>">
                            <?= h($r['attendance']) ?>
                        </td>

                        <td><?= h($r['created_at']) ?></td>

                        <?php if ($isAdmin): ?>
                            <td>
                                <form method="POST" style="margin:0"
                                      onsubmit="return confirm('Hapus konfirmasi dari &quot;<?= h(addslashes($r['name'])) ?>&quot;?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($dashCsrf) ?>">
                                    <input type="hidden" name="action" value="delete_rsvp">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="btn-del">Hapus</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
    </div>

</section>

<section>

    <h2>Ucapan dan Doa Tamu</h2>

    <div class="toolbar">
        <a class="btn" href="export-wishes.php">
            ⬇ Unduh Excel (.xlsx)
        </a>

        <?php if ($isAdmin && $wishes): ?>
            <form method="POST"
                  onsubmit="return confirm('Hapus SEMUA ucapan undangan ini? Tindakan ini tidak bisa dibatalkan.')">
                <input type="hidden" name="csrf_token" value="<?= h($dashCsrf) ?>">
                <input type="hidden" name="action" value="clear_wishes">
                <button type="submit" class="btn-clear">Kosongkan semua</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama</th>
                    <th>Ucapan</th>
                    <th>Waktu</th>
                    <?php if ($isAdmin): ?><th>Aksi</th><?php endif; ?>
                </tr>
            </thead>

            <tbody>

            <?php if (!$wishes): ?>

                <tr>
                    <td colspan="<?= $isAdmin ? 5 : 4 ?>">Belum ada ucapan.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($wishes as $i => $w): ?>

                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($w['name']) ?></td>
                        <td><?= nl2br(h($w['message'])) ?></td>
                        <td><?= h($w['created_at']) ?></td>

                        <?php if ($isAdmin): ?>
                            <td>
                                <form method="POST" style="margin:0"
                                      onsubmit="return confirm('Hapus ucapan dari &quot;<?= h(addslashes($w['name'])) ?>&quot;?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($dashCsrf) ?>">
                                    <input type="hidden" name="action" value="delete_wish">
                                    <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                                    <button type="submit" class="btn-del">Hapus</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
    </div>

</section>

</main>

</body>
</html>
