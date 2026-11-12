-- ==============================================================================
-- SIM BISOLPIN ACADEMY - PATCH CABANG KOLAKA (SULAWESI TENGGARA)
-- File: database/patch_cabang_kolaka.sql
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tambah Data Cabang Kolaka jika belum ada
INSERT INTO `tabel_cabang` (
    `id`, `kode_cabang`, `nama_cabang`, `id_direktur_cabang`, 
    `alamat`, `kota`, `provinsi`, `telepon`, `email_cabang`, 
    `nomor_izin_mitra`, `dokumen_kemitraan`, `status_mitra`, `tanggal_bergabung`, `created_at`
) VALUES (
    4, 'CBG-KLA-04', 'Bisolpin Cabang Kolaka', 10,
    'Jl. Pramuka No. 18, Lamokato, Kec. Kolaka', 'Kabupaten Kolaka', 'Sulawesi Tenggara', 
    '0405-232188', 'kolaka@bisolpin.com', 'MITRA-KLA-2025-007', 
    'mou_kolaka_terverifikasi.pdf', 'aktif', '2025-02-15', NOW()
) ON DUPLICATE KEY UPDATE 
    `nama_cabang` = VALUES(`nama_cabang`),
    `status_mitra` = 'aktif';

-- 2. Tambah Akun Users untuk Cabang Kolaka (Password default: password123)
-- Hash: $2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `id_cabang`, `is_active`, `created_at`)
VALUES
(10, 'direktur_kolaka', 'direktur.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'direktur_cabang', 4, 1, NOW()),
(11, 'mentor_kolaka', 'mentor.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'mentor', 4, 1, NOW()),
(12, 'staff_kolaka', 'staff.kolaka@bisolpin.com', '$2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2', 'staff', 4, 1, NOW())
ON DUPLICATE KEY UPDATE
    `password_hash` = VALUES(`password_hash`),
    `is_active` = 1;

-- 3. Update ID Direktur Cabang Kolaka
UPDATE `tabel_cabang` SET `id_direktur_cabang` = 10 WHERE `id` = 4;

-- 4. Tambah Biodata Pegawai Cabang Kolaka
INSERT INTO `tabel_pegawai` (
    `id`, `user_id`, `id_cabang`, `nip_pegawai`, `nama_lengkap`, 
    `jabatan_role`, `spesialisasi_mentor`, `no_hp`, `alamat`, 
    `status_kepegawaian`, `status_aktif`, `tanggal_masuk`, `created_at`
) VALUES
(8, 10, 4, 'CBG-KLA-DIR01', 'drh. Andi Mappatunru, M.Si.', 'direktur_cabang', 'Manajemen Kemitraan Wilayah', '085242110099', 'Jl. Pemuda No. 77, Kolaka', 'tetap', 'aktif', '2025-02-15', NOW()),
(9, 11, 4, 'KLA-MEN-001', 'Muh. Ilham Pratama, S.Pd.', 'mentor', 'Matematika & Coding Dasar', '082344556677', 'Jl. Mekongga Indah Blok B, Kolaka', 'tetap', 'aktif', '2025-02-20', NOW()),
(10, 12, 4, 'KLA-STF-001', 'Nurul Wahyuni, S.Kom.', 'staff', 'Administrasi & Layanan Cabang', '085399881122', 'Jl. Abadi No. 12, Kolaka', 'tetap', 'aktif', '2025-02-20', NOW())
ON DUPLICATE KEY UPDATE
    `status_aktif` = 'aktif';

SET FOREIGN_KEY_CHECKS = 1;

