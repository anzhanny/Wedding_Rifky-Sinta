<?php
declare(strict_types=1);

// Halaman awal folder admin: arahkan ke dashboard.
// Jika belum login, dashboard.php otomatis meneruskan ke halaman login.
header('Location: dashboard.php');
exit;
