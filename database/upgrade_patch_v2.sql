-- ==============================================================================
-- SIM-BISOLPIN ACADEMY - UPGRADE PATCH V2 (DATABASE & CORE SECURITY)
-- File: database/upgrade_patch_v2.sql
-- Keterangan:
-- Script migrasi DDL & DML untuk memperbarui database yang sudah berjalan
-- tanpa menghapus 8 tabel utama yang telah ada.
-- Kompatibel dengan MySQL 8.0+ dan MariaDB 10.4+
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. PENAMBAHAN COMPOSITE INDEX PADA TABEL OPERASIONAL UTAMA
-- ------------------------------------------------------------------------------
-- Composite Index untuk mempercepat query filtering presensi per cabang, tipe, dan tanggal
ALTER TABLE `tabel_absensi`
  ADD INDEX `idx_absensi_cabang_tipe_tanggal` (`id_cabang`, `tipe_absensi`, `tanggal_absensi`);

-- Composite Index untuk mempercepat rekap siswa aktif per cabang
ALTER TABLE `tabel_siswa`
  ADD INDEX `idx_siswa_cabang_status` (`id_cabang`, `status_belajar`);

-- Composite Index untuk mempercepat etalase & kurasi karya siswa per cabang
ALTER TABLE `tabel_produk`
  ADD INDEX `idx_produk_cabang_status` (`id_cabang`, `status_tampil`);

-- ------------------------------------------------------------------------------
-- 2. PENAMBAHAN KOLOM KEUANGAN & SOFT-DELETE PADA TABEL SISWA (tabel_siswa)
-- ------------------------------------------------------------------------------
ALTER TABLE `tabel_siswa`
  ADD COLUMN `status_pembayaran` ENUM('belum_bayar','cicilan','lunas') NOT NULL DEFAULT 'belum_bayar' AFTER `status_belajar`,
  ADD COLUMN `nominal_terbayar` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `status_pembayaran`,
  ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `nominal_terbayar`;

-- ------------------------------------------------------------------------------
-- 3. PENAMBAHAN KOLOM HYBRID STORAGE PADA TABEL MATERI & TABEL PRODUK
-- Mendukung Upload File Fisik Lokal (PDF/PPT/ZIP) dan Tautan Eksternal (GDrive, GitHub, Figma, YouTube)
-- ------------------------------------------------------------------------------
ALTER TABLE `tabel_materi`
  ADD COLUMN `tipe_lampiran` ENUM('file','link') NOT NULL DEFAULT 'file' AFTER `file_modul`,
  ADD COLUMN `external_url` VARCHAR(500) NULL AFTER `tipe_lampiran`;

ALTER TABLE `tabel_produk`
  ADD COLUMN `tipe_lampiran` ENUM('file','link') NOT NULL DEFAULT 'file' AFTER `file_atau_url_karya`,
  ADD COLUMN `external_url` VARCHAR(500) NULL AFTER `tipe_lampiran`;

-- ------------------------------------------------------------------------------
-- 4. TABEL JEMBATAN RIWAYAT LAYANAN SISWA (riwayat_layanan_siswa)
-- Mengizinkan 1 siswa mendaftar lebih dari 1 program kursus tanpa duplikasi NIS
-- ------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `riwayat_layanan_siswa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_siswa` INT NOT NULL,
  `id_cabang` INT NOT NULL,
  `id_layanan` INT NOT NULL,
  `id_mentor` INT NULL,
  `persentase_progres` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `status_pembayaran` ENUM('belum_bayar','cicilan','lunas') NOT NULL DEFAULT 'belum_bayar',
  `nominal_terbayar` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status_kursus` ENUM('aktif','lulus','cuti','keluar') NOT NULL DEFAULT 'aktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_rls_siswa` (`id_siswa`),
  INDEX `idx_rls_cabang` (`id_cabang`),
  INDEX `idx_rls_layanan` (`id_layanan`),
  INDEX `idx_rls_mentor` (`id_mentor`),
  INDEX `idx_rls_status` (`status_kursus`),
  CONSTRAINT `fk_rls_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rls_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rls_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rls_mentor` FOREIGN KEY (`id_mentor`) REFERENCES `tabel_pegawai` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. SINKRONISASI DATA EKSISTING (DATA BACKFILL & SEED INITIALIZATION)
-- ------------------------------------------------------------------------------

-- Perbarui status pembayaran data siswa eksisting
UPDATE `tabel_siswa` 
SET `status_pembayaran` = 'lunas', `nominal_terbayar` = 1850000.00, `is_archived` = 0 
WHERE `id` = 1;

UPDATE `tabel_siswa` 
SET `status_pembayaran` = 'cicilan', `nominal_terbayar` = 1000000.00, `is_archived` = 0 
WHERE `id` = 2;

-- Perbarui kolom hybrid storage data materi eksisting
UPDATE `tabel_materi` 
SET `tipe_lampiran` = 'file', `external_url` = NULL 
WHERE `file_modul` IS NOT NULL;

-- Perbarui kolom hybrid storage data produk eksisting
UPDATE `tabel_produk` 
SET `tipe_lampiran` = 'link', `external_url` = `file_atau_url_karya` 
WHERE `file_atau_url_karya` LIKE 'http%';

-- Backfill data riwayat layanan siswa dari data siswa yang sudah terdaftar
INSERT INTO `riwayat_layanan_siswa` (`id`, `id_siswa`, `id_cabang`, `id_layanan`, `id_mentor`, `persentase_progres`, `status_pembayaran`, `nominal_terbayar`, `status_kursus`, `created_at`)
VALUES
(1, 1, 1, 1, 5, 75.00, 'lunas', 1850000.00, 'aktif', NOW()),
(2, 2, 1, 1, 5, 50.00, 'cicilan', 1000000.00, 'aktif', NOW()),
-- Contoh multi-enrollment: Siswa Rizky juga mengambil kelas Digital Illustration di Cabang Makassar
(3, 1, 1, 4, 5, 25.00, 'lunas', 1650000.00, 'aktif', NOW())
ON DUPLICATE KEY UPDATE `id_siswa` = VALUES(`id_siswa`);

-- Izinkan id_cabang NULL pada tabel_absensi untuk presensi personil Kantor Pusat (BAK)
ALTER TABLE `tabel_absensi` MODIFY COLUMN `id_cabang` INT NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- UPGRADE SELESAI
-- ==============================================================================

