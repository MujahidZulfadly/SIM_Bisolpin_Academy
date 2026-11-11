-- ==============================================================================
-- SIM BISOLPIN ACADEMY - PATCH PENYELARASAN IDENTITAS RESMI BISOLPIN.COM
-- File: database/patch_bisolpin_official.sql
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Penyesuaian tabel_layanan
-- Tambah tipe layanan, jenjang resmi, harga promo, dan mata pelajaran inti
ALTER TABLE `tabel_layanan` 
  ADD COLUMN IF NOT EXISTS `tipe_layanan` ENUM('kursus_reguler','les_privat','tka_tryout','event_workshop') NOT NULL DEFAULT 'kursus_reguler' AFTER `nama_layanan`,
  ADD COLUMN IF NOT EXISTS `harga_promo` DECIMAL(12,2) NULL DEFAULT NULL AFTER `harga_layanan`,
  ADD COLUMN IF NOT EXISTS `mata_pelajaran` ENUM('Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web','Persiapan TKA','Komputer & Bisnis Digital','Lainnya') NOT NULL DEFAULT 'Coding' AFTER `tipe_layanan`;

-- Ubah enum kategori_jenjang agar selaras dengan jenjang resmi
ALTER TABLE `tabel_layanan`
  MODIFY COLUMN `kategori_jenjang` ENUM('TK','SD','SMP','SMA','SMK','Mahasiswa','Umum') NOT NULL DEFAULT 'SD';

-- 2. Penyesuaian tabel_siswa
-- Tambah biodata lengkap resmi (umur, jenjang, kelas, sekolah, wilayah, wa ortu, dan skor TKA)
ALTER TABLE `tabel_siswa`
  ADD COLUMN IF NOT EXISTS `umur` INT NULL DEFAULT NULL AFTER `nama_lengkap`,
  ADD COLUMN IF NOT EXISTS `jenjang` ENUM('TK','SD','SMP','SMA','SMK','Mahasiswa','Umum') NULL DEFAULT 'SD' AFTER `umur`,
  ADD COLUMN IF NOT EXISTS `kelas` VARCHAR(50) NULL DEFAULT NULL AFTER `jenjang`,
  ADD COLUMN IF NOT EXISTS `nama_sekolah` VARCHAR(150) NULL DEFAULT NULL AFTER `kelas`,
  ADD COLUMN IF NOT EXISTS `provinsi` VARCHAR(100) NULL DEFAULT 'Sulawesi Selatan' AFTER `alamat`,
  ADD COLUMN IF NOT EXISTS `kabupaten_kota` VARCHAR(100) NULL DEFAULT 'Kota Makassar' AFTER `provinsi`,
  ADD COLUMN IF NOT EXISTS `kecamatan` VARCHAR(100) NULL DEFAULT 'Panakkukang' AFTER `kabupaten_kota`,
  ADD COLUMN IF NOT EXISTS `no_wa_ortu` VARCHAR(30) NULL DEFAULT NULL AFTER `no_hp_orang_tua`,
  ADD COLUMN IF NOT EXISTS `skor_tka` DECIMAL(5,2) NULL DEFAULT NULL AFTER `catatan_progres_mentor`,
  ADD COLUMN IF NOT EXISTS `detail_tka` VARCHAR(255) NULL DEFAULT NULL AFTER `skor_tka`;

-- 3. Penyesuaian tabel_pegawai untuk Portal Rekrutmen Pengajar Cabang
ALTER TABLE `tabel_pegawai`
  MODIFY COLUMN `status_aktif` ENUM('aktif','nonaktif','pending_review') NOT NULL DEFAULT 'aktif',
  ADD COLUMN IF NOT EXISTS `spesialisasi_mapel` ENUM('Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web') NULL DEFAULT 'Coding' AFTER `spesialisasi_mentor`,
  ADD COLUMN IF NOT EXISTS `pengalaman_mengajar` TEXT NULL DEFAULT NULL AFTER `alamat`,
  ADD COLUMN IF NOT EXISTS `berkas_cv` VARCHAR(255) NULL DEFAULT NULL AFTER `pengalaman_mengajar`;

-- ------------------------------------------------------------------------------
-- SEED DATA RESMI: 5 MATA PELAJARAN INTI + TKA TRY OUT + WORKSHOP KOMPUTER
-- ------------------------------------------------------------------------------

-- Bersihkan data lama jika ada
DELETE FROM `tabel_layanan` WHERE `kode_layanan` IN (
  'LYN-CAL-01', 'LYN-MAT-01', 'LYN-ENG-01', 'LYN-COD-01', 'LYN-WEB-01', 'LYN-TKA-01', 'LYN-WSP-01'
);

INSERT INTO `tabel_layanan` (
  `id`, `id_cabang`, `kode_layanan`, `nama_layanan`, `tipe_layanan`, `mata_pelajaran`,
  `kategori_jenjang`, `deskripsi`, `jumlah_pertemuan`, `durasi_per_sesi_menit`,
  `harga_layanan`, `harga_promo`, `target_output_produk`, `status_aktif`, `created_at`
) VALUES
(10, NULL, 'LYN-CAL-01', 'Calistung & Logika Angka Ceria', 'kursus_reguler', 'Calistung', 'TK', 'Program membaca, menulis, dan berhitung interaktif dengan metode visual kreatif untuk anak usia dini.', 16, 60, 950000.00, 750000.00, 'Buku Jurnal Membaca Mandiri & Lembar Kreativitas Huruf-Angka', 'aktif', NOW()),
(11, NULL, 'LYN-MAT-01', 'Matematika Hebat & Penalaran Kuantitatif', 'kursus_reguler', 'Matematika', 'SD', 'Pembelajaran konsep dasar matematika, aritmatika cepat, dan pemecahan soal HOTS terstruktur.', 16, 90, 1200000.00, 990000.00, 'Kompilasi Rumus Aritmatika & Portofolio Pemecahan Soal Cerita', 'aktif', NOW()),
(12, NULL, 'LYN-ENG-01', 'English Active Speaking & Global Communication', 'kursus_reguler', 'Bahasa Inggris', 'SMP', 'Pelatihan percakapan bahasa Inggris aktif, listening komprehensif, dan penguasaan vocabulary harian.', 12, 90, 1350000.00, 1100000.00, 'Video Presentasi Mandiri & Mini Daily Conversation Booklet', 'aktif', NOW()),
(13, NULL, 'LYN-COD-01', 'Junior Coding & Game Logic Development', 'kursus_reguler', 'Coding', 'SD', 'Pelatihan logika algoritma komputer anak melalui visual block coding dan pembuatan game interaktif.', 16, 90, 1750000.00, 1450000.00, 'Game 2D Interaktif Scratch & Mini Animasi Komputasi', 'aktif', NOW()),
(14, NULL, 'LYN-WEB-01', 'Fullstack Web & Digital Solution Development', 'kursus_reguler', 'Pemrograman Web', 'SMA', 'Pengembangan website modern responsive menggunakan teknologi HTML5, CSS3, JavaScript, dan PHP database.', 20, 120, 2250000.00, 1850000.00, 'Website Portofolio Responsif & Prototipe Sistem Informasi', 'aktif', NOW()),
(15, NULL, 'LYN-TKA-01', 'Simulasi Intensif TKA & Try Out Terpadu', 'tka_tryout', 'Persiapan TKA', 'SMA', 'Drilling soal-soal Tes Kemampuan Akademik (TKA) persiapan seleksi PTN dan sekolah kedinasan.', 8, 120, 850000.00, 650000.00, 'Laporan Analisis Ketuntasan Materi & Rapor Skor Try Out Nasional', 'aktif', NOW()),
(16, 1, 'LYN-WSP-01', 'Workshop Desain Digital & Bisnis Kreatif', 'event_workshop', 'Komputer & Bisnis Digital', 'Umum', 'Pelatihan intensif 2 hari pembuatan materi promosi digital, branding visual, dan setup landing page bisnis.', 4, 180, 500000.00, 350000.00, 'Katalog Produk Digital & Aset Desain Siap Publikasi', 'aktif', NOW());

-- Update data seeder siswa agar memiliki biodata lengkap
UPDATE `tabel_siswa` SET
  `umur` = 14,
  `jenjang` = 'SMP',
  `kelas` = 'Kelas 8',
  `nama_sekolah` = 'SMP Negeri 6 Makassar',
  `provinsi` = 'Sulawesi Selatan',
  `kabupaten_kota` = 'Kota Makassar',
  `kecamatan` = 'Panakkukang',
  `no_wa_ortu` = '081241556677'
WHERE `id` = 1;

UPDATE `tabel_siswa` SET
  `umur` = 13,
  `jenjang` = 'SMP',
  `kelas` = 'Kelas 7',
  `nama_sekolah` = 'SMP Athirah 1 Makassar',
  `provinsi` = 'Sulawesi Selatan',
  `kabupaten_kota` = 'Kota Makassar',
  `kecamatan` = 'Panakkukang',
  `no_wa_ortu` = '081342998811'
WHERE `id` = 2;

-- ------------------------------------------------------------------------------
-- SEED DATA RESMI: MODUL MATERI BAK UNTUK 5 MAPEL INTI + TKA + WORKSHOP
-- ------------------------------------------------------------------------------
DELETE FROM `tabel_materi` WHERE `kode_materi` IN (
  'MAT-CAL-01', 'MAT-CAL-02', 'MAT-MAT-01', 'MAT-MAT-02',
  'MAT-ENG-01', 'MAT-ENG-02', 'MAT-COD-01', 'MAT-COD-02',
  'MAT-WEB-01', 'MAT-WEB-02', 'MAT-TKA-01', 'MAT-TKA-02', 'MAT-WSP-01'
);

-- 1. Modul Calistung (LYN-CAL-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-CAL-01', 'Calistung Ceria', 1, 'Pengenalan Fonik, Huruf Vokal & Huruf Konsonan', 'Metode bermain sambil belajar mengenal alfabet A-Z dengan lagu fonik dan kartu bergambar interaktif.', 'modul_fonik_calistung_bak.pdf', 'file', NULL, 'Gunakan flashcard visual dan ajak siswa melafalkan bunyi huruf vokal dengan lantang dan riang.', 'Siswa dapat melafalkan 5 huruf vokal dan menuliskan nama panggilannya sendiri di buku gambar.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-CAL-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-CAL-02', 'Calistung Ceria', 2, 'Berhitung Ceria 1-20 & Asosiasi Objek Visual', 'Pengenalan bilangan 1 sampai 20 menggunakan benda konkret, jari tangan, dan stiker warna-warni.', 'modul_angka_calistung_bak.pdf', 'file', NULL, 'Lakukan simulasi berhitung dengan menghitung jumlah balok atau buah di atas meja kelas.', 'Lembar kerja menghitung objek dan mewarnai angka 1 sampai 20 terisi rapi.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-CAL-01' LIMIT 1;

-- 2. Modul Matematika (LYN-MAT-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-MAT-01', 'Matematika Hebat', 1, 'Aritmatika Cepat & Logika Bilangan Bulat', 'Metode mental math penjumlahan, pengurangan cepat tanpa coretan kertas, dan trik perkalian jari.', 'modul_aritmatika_cepat_bak.pdf', 'file', NULL, 'Bimbing siswa mempraktikkan trik hitung cepat di papan tulis, berikan kuis cepat berhadiah poin.', 'Siswa mampu menyelesaikan 15 soal penjumlahan bertingkat dalam waktu kurang dari 5 menit.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-MAT-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-MAT-02', 'Matematika Hebat', 2, 'Penalaran Kuantitatif & Strategi Soal Cerita HOTS', 'Membedah kalimat soal cerita, menentukan variabel matematika, dan strategi eliminasi kesalahan logika.', 'modul_penalaran_matematika_bak.pdf', 'file', NULL, 'Bahas 3 tipe soal cerita HOTS yang sering muncul pada asesmen sekolah.', 'Portofolio penyelesaian 5 soal cerita HOTS dengan langkah penalaran yang terstruktur.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-MAT-01' LIMIT 1;

-- 3. Modul Bahasa Inggris (LYN-ENG-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-ENG-01', 'English Active Speaking', 1, 'Daily Active Conversation & Confident Self-Introduction', 'Penguasaan frasa percakapan harian, teknik intonasi percaya diri, dan simulasi perkenalan diri formal.', 'modul_speaking_intro_bak.pdf', 'file', NULL, 'Ajak siswa berpasangan (roleplay) untuk mempraktikkan dialog perkenalan di depan kelas.', 'Video rekaman 1 menit perkenalan diri dalam bahasa Inggris yang lancar dan percaya diri.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-ENG-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-ENG-02', 'English Active Speaking', 2, 'Listening Comprehension & Active Vocabulary Builder', 'Mendengarkan rekaman audio penutur asli (native speaker) dan menyusun 25 kosakata baru tematik.', 'modul_listening_vocab_bak.pdf', 'link', 'https://drive.google.com/drive/folders/bisolpin-english-audio', 'Putarkan audio listening 2 kali, lalu pandu diskusi makna konteks kalimat bersama siswa.', 'Lembar kerja listening terisi 100% dan daftar kartu kosakata (flashcard) mandiri siswa.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-ENG-01' LIMIT 1;

-- 4. Modul Coding (LYN-COD-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-COD-01', 'Junior Coding & Game Logic', 1, 'Logika Algoritma & Visual Block Programming Scratch', 'Pengenalan konsep sprite, koordinat X-Y, motion block, dan event listener "when green flag clicked".', 'modul_scratch_dasar_bak.pdf', 'link', 'https://scratch.mit.edu', 'Instruksikan siswa membuka Scratch editor dan membuat karakter bergerak mengikuti tombol panah keyboard.', 'Karya animasi mini di Scratch berisi 1 sprite bergerak dan efek suara dinamis.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-COD-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-COD-02', 'Junior Coding & Game Logic', 2, 'Game Loop, Variabel Skor & Animasi Karakter Interaktif', 'Membuat game menangkap benda jatuh menggunakan variabel score, conditional if-touching, dan timer.', 'modul_scratch_game_loop_bak.pdf', 'file', NULL, 'Bimbing siswa menyusun script penambahan skor saat sprite keranjang menyentuh sprite buah jatuh.', 'Game tangkap buah interaktif yang dapat dimainkan dengan skor yang bertambah otomatis.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-COD-01' LIMIT 1;

-- 5. Modul Pemrograman Web (LYN-WEB-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-WEB-01', 'Fullstack Web Development', 1, 'Arsitektur Web Modern & Fondasi Struktur Dokumen HTML5', 'Pengenalan client-server, protokol HTTP, semantic HTML5 tags (header, nav, main, section, footer).', 'modul_html5_semantic_bak.pdf', 'file', NULL, 'Bimbing siswa mengetik struktur kode HTML menggunakan VS Code dan menjalankannya di Google Chrome.', 'Halaman web profil pribadi berisi foto, deskripsi biografi, tabel hobi, dan 3 hyperlink tautan luar.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-WEB-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-WEB-02', 'Fullstack Web Development', 2, 'Styling Antarmuka Responsif dengan Flexbox & Tailwind CSS', 'Tata letak kartu profil modern, box-model, shadow lembut, transisi hover, dan grid adaptif ponsel.', 'modul_css_flexbox_bak.pdf', 'link', 'https://tailwindcss.com', 'Ajak siswa menyusun 3 kartu produk responsif yang rapi di layar desktop maupun smartphone.', 'Kartu profil interaktif responsif ber-radius lembut dengan efek hover dinamis.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-WEB-01' LIMIT 1;

-- 6. Modul Persiapan TKA & Try Out (LYN-TKA-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-TKA-01', 'Persiapan TKA & Try Out', 1, 'Bedah Pola Soal TKA & Trik Eliminasi Opsi Cepat', 'Analisis pola soal tes kemampuan akademik terkini, manajemen waktu 90 detik per butir soal, dan tips eliminasi.', 'modul_bedah_tka_saintek_soshum.pdf', 'file', NULL, 'Jelaskan jebakan distraktor dalam soal pilihan ganda dan ajarkan siswa membaca kata kunci pertanyaan.', 'Lembar jawaban bedah soal 20 nomor dengan catatan alasan eliminasi pada tiap opsi salah.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-TKA-01' LIMIT 1;

INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-TKA-02', 'Persiapan TKA & Try Out', 2, 'Simulasi Try Out Nasional Berbatas Waktu & Evaluasi Analitis', 'Pengerjaan paket simulasi try out 50 nomor dengan pengawasan mentor dan penghitungan skor akurasi otomatis.', 'paket_simulasi_tryout_nasional.pdf', 'link', 'https://drive.google.com/drive/folders/bisolpin-tryout-tka', 'Awasi jalannya simulasi try out selama 90 menit penuh, pastikan siswa tidak membuka catatan.', 'Rapor skor try out dengan rincian jumlah benar, salah, kosong, dan nilai akhir skala 100.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-TKA-01' LIMIT 1;

-- 7. Modul Workshop Desain Digital & Bisnis Kreatif (LYN-WSP-01)
INSERT INTO `tabel_materi` (`id_layanan`, `id_pembuat_bak`, `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul`, `tipe_lampiran`, `external_url`, `panduan_mentor`, `standar_tugas_produk`, `status_publikasi`, `created_at`)
SELECT id, 4, 'MAT-WSP-01', 'Komputer & Bisnis Digital', 1, 'Branding Visual, Desain Konten Kreatif & Setup Etalase Digital', 'Dasar teori warna bisnis, pembuatan feed media sosial di Canva/Figma, dan integrasi WhatsApp katalog produk.', 'panduan_workshop_desain_bisnis.pdf', 'link', 'https://www.figma.com', 'Bimbing peserta merancang 3 banner promosi bisnis dan menyusun bio profil media sosial yang menarik.', 'Paket aset promosi bisnis digital siap upload ke media sosial dan katalog daring.', 'published', NOW()
FROM `tabel_layanan` WHERE `kode_layanan` = 'LYN-WSP-01' LIMIT 1;

SET FOREIGN_KEY_CHECKS = 1;


