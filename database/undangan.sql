-- ============================================================
-- Database Undangan Pernikahan
-- Cara pakai: import file ini sekali lewat phpMyAdmin / HeidiSQL
-- atau: mysql -u root < database/undangan.sql
-- Aman dijalankan ulang (memakai IF NOT EXISTS).
-- ============================================================

CREATE DATABASE IF NOT EXISTS undangan_jerry_anzhanny
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE undangan_jerry_anzhanny;

-- ------------------------------------------------------------
-- Daftar undangan (satu baris per klien/pasangan)
-- Link undangan: {domain}/{slug}  dan  {domain}/{slug}/{nama-tamu}
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(60) NOT NULL,
    couple_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY slug (slug)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Undangan bawaan (aman dijalankan ulang)
INSERT IGNORE INTO invitations (id, slug, couple_name)
VALUES (1, 'rifky-sinta', 'Rifky & Sinta');

-- ------------------------------------------------------------
-- Ucapan & doa tamu (tampil di halaman utama)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wishes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invitation_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wishes_created (created_at, id),
    KEY idx_wishes_invitation (invitation_id, created_at, id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Konfirmasi kehadiran (RSVP)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rsvps (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invitation_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    attendance ENUM('Hadir', 'Tidak Hadir') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rsvps_created (created_at, id),
    KEY idx_rsvps_invitation (invitation_id, created_at, id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Akun admin (dibuat lewat admin/create-admin.php)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin',
    invitation_id INT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pembatasan percobaan login admin
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (username, ip_address)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pembatasan pengiriman RSVP & ucapan per IP
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rsvp_rate_limits (
    ip_address VARCHAR(45) NOT NULL,
    request_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_address)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wish_rate_limits (
    ip_address VARCHAR(45) NOT NULL,
    request_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_address)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
-- ------------------------------------------------------------
-- Daftar tamu & link undangan personal (admin/guests.php)
-- Link: {domain}/{slug-undangan}/{slug}  (link lama: {domain}/i/{short_code})
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitation_guests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invitation_id INT UNSIGNED NOT NULL,
    guest_name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NULL DEFAULT NULL,
    token CHAR(64) NOT NULL,
    short_code VARCHAR(12) NULL DEFAULT NULL,
    invitation_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY inv_slug (invitation_id, slug),
    UNIQUE KEY token (token),
    UNIQUE KEY short_code (short_code)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Pengaturan (template pesan WhatsApp, satu baris per undangan: id = id undangan)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitation_settings (
    id INT UNSIGNED NOT NULL,
    whatsapp_template TEXT NOT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
