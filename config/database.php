<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

$host = 'localhost';
$dbname = 'undangan_jerry_anzhanny';
$username = 'root';
$password = ''; // Sesuaikan dengan MySQL Anda.

// Pengaturan khusus server (hosting/staging). Berkas ini TIDAK ikut ke GitHub
// (lihat .gitignore), sehingga password asli tidak bocor dan tidak tertimpa
// saat berkas proyek diunggah ulang. Contoh isi: config/database.local.example.php
$localConfig = __DIR__ . '/database.local.php';

if (is_file($localConfig)) {
    require $localConfig; // mengisi ulang $host, $dbname, $username, $password
}

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    exit('Koneksi database bermasalah. Silakan hubungi administrator.');
}
