-- ==============================================================================
-- SIM BISOLPIN ACADEMY - PATCH EVALUASI & RATING KINERJA MENTOR (EDOM & IKM)
-- File: database/patch_evaluasi_mentor.sql
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Buat Tabel Evaluasi & Rating Kinerja Mentor oleh Siswa
CREATE TABLE IF NOT EXISTS `tabel_evaluasi_mentor` (
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
  UNIQUE KEY `unique_evaluasi` (`id_siswa`, `id_layanan`, `id_mentor`),
  CONSTRAINT `fk_eval_cabang` FOREIGN KEY (`id_cabang`) REFERENCES `tabel_cabang` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tabel_siswa` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_mentor` FOREIGN KEY (`id_mentor`) REFERENCES `tabel_pegawai` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_layanan` FOREIGN KEY (`id_layanan`) REFERENCES `tabel_layanan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Bersihkan Data Evaluasi Lama jika Ada (Idempotent)
DELETE FROM `tabel_evaluasi_mentor` WHERE `id_mentor` IN (5);

-- 3. Seed Data Evaluasi Riil untuk Mentor Cabang Makassar (Ahmad Farhan Fadillah - ID 5)
INSERT INTO `tabel_evaluasi_mentor` (
  `id_cabang`, `id_siswa`, `id_mentor`, `id_layanan`,
  `skor_materi`, `skor_penyampaian`, `skor_kedisiplinan`, `skor_kesabaran`,
  `nilai_rata_rata`, `ulasan_saran`, `is_anonymous`, `created_at`
) VALUES
-- Evaluasi Siswa 2 (Alya Khairunnisa)
(
  1, 2, 5, 5,
  5, 5, 5, 5,
  5.00,
  'Kak Farhan mengajarnya sangat sabar dan ramah. Penjelasan CSS Flexbox yang awalnya sulit jadi sangat mudah dipahami. Terima kasih Kak!',
  1,
  NOW() - INTERVAL 3 DAY
);

-- Seed Data Simulasi Evaluasi Tambahan (untuk mengisi data statistik grafik & rapor kepuasan cabang & nasional)
-- Siswa 1 (Rizky) dibiarkan belum mengisi agar banner prompt "Beri Penilaian untuk Mentor Anda" dapat diuji langsung saat login sebagai siswa!

SET FOREIGN_KEY_CHECKS = 1;

