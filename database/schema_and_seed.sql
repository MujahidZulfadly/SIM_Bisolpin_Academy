-- ==============================================================================
-- SISTEM INFORMASI MANAJEMEN KURSUS & KEMITRAAN MULTI-CABANG (SIM-BISOLPIN V2)
-- Database: sim_bisolpin_academy
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `tabel_maintenance_log`;
DROP TABLE IF EXISTS `tabel_pembayaran`;
DROP TABLE IF EXISTS `tabel_evaluasi_mentor`;
DROP TABLE IF EXISTS `riwayat_layanan_siswa`;
DROP TABLE IF EXISTS `jadwal_kelas`;
DROP TABLE IF EXISTS `tabel_aset`;
DROP TABLE IF EXISTS `tabel_produk`;
DROP TABLE IF EXISTS `tabel_absensi`;
DROP TABLE IF EXISTS `tabel_siswa`;
DROP TABLE IF EXISTS `tabel_materi`;
DROP TABLE IF EXISTS `tabel_layanan`;
DROP TABLE IF EXISTS `tabel_pegawai`;
DROP TABLE IF EXISTS `tabel_cabang`;
DROP TABLE IF EXISTS `users`;

-- ------------------------------------------------------------------------------
-- 1. TABEL PENGGUNA (users) - 7 ROLE RBAC
-- ------------------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('pimpinan','direktur_cabang','bak','mentor','staff','sarpras','siswa') NOT NULL,
  `id_cabang` INT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_cabang` (`id_cabang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 2. TABEL CABANG (tabel_cabang) - Multi-Tenant Kemitraan
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_cabang` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `kode_cabang` VARCHAR(20) NOT NULL UNIQUE,
  `nama_cabang` VARCHAR(150) NOT NULL,
  `id_direktur_cabang` INT NULL,
  `alamat` TEXT NOT NULL,
  `kota` VARCHAR(100) NOT NULL,
  `provinsi` VARCHAR(100) NOT NULL,
  `telepon` VARCHAR(25) NOT NULL,
  `email_cabang` VARCHAR(150) NOT NULL,
  `nomor_izin_mitra` VARCHAR(100) NULL,
  `dokumen_kemitraan` VARCHAR(255) NULL,
  `status_mitra` ENUM('pending_review','aktif','ditolak','nonaktif') NOT NULL DEFAULT 'pending_review',
  `tanggal_bergabung` DATE NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cabang_status` (`status_mitra`),
  INDEX `idx_cabang_kota` (`kota`),
  CONSTRAINT `fk_cabang_direktur` FOREIGN KEY (`id_direktur_cabang`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tambahkan FK users ke tabel_cabang
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------------------------
-- 3. TABEL PEGAWAI (tabel_pegawai) - Mentor, Staff, BAK, Sarpras, Direktur Cabang, Pimpinan
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_pegawai` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `id_cabang` INT NULL,
  `nip_pegawai` VARCHAR(30) NOT NULL UNIQUE,
  `nama_lengkap` VARCHAR(150) NOT NULL,
  `jabatan_role` ENUM('pimpinan','direktur_cabang','bak','mentor','staff','sarpras') NOT NULL,
  `spesialisasi_mentor` VARCHAR(150) NULL,
  `spesialisasi_mapel` ENUM('Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web') NULL DEFAULT 'Coding',
  `no_hp` VARCHAR(25) NOT NULL,
  `alamat` TEXT NULL,
  `pengalaman_mengajar` TEXT NULL,
  `berkas_cv` VARCHAR(255) NULL,
  `status_kepegawaian` ENUM('tetap','kontrak','mitra_pengajar') NOT NULL DEFAULT 'tetap',
  `status_aktif` ENUM('aktif','nonaktif','pending_review') NOT NULL DEFAULT 'aktif',
  `tanggal_masuk` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pegawai_role` (`jabatan_role`),
  INDEX `idx_pegawai_cabang` (`id_cabang`),
  CONSTRAINT `fk_pegawai_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pegawai_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 4. TABEL LAYANAN (tabel_layanan) - Program & Paket Kursus Offline
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_layanan` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NULL,
  `kode_layanan` VARCHAR(30) NOT NULL UNIQUE,
  `nama_layanan` VARCHAR(150) NOT NULL,
  `tipe_layanan` ENUM('kursus_reguler','les_privat','tka_tryout','event_workshop') NOT NULL DEFAULT 'kursus_reguler',
  `mata_pelajaran` ENUM('Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web','Persiapan TKA','Komputer & Bisnis Digital','Lainnya') NOT NULL DEFAULT 'Coding',
  `kategori_jenjang` ENUM('TK','SD','SMP','SMA','SMK','Mahasiswa','Umum') NOT NULL DEFAULT 'SD',
  `deskripsi` TEXT NULL,
  `jumlah_pertemuan` INT NOT NULL DEFAULT 12,
  `durasi_per_sesi_menit` INT NOT NULL DEFAULT 90,
  `harga_layanan` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `harga_promo` DECIMAL(12,2) NULL DEFAULT NULL,
  `target_output_produk` VARCHAR(255) NULL,
  `status_aktif` ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_layanan_cabang` (`id_cabang`),
  INDEX `idx_layanan_tipe` (`tipe_layanan`),
  INDEX `idx_layanan_kategori` (`kategori_jenjang`),
  CONSTRAINT `fk_layanan_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 5. TABEL MATERI (tabel_materi) - Modul Resmi oleh Bagian Akademik (BAK)
-- Mendukung Hybrid Storage (Upload File Lokal + Tautan URL Eksternal)
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_materi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_layanan` INT NOT NULL,
  `id_pembuat_bak` INT NOT NULL,
  `kode_materi` VARCHAR(30) NOT NULL UNIQUE,
  `nama_pelajaran` VARCHAR(150) NOT NULL,
  `pertemuan_ke` INT NOT NULL,
  `judul_modul` VARCHAR(255) NOT NULL,
  `ringkasan_materi` TEXT NOT NULL,
  `file_modul` VARCHAR(255) NULL,
  `tipe_lampiran` ENUM('file','link') NOT NULL DEFAULT 'file',
  `external_url` VARCHAR(500) NULL,
  `panduan_mentor` TEXT NULL,
  `standar_tugas_produk` TEXT NULL,
  `status_publikasi` ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_materi_layanan` (`id_layanan`),
  INDEX `idx_materi_pertemuan` (`pertemuan_ke`),
  CONSTRAINT `fk_materi_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_materi_bak` FOREIGN KEY (`id_pembuat_bak`) REFERENCES `tabel_pegawai` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 6. TABEL SISWA (tabel_siswa) - Data Induk, Status Keuangan & Tracking Progres
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_siswa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `id_cabang` INT NOT NULL,
  `id_layanan` INT NOT NULL,
  `id_mentor_pembimbing` INT NULL,
  `nis_bisolpin` VARCHAR(30) NOT NULL UNIQUE,
  `nama_lengkap` VARCHAR(150) NOT NULL,
  `umur` INT NULL DEFAULT NULL,
  `jenjang` ENUM('TK','SD','SMP','SMA','SMK','Mahasiswa','Umum') NULL DEFAULT 'SMP',
  `kelas` VARCHAR(50) NULL DEFAULT NULL,
  `nama_sekolah` VARCHAR(150) NULL DEFAULT NULL,
  `asal_sekolah_instansi` VARCHAR(150) NULL,
  `no_hp_siswa` VARCHAR(25) NOT NULL,
  `nama_orang_tua` VARCHAR(150) NULL,
  `no_hp_orang_tua` VARCHAR(25) NULL,
  `no_wa_ortu` VARCHAR(30) NULL DEFAULT NULL,
  `alamat` TEXT NULL,
  `provinsi` VARCHAR(100) NULL DEFAULT 'Sulawesi Selatan',
  `kabupaten_kota` VARCHAR(100) NULL DEFAULT 'Kota Makassar',
  `kecamatan` VARCHAR(100) NULL DEFAULT 'Panakkukang',
  `persentase_progres` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `pertemuan_terakhir_diikuti` INT NOT NULL DEFAULT 0,
  `catatan_progres_mentor` TEXT NULL,
  `skor_tka` DECIMAL(5,2) NULL DEFAULT NULL,
  `detail_tka` VARCHAR(255) NULL DEFAULT NULL,
  `status_belajar` ENUM('aktif','lulus','cuti','keluar') NOT NULL DEFAULT 'aktif',
  `status_pembayaran` ENUM('belum_bayar','cicilan','lunas') NOT NULL DEFAULT 'belum_bayar',
  `nominal_terbayar` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `tanggal_daftar` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_siswa_cabang` (`id_cabang`),
  INDEX `idx_siswa_cabang_status` (`id_cabang`, `status_belajar`),
  INDEX `idx_siswa_layanan` (`id_layanan`),
  INDEX `idx_siswa_mentor` (`id_mentor_pembimbing`),
  INDEX `idx_siswa_jenjang` (`jenjang`),
  CONSTRAINT `fk_siswa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_siswa_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_siswa_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_siswa_mentor` FOREIGN KEY (`id_mentor_pembimbing`) REFERENCES `tabel_pegawai` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 7. TABEL JEMBATAN RIWAYAT LAYANAN SISWA (riwayat_layanan_siswa)
-- Mendukung 1 Siswa Mendaftar >1 Program Kursus Tanpa Duplikasi NIS
-- ------------------------------------------------------------------------------
CREATE TABLE `riwayat_layanan_siswa` (
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
-- 8. TABEL ABSENSI (tabel_absensi) - Siswa & Pegawai/Mentor di Cabang
-- Dilengkapi Composite Index untuk akselerasi query harian per cabang
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_absensi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NULL,
  `tipe_absensi` ENUM('siswa','pegawai_mentor') NOT NULL,
  `id_siswa` INT NULL,
  `id_pegawai` INT NULL,
  `id_materi_pertemuan` INT NULL,
  `tanggal_absensi` DATE NOT NULL,
  `jam_masuk` TIME NOT NULL,
  `jam_keluar` TIME NULL,
  `status_kehadiran` ENUM('hadir','izin','sakit','alfa') NOT NULL DEFAULT 'hadir',
  `keterangan_jurnal` TEXT NULL,
  `diinput_oleh` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_absensi_cabang` (`id_cabang`),
  INDEX `idx_absensi_cabang_tipe_tanggal` (`id_cabang`, `tipe_absensi`, `tanggal_absensi`),
  INDEX `idx_absensi_tanggal` (`tanggal_absensi`),
  INDEX `idx_absensi_siswa` (`id_siswa`),
  INDEX `idx_absensi_pegawai` (`id_pegawai`),
  CONSTRAINT `fk_absensi_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_absensi_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_absensi_pegawai` FOREIGN KEY (`id_pegawai`) REFERENCES `tabel_pegawai` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_absensi_materi` FOREIGN KEY (`id_materi_pertemuan`) REFERENCES `tabel_materi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_absensi_inputer` FOREIGN KEY (`diinput_oleh`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 9. TABEL ASET (tabel_aset) - Sarana & Prasarana Cabang
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_aset` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `id_penanggung_jawab` INT NULL,
  `kode_aset` VARCHAR(50) NOT NULL UNIQUE,
  `nama_aset` VARCHAR(150) NOT NULL,
  `kategori_aset` ENUM('ruang_kelas','perangkat_it','alat_peraga','furnitur','lainnya') NOT NULL,
  `lokasi_penempatan` VARCHAR(100) NOT NULL,
  `jumlah_unit` INT NOT NULL DEFAULT 1,
  `kondisi` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL DEFAULT 'baik',
  `tanggal_perolehan` DATE NOT NULL,
  `nilai_perolehan` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `catatan_maintenance` TEXT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_aset_cabang` (`id_cabang`),
  INDEX `idx_aset_kategori` (`kategori_aset`),
  INDEX `idx_aset_kondisi` (`kondisi`),
  CONSTRAINT `fk_aset_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aset_penanggung_jawab` FOREIGN KEY (`id_penanggung_jawab`) REFERENCES `tabel_pegawai` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. TABEL PRODUK (tabel_produk) - Karya/Produk Siswa & Portofolio Kursus
-- Mendukung Hybrid Storage (Upload File Lokal + Tautan URL Eksternal) & Composite Index
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_produk` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `kategori_kepemilikan` ENUM('karya_siswa','produk_lembaga') NOT NULL DEFAULT 'karya_siswa',
  `id_siswa` INT NULL,
  `id_layanan` INT NULL,
  `id_materi` INT NULL,
  `kode_produk` VARCHAR(50) NOT NULL UNIQUE,
  `nama_produk` VARCHAR(200) NOT NULL,
  `deskripsi_produk` TEXT NOT NULL,
  `foto_produk` VARCHAR(255) NULL,
  `file_atau_url_karya` VARCHAR(255) NULL,
  `tipe_lampiran` ENUM('file','link') NOT NULL DEFAULT 'file',
  `external_url` VARCHAR(500) NULL,
  `progres_pembuatan` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `nilai_karya` DECIMAL(5,2) NULL,
  `review_mentor` TEXT NULL,
  `id_mentor_validator` INT NULL,
  `status_tampil` ENUM('draft','menunggu_validasi','dipublikasikan','revisi') NOT NULL DEFAULT 'draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_produk_cabang` (`id_cabang`),
  INDEX `idx_produk_cabang_status` (`id_cabang`, `status_tampil`),
  INDEX `idx_produk_siswa` (`id_siswa`),
  INDEX `idx_produk_status` (`status_tampil`),
  CONSTRAINT `fk_produk_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_produk_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_produk_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_produk_materi` FOREIGN KEY (`id_materi`) REFERENCES `tabel_materi` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_produk_mentor` FOREIGN KEY (`id_mentor_validator`) REFERENCES `tabel_pegawai` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. TABEL JADWAL KELAS (jadwal_kelas) - Plotting Kelas Tatap Muka
-- ------------------------------------------------------------------------------
CREATE TABLE `jadwal_kelas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `id_layanan` INT NOT NULL,
  `id_mentor` INT NOT NULL,
  `id_aset_ruangan` INT NULL,
  `hari` ENUM('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu') NOT NULL,
  `jam_mulai` TIME NOT NULL,
  `jam_selesai` TIME NOT NULL,
  `kuota_siswa` INT NOT NULL DEFAULT 15,
  `status` ENUM('aktif','dibatalkan','selesai') NOT NULL DEFAULT 'aktif',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_jadwal_cabang` (`id_cabang`),
  INDEX `idx_jadwal_hari` (`hari`),
  INDEX `idx_jadwal_mentor` (`id_mentor`),
  CONSTRAINT `fk_jadwal_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jadwal_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jadwal_mentor` FOREIGN KEY (`id_mentor`) REFERENCES `tabel_pegawai` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jadwal_ruangan` FOREIGN KEY (`id_aset_ruangan`) REFERENCES `tabel_aset` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 10. TABEL EVALUASI MENTOR (tabel_evaluasi_mentor) - EDOM & IKM Engine
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_evaluasi_mentor` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `id_siswa` INT NOT NULL,
  `id_mentor` INT NOT NULL,
  `id_layanan` INT NOT NULL,
  `skor_materi` TINYINT NOT NULL CHECK (`skor_materi` BETWEEN 1 AND 5),
  `skor_penyampaian` TINYINT NOT NULL CHECK (`skor_penyampaian` BETWEEN 1 AND 5),
  `skor_kedisiplinan` TINYINT NOT NULL CHECK (`skor_kedisiplinan` BETWEEN 1 AND 5),
  `skor_kesabaran` TINYINT NOT NULL CHECK (`skor_kesabaran` BETWEEN 1 AND 5),
  `nilai_rata_rata` DECIMAL(3,2) NOT NULL,
  `ulasan_saran` TEXT NULL,
  `is_anonymous` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_eval_cabang` (`id_cabang`),
  INDEX `idx_eval_mentor` (`id_mentor`),
  INDEX `idx_eval_siswa` (`id_siswa`),
  UNIQUE KEY `unique_evaluasi` (`id_siswa`, `id_layanan`, `id_mentor`),
  CONSTRAINT `fk_eval_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_mentor` FOREIGN KEY (`id_mentor`) REFERENCES `tabel_pegawai` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 11. TABEL LOG PEMELIHARAAN SARPRAS (tabel_maintenance_log)
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_maintenance_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `id_aset` INT NOT NULL,
  `tanggal_maintenance` DATE NOT NULL,
  `jenis_perbaikan` VARCHAR(150) NOT NULL,
  `biaya_perbaikan` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `teknisi_pelaksana` VARCHAR(150) NOT NULL,
  `kondisi_sebelum` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
  `kondisi_setelah` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
  `keterangan_tindakan` TEXT NOT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_maint_cabang` (`id_cabang`),
  INDEX `idx_maint_aset` (`id_aset`),
  CONSTRAINT `fk_maint_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_maint_aset` FOREIGN KEY (`id_aset`) REFERENCES `tabel_aset` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 12. TABEL TRANSAKSI KASIR & PEMBAYARAN SPP (tabel_pembayaran)
-- ------------------------------------------------------------------------------
CREATE TABLE `tabel_pembayaran` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_cabang` INT NOT NULL,
  `id_siswa` INT NOT NULL,
  `id_layanan` INT NOT NULL,
  `kode_transaksi` VARCHAR(50) NOT NULL UNIQUE,
  `tanggal_bayar` DATE NOT NULL,
  `jumlah_bayar` DECIMAL(12,2) NOT NULL,
  `metode_pembayaran` ENUM('tunai','transfer_bank','qris') NOT NULL DEFAULT 'tunai',
  `status_pembayaran` ENUM('lunas','cicilan','menunggu_konfirmasi') NOT NULL DEFAULT 'lunas',
  `bukti_transfer` VARCHAR(255) NULL,
  `catatan` TEXT NULL,
  `diterima_oleh` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_bayar_cabang` (`id_cabang`),
  INDEX `idx_bayar_siswa` (`id_siswa`),
  INDEX `idx_bayar_layanan` (`id_layanan`),
  CONSTRAINT `fk_bayar_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bayar_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bayar_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------------------
-- 13. TABEL TOKEN PEMULIHAN SANDI (password_resets)
-- ------------------------------------------------------------------------------
CREATE TABLE `password_resets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `token` VARCHAR(128) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(50) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_reset_token` (`token_hash`),
  INDEX `idx_reset_user` (`user_id`),
  INDEX `idx_reset_expires` (`expires_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- ==============================================================================
-- DATA SEEDER REALISTIS (7 ROLE & DEMO KEMITRAAN MULTI-CABANG)
-- Seluruh password akun demo adalah: password123
-- Password hash: $2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- SEED DATA: CABANG (tabel_cabang)
-- Dimasukkan dengan id_direktur_cabang = NULL terlebih dahulu untuk mencegah konflik FK
-- Nilai id_direktur_cabang akan di-update setelah tabel users di-seed
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_cabang` (`id`, `kode_cabang`, `nama_cabang`, `id_direktur_cabang`, `alamat`, `kota`, `provinsi`, `telepon`, `email_cabang`, `nomor_izin_mitra`, `dokumen_kemitraan`, `status_mitra`, `tanggal_bergabung`, `created_at`) VALUES
(1, 'CBG-MKS-01', 'Bisolpin Cabang Utama Makassar', NULL, 'Jl. A.P. Pettarani No. 88, Sinrijala, Kec. Panakkukang', 'Kota Makassar', 'Sulawesi Selatan', '0411-889977', 'makassar@bisolpin.com', 'MITRA-MKS-2025-001', 'mou_makassar_terverifikasi.pdf', 'aktif', '2025-01-10', NOW()),
(2, 'CBG-JKT-02', 'Bisolpin Cabang Jakarta Selatan', NULL, 'Jl. Tebet Raya No. 45, Tebet Timur, Jakarta Selatan', 'Jakarta Selatan', 'DKI Jakarta', '021-83701234', 'jaksel@bisolpin.com', 'MITRA-JKT-2025-004', 'mou_jaksel_terverifikasi.pdf', 'aktif', '2025-03-01', NOW()),
(3, 'CBG-SBY-03', 'Bisolpin Cabang Surabaya Gubeng', NULL, 'Jl. Raya Gubeng No. 12, Gubeng, Kec. Gubeng', 'Kota Surabaya', 'Jawa Timur', '031-5021234', 'surabaya.gubeng@bisolpin.com', 'REG-SBY-2026-X09', 'proposal_kemitraan_surabaya.pdf', 'pending_review', NULL, NOW()),
(4, 'CBG-KLA-04', 'Bisolpin Cabang Kolaka', NULL, 'Jl. Pramuka No. 18, Lamokato, Kec. Kolaka', 'Kabupaten Kolaka', 'Sulawesi Tenggara', '0405-232188', 'kolaka@bisolpin.com', 'MITRA-KLA-2025-007', 'mou_kolaka_terverifikasi.pdf', 'aktif', '2025-02-15', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: PENGGUNA (users) - 7 ROLE
-- ------------------------------------------------------------------------------
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `id_cabang`, `is_active`, `last_login`, `created_at`) VALUES
-- 1. Pimpinan (Super Admin Pusat)
(1, 'pimpinan', 'pimpinan@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'pimpinan', NULL, 1, NOW(), NOW()),
-- 2. Direktur Cabang (Cabang Makassar - Aktif)
(2, 'direktur_mks', 'direktur.mks@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'direktur_cabang', 1, 1, NOW(), NOW()),
-- 3. Calon Direktur Cabang (Cabang Surabaya - Pending Review)
(3, 'mitra_sby', 'mitra.sby@gmail.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'direktur_cabang', 3, 0, NULL, NOW()),
-- 4. BAK (Bagian Akademik Pusat)
(4, 'bak_pusat', 'bak.pusat@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'bak', NULL, 1, NOW(), NOW()),
-- 5. Mentor (Tutor Cabang Makassar)
(5, 'mentor_ahmad', 'mentor.ahmad@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'mentor', 1, 1, NOW(), NOW()),
-- 6. Staff (Admin Cabang Makassar)
(6, 'staff_siti', 'staff.siti@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'staff', 1, 1, NOW(), NOW()),
-- 7. Sarpras (Sarana & Prasarana Cabang Makassar)
(7, 'sarpras_budi', 'sarpras.budi@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'sarpras', 1, 1, NOW(), NOW()),
-- 8. Siswa 1 (Siswa Aktif Cabang Makassar)
(8, 'siswa_rizky', 'siswa.rizky@gmail.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'siswa', 1, 1, NOW(), NOW()),
-- 9. Siswa 2 (Siswa Aktif Cabang Makassar)
(9, 'siswa_alya', 'siswa.alya@gmail.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'siswa', 1, 1, NOW(), NOW()),
-- 10. Direktur Cabang (Cabang Kolaka - Aktif)
(10, 'direktur_kolaka', 'direktur.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'direktur_cabang', 4, 1, NOW(), NOW()),
-- 11. Mentor Cabang Kolaka (Aktif)
(11, 'mentor_kolaka', 'mentor.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'mentor', 4, 1, NOW(), NOW()),
-- 12. Staff Cabang Kolaka (Aktif)
(12, 'staff_kolaka', 'staff.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'staff', 4, 1, NOW(), NOW());

-- Update referensi direktur cabang
UPDATE `tabel_cabang` SET `id_direktur_cabang` = 2 WHERE `id` = 1;
UPDATE `tabel_cabang` SET `id_direktur_cabang` = 3 WHERE `id` = 3;
UPDATE `tabel_cabang` SET `id_direktur_cabang` = 10 WHERE `id` = 4;

-- ------------------------------------------------------------------------------
-- SEED DATA: PEGAWAI (tabel_pegawai)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_pegawai` (`id`, `user_id`, `id_cabang`, `nip_pegawai`, `nama_lengkap`, `jabatan_role`, `spesialisasi_mentor`, `no_hp`, `alamat`, `status_kepegawaian`, `status_aktif`, `tanggal_masuk`, `created_at`) VALUES
(1, 1, NULL, 'PST-DIR-001', 'Dr. H. Muhammad Ardiansyah, M.Pd.', 'pimpinan', NULL, '08114199001', 'Jl. Sultan Alauddin No. 100, Makassar', 'tetap', 'aktif', '2024-01-01', NOW()),
(2, 2, 1, 'CBG-MKS-DIR01', 'Ir. Faisal Rahman, S.T., M.Kom.', 'direktur_cabang', 'Manajemen & IT', '08124200881', 'Jl. Pengayoman Raya Blok C No. 4, Makassar', 'tetap', 'aktif', '2025-01-10', NOW()),
(3, 3, 3, 'CBG-SBY-MITRA01', 'Bambang Soebroto, S.E., M.M.', 'direktur_cabang', 'Wirausaha & Finansial', '081333444555', 'Jl. Manyar Kertoarjo No. 89, Surabaya', 'kontrak', 'nonaktif', '2026-02-15', NOW()),
(4, 4, NULL, 'PST-BAK-001', 'Prof. Dr. Nurul Hidayati, M.Sc.', 'bak', 'Kurikulum & Asesmen Nasional', '08114567890', 'Perum Dosen Unhas Tamalanrea Blok AC-12, Makassar', 'tetap', 'aktif', '2024-03-01', NOW()),
(5, 5, 1, 'MKS-MEN-001', 'Ahmad Farhan Fadillah, S.Kom.', 'mentor', 'Coding Web & Robotik Anak', '085299887766', 'Jl. Toddopuli Raya Timur No. 25, Makassar', 'tetap', 'aktif', '2025-02-01', NOW()),
(6, 6, 1, 'MKS-STF-001', 'Siti Rahmawati, S.Sos.', 'staff', 'Administrasi & Keuangan Cabang', '082190807060', 'Jl. Urip Sumoharjo KM 4, Makassar', 'tetap', 'aktif', '2025-01-15', NOW()),
(7, 7, 1, 'MKS-SRP-001', 'Budi Santoso, A.Md.T.', 'sarpras', 'Teknisi Jaringan & Pemeliharaan Gedung', '087841552233', 'Jl. Veteran Selatan No. 210, Makassar', 'tetap', 'aktif', '2025-01-20', NOW()),
(8, 10, 4, 'CBG-KLA-DIR01', 'drh. Andi Mappatunru, M.Si.', 'direktur_cabang', 'Manajemen Kemitraan Wilayah', '085242110099', 'Jl. Pemuda No. 77, Kolaka', 'tetap', 'aktif', '2025-02-15', NOW()),
(9, 11, 4, 'KLA-MEN-001', 'Muh. Ilham Pratama, S.Pd.', 'mentor', 'Matematika & Coding Dasar', '082344556677', 'Jl. Mekongga Indah Blok B, Kolaka', 'tetap', 'aktif', '2025-02-20', NOW()),
(10, 12, 4, 'KLA-STF-001', 'Nurul Wahyuni, S.Kom.', 'staff', 'Administrasi & Layanan Cabang', '085399881122', 'Jl. Abadi No. 12, Kolaka', 'tetap', 'aktif', '2025-02-20', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: LAYANAN KURSUS OFFLINE (tabel_layanan)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_layanan` (`id`, `id_cabang`, `kode_layanan`, `nama_layanan`, `tipe_layanan`, `mata_pelajaran`, `kategori_jenjang`, `deskripsi`, `jumlah_pertemuan`, `durasi_per_sesi_menit`, `harga_layanan`, `harga_promo`, `target_output_produk`, `status_aktif`, `created_at`) VALUES
(1, NULL, 'LYN-CAL-01', 'Calistung & Logika Angka Ceria', 'kursus_reguler', 'Calistung', 'TK', 'Program membaca, menulis, dan berhitung interaktif dengan metode visual kreatif untuk anak usia dini.', 16, 60, 950000.00, 750000.00, 'Buku Jurnal Membaca Mandiri & Lembar Kreativitas Huruf-Angka', 'aktif', NOW()),
(2, NULL, 'LYN-MAT-01', 'Matematika Hebat & Penalaran Kuantitatif', 'kursus_reguler', 'Matematika', 'SD', 'Pembelajaran konsep dasar matematika, aritmatika cepat, dan pemecahan soal HOTS terstruktur.', 16, 90, 1200000.00, 990000.00, 'Kompilasi Rumus Aritmatika & Portofolio Pemecahan Soal Cerita', 'aktif', NOW()),
(3, NULL, 'LYN-ENG-01', 'English Active Speaking & Global Communication', 'kursus_reguler', 'Bahasa Inggris', 'SMP', 'Pelatihan percakapan bahasa Inggris aktif, listening komprehensif, dan penguasaan vocabulary harian.', 12, 90, 1350000.00, 1100000.00, 'Video Presentasi Mandiri & Mini Daily Conversation Booklet', 'aktif', NOW()),
(4, NULL, 'LYN-COD-01', 'Junior Coding & Game Logic Development', 'kursus_reguler', 'Coding', 'SD', 'Pelatihan logika algoritma komputer anak melalui visual block coding dan pembuatan game interaktif.', 16, 90, 1750000.00, 1450000.00, 'Game 2D Interaktif Scratch & Mini Animasi Komputasi', 'aktif', NOW()),
(5, NULL, 'LYN-WEB-01', 'Fullstack Web & Digital Solution Development', 'kursus_reguler', 'Pemrograman Web', 'SMA', 'Pengembangan website modern responsive menggunakan teknologi HTML5, CSS3, JavaScript, dan PHP database.', 20, 120, 2250000.00, 1850000.00, 'Website Portofolio Responsif & Prototipe Sistem Informasi', 'aktif', NOW()),
(6, NULL, 'LYN-TKA-01', 'Simulasi Intensif TKA & Try Out Terpadu', 'tka_tryout', 'Persiapan TKA', 'SMA', 'Drilling soal-soal Tes Kemampuan Akademik (TKA) persiapan seleksi PTN dan sekolah kedinasan.', 8, 120, 850000.00, 650000.00, 'Laporan Analisis Ketuntasan Materi & Rapor Skor Try Out Nasional', 'aktif', NOW()),
(7, 1, 'LYN-WSP-01', 'Workshop Desain Digital & Bisnis Kreatif', 'event_workshop', 'Komputer & Bisnis Digital', 'Umum', 'Pelatihan intensif 2 hari pembuatan materi promosi digital, branding visual, dan setup landing page bisnis.', 4, 180, 500000.00, 350000.00, 'Katalog Produk Digital & Aset Desain Siap Publikasi', 'aktif', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: MODUL & PELAJARAN OLEH BAK (tabel_materi)
-- 5 Mata Pelajaran Inti Bisolpin + TKA Try Out + Workshop Komputer Bisnis
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_materi` (`id`, `id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`) VALUES
-- 1. Calistung (id_layanan = 1)
(1, 1, 4, 'MAT-CAL-01', 'Calistung Ceria', 1, 'Pengenalan Fonik, Huruf Vokal & Huruf Konsonan', 'Metode bermain sambil belajar mengenal alfabet A-Z dengan lagu fonik dan kartu bergambar interaktif.', 'modul_fonik_calistung_bak.pdf', 'file', NULL, 'Gunakan flashcard visual dan ajak siswa melafalkan bunyi huruf vokal dengan lantang dan riang.', 'Siswa dapat melafalkan 5 huruf vokal dan menuliskan nama panggilannya sendiri di buku gambar.', 'published', NOW()),
(2, 1, 4, 'MAT-CAL-02', 'Calistung Ceria', 2, 'Berhitung Ceria 1-20 & Asosiasi Objek Visual', 'Pengenalan bilangan 1 sampai 20 menggunakan benda konkret, jari tangan, dan stiker warna-warni.', 'modul_angka_calistung_bak.pdf', 'file', NULL, 'Lakukan simulasi berhitung dengan menghitung jumlah balok atau buah di atas meja kelas.', 'Lembar kerja menghitung objek dan mewarnai angka 1 sampai 20 terisi rapi.', 'published', NOW()),

-- 2. Matematika (id_layanan = 2)
(3, 2, 4, 'MAT-MAT-01', 'Matematika Hebat', 1, 'Aritmatika Cepat & Logika Bilangan Bulat', 'Metode mental math penjumlahan, pengurangan cepat tanpa coretan kertas, dan trik perkalian jari.', 'modul_aritmatika_cepat_bak.pdf', 'file', NULL, 'Bimbing siswa mempraktikkan trik hitung cepat di papan tulis, berikan kuis cepat berhadiah poin.', 'Siswa mampu menyelesaikan 15 soal penjumlahan bertingkat dalam waktu kurang dari 5 menit.', 'published', NOW()),
(4, 2, 4, 'MAT-MAT-02', 'Matematika Hebat', 2, 'Penalaran Kuantitatif & Strategi Soal Cerita HOTS', 'Membedah kalimat soal cerita, menentukan variabel matematika, dan strategi eliminasi kesalahan logika.', 'modul_penalaran_matematika_bak.pdf', 'file', NULL, 'Bahas 3 tipe soal cerita HOTS yang sering muncul pada asesmen sekolah.', 'Portofolio penyelesaian 5 soal cerita HOTS dengan langkah penalaran yang terstruktur.', 'published', NOW()),

-- 3. Bahasa Inggris (id_layanan = 3)
(5, 3, 4, 'MAT-ENG-01', 'English Active Speaking', 1, 'Daily Active Conversation & Confident Self-Introduction', 'Penguasaan frasa percakapan harian, teknik intonasi percaya diri, dan simulasi perkenalan diri formal.', 'modul_speaking_intro_bak.pdf', 'file', NULL, 'Ajak siswa berpasangan (roleplay) untuk mempraktikkan dialog perkenalan di depan kelas.', 'Video rekaman 1 menit perkenalan diri dalam bahasa Inggris yang lancar dan percaya diri.', 'published', NOW()),
(6, 3, 4, 'MAT-ENG-02', 'English Active Speaking', 2, 'Listening Comprehension & Active Vocabulary Builder', 'Mendengarkan rekaman audio penutur asli (native speaker) dan menyusun 25 kosakata baru tematik.', 'modul_listening_vocab_bak.pdf', 'link', 'https://drive.google.com/drive/folders/bisolpin-english-audio', 'Putarkan audio listening 2 kali, lalu pandu diskusi makna konteks kalimat bersama siswa.', 'Lembar kerja listening terisi 100% dan daftar kartu kosakata (flashcard) mandiri siswa.', 'published', NOW()),

-- 4. Coding (id_layanan = 4)
(7, 4, 4, 'MAT-COD-01', 'Junior Coding & Game Logic', 1, 'Logika Algoritma & Visual Block Programming Scratch', 'Pengenalan konsep sprite, koordinat X-Y, motion block, dan event listener "when green flag clicked".', 'modul_scratch_dasar_bak.pdf', 'link', 'https://scratch.mit.edu', 'Instruksikan siswa membuka Scratch editor dan membuat karakter bergerak mengikuti tombol panah keyboard.', 'Karya animasi mini di Scratch berisi 1 sprite bergerak dan efek suara dinamis.', 'published', NOW()),
(8, 4, 4, 'MAT-COD-02', 'Junior Coding & Game Logic', 2, 'Game Loop, Variabel Skor & Animasi Karakter Interaktif', 'Membuat game menangkap benda jatuh menggunakan variabel score, conditional if-touching, dan timer.', 'modul_scratch_game_loop_bak.pdf', 'file', NULL, 'Bimbing siswa menyusun script penambahan skor saat sprite keranjang menyentuh sprite buah jatuh.', 'Game tangkap buah interaktif yang dapat dimainkan dengan skor yang bertambah otomatis.', 'published', NOW()),

-- 5. Pemrograman Web (id_layanan = 5)
(9, 5, 4, 'MAT-WEB-01', 'Fullstack Web Development', 1, 'Pengenalan Arsitektur Web & Struktur Dasar HTML5', 'Pengenalan konsep client-server, tag dasar HTML5 (h1-h6, p, a, img, list), dan anatomi dokumen.', 'modul_html5_dasar_bak.pdf', 'file', NULL, 'Instruksikan siswa membuka VS Code dan membuat file index.html berisi profil biodata pribadi.', 'Siswa berhasil menampilkan halaman profil berisi foto, paragraf bio, dan 3 link sosial media.', 'published', NOW()),
(10, 5, 4, 'MAT-WEB-02', 'Fullstack Web Development', 2, 'Styling Modern, Box Model & Layout Flexbox CSS3', 'Memahami warna, margin, padding, border, pseudo-class, dan penyusunan kartu responsive dengan Flexbox.', 'modul_css3_flexbox_bak.pdf', 'file', NULL, 'Bimbing siswa memberikan warna palet Bisolpin dan menata kartu biodata ke tengah layar (center alignment).', 'Kartu profil ber-sudut melengkung (rounded-2xl) dengan bayangan lembut dan tombol interaktif hover.', 'published', NOW()),
(11, 5, 4, 'MAT-WEB-03', 'Fullstack Web Development', 3, 'Logika Dasar, Event Listener & Manipulasi DOM JS', 'Pengenalan variabel, fungsi klik, querySelector, dan mengubah elemen teks secara dinamis.', 'modul_javascript_dom_bak.pdf', 'file', NULL, 'Ajak siswa membuat tombol toggle dark mode dan pop-up ucapan selamat datang.', 'Tombol interaktif yang mengubah tema warna kartu profil saat diklik.', 'published', NOW()),
(12, 5, 4, 'MAT-WEB-04', 'Fullstack Web Development', 4, 'Finalisasi Proyek Portofolio Mandiri & Showcase', 'Penyusunan proyek mandiri menggabungkan seluruh modul untuk dipublikasikan ke etalase karya siswa.', 'panduan_project_portofolio.pdf', 'file', NULL, 'Lakukan review kode siswa satu per satu, beri masukan estetika dan validasi fungsi sebelum diunggah.', 'Karya portofolio lengkap dengan foto, deskripsi, dan link file proyek siap dipajang.', 'published', NOW()),

-- 6. Persiapan TKA & Try Out (id_layanan = 6)
(13, 6, 4, 'MAT-TKA-01', 'Persiapan TKA & Try Out', 1, 'Bedah Pola Soal TKA & Trik Eliminasi Opsi Cepat', 'Analisis pola soal tes kemampuan akademik terkini, manajemen waktu 90 detik per butir soal, dan tips eliminasi.', 'modul_bedah_tka_saintek_soshum.pdf', 'file', NULL, 'Jelaskan jebakan distraktor dalam soal pilihan ganda dan ajarkan siswa membaca kata kunci pertanyaan.', 'Lembar jawaban bedah soal 20 nomor dengan catatan alasan eliminasi pada tiap opsi salah.', 'published', NOW()),
(14, 6, 4, 'MAT-TKA-02', 'Persiapan TKA & Try Out', 2, 'Simulasi Try Out Nasional Berbatas Waktu & Evaluasi Analitis', 'Pengerjaan paket simulasi try out 50 nomor dengan pengawasan mentor dan penghitungan skor akurasi otomatis.', 'paket_simulasi_tryout_nasional.pdf', 'link', 'https://drive.google.com/drive/folders/bisolpin-tryout-tka', 'Awasi jalannya simulasi try out selama 90 menit penuh, pastikan siswa tidak membuka catatan.', 'Rapor skor try out dengan rincian jumlah benar, salah, kosong, dan nilai akhir skala 100.', 'published', NOW()),

-- 7. Workshop Komputer & Bisnis Digital (id_layanan = 7)
(15, 7, 4, 'MAT-WSP-01', 'Komputer & Bisnis Digital', 1, 'Branding Visual, Desain Konten Kreatif & Setup Etalase Digital', 'Dasar teori warna bisnis, pembuatan feed media sosial di Canva/Figma, dan integrasi WhatsApp katalog produk.', 'panduan_workshop_desain_bisnis.pdf', 'link', 'https://www.figma.com', 'Bimbing peserta merancang 3 banner promosi bisnis dan menyusun bio profil media sosial yang menarik.', 'Paket aset promosi bisnis digital siap upload ke media sosial dan katalog daring.', 'published', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: SARANA DAN PRASARANA (tabel_aset)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_aset` (`id`, `id_cabang`, `id_penanggung_jawab`, `kode_aset`, `nama_aset`, `kategori_aset`, `lokasi_penempatan`, `jumlah_unit`, `kondisi`, `tanggal_perolehan`, `nilai_perolehan`, `catatan_maintenance`, `updated_at`) VALUES
(1, 1, 7, 'AST-MKS-RK01', 'Ruang Kelas Teori Einstein (Lantai 2)', 'ruang_kelas', 'Gedung A Lantai 2', 1, 'baik', '2025-01-15', 35000000.00, 'AC rutin diservis tiap 3 bulan. Kebersihan dan meja kursi prima.', NOW()),
(2, 1, 7, 'AST-MKS-LAB01', 'Laboratorium Komputer & Robotik Alan Turing', 'ruang_kelas', 'Gedung A Lantai 1', 1, 'baik', '2025-01-15', 85000000.00, 'Terpasang 20 unit PC All-in-One dan jaringan LAN Gigabit.', NOW()),
(3, 1, 7, 'AST-MKS-PC01', 'PC Workstation Guru & Server Lab Core i7', 'perangkat_it', 'Lab Komputer Lantai 1', 2, 'baik', '2025-01-20', 24000000.00, 'Backup data master materi BAK terupdate tiap akhir pekan.', NOW()),
(4, 1, 7, 'AST-MKS-TV01', 'Smart TV Interaktif 65 Inch Samsung UHD', 'perangkat_it', 'Ruang Teori Einstein', 1, 'baik', '2025-01-22', 12500000.00, 'Kabel HDMI dan casting wireless berfungsi normal.', NOW()),
(5, 1, 7, 'AST-MKS-ROB01', 'Kit Pembelajaran Robotik Arduino Mega & Sensor', 'alat_peraga', 'Lemari Aset Lab', 15, 'perlu_perbaikan', '2025-02-05', 7500000.00, '2 modul sensor ultrasonik perlu penggantian kabel jumper.', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: JADWAL KELAS TATAP MUKA (jadwal_kelas)
-- ------------------------------------------------------------------------------
INSERT INTO `jadwal_kelas` (`id`, `id_cabang`, `id_layanan`, `id_mentor`, `id_aset_ruangan`, `hari`, `jam_mulai`, `jam_selesai`, `kuota_siswa`, `status`, `created_at`) VALUES
(1, 1, 5, 5, 2, 'Senin', '15:30:00', '17:00:00', 15, 'aktif', NOW()),
(2, 1, 5, 5, 2, 'Rabu', '15:30:00', '17:00:00', 15, 'aktif', NOW()),
(3, 1, 2, 5, 1, 'Selasa', '16:00:00', '18:00:00', 20, 'aktif', NOW()),
(4, 1, 4, 5, 2, 'Sabtu', '09:00:00', '10:30:00', 12, 'aktif', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: SISWA (tabel_siswa) - Termasuk Biodata Lengkap Bisolpin & Skor TKA
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_siswa` (`id`, `user_id`, `id_cabang`, `id_layanan`, `id_mentor_pembimbing`, `nis_bisolpin`, `nama_lengkap`, `umur`, `jenjang`, `kelas`, `nama_sekolah`, `asal_sekolah_instansi`, `no_hp_siswa`, `nama_orang_tua`, `no_hp_orang_tua`, `no_wa_ortu`, `alamat`, `provinsi`, `kabupaten_kota`, `kecamatan`, `persentase_progres`, `pertemuan_terakhir_diikuti`, `catatan_progres_mentor`, `skor_tka`, `detail_tka`, `status_belajar`, `status_pembayaran`, `nominal_terbayar`, `is_archived`, `tanggal_daftar`, `created_at`) VALUES
(1, 8, 1, 5, 5, 'NIS-2025-01001', 'Muhammad Rizky Pratama', 14, 'SMP', 'Kelas 8', 'SMP Negeri 6 Makassar', 'SMP Negeri 6 Makassar', '085399112233', 'Drs. Hendra Pratama', '081241556677', '081241556677', 'Komp. Panakkukang Mas Blok F No. 12, Makassar', 'Sulawesi Selatan', 'Kota Makassar', 'Panakkukang', 75.00, 3, 'Pemahaman logika HTML dan CSS sangat cepat. Tinggal memperdalam struktur event JavaScript di modul 3.', 88.50, 'Benar: 44, Salah: 4, Kosong: 2 (Skor: 88.50)', 'aktif', 'lunas', 1850000.00, 0, '2025-02-01', NOW()),
(2, 9, 1, 5, 5, 'NIS-2025-01002', 'Alya Khairunnisa Azzahra', 13, 'SMP', 'Kelas 7', 'SMP Athirah 1 Makassar', 'SMP Athirah 1 Makassar', '089533221100', 'Hj. Mardiana, S.E.', '081342998811', '081342998811', 'Jl. Boulevard Ruko Ruby No. 5, Makassar', 'Sulawesi Selatan', 'Kota Makassar', 'Panakkukang', 50.00, 2, 'Sangat tekun dalam mendesain tampilan web. Telah menyelesaikan modul CSS Flexbox dengan rapi.', NULL, NULL, 'aktif', 'cicilan', 1000000.00, 0, '2025-02-03', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: RIWAYAT LAYANAN SISWA (riwayat_layanan_siswa)
-- Multi-Enrollment Siswa Tanpa Duplikasi NIS
-- ------------------------------------------------------------------------------
INSERT INTO `riwayat_layanan_siswa` (`id`, `id_siswa`, `id_cabang`, `id_layanan`, `id_mentor`, `persentase_progres`, `status_pembayaran`, `nominal_terbayar`, `status_kursus`, `created_at`) VALUES
(1, 1, 1, 5, 5, 75.00, 'lunas', 1850000.00, 'aktif', NOW()),
(2, 2, 1, 5, 5, 50.00, 'cicilan', 1000000.00, 'aktif', NOW()),
(3, 1, 1, 4, 5, 25.00, 'lunas', 1650000.00, 'aktif', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: ABSENSI (tabel_absensi)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_absensi` (`id`, `id_cabang`, `tipe_absensi`, `id_siswa`, `id_pegawai`, `id_materi_pertemuan`, `tanggal_absensi`, `jam_masuk`, `jam_keluar`, `status_kehadiran`, `keterangan_jurnal`, `diinput_oleh`, `created_at`) VALUES
-- Presensi Mentor
(1, 1, 'pegawai_mentor', NULL, 5, 9, '2025-02-10', '15:20:00', '17:05:00', 'hadir', 'Mengajar modul 1 HTML5 Dasar di Lab Komputer. Semua siswa aktif praktek.', 5, NOW()),
(2, 1, 'pegawai_mentor', NULL, 5, 10, '2025-02-12', '15:25:00', '17:10:00', 'hadir', 'Mengajar modul 2 CSS Flexbox. Pembagian sesi hands-on desain kartu.', 5, NOW()),
-- Presensi Siswa Rizky
(3, 1, 'siswa', 1, NULL, 9, '2025-02-10', '15:28:00', '17:00:00', 'hadir', 'Hadir tepat waktu dan menyelesaikan tugas biodata HTML5.', 5, NOW()),
(4, 1, 'siswa', 1, NULL, 10, '2025-02-12', '15:26:00', '17:00:00', 'hadir', 'Hadir dan aktif bertanya tentang fungsi flex-direction.', 5, NOW()),
-- Presensi Siswa Alya
(5, 1, 'siswa', 2, NULL, 9, '2025-02-10', '15:30:00', '17:00:00', 'hadir', 'Hadir, hasil latihan HTML sangat terstruktur.', 5, NOW()),
(6, 1, 'siswa', 2, NULL, 10, '2025-02-12', '15:29:00', '17:00:00', 'hadir', 'Hadir, kreatif dalam pemilihan warna styling CSS.', 5, NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: PRODUK & KARYA SISWA (tabel_produk) - Hybrid Storage
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_produk` (`id`, `id_cabang`, `kategori_kepemilikan`, `id_siswa`, `id_layanan`, `id_materi`, `kode_produk`, `nama_produk`, `deskripsi_produk`, `foto_produk`, `file_atau_url_karya`, `tipe_lampiran`, `external_url`, `progres_pembuatan`, `nilai_karya`, `review_mentor`, `id_mentor_validator`, `status_tampil`, `created_at`) VALUES
(1, 1, 'karya_siswa', 1, 5, 12, 'PRD-MKS-001', 'Smart EduProfile - Portofolio Web Pelajar Modern', 'Aplikasi portofolio web responsif karya Rizky yang memuat galeri prestasi sains, ringkasan belajar, dan integrasi formulir kontak interaktif bertema Bisolpin.', 'produk_eduprofile_rizky.png', 'https://github.com/rizky-bisolpin/smart-eduprofile', 'link', 'https://github.com/rizky-bisolpin/smart-eduprofile', 100.00, 95.00, 'Desain sangat profesional untuk tingkat SMP. Logika responsif dan semantic HTML diimplementasikan dengan sangat baik.', 5, 'dipublikasikan', NOW()),
(2, 1, 'karya_siswa', 2, 5, 10, 'PRD-MKS-002', 'Botanica Card Collection - Mini Web Showcase', 'Karya tampilan katalog tanaman hias interaktif berbasis Flexbox CSS3 dengan animasi hover modern dan typography clean.', 'produk_botanica_alya.png', 'https://github.com/alya-bisolpin/botanica-cards', 'link', 'https://github.com/alya-bisolpin/botanica-cards', 85.00, 88.00, 'Palet warna sangat harmonis dan estetik. Menunggu integrasi sedikit script JS sebelum rilis final.', 5, 'menunggu_validasi', NOW()),
(3, 1, 'produk_lembaga', NULL, 4, 7, 'PRD-MKS-KIT01', 'Bisolpin IoT Weather Station Kit', 'Paket perangkat ajar kit sensor suhu dan kelembaban untuk pembelajaran coding tatap muka di lab komputer.', 'kit_iot_bisolpin.png', 'https://bisolpin.com/products/iot-kit', 'link', 'https://bisolpin.com/products/iot-kit', 100.00, 98.00, 'Kit resmi standar pusat siap digunakan oleh seluruh cabang.', 5, 'dipublikasikan', NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: EVALUASI MENTOR OLEH SISWA (tabel_evaluasi_mentor) - EDOM & IKM
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_evaluasi_mentor` (`id`, `id_cabang`, `id_siswa`, `id_mentor`, `id_layanan`, `skor_materi`, `skor_penyampaian`, `skor_kedisiplinan`, `skor_kesabaran`, `nilai_rata_rata`, `ulasan_saran`, `is_anonymous`, `created_at`) VALUES
(1, 1, 2, 5, 5, 5, 5, 5, 5, 5.00, 'Kak Farhan mengajarnya sangat sabar dan ramah. Penjelasan CSS Flexbox yang awalnya sulit jadi sangat mudah dipahami. Terima kasih Kak!', 1, NOW() - INTERVAL 3 DAY);

-- ------------------------------------------------------------------------------
-- SEED DATA: LOG PEMELIHARAAN SARPRAS (tabel_maintenance_log)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_maintenance_log` (`id`, `id_cabang`, `id_aset`, `tanggal_maintenance`, `jenis_perbaikan`, `biaya_perbaikan`, `teknisi_pelaksana`, `kondisi_sebelum`, `kondisi_setelah`, `keterangan_tindakan`, `created_by`, `created_at`) VALUES
(1, 1, 1, '2025-01-25', 'Servis Cuci AC & Pengecekan Kelistrikan', 350000.00, 'CV Hawa Sejuk Bersama', 'perlu_perbaikan', 'baik', 'Pembersihan filter AC 2 PK dan pengisian freon R32. Temperatur ruang kelas stabil di 22C.', 7, NOW()),
(2, 1, 2, '2025-02-02', 'Optimalisasi Jaringan Gigabit LAN & Re-imaging OS', 500000.00, 'Budi Santoso (Sarpras)', 'perlu_perbaikan', 'baik', 'Peremajaan kabel switch hub port 8 dan deploy software VS Code serta compiler C++ untuk 20 PC.', 7, NOW());

-- ------------------------------------------------------------------------------
-- SEED DATA: TRANSAKSI KASIR & PEMBAYARAN SPP (tabel_pembayaran)
-- ------------------------------------------------------------------------------
INSERT INTO `tabel_pembayaran` (`id`, `id_cabang`, `id_siswa`, `id_layanan`, `kode_transaksi`, `tanggal_bayar`, `jumlah_bayar`, `metode_pembayaran`, `status_pembayaran`, `catatan`, `diterima_oleh`, `created_at`) VALUES
(1, 1, 1, 5, 'INV-202502-001', '2025-02-01', 1850000.00, 'transfer_bank', 'lunas', 'Pembayaran lunas pendaftaran kursus Junior Web Developer & Robotik.', 6, NOW()),
(2, 1, 2, 5, 'INV-202502-002', '2025-02-03', 1000000.00, 'tunai', 'cicilan', 'Pembayaran cicilan tahap 1 kursus tatap muka.', 6, NOW());

SET FOREIGN_KEY_CHECKS = 1;
