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
require_once __DIR__ . '/layout.php';

$ctx = admin_context($pdo);

// Semua data di halaman ini hanya milik undangan yang sedang dikelola.
$invitationId = (int)$ctx['invitation']['id'];

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

    <a class="btn" href="export-rsvp.php">
        ⬇ Unduh Excel (.xlsx)
    </a>

    <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama</th>
                    <th>Status</th>
                    <th>Waktu Konfirmasi</th>
                </tr>
            </thead>

            <tbody>

            <?php if (!$rsvps): ?>

                <tr>
                    <td colspan="4">Belum ada data RSVP.</td>
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
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>
        </table>
    </div>

</section>

<section>

    <h2>Ucapan dan Doa Tamu</h2>

    <a class="btn" href="export-wishes.php">
        ⬇ Unduh Excel (.xlsx)
    </a>

    <div class="table-wrap">
        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nama</th>
                    <th>Ucapan</th>
                    <th>Waktu</th>
                </tr>
            </thead>

            <tbody>

            <?php if (!$wishes): ?>

                <tr>
                    <td colspan="4">Belum ada ucapan.</td>
                </tr>

            <?php else: ?>

                <?php foreach ($wishes as $i => $w): ?>

                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= h($w['name']) ?></td>
                        <td><?= nl2br(h($w['message'])) ?></td>
                        <td><?= h($w['created_at']) ?></td>
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
