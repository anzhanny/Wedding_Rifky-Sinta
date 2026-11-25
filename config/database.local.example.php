<?php
declare(strict_types=1);

/**
 * CONTOH pengaturan database untuk hosting/staging.
 *
 * Cara pakai di server:
 *   1. Salin berkas ini menjadi  config/database.local.php  (di server, lewat File Manager).
 *   2. Isi nilai di bawah sesuai database yang dibuat di cPanel -> MySQL Databases.
 *   3. Jangan pernah mengunggah database.local.php ke GitHub (sudah diabaikan di .gitignore).
 *
 * Staging dan produksi memakai berkas database.local.php sendiri-sendiri
 * dengan DATABASE YANG BERBEDA, supaya data uji tidak bercampur dengan data asli.
 */

$host = 'localhost';
$dbname = 'akuncpanel_undangan';      // nama lengkap, biasanya berawalan nama akun cPanel
$username = 'akuncpanel_undanganuser'; // user database dari cPanel
$password = 'GANTI-DENGAN-PASSWORD-DATABASE';
