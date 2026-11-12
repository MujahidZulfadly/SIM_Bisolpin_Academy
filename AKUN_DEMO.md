# 📋 LEMBAR KREDENSIAL PENGUJIAN AKUN DEMO
### **SIM BISOLPIN ACADEMY** — *Sistem Informasi Manajemen Kursus & Kemitraan Multi-Cabang*

Dokumen ini memuat daftar lengkap kredensial akun contoh (*demo accounts*) untuk seluruh **7 Role Pengguna** serta akun **Cabang Kolaka (Sulawesi Tenggara)**. Lembar ini dirancang khusus agar Pimpinan, Manajemen, Penguji (*Tester*), dan Tim QA dapat langsung login dan menguji seluruh skenario alur kerja sistem tanpa kebingungan.

---

## 🔑 Kunci Akses Seragam
> [!IMPORTANT]
> Seluruh akun demo pada sistem ini menggunakan **password seragam**:
> - **Password:** `password123`
> - **URL Halaman Login:** `http://localhost:8000/modules/auth/login.php` (atau `http://localhost/SIM_bisolpin_academy/modules/auth/login.php`)

---

## 1. Tabel Ringkas Kredensial Pengujian (7 Role Inti + Cabang Kolaka)

| No | Role Sistem | Username | Password | Nama Lengkap & Gelar | Lingkup / Cabang | Hak Akses Utama |
|:--:|:---|:---|:---|:---|:---|:---|
| **1** | **Pimpinan Pusat** (`pimpinan`) | `pimpinan` | `password123` | Dr. H. Muhammad Ardiansyah, M.Pd. | **Nasional (Kantor Pusat)** | Executive Bento Dashboard, Approval Kemitraan, Monitoring IKM Seluruh Cabang, Rekap Finansial. |
| **2** | **Direktur Cabang** (`direktur_cabang`) | `direktur_mks` | `password123` | Ir. Faisal Rahman, S.T., M.Kom. | **Cabang Utama Makassar** | CRUD Pegawai Cabang, Data Siswa, Evaluasi Mutu Mentor, Inventaris Aset Cabang, Laporan. |
| **3** | **Bagian Akademik** (`bak`) | `bak_pusat` | `password123` | Prof. Dr. Nurul Hidayati, M.Sc. | **Nasional (Kantor Pusat)** | Master Paket Kursus Bisolpin, Kurikulum & Modul Ajar, Standar Capaian Produk. |
| **4** | **Mentor / Pengajar** (`mentor`) | `mentor_ahmad` | `password123` | Ahmad Farhan Fadillah, S.Kom. | **Cabang Utama Makassar** | Ruang Mengajar, Presensi Siswa Tatap Muka, Validasi Karya Siswa, Rapor Kepuasan Mengajar (EDOM). |
| **5** | **Staff Cabang** (`staff`) | `staff_siti` | `password123` | Siti Rahmawati, S.Sos. | **Cabang Utama Makassar** | Pendaftaran & Biodata Siswa, Chat WhatsApp Ortu, Kasir & SPP, Jadwal Kelas Tatap Muka. |
| **6** | **Sarpras Cabang** (`sarpras`) | `sarpras_budi` | `password123` | Budi Santoso, A.Md.T. | **Cabang Utama Makassar** | Inventaris Ruang & Perangkat Lab, Laporan Kerusakan & Maintenance Fisik. |
| **7** | **Siswa** (`siswa`) | `siswa_rizky` | `password123` | Muhammad Rizky Pratama | **Cabang Utama Makassar** | Progres Materi, Jadwal Tatap Muka, Upload Portofolio, Form Evaluasi Mentor (EDOM). |

---

## 2. Tabel Kredensial Khusus Cabang Kolaka (Sulawesi Tenggara)

Khusus untuk pengujian isolasi multi-cabang (*branch data multi-tenancy*), sistem menyediakan data dan akun terverifikasi untuk **Bisolpin Cabang Kolaka**:

| No | Role Sistem | Username | Password | Email Akun | Nama Petugas Cabang Kolaka | Status |
|:--:|:---|:---|:---|:---|:---|:---|
| **1** | **Direktur Cabang Kolaka** | `direktur_kolaka` | `password123` | `direktur.kolaka@bisolpin.com` | drh. Andi Mappatunru, M.Si. | **Aktif** (Mitra Terverifikasi) |
| **2** | **Mentor Cabang Kolaka** | `mentor_kolaka` | `password123` | `mentor.kolaka@bisolpin.com` | Muh. Ilham Pratama, S.Pd. | **Aktif** |
| **3** | **Staff Cabang Kolaka** | `staff_kolaka` | `password123` | `staff.kolaka@bisolpin.com` | Nurul Wahyuni, S.Kom. | **Aktif** |

> [!NOTE]
> - **Kode Cabang:** `CBG-KLA-04`
> - **Alamat Fisik:** Jl. Pramuka No. 18, Lamokato, Kec. Kolaka, Kabupaten Kolaka, Sulawesi Tenggara (Telp: `0405-232188`).
> - **Uji Multi-Tenant:** Saat login sebagai `direktur_kolaka`, Anda **hanya** dapat melihat dan mengelola pegawai, siswa, serta aset milik Cabang Kolaka, terisolasi sempurna dari Cabang Makassar atau Jakarta.

---

## 3. Akun Pendukung Tambahan (Siswa & Calon Mitra Pending)

| Role | Username | Password | Nama Lengkap | Cabang | Tujuan Pengujian Spesifik |
|:---|:---|:---|:---|:---|:---|
| **Siswa 2** | `siswa_alya` | `password123` | Alya Khairunnisa Azzahra | Cabang Makassar | Siswa yang sudah mengisi formulir EDOM (dapat diuji di rapor mentor). |
| **Calon Mitra** | `mitra_sby` | `password123` | Bambang Soebroto, S.E., M.M. | Cabang Surabaya Gubeng | Akun berstatus `pending_review` (belum aktif) untuk menguji alur verifikasi MoU oleh Pimpinan Pusat. |

---

## 4. Panduan Skenario Pengujian Fitur Unggulan (QA Checklist)

### 🧪 Skenario 1: Ceklok Presensi Mandiri Pegawai (Clock-In & Clock-Out)
1. Login dengan salah satu role pegawai: `direktur_mks`, `mentor_ahmad`, `staff_siti`, `sarpras_budi`, atau `bak_pusat`.
2. Pada bagian atas Dashboard, perhatikan **Widget Ceklok Presensi Hari Ini** lengkap dengan jam digital *real-time*.
3. Klik tombol hijau **"Absen Masuk (Clock In)"** atau klik **"Ajukan Izin / Sakit"**.
4. Sistem akan mencatat waktu masuk secara instan.
5. Menjelang pulang kerja, klik tombol biru **"Absen Pulang (Clock Out)"** dan isi catatan aktivitas harian.
6. Cek riwayat lengkap pada menu **"Presensi Ceklok Saya"** di sidebar.

---

### 🧪 Skenario 2: Alur Evaluasi Mutu Mentor (EDOM & IKM Engine)
1. **Sisi Siswa (`siswa_rizky`):** 
   - Begitu progres belajar mencapai $\ge$ 80%, muncul banner oranye di Dashboard: *"Beri Penilaian untuk Mentor Anda"*.
   - Buka menu **"Evaluasi & Rating Mentor"**.
   - Berikan rating bintang 1 s/d 5 untuk 4 aspek (Materi, Penyampaian, Disiplin, Kesabaran) dan tulis saran konstruktif secara 100% anonim.
2. **Sisi Mentor (`mentor_ahmad`):**
   - Buka menu **"Rapor Kepuasan Mengajar"**.
   - Lihat skor rata-rata bintang, persentase kepuasan, breakdown 4 grafik capaian, dan ulasan siswa dengan identitas tersamarkan (*Siswa Bisolpin*).
3. **Sisi Direktur Cabang (`direktur_mks`):**
   - Buka menu **"Evaluasi Mutu Mentor"**.
   - Tinjau ranking seluruh mentor di cabang, badge predikat (Hijau/Kuning/Merah), dan gunakan detail ulasan sebagai bahan supervisi/perpanjangan kontrak.
4. **Sisi Pimpinan Pusat (`pimpinan`):**
   - Lihat kartu KPI **"Indeks Kepuasan Pengajaran Nasional (IKM)"** di Dashboard Bento.
   - Buka halaman **"Monitoring Seluruh Cabang"** untuk melihat tabel perbandingan (*benchmarking*) kepuasan siswa antar-cabang.

---

### 🧪 Skenario 3: Onboarding & Multi-Tenant Isolasi Cabang
1. Login sebagai **`direktur_mks`** (Makassar) &rarr; Buat pegawai baru di menu *"Tim Pegawai & Mentor"*. Sistem otomatis mengunci `id_cabang = 1` tanpa kebocoran data.
2. Login sebagai **`direktur_kolaka`** (Kolaka) &rarr; Periksa data pegawai. Pegawai Cabang Makassar **tidak akan muncul** di Cabang Kolaka.
3. Login sebagai **`staff_siti`** &rarr; Tambah siswa baru di menu *"Pendaftaran Siswa"*. Tombol pintas **"Chat WA Ortu"** (`wa.me/...`) langsung tersedia pada tabel data siswa.
4. Login sebagai **`pimpinan`** &rarr; Gunakan dropdown **"Filter Cabang"** di bagian atas untuk berpindah sudut pandang pemantauan antara Seluruh Nasional, Makassar, atau Kolaka secara fleksibel.

---

## 5. Tips & Penanganan Kendala

1. **Bagaimana jika database belum memiliki data Cabang Kolaka?**
   - Jalankan perintah SQL yang ada pada file [`database/patch_cabang_kolaka.sql`](file:///d:/SIM_bisolpin_academy/database/patch_cabang_kolaka.sql), atau import ulang [`database/schema_and_seed.sql`](file:///d:/SIM_bisolpin_academy/database/schema_and_seed.sql).
2. **Koneksi Database:**
   - Konfigurasi database tersimpan di [`config/database.php`](file:///d:/SIM_bisolpin_academy/config/database.php) dengan default: Host `127.0.0.1`, User `root`, Password kosong, Database `db_bisolpin_sim`.
3. **Format Hash Password:**
   - Sistem menggunakan algoritma aman `PASSWORD_BCRYPT`. Jika membuat user manual via SQL, gunakan hash:
     ```sql
     $2y$10$OBBayw84fmCV4He3ykQ1GOs6F/bTZ1wAfuc45CxBy6CPVRRvw6Dj2
     ```
     *(Hash di atas sesuai dengan plaintext: `password123`)*

---
*© 2026 SIM BISOLPIN ACADEMY. PT Solusi Pintar Indonesia. All rights reserved.*

