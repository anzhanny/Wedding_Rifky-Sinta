<?php
declare(strict_types=1);

/**
 * Bagian bersama halaman admin: peran akun, undangan aktif, dan menu samping (sidebar).
 *
 * Peran:
 *  - admin : penyedia jasa. Semua undangan, Kelola Akun, dan halaman Undangan.
 *  - klien : mempelai (CPP/CPW). Hanya satu undangan miliknya:
 *            dashboard, RSVP, ucapan, unduh Excel, daftar tamu.
 *
 * Dipanggil setelah auth.php dan config/database.php.
 */

require_once __DIR__ . '/../config/invitation.php';

const ADMIN_ROLES = [
    'admin' => 'Admin',
    'klien' => 'Mempelai',
];

function adm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Menambahkan kolom role ke tabel admins pada database lama (sekali saja).
 * Akun yang sudah ada otomatis menjadi "admin".
 */
function ensure_admin_schema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM admins LIKE 'role'");

    if ($stmt->fetch()) {
        return;
    }

    $pdo->exec(
        "ALTER TABLE admins
         ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER password_hash"
    );
}

/**
 * Mengambil akun yang sedang login langsung dari database (bukan dari session),
 * sehingga akun yang sudah dihapus atau diubah perannya langsung berlaku.
 *
 * Hasil:
 *  - id, username, role
 *  - invitation : undangan yang sedang dikelola (array id/slug/couple_name)
 *      * klien : selalu undangan miliknya (tidak bisa diganti)
 *      * admin : yang dipilih lewat pemilih undangan (default: yang pertama)
 *  - invitations: daftar semua undangan (khusus admin, untuk pemilih)
 *
 * @return array{id:int,username:string,role:string,invitation:array,invitations:array}
 */
function admin_context(PDO $pdo): array
{
    try {
        ensure_admin_schema($pdo);
        ensure_invitation_schema($pdo);

        $stmt = $pdo->prepare(
            'SELECT id, username, role, invitation_id
             FROM admins WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => (int)($_SESSION['admin_id'] ?? 0)]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('admin_context: ' . $e->getMessage());
        http_response_code(500);
        exit('Terjadi kesalahan sistem.');
    }

    // Akun sudah dihapus: akhiri sesi.
    if (!$row) {
        $_SESSION = [];
        header('Location: login.php');
        exit;
    }

    $role = isset(ADMIN_ROLES[$row['role']]) ? $row['role'] : 'klien';

    $_SESSION['admin_username'] = $row['username'];

    $invitations = [];
    $invitation = null;

    try {
        if ($role === 'admin') {
            $invitations = invitation_all($pdo);

            $chosen = (int)($_SESSION['admin_invitation_id'] ?? 0);

            foreach ($invitations as $item) {
                if ($item['id'] === $chosen) {
                    $invitation = $item;
                }
            }

            $invitation ??= $invitations[0] ?? null;
        } else {
            $invitation = $row['invitation_id'] !== null
                ? invitation_by_id($pdo, (int)$row['invitation_id'])
                : null;
        }
    } catch (Throwable $e) {
        error_log('admin_context invitation: ' . $e->getMessage());
        http_response_code(500);
        exit('Terjadi kesalahan sistem.');
    }

    if ($invitation === null) {
        http_response_code(403);
        exit(
            $role === 'admin'
                ? 'Belum ada undangan. Buat undangan terlebih dahulu.'
                : 'Akun Anda belum dihubungkan ke undangan. Hubungi admin.'
        );
    }

    return [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => $role,
        'invitation' => $invitation,
        'invitations' => $invitations,
    ];
}

/**
 * Hanya peran tertentu yang boleh membuka halaman ini.
 */
function require_admin_role(array $ctx, string $role): void
{
    if ($ctx['role'] !== $role) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke halaman ini.');
    }
}

/**
 * Alamat dasar folder proyek, mis. "https://domain.com/" atau
 * "http://localhost/wedding/Digital_Card_Invitation_Wedding/".
 * Dipakai untuk membuat link undangan yang bisa diklik.
 */
function admin_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $root = str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'))));
    $root = rtrim($root, '/');

    return $scheme . '://' . $host . ($root === '' ? '' : $root) . '/';
}

/**
 * Menampilkan menu navigasi samping (sidebar) untuk semua halaman admin.
 *
 * - Desktop (> 900px): sidebar tetap di kiri, isi halaman bergeser ke kanan.
 * - HP/tablet (<= 900px): bar atas kecil dengan tombol menu; sidebar menggeser
 *   masuk dari kiri dengan latar gelap, ditutup lewat tombol, latar, atau Esc.
 *
 * Dipanggil tepat setelah <body>. Tidak perlu mengubah tata letak halaman:
 * sidebar memberi jarak pada <body> lewat CSS.
 *
 * @param array $ctx hasil admin_context()
 * @param string $active dashboard | guests | invitations | accounts
 */
function admin_nav(array $ctx, string $active): void
{
    if (empty($_SESSION['logout_csrf'])) {
        $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
    }

    if (empty($_SESSION['nav_csrf'])) {
        $_SESSION['nav_csrf'] = bin2hex(random_bytes(32));
    }

    // Ikon (garis sederhana, 24x24).
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'guests' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'invitations' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'accounts' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    ];

    $svg = static fn(string $name): string =>
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $icons[$name] . '</svg>';

    $items = [
        'dashboard' => ['dashboard.php', 'Dashboard'],
        'guests' => ['guests.php', 'Daftar Tamu'],
    ];

    if ($ctx['role'] === 'admin') {
        $items['invitations'] = ['invitations.php', 'Undangan'];
        $items['accounts'] = ['accounts.php', 'Kelola Akun'];
    }

    $inv = $ctx['invitation'];
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    $pageTitle = $items[$active][1] ?? 'Admin';
    ?>
<style>
:root {
    --adm-side-w: 260px;
    --adm-bar-h: 56px;
}

/* Geser isi halaman agar tidak tertutup sidebar */
html body {
    padding-left: var(--adm-side-w);
}

.adm-side {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--adm-side-w);
    z-index: 1000;
    display: flex;
    flex-direction: column;
    background: linear-gradient(180deg, #2f68b5 0%, #1b4a8f 100%);
    color: #e6f0fc;
    font-family: Arial, sans-serif;
    border-right: 3px solid #9cc9f5;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

.adm-side * { box-sizing: border-box; }

.adm-brand {
    font-family: Georgia, serif;
    font-size: 20px;
    color: #fff;
    padding: 22px 22px 6px;
}

.adm-sub {
    font-size: 12px;
    color: #bcd3f0;
    padding: 0 22px 18px;
}

.adm-inv {
    margin: 0 14px 16px;
    padding: 12px;
    background: rgba(255, 255, 255, .07);
    border-radius: 12px;
}

.adm-inv-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #bcd3f0;
    margin: 0 0 6px;
}

.adm-inv-name {
    font-weight: bold;
    color: #d8ebff;
    font-size: 14px;
}

.adm-inv select {
    width: 100%;
    background: rgba(255, 255, 255, .12);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, .3);
    border-radius: 9px;
    padding: 9px 10px;
    font-size: 14px;
}

.adm-inv select option {
    color: #1b4a8f;
    background: #fff;
}

.adm-links {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 0 12px;
}

.adm-side a.adm-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    color: #e6f0fc;
    text-decoration: none;
    font-size: 15px;
}

.adm-side a.adm-link svg {
    width: 20px;
    height: 20px;
    flex: none;
}

.adm-side a.adm-link:hover {
    background: rgba(255, 255, 255, .09);
    color: #fff;
}

.adm-side a.adm-link.is-active {
    background: #9cc9f5;
    color: #1b4a8f;
    font-weight: bold;
}

.adm-sep {
    height: 1px;
    background: rgba(255, 255, 255, .12);
    margin: 14px 26px;
}

.adm-foot {
    margin-top: auto;
    padding: 16px 16px 20px;
    border-top: 1px solid rgba(255, 255, 255, .12);
}

.adm-user {
    font-size: 14px;
    color: #fff;
    margin-bottom: 10px;
    word-break: break-all;
}

.adm-role {
    display: inline-block;
    background: rgba(255, 255, 255, .14);
    border-radius: 10px;
    padding: 2px 9px;
    font-size: 11px;
    margin-left: 6px;
    color: #e6f0fc;
}

.adm-logout {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px solid rgba(255, 255, 255, .4);
    background: transparent;
    color: #fff;
    padding: 11px 14px;
    border-radius: 12px;
    font-size: 14px;
    cursor: pointer;
}

.adm-logout svg { width: 18px; height: 18px; }

.adm-logout:hover { background: rgba(255, 255, 255, .12); }

/* Bar atas + latar gelap: hanya tampil di HP/tablet */
.adm-topbar,
.adm-backdrop {
    display: none;
}

@media (max-width: 900px) {
    html body {
        padding-left: 0;
        padding-top: var(--adm-bar-h);
    }

    .adm-topbar {
        display: flex;
        align-items: center;
        gap: 12px;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: var(--adm-bar-h);
        z-index: 900;
        padding: 0 12px;
        background: #1f4e96;
        color: #fff;
        font-family: Arial, sans-serif;
        border-bottom: 3px solid #9cc9f5;
    }

    .adm-menu-btn {
        width: 42px;
        height: 42px;
        border: 0;
        border-radius: 10px;
        background: rgba(255, 255, 255, .1);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        padding: 0;
    }

    .adm-menu-btn svg { width: 24px; height: 24px; }

    .adm-top-title {
        font-family: Georgia, serif;
        font-size: 18px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .adm-top-inv {
        margin-left: auto;
        font-size: 12px;
        color: #d8ebff;
        background: rgba(156, 201, 245, .25);
        border-radius: 12px;
        padding: 4px 10px;
        max-width: 42%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .adm-side {
        transform: translateX(-105%);
        transition: transform .25s ease;
        width: min(82vw, 300px);
        box-shadow: none;
    }

    body.adm-open .adm-side {
        transform: translateX(0);
        box-shadow: 8px 0 30px rgba(0, 0, 0, .35);
    }

    .adm-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 950;
        background: rgba(16, 36, 70, .5);
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s ease;
    }

    .adm-side { z-index: 1000; }

    body.adm-open .adm-backdrop {
        opacity: 1;
        pointer-events: auto;
    }

    body.adm-open { overflow: hidden; }
}

@media (prefers-reduced-motion: reduce) {
    .adm-side, .adm-backdrop { transition: none; }
}
</style>

<div class="adm-topbar">
    <button type="button" class="adm-menu-btn" id="adm-menu-btn"
            aria-label="Buka menu" aria-controls="adm-side" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" aria-hidden="true">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
    <div class="adm-top-title"><?= adm_h($pageTitle) ?></div>
    <div class="adm-top-inv"><?= adm_h($inv['couple_name']) ?></div>
</div>

<div class="adm-backdrop" id="adm-backdrop"></div>

<aside class="adm-side" id="adm-side" aria-label="Menu admin">
    <div class="adm-brand">Undangan Digital</div>
    <div class="adm-sub">Panel pengelolaan</div>

    <div class="adm-inv">
        <p class="adm-inv-title">Undangan</p>

        <?php if ($ctx['role'] === 'admin'): ?>
            <form method="POST" action="switch.php" style="margin:0">
                <input type="hidden" name="csrf" value="<?= adm_h($_SESSION['nav_csrf']) ?>">
                <input type="hidden" name="back" value="<?= adm_h($current) ?>">
                <select name="invitation_id" aria-label="Pilih undangan"
                        onchange="this.form.submit()">
                    <?php foreach ($ctx['invitations'] as $item): ?>
                        <option value="<?= (int)$item['id'] ?>"
                            <?= $item['id'] === $inv['id'] ? 'selected' : '' ?>>
                            <?= adm_h($item['couple_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit">Pilih</button></noscript>
            </form>
        <?php else: ?>
            <div class="adm-inv-name"><?= adm_h($inv['couple_name']) ?></div>
        <?php endif; ?>
    </div>

    <nav class="adm-links">
        <?php foreach ($items as $key => [$url, $label]): ?>
            <a class="adm-link <?= $key === $active ? 'is-active' : '' ?>"
               href="<?= adm_h($url) ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <?= $svg($key) ?><span><?= adm_h($label) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="adm-sep"></div>

        <a class="adm-link" href="../<?= adm_h($inv['slug']) ?>"
           target="_blank" rel="noopener">
            <?= $svg('external') ?><span>Lihat Undangan</span>
        </a>
    </nav>

    <div class="adm-foot">
        <div class="adm-user">
            <?= adm_h($ctx['username']) ?>
            <span class="adm-role"><?= adm_h(ADMIN_ROLES[$ctx['role']]) ?></span>
        </div>

        <form method="POST" action="logout.php" style="margin:0">
            <input type="hidden" name="csrf"
                   value="<?= adm_h($_SESSION['logout_csrf']) ?>">
            <button type="submit" class="adm-logout">
                <?= $svg('logout') ?><span>Keluar</span>
            </button>
        </form>
    </div>
</aside>

<script>
(function () {
    var btn = document.getElementById('adm-menu-btn');
    var backdrop = document.getElementById('adm-backdrop');
    if (!btn || !backdrop) return;

    function setOpen(open) {
        document.body.classList.toggle('adm-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
    }

    btn.addEventListener('click', function () {
        setOpen(!document.body.classList.contains('adm-open'));
    });

    backdrop.addEventListener('click', function () { setOpen(false); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });

    // Kembali ke layar lebar: pastikan keadaan menu bersih.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) setOpen(false);
    });
})();
</script>
    <?php
}
