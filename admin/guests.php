<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_admin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/guest_helpers.php';
require_once __DIR__ . '/../config/guest_import.php';
require_once __DIR__ . '/layout.php';

$adminCtx = admin_context($pdo);

// Undangan yang sedang dikelola. SEMUA data di halaman ini dibatasi ke undangan ini.
$invitation = $adminCtx['invitation'];
$invitationId = (int)$invitation['id'];

// Pastikan kolom slug (nama di link) ada, termasuk di database lama.
try {
    ensure_guest_schema($pdo);
} catch (Throwable $e) {
    error_log('ensure_guest_schema: ' . $e->getMessage());
    http_response_code(500);
    exit('Database belum siap. Import database/undangan.sql terlebih dahulu.');
}

// --------------------------------------------------
// CSRF TOKEN
// --------------------------------------------------
if (empty($_SESSION['guests_csrf'])) {
    $_SESSION['guests_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['guests_csrf'];
$success = '';
$error = '';

// --------------------------------------------------
// HELPER
// --------------------------------------------------
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect_guests(): void
{
    header('Location: guests.php');
    exit;
}

/**
 * Membuat kode pendek unik untuk setiap tamu.
 */
function create_guest_short_code(PDO $pdo): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    for ($attempt = 0; $attempt < 10; $attempt++) {

        $code = '';

        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $stmt = $pdo->prepare(
            "SELECT id FROM invitation_guests
             WHERE short_code = :code
             LIMIT 1"
        );

        $stmt->execute([':code' => $code]);

        if (!$stmt->fetch()) {
            return $code;
        }
    }

    throw new RuntimeException(
        'Gagal membuat kode undangan unik.'
    );
}

// --------------------------------------------------
// BASE URL (LOCAL & HOSTING)
// --------------------------------------------------
$isHttps = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
);

$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$projectRoot = dirname(dirname($_SERVER['SCRIPT_NAME']));
$projectRoot = str_replace('\\', '/', $projectRoot);
$projectRoot = rtrim($projectRoot, '/');

if ($projectRoot === '/') {
    $projectRoot = '';
}

$baseUrl = $scheme . '://' . $host . $projectRoot . '/';


 // --------------------------------------------------
 // GENERATE SHORT CODE FOR EXISTING GUESTS
 // --------------------------------------------------
$stmt = $pdo->query(
    "SELECT id
     FROM invitation_guests
     WHERE short_code IS NULL OR short_code = ''"
);

$guestsWithoutCode = $stmt->fetchAll();

if (!empty($guestsWithoutCode)) {

    $updateStmt = $pdo->prepare(
        "UPDATE invitation_guests
         SET short_code = :short_code
         WHERE id = :id
           AND (short_code IS NULL OR short_code = '')"
    );

    foreach ($guestsWithoutCode as $row) {

        $shortCode = create_guest_short_code($pdo);

        $updateStmt->execute([
            ':short_code' => $shortCode,
            ':id' => $row['id']
        ]);
    }
}

/**
 * Apakah tamu ini milik undangan yang sedang dikelola?
 */
function guest_belongs_to(PDO $pdo, int $guestId, int $invitationId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM invitation_guests WHERE id = :id AND invitation_id = :inv LIMIT 1'
    );
    $stmt->execute([':id' => $guestId, ':inv' => $invitationId]);

    return (bool)$stmt->fetchColumn();
}

/**
 * Menambahkan banyak tamu sekaligus (dipakai input teks dan unggah Excel).
 * Nama yang sudah ada di database (tanpa membedakan huruf besar/kecil) dilewati,
 * supaya CPP dan CPW tidak membuat link ganda untuk tamu yang sama.
 *
 * @param string[] $names
 * @return array{0:int,1:string[]} [jumlah ditambahkan, daftar nama yang dilewati]
 */
function add_guests(PDO $pdo, array $names, int $invitationId): array
{
    $existing = [];

    $existingStmt = $pdo->prepare(
        'SELECT guest_name FROM invitation_guests WHERE invitation_id = :inv'
    );
    $existingStmt->execute([':inv' => $invitationId]);

    foreach ($existingStmt as $row) {
        $existing[mb_strtolower((string)$row['guest_name'], 'UTF-8')] = true;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO invitation_guests (invitation_id, guest_name, slug, token, short_code)
         VALUES (:invitation_id, :guest_name, :slug, :token, :short_code)"
    );

    $added = 0;
    $skipped = [];

    $pdo->beginTransaction();

    try {
        foreach ($names as $name) {
            $name = guest_clean_name((string)$name);

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name, 'UTF-8');

            if (isset($existing[$key])) {
                $skipped[] = $name;
                continue;
            }

            $stmt->execute([
                ':invitation_id' => $invitationId,
                ':guest_name' => $name,
                ':slug' => guest_unique_slug($pdo, $name, $invitationId),
                ':token' => bin2hex(random_bytes(32)),
                ':short_code' => create_guest_short_code($pdo),
            ]);

            $existing[$key] = true;
            $added++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return [$added, $skipped];
}

/**
 * Teks hasil penambahan tamu untuk ditampilkan ke admin.
 *
 * @param string[] $skipped
 */
function add_guests_message(int $added, array $skipped): string
{
    $message = $added . ' tamu berhasil ditambahkan.';

    if ($skipped !== []) {
        $sample = implode(', ', array_slice($skipped, 0, 5));
        $more = count($skipped) > 5 ? ' dan ' . (count($skipped) - 5) . ' lainnya' : '';
        $message .= ' ' . count($skipped) . ' nama sudah ada dan dilewati: '
            . $sample . $more . '.';
    }

    return $message;
}

// --------------------------------------------------
// UNDUH TEMPLATE EXCEL (guests.php?template=1)
// --------------------------------------------------
if (($_GET['template'] ?? '') === '1') {
    require_once __DIR__ . '/xlsx.php';

    send_xlsx(
        'template-daftar-tamu.xlsx',
        'Tamu',
        ['Nama Tamu'],
        [
            ['Bapak Andi'],
            ['Ibu Siti'],
            ['Keluarga Besar Budi'],
            ['Rizky dan pasangan'],
        ],
        [40]
    );
}
// --------------------------------------------------
// SLUG (NAMA DI LINK) UNTUK TAMU YANG BELUM PUNYA
// --------------------------------------------------
$stmt = $pdo->query(
    "SELECT id, guest_name, invitation_id
     FROM invitation_guests
     WHERE slug IS NULL OR slug = ''
     ORDER BY id ASC"
);

$guestsWithoutSlug = $stmt->fetchAll();

if (!empty($guestsWithoutSlug)) {

    $slugStmt = $pdo->prepare(
        "UPDATE invitation_guests
         SET slug = :slug
         WHERE id = :id
           AND (slug IS NULL OR slug = '')"
    );

    foreach ($guestsWithoutSlug as $row) {
        $slugStmt->execute([
            ':slug' => guest_unique_slug($pdo, (string)$row['guest_name'], (int)$row['invitation_id']),
            ':id' => $row['id']
        ]);
    }
}

// --------------------------------------------------
// POST ACTIONS
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedCsrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf, $submittedCsrf)) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan refresh halaman.');
    }

    // ==============================================
    // TANDAI TERKIRIM / BELUM (dipanggil lewat fetch, balasan JSON)
    // ==============================================
    if (($_POST['action'] ?? '') === 'mark_sent') {

        header('Content-Type: application/json; charset=UTF-8');

        $id = (int)($_POST['guest_id'] ?? 0);
        $sent = (($_POST['sent'] ?? '1') === '1') ? 1 : 0;

        if ($id <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false]);
            exit;
        }

        try {
            if (!guest_belongs_to($pdo, $id, $invitationId)) {
                http_response_code(404);
                echo json_encode(['success' => false]);
                exit;
            }

            $stmt = $pdo->prepare(
                "UPDATE invitation_guests
                 SET invitation_sent = :sent
                 WHERE id = :id AND invitation_id = :inv"
            );

            $stmt->execute([':sent' => $sent, ':id' => $id, ':inv' => $invitationId]);

            echo json_encode(['success' => true, 'sent' => $sent]);
        } catch (Throwable $e) {
            error_log('Guest mark_sent error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false]);
        }

        exit;
    }

    // ==============================================
    // SAVE WHATSAPP TEMPLATE
    // ==============================================
    if (($_POST['action'] ?? '') === 'save_template') {

        $template = $_POST['whatsapp_template'] ?? '';

        if (!is_string($template)) {
            $_SESSION['guests_flash_error'] =
                'Format template tidak valid.';
            redirect_guests();
        }

        $template = trim($template);

        if ($template === '') {
            $_SESSION['guests_flash_error'] =
                'Template pesan tidak boleh kosong.';
            redirect_guests();
        }

        if (mb_strlen($template) > 10000) {
            $_SESSION['guests_flash_error'] =
                'Template pesan maksimal 10.000 karakter.';
            redirect_guests();
        }

        if (
            strpos($template, '{nama_tamu}') === false ||
            strpos($template, '{link_undangan}') === false
        ) {
            $_SESSION['guests_flash_error'] =
                'Template wajib memiliki {nama_tamu} dan {link_undangan}.';
            redirect_guests();
        }

        try {

            // Simpan ke tabel pengaturan (satu baris per undangan, id = id undangan).
            $stmt = $pdo->prepare(
                "INSERT INTO invitation_settings (id, whatsapp_template)
                 VALUES (:inv, :template)
                 ON DUPLICATE KEY UPDATE
                    whatsapp_template = VALUES(whatsapp_template)"
            );

            $stmt->execute([
                ':inv' => $invitationId,
                ':template' => $template
            ]);

            $_SESSION['guests_flash_success'] =
                'Template WhatsApp berhasil disimpan.';

        } catch (Throwable $e) {

            error_log('WhatsApp template error: ' . $e->getMessage());

            $_SESSION['guests_flash_error'] =
                'Gagal menyimpan template WhatsApp.';
        }

        redirect_guests();
    }

    $action = $_POST['action'] ?? '';

    // ==============================================
    // BULK ADD GUESTS
    // ==============================================
    if ($action === 'bulk_add') {

        $rawNames = trim((string)($_POST['guest_names'] ?? ''));

        if ($rawNames === '') {
            $_SESSION['guests_flash_error'] = 'Nama tamu belum diisi.';
            redirect_guests();
        }

        $names = preg_split('/\r\n|\r|\n/', $rawNames) ?: [];
        $names = array_map('guest_clean_name', $names);
        $names = array_filter($names, fn($name) => $name !== '');

        // Hilangkan duplikat dalam input yang sama
        $names = array_values(array_unique($names));

        if (count($names) > 500) {
            $_SESSION['guests_flash_error'] =
                'Maksimal 500 nama dalam satu kali input.';
            redirect_guests();
        }

        try {
            [$added, $skipped] = add_guests($pdo, $names, $invitationId);

            $_SESSION['guests_flash_success'] =
                add_guests_message($added, $skipped);

        } catch (Throwable $e) {

            error_log('Guest bulk insert error: ' . $e->getMessage());

            $_SESSION['guests_flash_error'] =
                'Gagal menyimpan data tamu.';
        }

        redirect_guests();
    }

    // ==============================================
    // IMPORT GUESTS FROM EXCEL / CSV
    // ==============================================
    if ($action === 'import_file') {

        $file = $_FILES['guest_file'] ?? null;

        if (
            !is_array($file) ||
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            $_SESSION['guests_flash_error'] = 'Pilih file Excel terlebih dahulu.';
            redirect_guests();
        }

        if (
            $file['error'] !== UPLOAD_ERR_OK ||
            !is_uploaded_file((string)$file['tmp_name'])
        ) {
            $_SESSION['guests_flash_error'] =
                'File gagal diunggah. Coba lagi dengan file yang lebih kecil.';
            redirect_guests();
        }

        if ((int)$file['size'] > 2 * 1024 * 1024) {
            $_SESSION['guests_flash_error'] = 'Ukuran file maksimal 2 MB.';
            redirect_guests();
        }

        try {
            $names = guest_names_from_upload(
                (string)$file['tmp_name'],
                (string)$file['name']
            );

            // Hilangkan duplikat dalam file yang sama
            $unique = [];
            foreach ($names as $name) {
                $unique[mb_strtolower($name, 'UTF-8')] ??= $name;
            }
            $names = array_values($unique);

            if ($names === []) {
                $_SESSION['guests_flash_error'] =
                    'Tidak ada nama tamu yang terbaca di file. '
                    . 'Isi nama pada kolom pertama atau kolom berjudul "Nama Tamu".';
                redirect_guests();
            }

            if (count($names) > 500) {
                $_SESSION['guests_flash_error'] =
                    'File berisi ' . count($names) . ' nama. Maksimal 500 nama dalam satu kali unggah.';
                redirect_guests();
            }

            [$added, $skipped] = add_guests($pdo, $names, $invitationId);

            $_SESSION['guests_flash_success'] =
                'Dari file Excel: ' . add_guests_message($added, $skipped);

        } catch (RuntimeException $e) {

            // Pesan ini aman ditampilkan (dibuat sendiri oleh pembaca file).
            $_SESSION['guests_flash_error'] = $e->getMessage();

        } catch (Throwable $e) {

            error_log('Guest import error: ' . $e->getMessage());

            $_SESSION['guests_flash_error'] =
                'Gagal membaca atau menyimpan file.';
        }

        redirect_guests();
    }
    // ==============================================
    // EDIT GUEST
    // ==============================================
    if ($action === 'edit') {

        $id = (int)($_POST['guest_id'] ?? 0);
        $name = trim($_POST['guest_name'] ?? '');

        if ($id <= 0 || $name === '') {
            $_SESSION['guests_flash_error'] =
                'Data nama tamu tidak valid.';
            redirect_guests();
        }

        try {
            if (!guest_belongs_to($pdo, $id, $invitationId)) {
                $_SESSION['guests_flash_error'] = 'Tamu tidak ditemukan.';
                redirect_guests();
            }

            $stmt = $pdo->prepare(
                "UPDATE invitation_guests
                 SET guest_name = :guest_name
                 WHERE id = :id AND invitation_id = :inv"
            );

            $stmt->execute([
                ':guest_name' => mb_substr($name, 0, 150),
                ':id' => $id,
                ':inv' => $invitationId
            ]);

            $_SESSION['guests_flash_success'] =
                'Nama tamu berhasil diperbarui.';

        } catch (Throwable $e) {

            error_log('Guest edit error: ' . $e->getMessage());

            $_SESSION['guests_flash_error'] =
                'Gagal memperbarui nama tamu.';
        }

        redirect_guests();
    }

    // ==============================================
    // DELETE GUEST
    // ==============================================
    if ($action === 'delete') {

        $id = (int)($_POST['guest_id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['guests_flash_error'] =
                'ID tamu tidak valid.';
            redirect_guests();
        }

        try {
            $stmt = $pdo->prepare(
                "DELETE FROM invitation_guests
                 WHERE id = :id AND invitation_id = :inv"
            );

            $stmt->execute([':id' => $id, ':inv' => $invitationId]);

            if ($stmt->rowCount() === 0) {
                $_SESSION['guests_flash_error'] = 'Tamu tidak ditemukan.';
                redirect_guests();
            }

            $_SESSION['guests_flash_success'] =
                'Data tamu berhasil dihapus.';

        } catch (Throwable $e) {

            error_log('Guest delete error: ' . $e->getMessage());

            $_SESSION['guests_flash_error'] =
                'Gagal menghapus data tamu.';
        }

        redirect_guests();
    }
}


// --------------------------------------------------
// WHATSAPP TEMPLATE
// --------------------------------------------------
// Tanda *teks* = cetak tebal di WhatsApp. Baris kosong dibuat dengan \n\n.
$defaultWhatsappTemplate =
    "Assalamu'alaikum Warahmatullahi Wabarakatuh.\n\n" .
    "Kepada Yth.\n" .
    "Bapak/Ibu/Saudara/i *{nama_tamu}*\n" .
    "di tempat\n\n" .
    "Dengan memohon rahmat dan ridho Allah SWT, kami bermaksud " .
    "mengundang Bapak/Ibu/Saudara/i untuk menghadiri acara " .
    "pernikahan kami:\n" .
    "*" . $invitation['couple_name'] . "*\n\n" .
    "Detail acara dan lokasi dapat dilihat pada tautan " .
    "undangan berikut:\n" .
    "{link_undangan}\n\n" .
    "Merupakan suatu kehormatan dan kebahagiaan bagi kami " .
    "apabila Bapak/Ibu/Saudara/i berkenan hadir dan " .
    "memberikan doa restu.\n\n" .
    "Atas perhatian dan kehadirannya, kami ucapkan terima kasih.\n" .
    "Wassalamu'alaikum Warahmatullahi Wabarakatuh.\n\n" .
    "Hormat kami,\n" .
    "*" . $invitation['couple_name'] . "*";

$stmt = $pdo->prepare(
    "SELECT whatsapp_template
     FROM invitation_settings
     WHERE id = :inv
     LIMIT 1"
);
$stmt->execute([':inv' => $invitationId]);

$savedTemplate = $stmt->fetchColumn();

$whatsappTemplate = (
    $savedTemplate !== false &&
    $savedTemplate !== ''
)
    ? $savedTemplate
    : $defaultWhatsappTemplate;

// --------------------------------------------------
// FLASH MESSAGES
// --------------------------------------------------
$success = $_SESSION['guests_flash_success'] ?? '';
$error = $_SESSION['guests_flash_error'] ?? '';

unset(
    $_SESSION['guests_flash_success'],
    $_SESSION['guests_flash_error']
);

// --------------------------------------------------
// FETCH GUEST DATA
// --------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT
        id,
        guest_name,
        slug,
        token,
        short_code,
        invitation_sent,
        created_at
     FROM invitation_guests
     WHERE invitation_id = :inv
     ORDER BY id DESC"
);
$stmt->execute([':inv' => $invitationId]);

$guests = $stmt->fetchAll();

$totalGuests = count($guests);
$totalSent = count(array_filter(
    $guests,
    fn($g) => (int)$g['invitation_sent'] === 1
));
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Guest Management | Admin</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f6fb;
    color: #1f2d3d;
}

header {
    background: #1f4e96;
    color: white;
    padding: 22px 30px;
}

header h1 {
    margin: 0;
    font-size: 24px;
}

header p {
    margin: 8px 0 0;
    color: #e6f0fc;
}

.container {
    max-width: 1200px;
    margin: auto;
    padding: 25px;
}

.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.btn {
    border: none;
    padding: 10px 14px;
    border-radius: 7px;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
    display: inline-block;
}

.btn-green {
    background: #2a62b8;
    color: white;
}

.btn-light {
    background: #e3ebf6;
    color: #1f4e96;
}

.btn-red {
    background: #d9534f;
    color: white;
}

.btn-gold {
    background: #9cc9f5;
    color: #1f2d3d;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 3px 15px rgba(0,0,0,.04);
}

.card h2 {
    margin-top: 0;
}

textarea,
input[type="text"] {
    width: 100%;
    padding: 12px;
    border: 1px solid #cfd9e6;
    border-radius: 7px;
    font-family: inherit;
    font-size: 14px;
}

textarea {
    min-height: 150px;
    resize: vertical;
}

.form-note {
    font-size: 13px;
    color: #777;
    margin: 10px 0 15px;
}

.stats {
    background: #e6eef9;
    color: #1f4e96;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 20px;
}

.stats strong {
    font-size: 28px;
    display: block;
    margin-top: 5px;
}

.alert {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 18px;
}

.alert-success {
    background: #dff2e5;
    color: #24613a;
}

.alert-error {
    background: #f8e0e0;
    color: #912f2f;
}

.search-box {
    margin-bottom: 15px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

th,
td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    vertical-align: middle;
}

th {
    background: #f4f7fc;
    font-size: 13px;
}

td {
    font-size: 13px;
}

.link-text {
    max-width: 250px;
    overflow-wrap: anywhere;
    color: #2a62b8;
}

.link-text a {
    color: #2b6cb0;
    text-decoration: underline;
}

.link-text a:hover {
    color: #1f4e96;
}

.badge {
    border: 0;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 12px;
    cursor: pointer;
    background: #e8edf4;
    color: #64748b;
}

.badge.is-sent {
    background: #dff2e5;
    color: #24613a;
    font-weight: bold;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.edit-form {
    display: flex;
    gap: 5px;
    min-width: 210px;
}

.edit-form input {
    min-width: 120px;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #888;
}

@media(max-width:600px) {
    .container {
        padding: 15px;
    }

    header {
        padding: 20px;
    }
}
</style>
</head>

<body>

<?php admin_nav($adminCtx, 'guests'); ?>

<div class="container">

    <div class="top-bar">
        <div>
            <h2 style="margin:0">Daftar Tamu Undangan</h2>
            <p class="form-note" style="margin:6px 0 0">
                Kelola daftar tamu dan link undangan personal
            </p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="stats">
        Total Tamu Terdaftar
        <strong><?= $totalGuests ?></strong>
        <span id="sentSummary" style="font-size:13px">
            Terkirim: <b id="sentCount"><?= $totalSent ?></b>
            dari <?= $totalGuests ?>
        </span>
    </div>

<!-- WHATSAPP TEMPLATE SETTINGS -->
<div class="card">
    <h2>Pengaturan Pesan WhatsApp</h2>

    <p class="form-note">
        Atur pesan undangan yang akan digunakan ketika
        tombol WhatsApp pada daftar tamu diklik.
    </p>

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($csrf) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save_template"
        >

        <label for="whatsapp_template">
            Template Pesan
        </label>

        <textarea
            id="whatsapp_template"
            name="whatsapp_template"
            maxlength="10000"
            style="min-height: 280px;"
            required
        ><?= h($whatsappTemplate) ?></textarea>

        <p class="form-note">
            Gunakan variabel berikut pada pesan:
            <br>
            <strong>{nama_tamu}</strong> = Nama penerima undangan
            <br>
            <strong>{link_undangan}</strong> = Link undangan personal
        </p>

        <button type="submit" class="btn btn-green">
            Simpan Template WhatsApp
        </button>

    </form>
</div>

    <!-- BULK INPUT -->
    <div class="card">
        <h2>Tambah Tamu</h2>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= h($csrf) ?>">

            <input type="hidden" name="action"
                   value="bulk_add">

            <label for="guest_names">
                Nama tamu (satu nama per baris)
            </label>

            <textarea
                id="guest_names"
                name="guest_names"
                placeholder="Contoh:
Bapak Andi
Ibu Siti
Keluarga Budi
Rizky dan pasangan"
                required></textarea>

            <p class="form-note">
                Maksimal 500 nama dalam satu kali input.
            </p>

            <button type="submit" class="btn btn-green">
                + Tambahkan Tamu
            </button>
        </form>
    </div>

    <!-- IMPORT EXCEL -->
    <div class="card">
        <h2>Unggah Excel</h2>

        <p class="form-note">
            Cocok bila daftar tamu sudah ada di Excel (misalnya dari
            keluarga mempelai pria atau wanita). Sistem membaca nama
            tamu lalu membuat link undangan seperti biasa.
        </p>

        <form method="POST" enctype="multipart/form-data">

            <input type="hidden" name="csrf_token"
                   value="<?= h($csrf) ?>">

            <input type="hidden" name="action"
                   value="import_file">

            <label for="guest_file">
                File daftar tamu (.xlsx atau .csv)
            </label>

            <input
                type="file"
                id="guest_file"
                name="guest_file"
                accept=".xlsx,.csv"
                required
                style="display:block;margin:8px 0 12px">

            <p class="form-note">
                Isi nama tamu pada <strong>kolom pertama</strong>, satu nama
                per baris, atau pada kolom berjudul
                <strong>Nama Tamu</strong>. Baris judul boleh ada atau tidak.
                Maksimal 500 nama dan ukuran 2 MB. Nama yang sudah terdaftar
                akan dilewati.
            </p>

            <button type="submit" class="btn btn-green">
                Unggah &amp; Buat Link
            </button>

            <a href="guests.php?template=1" class="btn btn-light">
                Unduh Template Excel
            </a>
        </form>
    </div>
    <!-- GUEST TABLE -->
    <div class="card">
        <h2>Data Tamu</h2>

        <input
            type="text"
            id="searchGuest"
            class="search-box"
            placeholder="Cari nama tamu...">

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Tamu</th>
                        <th>Link Undangan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody id="guestTable">

                <?php if (empty($guests)): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            Belum ada data tamu.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($guests as $index => $guest): ?>

                        <?php
                        $invitationLink =
    $baseUrl . $invitation['slug'] . '/' . $guest['slug'];

                        $whatsappMessage = str_replace(
    ['{nama_tamu}', '{link_undangan}'],
    [$guest['guest_name'], $invitationLink],
    $whatsappTemplate
);
                        ?>

                        <tr class="guest-row">

                            <td><?= $index + 1 ?></td>

                            <td>
                                <form method="POST" class="edit-form">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= h($csrf) ?>">

                                    <input type="hidden"
                                           name="action"
                                           value="edit">

                                    <input type="hidden"
                                           name="guest_id"
                                           value="<?= (int)$guest['id'] ?>">

                                    <input type="text"
                                           name="guest_name"
                                           value="<?= h($guest['guest_name']) ?>"
                                           maxlength="150"
                                           required>

                                    <button class="btn btn-light"
                                            type="submit">
                                        Simpan
                                    </button>
                                </form>
                            </td>

                            <td>
                                <div class="link-text">
                                    <a href="<?= h($invitationLink) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <?= h($invitationLink) ?>
                                    </a>
                                </div>
                            </td>

                            <td>
                                <?php $isSent = (int)$guest['invitation_sent'] === 1; ?>
                                <button
                                    type="button"
                                    class="badge sent-badge <?= $isSent ? 'is-sent' : '' ?>"
                                    data-id="<?= (int)$guest['id'] ?>"
                                    data-sent="<?= $isSent ? '1' : '0' ?>"
                                    title="Klik untuk mengubah status">
                                    <?= $isSent ? 'Terkirim' : 'Belum' ?>
                                </button>
                            </td>

                            <td>
                                <div class="actions">

                                    <button
                                        type="button"
                                        class="btn btn-green copy-btn"
                                        data-link="<?= h($invitationLink) ?>">
                                        Copy Link
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-gold wa-btn"
                                        data-id="<?= (int)$guest['id'] ?>"
                                        data-message="<?= h($whatsappMessage) ?>">
                                        WhatsApp
                                    </button>

                                    <form method="POST"
                                          onsubmit="return confirm('Hapus tamu ini?')">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= h($csrf) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete">

                                        <input type="hidden"
                                               name="guest_id"
                                               value="<?= (int)$guest['id'] ?>">

                                        <button type="submit"
                                                class="btn btn-red">
                                            Hapus
                                        </button>
                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// --------------------------------------------------
// SEARCH GUEST
// --------------------------------------------------
document.getElementById('searchGuest')
    .addEventListener('input', function () {

        const keyword = this.value.toLowerCase();

        document.querySelectorAll('.guest-row')
            .forEach(row => {

                const nameInput = row.querySelector(
                    'input[name="guest_name"]'
                );

                const name = nameInput
                    ? nameInput.value.toLowerCase()
                    : '';

                row.style.display = name.includes(keyword)
                    ? ''
                    : 'none';
            });
    });

// --------------------------------------------------
// COPY LINK
// --------------------------------------------------
document.querySelectorAll('.copy-btn')
    .forEach(button => {

        button.addEventListener('click', async function () {

            const link = this.dataset.link;

            try {

                await navigator.clipboard.writeText(link);

                const oldText = this.textContent;
                this.textContent = 'Copied!';

                setTimeout(() => {
                    this.textContent = oldText;
                }, 1500);

            } catch (error) {

                prompt('Copy link berikut:', link);
            }
        });
    });

// --------------------------------------------------
// WHATSAPP
// --------------------------------------------------
document.querySelectorAll('.wa-btn')
    .forEach(button => {

        button.addEventListener('click', function () {

            const message = this.dataset.message;

            const whatsappUrl =
                'https://wa.me/?text=' +
                encodeURIComponent(message);

            window.open(whatsappUrl, '_blank');

            // Otomatis tandai tamu ini sudah dikirimi undangan.
            setSent(this.dataset.id, 1);
        });
    });

// --------------------------------------------------
// STATUS TERKIRIM
// --------------------------------------------------
const CSRF_TOKEN = <?= json_encode($csrf) ?>;

function renderSent(badge, sent) {
    badge.dataset.sent = sent ? '1' : '0';
    badge.textContent = sent ? 'Terkirim' : 'Belum';
    badge.classList.toggle('is-sent', !!sent);

    const count = document.querySelectorAll(
        '.sent-badge.is-sent'
    ).length;

    document.getElementById('sentCount').textContent = count;
}

async function setSent(id, sent) {

    const badge = document.querySelector(
        '.sent-badge[data-id="' + id + '"]'
    );

    if (!badge) return;

    const body = new URLSearchParams({
        csrf_token: CSRF_TOKEN,
        action: 'mark_sent',
        guest_id: id,
        sent: sent ? '1' : '0'
    });

    try {
        const response = await fetch('guests.php', {
            method: 'POST',
            body: body,
            headers: {'Accept': 'application/json'}
        });

        const result = await response.json();

        if (result.success) {
            renderSent(badge, sent);
        }
    } catch (error) {
        // Gagal menyimpan: biarkan status lama.
    }
}

document.querySelectorAll('.sent-badge')
    .forEach(badge => {
        badge.addEventListener('click', function () {
            setSent(this.dataset.id, this.dataset.sent === '1' ? 0 : 1);
        });
    });
</script>

</body>
</html>
