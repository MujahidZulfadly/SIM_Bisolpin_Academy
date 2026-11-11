# MASTER PROMPT: ENTERPRISE MULTI-BRANCH SIM & KEMITRAAN KURSUS (SIM-BISOLPIN V2)

## 1. KONTEKS & ARSITEKTUR SISTEM
Kamu adalah Principal System Architect & Senior Full-Stack PHP Developer. Tugasmu adalah membangun **Sistem Informasi Manajemen Kursus & Kemitraan Multi-Cabang BISOLPIN** (referensi identitas brand: `https://www.bisolpin.com/`).

### Prinsip Utama Bisnis & Sistem (CRITICAL BUSINESS RULES):
1. **Sistem Manajemen Kursus Offline & Kemitraan (Bukan LMS Belajar Online):**
   - Seluruh proses belajar-mengajar dilakukan secara **Tatap Muka (Offline)** di cabang masing-masing.
   - Sistem ini adalah kombinasi **ERP Kemitraan Cabang + SIAKAD + Katalog Materi Terpusat + Showcase Produk Siswa**.
2. **Arsitektur Multi-Cabang (Multi-Tenant via `id_cabang`):**
   - Dikendalikan oleh **Pimpinan Pusat (Super Admin)**.
   - Calon mitra dapat **mendaftarkan diri sebagai Direktur Cabang** melalui halaman *Registrasi Kemitraan*. Setelah akun dan dokumen diverifikasi oleh Super Admin, cabang baru aktif dan Direktur Cabang mendapatkan akses penuh ke Dashboard Cabangnya sendiri.
3. **Pemisahan Wewenang Akademik (BAK vs Mentor):**
   - **BAK (Bagian Akademik)** bertugas menyusun standar kurikulum, pelajaran, dan mengunggah modul (`tabel_materi`).
   - **Mentor/Tutor** fokus penuh mengajar di kelas offline ("sisa mengajar saja"), membuka modul yang sudah disiapkan BAK, mengisi absensi pertemuan, serta menginput progres dan memvalidasi produk/karya siswa.

---

## 2. SPESIFIKASI TEKNOLOGI (TECH STACK)
- **Backend:** PHP Native (PHP 8.1/8.2+) dengan pendekatan **Modular MVC-ish / Clean Architecture**, wajib menggunakan **PDO (PHP Data Objects)** dengan *Prepared Statements*.
- **Database:** MySQL / MariaDB (InnoDB, relasi *Foreign Key* ketat, dukungan filter `id_cabang`).
- **Frontend & UI/UX:**
  - HTML5 Semantik, CSS3 Modern / Tailwind CSS (via CDN dengan konfigurasi *Design Tokens* sesuai gaya visual `bisolpin.com`), dan Vanilla JavaScript (ES6+ & Fetch AJAX).
  - **Gaya Desain:** *Modern EdTech & SaaS Franchise Dashboard* menggunakan **Bento Box Grid Layout**, navigasi sidebar dinamis sesuai role, tabel interaktif (DataTables), grafik analitik (Chart.js), dan notifikasi (SweetAlert2).

---

## 3. SPESIFIKASI 7 ROLE PENGGUNA & HAK AKSES (RBAC)

### 1. PIMPINAN (Super Admin / Direktur Utama - Pusat)
- **Kontrol Utama & Persetujuan Mitra:** Memverifikasi pendaftaran calon Direktur Cabang baru (Approve/Reject pengajuan mitra, aktivasi cabang, penetapan kuota/lisensi cabang).
- **Global Executive Bento Dashboard:** Memantau performa seluruh cabang secara *real-time* (Total Cabang Aktif, Total Siswa Nasional, Total Pegawai/Mentor, Grafik Perbandingan Pendapatan & Pertumbuhan Siswa Antar-Cabang, Ringkasan Kondisi Aset Nasional).
- **Manajemen Master Layanan Pusat:** Mengatur katalog layanan/program kursus utama dan kebijakan bagi hasil/royalti kemitraan.
- **Audit & Monitoring Lintas Cabang:** Dapat memfilter dashboard untuk menginspeksi data akademik, keuangan, pegawai, dan sarana-prasarana di cabang mana pun.

### 2. DIREKTUR CABANG (Mitra Cabang)
- **Onboarding & Registrasi Mandiri:** Dapat mendaftar akun kemitraan di halaman depan (`register-mitra.php`), melengkapi profil cabang (lokasi, kontak, legalitas), dan memantau status verifikasi.
- **Branch Executive Dashboard:** Melihat metrik khusus cabangnya sendiri (Jumlah Siswa Cabang, Kinerja Mentor Cabang, Pemasukan Cabang, Kelayakan Aset Cabang, serta Portofolio Produk Siswa di cabangnya).
- **Manajemen SDM Cabang:** Memantau daftar pegawai (Staff, Mentor, Sarpras) dan rekap absensi pegawai di cabangnya.
- **Laporan Operasional Cabang:** Mencetak laporan bulanan akademik, keuangan, dan inventaris cabang.

### 3. BAK (Bagian Akademik)
- **Manajemen Kurikulum & Pelajaran:** Menyusun struktur mata pelajaran, level/jenjang, silabus, dan target capaian per pertemuan untuk setiap layanan kursus.
- **Manajemen Modul & Materi (`tabel_materi`):** Mengunggah dan mengelola modul pembelajaran resmi (PDF, PPT, Panduan Praktek, Bahan Ajar) yang terstruktur per Layanan -> Pelajaran -> Pertemuan.
- **Standarisasi Kualitas Akademik:** Memantau kesesuaian antara materi yang disiapkan BAK dengan progres pengajaran Mentor di kelas, serta menentukan indikator penilaian progres & standar produk kelulusan siswa.

### 4. MENTOR / TUTOR (Fokus Mengajar)
- **Dashboard Mengajar Harian:** Melihat jadwal kelas tatap muka hari ini dan mengakses langsung **Modul/Materi resmi yang telah disiapkan oleh BAK** tanpa perlu repot membuat materi sendiri.
- **Presensi Kelas & Absensi Mengajar (`tabel_absensi`):** Melakukan *check-in* kehadiran mengajar dan mengabsen siswa di kelas (`Hadir`, `Izin`, `Sakit`, `Alfa`).
- **Input Progres Siswa & Validasi Produk (`tabel_siswa` & `tabel_produk`):**
  - Mengupdate persentase dan catatan progres belajar siswa per pertemuan/bab.
  - Memberikan penilaian dan menyetujui (*approve*) karya/produk hasil belajar siswa agar tampil di halaman portofolio siswa.

### 5. STAFF (Administrasi Cabang)
- **Pendaftaran & Administrasi Siswa (`tabel_siswa`):** Mengelola pendaftaran siswa baru di cabang, penempatan kelas/layanan, dan pencetakan kartu siswa.
- **Administrasi Pegawai & Absensi Harian (`tabel_pegawai` & `tabel_absensi`):** Mengelola data administrasi pegawai cabang dan rekapitulasi absensi harian staf/mentor.
- **Penjadwalan Kelas & Layanan (`tabel_layanan`):** Mengatur jadwal kelas tatap muka (memasangkan Layanan, Mentor, Siswa, dan Ruangan/Aset).
- **Administrasi Keuangan/Kasir Cabang:** Mengelola tagihan pendaftaran layanan kursus siswa dan pencatatan pembayaran.

### 6. SARANA DAN PRASARANA (Sarpras)
- **Manajemen Inventaris & Aset (`tabel_aset`):** CRUD data seluruh aset fisik dan fasilitas kursus per cabang (Kode Aset, Nama Barang/Ruangan, Kategori, Jumlah, Tahun Pengadaan, Nilai Aset).
- **Monitoring Kondisi & Pemeliharaan (Maintenance):** Mengupdate status kondisi aset (`Baik`, `Perlu Perbaikan`, `Rusak Berat`, `Sedang Dipinjam/Digunakan`) serta mencatat riwayat perawatan/perbaikan fasilitas kelas.
- **Pengajuan Pengadaan Aset:** Mengajukan kebutuhan sarana belajar baru kepada Direktur Cabang / Pimpinan.

### 7. SISWA (Portal Akademik & Showcase Produk)
- **Dashboard Progres Belajar:** Melihat grafik persentase progres belajar (*learning milestone*), rekap kehadiran, jadwal kelas offline, dan nilai evaluasi dari Mentor.
- **Akses Materi & Modul (`tabel_materi`):** Membaca dan mengunduh modul pembelajaran resmi dari BAK sesuai tahapan pertemuan kelas yang sudah diikuti.
- **Etalase Produk & Karya Siswa (`tabel_produk`):**
  - Mengunggah dan menampilkan **Produk / Karya Hasil Belajar** (lengkap dengan foto/thumbnail produk, judul karya, deskripsi, link demo/dokumentasi, kategori layanan, dan *feedback* dari Mentor).
  - Halaman **Galeri Produk Siswa** yang dapat dilihat sebagai portofolio pencapaian belajar di BISOLPIN.

---

## 4. SKEMA DATABASE MYSQL (8 TABEL UTAMA + TABEL RELASI PENDUKUNG)
Buat file `database/schema_and_seed.sql` menggunakan engine `InnoDB` dengan struktur yang berpusat pada **8 Tabel Utama** berikut (dilengkapi tabel autentikasi & jadwal agar berelasi sempurna):

### A. 8 Tabel Utama (Wajib Ada & Lengkap):
1. **`tabel_cabang` (`branches`)**
   - Kolom: `id`, `kode_cabang`, `nama_cabang`, `id_direktur_cabang` (FK ke `users`/`tabel_pegawai`), `alamat`, `kota`, `provinsi`, `telepon`, `email_cabang`, `nomor_izin_mitra`, `dokumen_kemitraan`, `status_mitra` (`ENUM('pending_review','aktif','ditolak','nonaktif')`), `tanggal_bergabung`, `created_at`.
2. **`tabel_pegawai` (`employees` - Mencakup Mentor, Staff, BAK, Sarpras, Direktur Cabang)**
   - Kolom: `id`, `user_id` (FK ke `users`), `id_cabang` (FK ke `tabel_cabang`, `NULL` jika Pusat/BAK Pusat), `nip_pegawai`, `nama_lengkap`, `jabatan_role` (`ENUM('pimpinan','direktur_cabang','bak','mentor','staff','sarpras')`), `spesialisasi_mentor`, `no_hp`, `alamat`, `status_kepegawaian` (`ENUM('tetap','kontrak','mitra_pengajar')`), `status_aktif`, `tanggal_masuk`, `created_at`.
3. **`tabel_layanan` (`services` - Program & Paket Kursus)**
   - Kolom: `id`, `id_cabang` (`NULL` = Layanan Standar Pusat, terisi = Layanan Khusus Cabang), `kode_layanan`, `nama_layanan`, `kategori_jenjang`, `deskripsi`, `jumlah_pertemuan`, `durasi_per_sesi_menit`, `harga_layanan`, `target_output_produk`, `status_aktif`, `created_at`.
4. **`tabel_materi` (`materials` - Modul & Pelajaran oleh BAK)**
   - Kolom: `id`, `id_layanan` (FK ke `tabel_layanan`), `id_pembuat_bak` (FK ke `tabel_pegawai`), `kode_materi`, `nama_pelajaran`, `pertemuan_ke`, `judul_modul`, `ringkasan_materi`, `file_modul` (PDF/PPT), `panduan_mentor` (instruksi khusus untuk mentor saat mengajar di kelas), `standar_tugas_produk`, `status_publikasi` (`ENUM('draft','published','archived')`), `created_at`.
5. **`tabel_siswa` (`students` - Data Induk & Progres Belajar)**
   - Kolom: `id`, `user_id` (FK ke `users`), `id_cabang` (FK ke `tabel_cabang`), `id_layanan` (FK ke `tabel_layanan`), `id_mentor_pembimbing` (FK ke `tabel_pegawai`), `nis_bisolpin`, `nama_lengkap`, `asal_sekolah_instansi`, `no_hp_siswa`, `nama_orang_tua`, `no_hp_orang_tua`, `alamat`, `persentase_progres` (DECIMAL 0-100), `pertemuan_terakhir_diikuti`, `catatan_progres_mentor`, `status_belajar` (`ENUM('aktif','lulus','cuti','keluar')`), `tanggal_daftar`, `created_at`.
6. **`tabel_absensi` (`attendances` - Absensi Siswa & Absensi Pegawai/Mentor)**
   - Kolom: `id`, `id_cabang` (FK ke `tabel_cabang`), `tipe_absensi` (`ENUM('siswa','pegawai_mentor')`), `id_siswa` (FK nullable ke `tabel_siswa`), `id_pegawai` (FK nullable ke `tabel_pegawai`), `id_materi_pertemuan` (FK nullable ke `tabel_materi`), `tanggal_absensi`, `jam_masuk`, `jam_keluar`, `status_kehadiran` (`ENUM('hadir','izin','sakit','alfa')`), `keterangan_jurnal`, `diinput_oleh` (FK ke `users`), `created_at`.
7. **`tabel_produk` (`products` - Karya/Produk Siswa & Produk Kursus)**
   - Kolom: `id`, `id_cabang` (FK ke `tabel_cabang`), `kategori_kepemilikan` (`ENUM('karya_siswa','produk_lembaga')`), `id_siswa` (FK nullable ke `tabel_siswa`), `id_layanan` (FK nullable ke `tabel_layanan`), `id_materi` (FK nullable ke `tabel_materi`), `kode_produk`, `nama_produk`, `deskripsi_produk`, `foto_produk`, `file_atau_url_karya`, `progres_pembuatan` (0-100%), `nilai_karya`, `review_mentor`, `id_mentor_validator` (FK nullable ke `tabel_pegawai`), `status_tampil` (`ENUM('draft','menunggu_validasi','dipublikasikan','revisi')`), `created_at`.
8. **`tabel_aset` (`assets` - Sarana & Prasarana Cabang)**
   - Kolom: `id`, `id_cabang` (FK ke `tabel_cabang`), `id_penanggung_jawab` (FK ke `tabel_pegawai` role Sarpras), `kode_aset`, `nama_aset`, `kategori_aset` (`ENUM('ruang_kelas','perangkat_it','alat_peraga','furnitur','lainnya')`), `lokasi_penempatan`, `jumlah_unit`, `kondisi` (`ENUM('baik','perlu_perbaikan','rusak_berat')`), `tanggal_perolehan`, `nilai_perolehan`, `catatan_maintenance`, `updated_at`.

### B. Tabel Pendukung Autentikasi & Operasional:
9. **`users`**: `id`, `username`, `email`, `password_hash`, `role` (`ENUM('pimpinan','direktur_cabang','bak','mentor','staff','sarpras','siswa')`), `id_cabang` (nullable untuk Pimpinan/BAK Pusat), `is_active`, `last_login`, `created_at`.
10. **`jadwal_kelas`**: Menghubungkan `id_cabang`, `id_layanan`, `id_mentor`, `id_aset_ruangan`, `hari`, `jam_mulai`, `jam_selesai` agar Staff dapat menyusun jadwal dan Mentor tahu jadwal mengajarnya.

*Instruksi Seeder:* Wajib sertakan data dummy realistis untuk ke-7 role, minimal 2 Cabang Aktif + 1 Cabang berstatus `pending_review` (untuk simulasi approval Pimpinan), data BAK, Mentor, Siswa dengan persentase progres & karya di `tabel_produk`, serta inventaris di `tabel_aset`. Semua akun demo menggunakan password `password123` (`PASSWORD_BCRYPT`).

---

## 5. STRUKTUR FOLDER PROYEK (CLEAN MODULAR PHP NATIVE)
```text
/bisolpin-enterprise
├── config/
│   ├── app.php                 # Base URL, konstanta sistem, helper timezone
│   └── database.php            # Koneksi PDO Singleton
├── core/
│   ├── auth.php                # Session, RBAC guard (check_role), & Branch Isolation (get_active_branch_id)
│   ├── csrf.php                # Proteksi CSRF Token
│   ├── helpers.php             # Sanitasi XSS e(), upload handler, format Rupiah & tanggal
│   └── flash.php               # Alert / Flash message session
├── database/
│   └── schema_and_seed.sql     # Full DDL & Seeder untuk 8 Tabel Utama + Tabel Pendukung
├── assets/
│   ├── css/
│   │   ├── variables.css       # Design Tokens gaya visual bisolpin.com
│   │   └── main.css            # Bento Box Grid, badge status, kartu produk & modul
│   ├── js/
│   │   └── app.js              # Logic modal, filter cabang dinamis, preview upload
│   └── uploads/
│       ├── modul_bak/          # File materi PDF/PPT dari BAK
│       ├── produk_siswa/       # Foto/file hasil karya & produk siswa
│       └── dokumen_mitra/      # Dokumen pengajuan kemitraan Direktur Cabang
├── includes/
│   ├── header.php
│   ├── sidebar.php             # Menu dinamis menyesuaikan 1 dari 7 role yang login
│   ├── topbar.php              # Info Cabang Aktif, Role Badge, & Profil
│   └── footer.php
├── modules/
│   ├── auth/
│   │   ├── login.php           # Login multi-role
│   │   ├── register_mitra.php  # Form pendaftaran mandiri Calon Direktur Cabang (Mitra Baru)
│   │   └── logout.php
│   ├── pimpinan/               # Dashboard Global, Approval Mitra Cabang, Monitoring Semua Tabel
│   ├── direktur_cabang/        # Dashboard Cabang, Statistik SDM/Siswa/Aset Cabang, Laporan
│   ├── bak/                    # CRUD Layanan Akademik, Kurikulum, & Upload Modul (tabel_materi)
│   ├── mentor/                 # Jadwal Mengajar, Buka Modul BAK, Input Absensi, Update Progres & Validasi Produk Siswa
│   ├── staff/                  # CRUD Siswa, Pegawai Cabang, Jadwal Kelas, Absensi Harian, Administrasi Layanan
│   ├── sarpras/                # CRUD Aset Cabang (tabel_aset), Log Maintenance, Update Kondisi Fasilitas
│   └── siswa/                  # Dashboard Progres Belajar, Unduh Materi BAK, & CRUD/Showcase Produk Siswa (tabel_produk)
└── index.php                   # Gerbang utama & auto-redirect berdasarkan Role@