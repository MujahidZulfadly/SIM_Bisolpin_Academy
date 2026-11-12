<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Pendaftaran & Data Siswa Cabang
 * Mendukung Status Finansial (Lunas, Cicilan, Belum Bayar), Multi-Enrollment (riwayat_layanan_siswa),
 * serta Soft-Delete (Arsip Siswa).
 */

declare(strict_types=1);

$pageTitle = 'Pendaftaran & Data Siswa';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

$db = get_db();
ensure_database_schema_integrity($db);
$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;

// Keamanan isolasi tenant multi-cabang
verify_branch_access($branchId);

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH SISWA BARU
    if ($action === 'tambah_siswa') {
        $nis = trim($_POST['nis_bisolpin'] ?? '');
        $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? 'password123');
        $idLayanan = (int)($_POST['id_layanan'] ?? 0);
        $idMentor = !empty($_POST['id_mentor']) ? (int)$_POST['id_mentor'] : null;
        $umur = !empty($_POST['umur']) ? (int)$_POST['umur'] : null;
        $jenjang = in_array($_POST['jenjang'] ?? '', ['TK','SD','SMP','SMA','SMK','Mahasiswa','Umum'], true) ? $_POST['jenjang'] : 'SMP';
        $kelas = trim($_POST['kelas'] ?? '');
        $namaSekolah = trim($_POST['nama_sekolah'] ?? '');
        $asalSekolah = trim($_POST['asal_sekolah_instansi'] ?? '');
        if (empty($asalSekolah) && !empty($namaSekolah)) {
            $asalSekolah = $namaSekolah;
        }
        if (empty($namaSekolah) && !empty($asalSekolah)) {
            $namaSekolah = $asalSekolah;
        }
        $noHpSiswa = trim($_POST['no_hp_siswa'] ?? '');
        $namaOrangTua = trim($_POST['nama_orang_tua'] ?? '');
        $noHpOrangTua = trim($_POST['no_hp_orang_tua'] ?? '');
        $noWaOrtu = trim($_POST['no_wa_ortu'] ?? '');
        if (empty($noHpOrangTua) && !empty($noWaOrtu)) {
            $noHpOrangTua = $noWaOrtu;
        }
        if (empty($noWaOrtu) && !empty($noHpOrangTua)) {
            $noWaOrtu = $noHpOrangTua;
        }
        $provinsi = trim($_POST['provinsi'] ?? 'Sulawesi Selatan');
        $kabupatenKota = trim($_POST['kabupaten_kota'] ?? 'Kota Makassar');
        $kecamatan = trim($_POST['kecamatan'] ?? 'Panakkukang');
        $alamat = trim($_POST['alamat'] ?? '');
        $tanggalDaftar = !empty($_POST['tanggal_daftar']) ? trim($_POST['tanggal_daftar']) : date('Y-m-d');

        // Parameter Finansial Patch 1
        $statusPembayaran = in_array($_POST['status_pembayaran'] ?? '', ['belum_bayar', 'cicilan', 'lunas'], true) 
            ? $_POST['status_pembayaran'] 
            : 'belum_bayar';
        $nominalTerbayar = (float)($_POST['nominal_terbayar'] ?? 0.00);

        if (empty($nis) || empty($namaLengkap) || empty($username) || empty($email) || empty($idLayanan)) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Mohon lengkapi NIS, Nama Lengkap, Username, Email, dan Pilihan Layanan.', 'Validasi Gagal');
        }

        // Cek keunikan NIS
        $stmtCekNis = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE nis_bisolpin = ?");
        $stmtCekNis->execute([$nis]);
        if ((int)$stmtCekNis->fetchColumn() > 0) {
            redirect_with_message('modules/staff/siswa.php', 'error', "NIS '{$nis}' sudah terdaftar dalam sistem.", 'NIS Duplikat');
        }

        // Cek keunikan Username & Email di users
        $stmtCekUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtCekUser->execute([$username, $email]);
        if ((int)$stmtCekUser->fetchColumn() > 0) {
            redirect_with_message('modules/staff/siswa.php', 'error', "Username atau Email tersebut sudah digunakan oleh akun lain.", 'Akun Duplikat');
        }

        try {
            $db->beginTransaction();

            // 1. Buat User Login Siswa
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $db->prepare("
                INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at)
                VALUES (?, ?, ?, 'siswa', ?, 1, NOW())
            ");
            $stmtUser->execute([$username, $email, $passwordHash, $branchId]);
            $userId = (int)$db->lastInsertId();

            // 2. Buat Record di tabel_siswa (Lengkap Biodata Resmi Bisolpin, Finansial & is_archived = 0)
            $stmtSiswa = $db->prepare("
                INSERT INTO tabel_siswa (
                    user_id, id_cabang, id_layanan, id_mentor_pembimbing, nis_bisolpin,
                    nama_lengkap, umur, jenjang, kelas, nama_sekolah,
                    asal_sekolah_instansi, no_hp_siswa, nama_orang_tua,
                    no_hp_orang_tua, no_wa_ortu, alamat, provinsi, kabupaten_kota, kecamatan,
                    persentase_progres, pertemuan_terakhir_diikuti,
                    catatan_progres_mentor, status_belajar, status_pembayaran, nominal_terbayar,
                    is_archived, tanggal_daftar, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    0.00, 0,
                    'Siswa baru terdaftar. Menunggu sesi pertemuan perdana di kelas tatap muka.', 'aktif', ?, ?,
                    0, ?, NOW()
                )
            ");
            $stmtSiswa->execute([
                $userId, $branchId, $idLayanan, $idMentor, $nis,
                $namaLengkap, $umur, $jenjang, $kelas, $namaSekolah,
                $asalSekolah, $noHpSiswa, $namaOrangTua,
                $noHpOrangTua, $noWaOrtu, $alamat, $provinsi, $kabupatenKota, $kecamatan,
                $statusPembayaran, $nominalTerbayar,
                $tanggalDaftar
            ]);
            $newSiswaId = (int)$db->lastInsertId();

            // 3. Buat Catatan Pertama pada Tabel Jembatan riwayat_layanan_siswa
            $stmtRls = $db->prepare("
                INSERT INTO riwayat_layanan_siswa 
                (id_siswa, id_cabang, id_layanan, id_mentor, persentase_progres, status_pembayaran, nominal_terbayar, status_kursus, created_at)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, 'aktif', NOW())
            ");
            $stmtRls->execute([$newSiswaId, $branchId, $idLayanan, $idMentor, $statusPembayaran, $nominalTerbayar]);

            $db->commit();
            redirect_with_message('modules/staff/siswa.php', 'success', "Siswa {$namaLengkap} ({$nis}) berhasil didaftarkan dan paket kursus aktif telah dicatat.", 'Pendaftaran Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal tambah siswa: " . $e->getMessage());
            redirect_with_message('modules/staff/siswa.php', 'error', 'Terjadi kesalahan sistem saat mendaftarkan siswa: ' . $e->getMessage(), 'Pendaftaran Gagal');
        }
    }

    // 2. EDIT DATA SISWA & STATUS KEUANGAN
    if ($action === 'edit_siswa') {
        $id = (int)($_POST['id'] ?? 0);
        $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
        $umur = !empty($_POST['umur']) ? (int)$_POST['umur'] : null;
        $jenjang = in_array($_POST['jenjang'] ?? '', ['TK','SD','SMP','SMA','SMK','Mahasiswa','Umum'], true) ? $_POST['jenjang'] : 'SMP';
        $kelas = trim($_POST['kelas'] ?? '');
        $namaSekolah = trim($_POST['nama_sekolah'] ?? '');
        $asalSekolah = trim($_POST['asal_sekolah_instansi'] ?? '');
        if (empty($asalSekolah) && !empty($namaSekolah)) {
            $asalSekolah = $namaSekolah;
        }
        if (empty($namaSekolah) && !empty($asalSekolah)) {
            $namaSekolah = $asalSekolah;
        }
        $noHpSiswa = trim($_POST['no_hp_siswa'] ?? '');
        $namaOrangTua = trim($_POST['nama_orang_tua'] ?? '');
        $noHpOrangTua = trim($_POST['no_hp_orang_tua'] ?? '');
        $noWaOrtu = trim($_POST['no_wa_ortu'] ?? '');
        if (empty($noHpOrangTua) && !empty($noWaOrtu)) {
            $noHpOrangTua = $noWaOrtu;
        }
        if (empty($noWaOrtu) && !empty($noHpOrangTua)) {
            $noWaOrtu = $noHpOrangTua;
        }
        $provinsi = trim($_POST['provinsi'] ?? 'Sulawesi Selatan');
        $kabupatenKota = trim($_POST['kabupaten_kota'] ?? 'Kota Makassar');
        $kecamatan = trim($_POST['kecamatan'] ?? 'Panakkukang');
        $alamat = trim($_POST['alamat'] ?? '');
        $idLayanan = (int)($_POST['id_layanan'] ?? 0);
        $idMentor = !empty($_POST['id_mentor']) ? (int)$_POST['id_mentor'] : null;
        $statusBelajar = trim($_POST['status_belajar'] ?? 'aktif');

        // Parameter Finansial
        $statusPembayaran = in_array($_POST['status_pembayaran'] ?? '', ['belum_bayar', 'cicilan', 'lunas'], true) 
            ? $_POST['status_pembayaran'] 
            : 'belum_bayar';
        $nominalTerbayar = (float)($_POST['nominal_terbayar'] ?? 0.00);

        if (empty($namaLengkap) || empty($idLayanan)) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Nama Lengkap dan Layanan Kursus wajib diisi.', 'Validasi Gagal');
        }

        // Verifikasi kepemilikan siswa di cabang aktif
        $stmtCek = $db->prepare("SELECT user_id, id_cabang FROM tabel_siswa WHERE id = ?");
        $stmtCek->execute([$id]);
        $siswaRow = $stmtCek->fetch();

        if (!$siswaRow) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Data siswa tidak ditemukan.', 'Akses Ditolak');
        }
        verify_branch_access((int)$siswaRow['id_cabang']);

        try {
            $db->beginTransaction();

            $stmtUpdate = $db->prepare("
                UPDATE tabel_siswa SET
                    nama_lengkap = ?,
                    umur = ?,
                    jenjang = ?,
                    kelas = ?,
                    nama_sekolah = ?,
                    asal_sekolah_instansi = ?,
                    no_hp_siswa = ?,
                    nama_orang_tua = ?,
                    no_hp_orang_tua = ?,
                    no_wa_ortu = ?,
                    provinsi = ?,
                    kabupaten_kota = ?,
                    kecamatan = ?,
                    alamat = ?,
                    id_layanan = ?,
                    id_mentor_pembimbing = ?,
                    status_belajar = ?,
                    status_pembayaran = ?,
                    nominal_terbayar = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUpdate->execute([
                $namaLengkap, $umur, $jenjang, $kelas, $namaSekolah,
                $asalSekolah, $noHpSiswa, $namaOrangTua, $noHpOrangTua, $noWaOrtu,
                $provinsi, $kabupatenKota, $kecamatan, $alamat,
                $idLayanan, $idMentor,
                $statusBelajar, $statusPembayaran, $nominalTerbayar,
                $id, $branchId
            ]);

            // Sinkronkan juga pada riwayat_layanan_siswa untuk layanan aktif
            $stmtUpRls = $db->prepare("
                UPDATE riwayat_layanan_siswa 
                SET status_pembayaran = ?, nominal_terbayar = ?, id_mentor = ?
                WHERE id_siswa = ? AND id_layanan = ?
            ");
            $stmtUpRls->execute([$statusPembayaran, $nominalTerbayar, $idMentor, $id, $idLayanan]);

            $db->commit();
            redirect_with_message('modules/staff/siswa.php', 'success', "Data dan status pembayaran siswa {$namaLengkap} berhasil diperbarui.", 'Pembaruan Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal edit siswa: " . $e->getMessage());
            redirect_with_message('modules/staff/siswa.php', 'error', 'Terjadi kesalahan sistem: ' . $e->getMessage(), 'Gagal Update');
        }
    }

    // 3. TAMBAH PAKET KURSUS BARU UNTUK SISWA EKSISTING (MULTI-ENROLLMENT)
    if ($action === 'tambah_paket_kursus') {
        $idSiswa          = (int)($_POST['id_siswa'] ?? 0);
        $idLayananBaru    = (int)($_POST['id_layanan_baru'] ?? 0);
        $idMentorBaru     = !empty($_POST['id_mentor_baru']) ? (int)$_POST['id_mentor_baru'] : null;
        $statusPembayaran = in_array($_POST['status_pembayaran_paket'] ?? '', ['belum_bayar', 'cicilan', 'lunas'], true) 
            ? $_POST['status_pembayaran_paket'] 
            : 'belum_bayar';
        $nominalTerbayar  = (float)($_POST['nominal_terbayar_paket'] ?? 0.00);

        if ($idSiswa <= 0 || $idLayananBaru <= 0) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Pilih siswa dan paket program kursus baru yang valid.', 'Validasi Gagal');
        }

        // Verifikasi kepemilikan siswa di cabang
        $stmtSiswaCek = $db->prepare("SELECT id, nama_lengkap, id_cabang FROM tabel_siswa WHERE id = ?");
        $stmtSiswaCek->execute([$idSiswa]);
        $sData = $stmtSiswaCek->fetch();

        if (!$sData) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Siswa tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$sData['id_cabang']);

        try {
            // Cek apakah siswa sudah terdaftar pada layanan ini dan masih aktif
            $stmtCekDup = $db->prepare("SELECT COUNT(*) FROM riwayat_layanan_siswa WHERE id_siswa = ? AND id_layanan = ? AND status_kursus = 'aktif'");
            $stmtCekDup->execute([$idSiswa, $idLayananBaru]);
            if ((int)$stmtCekDup->fetchColumn() > 0) {
                redirect_with_message('modules/staff/siswa.php', 'warning', "Siswa {$sData['nama_lengkap']} saat ini sudah aktif terdaftar pada paket kursus tersebut.", 'Paket Sudah Aktif');
            }

            // Simpan paket baru ke tabel jembatan riwayat_layanan_siswa
            $stmtNewCourse = $db->prepare("
                INSERT INTO riwayat_layanan_siswa 
                (id_siswa, id_cabang, id_layanan, id_mentor, persentase_progres, status_pembayaran, nominal_terbayar, status_kursus, created_at)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, 'aktif', NOW())
            ");
            $stmtNewCourse->execute([
                $idSiswa, $branchId, $idLayananBaru, $idMentorBaru,
                $statusPembayaran, $nominalTerbayar
            ]);

            redirect_with_message('modules/staff/siswa.php', 'success', "Paket kursus tambahan berhasil didaftarkan untuk {$sData['nama_lengkap']} tanpa membuat akun baru!", 'Multi-Enrollment Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal tambah paket kursus: " . $e->getMessage());
            redirect_with_message('modules/staff/siswa.php', 'error', 'Gagal menambahkan paket kursus: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 4. SOFT DELETE / ARSIPKAN SISWA (Bukan DELETE fisik)
    if ($action === 'hapus_siswa') {
        $id = (int)($_POST['id'] ?? 0);

        $stmtCek = $db->prepare("SELECT user_id, nama_lengkap, id_cabang FROM tabel_siswa WHERE id = ?");
        $stmtCek->execute([$id]);
        $row = $stmtCek->fetch();

        if (!$row) {
            redirect_with_message('modules/staff/siswa.php', 'error', 'Data siswa tidak ditemukan.', 'Gagal Hapus');
        }
        verify_branch_access((int)$row['id_cabang']);

        try {
            $db->beginTransaction();

            // SOFT DELETE: Tandai is_archived = 1 dan ubah status_belajar ke 'keluar'
            $stmtArchive = $db->prepare("
                UPDATE tabel_siswa 
                SET is_archived = 1, status_belajar = 'keluar' 
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtArchive->execute([$id, $branchId]);

            // Nonaktifkan akses login portal siswa
            $stmtUserDeact = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmtUserDeact->execute([$row['user_id']]);

            $db->commit();
            redirect_with_message('modules/staff/siswa.php', 'success', "Siswa {$row['nama_lengkap']} berhasil diarsipkan (soft delete). Riwayat presensi dan portofolio tetap tersimpan rapi.", 'Siswa Diarsipkan');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal arsipkan siswa: " . $e->getMessage());
            redirect_with_message('modules/staff/siswa.php', 'error', 'Gagal mengarsipkan siswa: ' . $e->getMessage(), 'Gagal');
        }
    }
}

// AMBIL DATA SISWA DENGAN FILTER (Hanya yang TIDAK diarsipkan secara default)
$filterStatus = trim($_GET['status'] ?? '');
$filterLayanan = !empty($_GET['layanan']) ? (int)$_GET['layanan'] : 0;
$filterPayment = trim($_GET['pembayaran'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT s.*, l.nama_layanan, l.kode_layanan, l.kategori_jenjang, l.harga_layanan,
           p.nama_lengkap as nama_mentor, u.username, u.email,
           (SELECT COUNT(*) FROM riwayat_layanan_siswa r WHERE r.id_siswa = s.id) as total_kursus_diambil
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.id_cabang = ? AND s.is_archived = 0
";
$params = [$branchId];

if (!empty($filterStatus)) {
    $sql .= " AND s.status_belajar = ?";
    $params[] = $filterStatus;
}
if (!empty($filterLayanan)) {
    $sql .= " AND s.id_layanan = ?";
    $params[] = $filterLayanan;
}
if (!empty($filterPayment)) {
    $sql .= " AND s.status_pembayaran = ?";
    $params[] = $filterPayment;
}
if (!empty($search)) {
    $sql .= " AND (s.nama_lengkap LIKE ? OR s.nis_bisolpin LIKE ? OR s.asal_sekolah_instansi LIKE ? OR s.no_hp_siswa LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY s.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$siswaList = $stmt->fetchAll();

// Ambil Layanan Aktif untuk Form Dropdown
$layananList = $db->query("SELECT id, kode_layanan, nama_layanan, kategori_jenjang, harga_layanan FROM tabel_layanan WHERE status_aktif = 'aktif' ORDER BY nama_layanan ASC")->fetchAll();

// Ambil Mentor Cabang untuk Form Dropdown
$stmtMentor = $db->prepare("SELECT id, nip_pegawai, nama_lengkap, spesialisasi_mentor FROM tabel_pegawai WHERE id_cabang = ? AND jabatan_role = 'mentor' AND status_aktif = 'aktif' ORDER BY nama_lengkap ASC");
$stmtMentor->execute([$branchId]);
$mentorList = $stmtMentor->fetchAll();

// Ambil Riwayat Layanan Seluruh Siswa untuk Detail Multi-Enrollment
$stmtAllRls = $db->prepare("
    SELECT r.*, l.nama_layanan, l.kode_layanan, p.nama_lengkap as nama_mentor
    FROM riwayat_layanan_siswa r
    JOIN tabel_layanan l ON l.id = r.id_layanan
    LEFT JOIN tabel_pegawai p ON p.id = r.id_mentor
    WHERE r.id_cabang = ?
    ORDER BY r.created_at DESC
");
$stmtAllRls->execute([$branchId]);
$allRls = $stmtAllRls->fetchAll();
$rlsMap = [];
foreach ($allRls as $item) {
    $rlsMap[$item['id_siswa']][] = $item;
}

// Rekomendasi NIS Baru: NIS-TAHUN-CBG-XXXX
$tahunNow = date('Y');
$countNis = (int)$db->query("SELECT COUNT(*) FROM tabel_siswa")->fetchColumn() + 1;
$suggestedNis = sprintf("NIS-%s-%04d", $tahunNow, $countNis);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-user-graduate"></i>
            <span>Manajemen Kesiswaan & Multi-Enrollment</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Pendaftaran & Data Induk Siswa
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Kelola pendaftaran siswa baru, status pelunasan kursus, pendaftaran multi-paket kursus, dan kartu pelajar.
        </p>
    </div>

    <div>
        <button onclick="openModal('modalTambahSiswa')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Daftar Siswa Baru</span>
        </button>
    </div>
</div>

<!-- Filter & Pencarian Bar -->
<div class="card p-4 mb-6">
    <form method="GET" action="" class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3 flex-1 min-w-[280px]">
            <div class="relative flex-1 min-w-[200px]">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama, NIS, sekolah, atau no telp..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-36">
                <select name="status" class="form-input text-xs py-2">
                    <option value="">Status Belajar</option>
                    <option value="aktif" <?= $filterStatus === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="lulus" <?= $filterStatus === 'lulus' ? 'selected' : '' ?>>Lulus</option>
                    <option value="cuti" <?= $filterStatus === 'cuti' ? 'selected' : '' ?>>Cuti</option>
                    <option value="keluar" <?= $filterStatus === 'keluar' ? 'selected' : '' ?>>Keluar</option>
                </select>
            </div>

            <div class="w-40">
                <select name="pembayaran" class="form-input text-xs py-2">
                    <option value="">Status Keuangan</option>
                    <option value="lunas" <?= $filterPayment === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                    <option value="cicilan" <?= $filterPayment === 'cicilan' ? 'selected' : '' ?>>Cicilan</option>
                    <option value="belum_bayar" <?= $filterPayment === 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                </select>
            </div>

            <div class="w-44">
                <select name="layanan" class="form-input text-xs py-2">
                    <option value="">Semua Layanan</option>
                    <?php foreach ($layananList as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= $filterLayanan === (int)$l['id'] ? 'selected' : '' ?>>
                            <?= e($l['nama_layanan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterStatus) || !empty($filterLayanan) || !empty($filterPayment)): ?>
                <a href="<?= base_url('modules/staff/siswa.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset Filter
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Total Siswa Aktif: <span class="font-bold text-slate-800"><?= count($siswaList) ?> Siswa</span>
        </div>
    </form>
</div>

<!-- Tabel Data Siswa -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <th class="py-3 px-4">Siswa & NIS</th>
                    <th class="py-3 px-4">Program & Multi-Kursus</th>
                    <th class="py-3 px-4">Mentor Pembimbing</th>
                    <th class="py-3 px-4">Status Finansial</th>
                    <th class="py-3 px-4 text-center">Progres Belajar</th>
                    <th class="py-3 px-4 text-center">Status</th>
                    <th class="py-3 px-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($siswaList)): ?>
                    <?= render_empty_state(
                        'fa-user-graduate',
                        'Belum Ada Siswa Terdaftar',
                        'Daftarkan siswa baru ke dalam program kursus offline untuk memulai kegiatan belajar mengajar dan pelacakan progres.',
                        '<button type="button" onclick="openModal(\'modalTambahSiswa\')" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20"><i class="fa-solid fa-user-plus mr-1"></i> Daftarkan Siswa Baru</button>',
                        7
                    ) ?>
                <?php else: ?>
                    <?php foreach ($siswaList as $s): ?>
                        <?php 
                        $enrolledCourses = $rlsMap[$s['id']] ?? [];
                        ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center font-black text-sm flex-shrink-0">
                                        <?= strtoupper(substr($s['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h4 class="font-bold text-slate-800 hover:text-brand-600 transition-colors"><?= e($s['nama_lengkap']) ?></h4>
                                            <?php if (!empty($s['umur'])): ?>
                                                <span class="text-[10px] text-slate-400 font-medium">(<?= (int)$s['umur'] ?> thn)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="font-mono text-[11px] text-brand-600 font-semibold"><?= e($s['nis_bisolpin']) ?></span>
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                <?= e($s['jenjang'] ?? 'SMP') ?><?= !empty($s['kelas']) ? ' • ' . e($s['kelas']) : '' ?>
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-400 truncate max-w-[200px]" title="<?= e($s['nama_sekolah'] ?? $s['asal_sekolah_instansi'] ?? 'Umum') ?>">
                                            <i class="fa-solid fa-school text-[10px] mr-1 text-slate-300"></i><?= e($s['nama_sekolah'] ?? $s['asal_sekolah_instansi'] ?? 'Umum') ?>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3 px-4">
                                <span class="font-bold text-slate-800 block"><?= e($s['nama_layanan']) ?></span>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[11px] text-slate-500 font-mono"><?= e($s['kode_layanan']) ?></span>
                                    <?php if (count($enrolledCourses) > 1): ?>
                                        <button onclick='lihatRiwayatPaket(<?= json_encode($s) ?>, <?= json_encode($enrolledCourses) ?>)' 
                                                class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 font-bold text-[10px] border border-purple-200 hover:bg-purple-100">
                                            <?= count($enrolledCourses) ?> Paket Kursus
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="py-3 px-4">
                                <?php if (!empty($s['nama_mentor'])): ?>
                                    <div class="flex items-center gap-1.5 font-semibold text-slate-700">
                                        <i class="fa-solid fa-chalkboard-user text-teal-500 text-[11px]"></i>
                                        <span><?= e($s['nama_mentor']) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-slate-400 italic text-[11px]">Belum di-assign</span>
                                <?php endif; ?>
                            </td>

                            <!-- STATUS FINANSIAL / PEMBAYARAN -->
                            <td class="py-3 px-4">
                                <?php
                                $payStatus = $s['status_pembayaran'] ?? 'belum_bayar';
                                $badgeColor = match($payStatus) {
                                    'lunas'       => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                    'cicilan'     => 'bg-amber-100 text-amber-800 border-amber-200',
                                    default       => 'bg-rose-100 text-rose-800 border-rose-200'
                                };
                                $payLabel = match($payStatus) {
                                    'lunas'       => 'Lunas',
                                    'cicilan'     => 'Cicilan',
                                    default       => 'Belum Bayar'
                                };
                                ?>
                                <div>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $badgeColor ?>">
                                        <?= $payLabel ?>
                                    </span>
                                    <p class="text-[11px] font-mono text-slate-700 font-bold mt-1">
                                        <?= format_rupiah($s['nominal_terbayar'] ?? 0) ?>
                                    </p>
                                </div>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <div class="w-24 mx-auto">
                                    <div class="flex items-center justify-between text-[11px] font-bold text-slate-700 mb-1">
                                        <span><?= number_format((float)$s['persentase_progres'], 0) ?>%</span>
                                        <span class="text-slate-400 font-normal">Sesi <?= $s['pertemuan_terakhir_diikuti'] ?></span>
                                    </div>
                                    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                                        <div class="bg-brand-500 h-1.5 rounded-full" style="width: <?= min(100, (float)$s['persentase_progres']) ?>%"></div>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($s['status_belajar']) ?>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <div class="inline-flex items-center gap-1.5">
                                    <!-- Tombol Tambah Paket Kursus Baru -->
                                    <button onclick="tambahPaket(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)" title="Tambah Paket Kursus Baru (Tanpa Akun Baru)" class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 hover:bg-purple-100 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-layer-group text-xs"></i>
                                    </button>

                                    <!-- Tombol Kartu Pelajar -->
                                    <button onclick="cetakKartu(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)" title="Cetak Kartu Siswa" class="w-8 h-8 rounded-lg bg-teal-50 text-teal-600 hover:bg-teal-100 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-id-card text-xs"></i>
                                    </button>

                                    <!-- Tombol Chat WhatsApp Ortu -->
                                    <?php 
                                    $waOrtu = !empty($s['no_wa_ortu']) ? $s['no_wa_ortu'] : (!empty($s['no_hp_orang_tua']) ? $s['no_hp_orang_tua'] : $s['no_hp_siswa']);
                                    $waMsg = "Halo Ayah/Bunda dari {$s['nama_lengkap']} ({$s['nis_bisolpin']}), kami dari Staff SIM BISOLPIN ACADEMY ingin menginformasikan perihal kegiatan kursus ananda.";
                                    $waUrl = format_wa_url($waOrtu, $waMsg);
                                    ?>
                                    <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" title="Chat WhatsApp Orang Tua (<?= e($waOrtu) ?>)" class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 hover:bg-emerald-100 flex items-center justify-center transition-colors">
                                        <i class="fa-brands fa-whatsapp text-sm"></i>
                                    </a>

                                    <!-- Tombol Edit -->
                                    <button onclick="editSiswa(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Siswa" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </button>

                                    <!-- Tombol Arsip (Soft Delete) -->
                                    <form method="POST" action="" onsubmit="return confirm('Arsipkan siswa <?= addslashes($s['nama_lengkap']) ?>? Riwayat presensi akan tetap tersimpan.');" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hapus_siswa">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button type="submit" title="Arsipkan Siswa (Soft Delete)" class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 flex items-center justify-center transition-colors">
                                            <i class="fa-solid fa-box-archive text-xs"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM TAMBAH SISWA BARU -->
<!-- ========================================================================= -->
<div id="modalTambahSiswa" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Formulir Pendaftaran Siswa Baru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Siswa akan otomatis dibuatkan akun portal siswa di Bisolpin.</p>
            </div>
            <button onclick="closeModal('modalTambahSiswa')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_siswa">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Data Akademik & Identitas Siswa -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nomor Induk Siswa (NIS) <span class="text-rose-500">*</span></label>
                    <input type="text" name="nis_bisolpin" value="<?= e($suggestedNis) ?>" required class="form-input text-xs font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap Siswa <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" placeholder="Contoh: Muhammad Rizky Pratama" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jenjang Pendidikan <span class="text-rose-500">*</span></label>
                    <select name="jenjang" required class="form-input text-xs font-semibold">
                        <option value="TK">TK (Taman Kanak-Kanak)</option>
                        <option value="SD">SD (Sekolah Dasar)</option>
                        <option value="SMP" selected>SMP (Sekolah Menengah Pertama)</option>
                        <option value="SMA">SMA (Sekolah Menengah Atas)</option>
                        <option value="SMK">SMK (Sekolah Menengah Kejuruan)</option>
                        <option value="Mahasiswa">Mahasiswa / Perguruan Tinggi</option>
                        <option value="Umum">Umum / Profesional</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Umur (Tahun)</label>
                        <input type="number" name="umur" min="4" max="70" placeholder="Contoh: 14" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tingkat / Kelas</label>
                        <input type="text" name="kelas" placeholder="Contoh: Kelas 8" class="form-input text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Sekolah / Instansi</label>
                    <input type="text" name="nama_sekolah" placeholder="Contoh: SMP Negeri 6 Makassar" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp Siswa <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp_siswa" placeholder="Contoh: 085299887766" required class="form-input text-xs">
                </div>

                <!-- Kontak Wali & Wilayah -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Orang Tua / Wali</label>
                    <input type="text" name="nama_orang_tua" placeholder="Nama ayah / ibu / wali" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp Orang Tua / Wali <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_wa_ortu" placeholder="Contoh: 081241556677" required class="form-input text-xs">
                </div>

                <div class="grid grid-cols-3 gap-2 md:col-span-2">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Provinsi</label>
                        <input type="text" name="provinsi" value="Sulawesi Selatan" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Kabupaten / Kota</label>
                        <input type="text" name="kabupaten_kota" value="Kota Makassar" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Kecamatan</label>
                        <input type="text" name="kecamatan" value="Panakkukang" class="form-input text-xs">
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Domisili Siswa</label>
                    <textarea name="alamat" rows="2" placeholder="Alamat lengkap tempat tinggal..." class="form-input text-xs"></textarea>
                </div>

                <!-- Program Kursus & Finansial -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilihan Layanan Kursus <span class="text-rose-500">*</span></label>
                    <?php if (empty($layananList)): ?>
                        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-start gap-2">
                            <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5"></i>
                            <div>
                                <strong class="font-bold">Belum Ada Program Kursus Aktif</strong>
                                <p class="text-[11px] text-amber-700 mt-0.5">Master kurikulum kursus dibuat oleh tim <strong>Bagian Akademik (BAK)</strong> atau <strong>Pimpinan Pusat</strong>.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <select name="id_layanan" required class="form-input text-xs">
                            <option value="">-- Pilih Program Kursus --</option>
                            <?php foreach ($layananList as $l): ?>
                                <option value="<?= $l['id'] ?>"><?= e($l['nama_layanan']) ?> (<?= format_rupiah($l['harga_layanan']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mentor Pembimbing (Tutor)</label>
                    <?php if (empty($mentorList)): ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 flex items-start gap-2">
                            <i class="fa-solid fa-info-circle text-slate-400 mt-0.5"></i>
                            <div>
                                <p class="text-[11px] text-slate-500">Belum ada mentor aktif di cabang ini. Daftarkan mentor di menu <strong>Data Pegawai Cabang</strong>.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <select name="id_mentor" class="form-input text-xs">
                            <option value="">-- Tentukan Nanti / Random --</option>
                            <?php foreach ($mentorList as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= e($m['nama_lengkap']) ?> (<?= e($m['spesialisasi_mentor'] ?? 'Mentor') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Pembayaran Kursus <span class="text-rose-500">*</span></label>
                    <select name="status_pembayaran" required class="form-input text-xs font-bold">
                        <option value="belum_bayar">Belum Bayar</option>
                        <option value="cicilan">Cicilan / DP</option>
                        <option value="lunas">Lunas Penuh</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nominal Terbayar (Rp)</label>
                    <input type="number" name="nominal_terbayar" value="0" min="0" step="10000" class="form-input text-xs font-mono font-bold">
                </div>
            </div>

            <!-- Akun Portal Siswa -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200">
                <h4 class="text-xs font-bold text-slate-800 mb-2 flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-teal-600"></i> Akun Login Portal Siswa (Single Sign-On)
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Username Siswa <span class="text-rose-500">*</span></label>
                        <input type="text" name="username" placeholder="siswa_nama" required class="form-input text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Email Siswa <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" placeholder="siswa@gmail.com" required class="form-input text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Password Awal</label>
                        <input type="text" name="password" value="password123" class="form-input text-xs bg-white">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahSiswa')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Pendaftaran
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM EDIT DATA SISWA & STATUS PEMBAYARAN -->
<!-- ========================================================================= -->
<div id="modalEditSiswa" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Data & Status Siswa</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="editNisDisplay">-</p>
            </div>
            <button onclick="closeModal('modalEditSiswa')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_siswa">
            <input type="hidden" name="id" id="edit_id" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap Siswa <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" id="edit_nama_lengkap" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jenjang Pendidikan <span class="text-rose-500">*</span></label>
                    <select name="jenjang" id="edit_jenjang" required class="form-input text-xs font-semibold">
                        <option value="TK">TK (Taman Kanak-Kanak)</option>
                        <option value="SD">SD (Sekolah Dasar)</option>
                        <option value="SMP">SMP (Sekolah Menengah Pertama)</option>
                        <option value="SMA">SMA (Sekolah Menengah Atas)</option>
                        <option value="SMK">SMK (Sekolah Menengah Kejuruan)</option>
                        <option value="Mahasiswa">Mahasiswa / Perguruan Tinggi</option>
                        <option value="Umum">Umum / Profesional</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Umur (Tahun)</label>
                        <input type="number" name="umur" id="edit_umur" min="4" max="70" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tingkat / Kelas</label>
                        <input type="text" name="kelas" id="edit_kelas" class="form-input text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Sekolah / Instansi</label>
                    <input type="text" name="nama_sekolah" id="edit_nama_sekolah" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp Siswa <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp_siswa" id="edit_no_hp_siswa" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Belajar</label>
                    <select name="status_belajar" id="edit_status_belajar" class="form-input text-xs font-semibold">
                        <option value="aktif">Aktif</option>
                        <option value="lulus">Lulus</option>
                        <option value="cuti">Cuti</option>
                        <option value="keluar">Keluar</option>
                    </select>
                </div>

                <!-- Kontak Wali & Wilayah -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Orang Tua / Wali</label>
                    <input type="text" name="nama_orang_tua" id="edit_nama_orang_tua" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp Orang Tua / Wali <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_wa_ortu" id="edit_no_wa_ortu" required class="form-input text-xs">
                </div>

                <div class="grid grid-cols-3 gap-2 md:col-span-2">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Provinsi</label>
                        <input type="text" name="provinsi" id="edit_provinsi" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Kabupaten / Kota</label>
                        <input type="text" name="kabupaten_kota" id="edit_kabupaten_kota" class="form-input text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Kecamatan</label>
                        <input type="text" name="kecamatan" id="edit_kecamatan" class="form-input text-xs">
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Domisili Siswa</label>
                    <textarea name="alamat" id="edit_alamat" rows="2" class="form-input text-xs"></textarea>
                </div>

                <!-- Program Kursus & Finansial -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilihan Layanan Kursus <span class="text-rose-500">*</span></label>
                    <select name="id_layanan" id="edit_id_layanan" required class="form-input text-xs">
                        <?php foreach ($layananList as $l): ?>
                            <option value="<?= $l['id'] ?>"><?= e($l['nama_layanan']) ?> (<?= format_rupiah($l['harga_layanan']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mentor Pembimbing</label>
                    <select name="id_mentor" id="edit_id_mentor" class="form-input text-xs">
                        <option value="">-- Belum Ditentukan --</option>
                        <?php foreach ($mentorList as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Pembayaran <span class="text-rose-500">*</span></label>
                    <select name="status_pembayaran" id="edit_status_pembayaran" required class="form-input text-xs font-bold">
                        <option value="belum_bayar">Belum Bayar</option>
                        <option value="cicilan">Cicilan / DP</option>
                        <option value="lunas">Lunas</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nominal Terbayar (Rp)</label>
                    <input type="number" name="nominal_terbayar" id="edit_nominal_terbayar" min="0" step="10000" class="form-input text-xs font-mono font-bold">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditSiswa')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-save text-xs mr-1"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: TAMBAH PAKET KURSUS BARU UNTUK SISWA EKSISTING -->
<!-- ========================================================================= -->
<div id="modalTambahPaket" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-base font-black text-slate-800">Tambah Paket Kursus Tambahan</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="paketSiswaNama">-</p>
            </div>
            <button onclick="closeModal('modalTambahPaket')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_paket_kursus">
            <input type="hidden" name="id_siswa" id="paket_id_siswa" value="">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Program Kursus Baru <span class="text-rose-500">*</span></label>
                <select name="id_layanan_baru" required class="form-input text-xs">
                    <option value="">-- Pilih Program Tambahan --</option>
                    <?php foreach ($layananList as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= e($l['nama_layanan']) ?> (<?= format_rupiah($l['harga_layanan']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Mentor Pembimbing Paket Ini</label>
                <select name="id_mentor_baru" class="form-input text-xs">
                    <option value="">-- Tentukan Nanti / Random --</option>
                    <?php foreach ($mentorList as $m): ?>
                        <option value="<?= $m['id'] ?>"><?= e($m['nama_lengkap']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Pembayaran</label>
                    <select name="status_pembayaran_paket" required class="form-input text-xs font-bold">
                        <option value="belum_bayar">Belum Bayar</option>
                        <option value="cicilan">Cicilan</option>
                        <option value="lunas">Lunas</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nominal Terbayar (Rp)</label>
                    <input type="number" name="nominal_terbayar_paket" value="0" min="0" step="10000" class="form-input text-xs font-mono font-bold">
                </div>
            </div>

            <div class="p-3 bg-purple-50 rounded-xl border border-purple-100 text-xs text-purple-900">
                <i class="fa-solid fa-circle-info mr-1 text-purple-700"></i>
                Fitur ini mencatat pendaftaran ke <code>riwayat_layanan_siswa</code> sehingga akun portal dan NIS siswa tetap satu tanpa duplikasi.
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahPaket')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-plus text-xs mr-1"></i> Tambah Paket
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 4: LIHAT SELURUH RIWAYAT PAKET KURSUS SISWA -->
<!-- ========================================================================= -->
<div id="modalRiwayatPaket" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <h3 class="text-base font-black text-slate-800">Daftar Paket Kursus Siswa</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="riwayatSiswaNama">-</p>
            </div>
            <button onclick="closeModal('modalRiwayatPaket')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="mt-4 space-y-3" id="riwayatPaketContainer">
            <!-- Diisi via JavaScript -->
        </div>

        <div class="mt-5 pt-3 border-t border-slate-100 text-right">
            <button type="button" onclick="closeModal('modalRiwayatPaket')" class="btn-brand text-xs py-2 px-5">
                Tutup
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 5: CETAK KARTU PELAJAR BISOLPIN -->
<!-- ========================================================================= -->
<div id="modalCetakKartu" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-800">Kartu Pelajar Resmi BISOLPIN</h3>
            <button onclick="closeModal('modalCetakKartu')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div id="printAreaKartu" class="mt-4 p-5 rounded-2xl border-2 border-brand-500 bg-gradient-to-br from-brand-900 via-brand-800 to-teal-900 text-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 w-44 h-44 rounded-full bg-teal-500/10 blur-xl pointer-events-none"></div>

            <div class="flex items-center justify-between pb-3 border-b border-white/20">
                <div class="flex items-center gap-2.5">
                    <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="w-9 h-9 object-contain">
                    <div>
                        <h4 class="text-xs font-black tracking-wider uppercase">BISOLPIN ACADEMY</h4>
                        <p class="text-[9px] text-teal-200">STUDENT IDENTIFICATION CARD</p>
                    </div>
                </div>
                <span class="text-[9px] px-2 py-0.5 rounded-full bg-emerald-400 text-slate-900 font-bold uppercase" id="card_status">AKTIF</span>
            </div>

            <div class="mt-4 flex items-center gap-4">
                <div class="w-20 h-24 rounded-xl bg-white/10 border border-white/20 flex flex-col items-center justify-center text-white/40 flex-shrink-0">
                    <i class="fa-solid fa-user-graduate text-3xl"></i>
                    <span class="text-[8px] mt-1">PASFOTO</span>
                </div>
                <div class="min-w-0">
                    <h5 class="text-sm font-black text-white truncate" id="card_nama">-</h5>
                    <p class="font-mono text-xs text-amber-300 font-bold tracking-wider mt-0.5" id="card_nis">-</p>
                    <p class="text-[11px] text-teal-100 mt-1 truncate" id="card_layanan">-</p>
                    <p class="text-[10px] text-white/60 truncate" id="card_sekolah">-</p>
                </div>
            </div>

            <div class="mt-4 pt-3 border-t border-white/15 flex items-center justify-between text-[10px] text-white/70">
                <div>
                    <span class="block text-[8px] text-white/40">TANGGAL BERGABUNG</span>
                    <span id="card_tanggal" class="font-semibold text-white">-</span>
                </div>
                <div class="text-right">
                    <span class="block text-[8px] text-white/40">OTORISASI CABANG</span>
                    <span class="font-bold text-teal-200">Bisolpin Indonesia</span>
                </div>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-2">
            <button onclick="closeModal('modalCetakKartu')" class="btn-brand-outline text-xs py-2 px-4">
                Tutup
            </button>
            <button onclick="window.print()" class="btn-brand text-xs py-2 px-4">
                <i class="fa-solid fa-print mr-1"></i> Cetak Kartu
            </button>
        </div>
    </div>
</div>

<script>
function editSiswa(s) {
    document.getElementById('edit_id').value = s.id;
    document.getElementById('editNisDisplay').textContent = 'NIS: ' + s.nis_bisolpin;
    document.getElementById('edit_nama_lengkap').value = s.nama_lengkap;
    document.getElementById('edit_jenjang').value = s.jenjang || 'SMP';
    document.getElementById('edit_umur').value = s.umur || '';
    document.getElementById('edit_kelas').value = s.kelas || '';
    document.getElementById('edit_nama_sekolah').value = s.nama_sekolah || s.asal_sekolah_instansi || '';
    document.getElementById('edit_no_hp_siswa').value = s.no_hp_siswa || '';
    document.getElementById('edit_status_belajar').value = s.status_belajar;
    document.getElementById('edit_id_layanan').value = s.id_layanan;
    document.getElementById('edit_id_mentor').value = s.id_mentor_pembimbing || '';
    document.getElementById('edit_status_pembayaran').value = s.status_pembayaran || 'belum_bayar';
    document.getElementById('edit_nominal_terbayar').value = parseFloat(s.nominal_terbayar || 0);
    document.getElementById('edit_nama_orang_tua').value = s.nama_orang_tua || '';
    document.getElementById('edit_no_wa_ortu').value = s.no_wa_ortu || s.no_hp_orang_tua || '';
    document.getElementById('edit_provinsi').value = s.provinsi || 'Sulawesi Selatan';
    document.getElementById('edit_kabupaten_kota').value = s.kabupaten_kota || 'Kota Makassar';
    document.getElementById('edit_kecamatan').value = s.kecamatan || 'Panakkukang';
    document.getElementById('edit_alamat').value = s.alamat || '';
    openModal('modalEditSiswa');
}

function tambahPaket(s) {
    document.getElementById('paket_id_siswa').value = s.id;
    document.getElementById('paketSiswaNama').textContent = s.nama_lengkap + ' (' + s.nis_bisolpin + ')';
    openModal('modalTambahPaket');
}

function lihatRiwayatPaket(s, courses) {
    document.getElementById('riwayatSiswaNama').textContent = s.nama_lengkap + ' (' + s.nis_bisolpin + ')';
    const container = document.getElementById('riwayatPaketContainer');
    container.innerHTML = '';

    if (!courses || courses.length === 0) {
        container.innerHTML = '<p class="text-xs text-slate-400 italic">Belum ada paket kursus tambahan.</p>';
    } else {
        courses.forEach((c, idx) => {
            const div = document.createElement('div');
            div.className = 'p-3 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-between text-xs';
            div.innerHTML = `
                <div>
                    <strong class="text-slate-800 block">${c.nama_layanan}</strong>
                    <span class="text-[10px] text-slate-400 font-mono">${c.kode_layanan} • Mentor: ${c.nama_mentor || 'Belum di-assign'}</span>
                </div>
                <div class="text-right">
                    <span class="font-mono font-bold text-brand-700 block">${c.persentase_progres}%</span>
                    <span class="text-[10px] uppercase font-bold text-emerald-700">${c.status_pembayaran}</span>
                </div>
            `;
            container.appendChild(div);
        });
    }
    openModal('modalRiwayatPaket');
}

function cetakKartu(s) {
    document.getElementById('card_nama').textContent = s.nama_lengkap;
    document.getElementById('card_nis').textContent = s.nis_bisolpin;
    document.getElementById('card_layanan').textContent = s.nama_layanan;
    document.getElementById('card_sekolah').textContent = s.asal_sekolah_instansi || 'Peserta Umum';
    document.getElementById('card_status').textContent = s.status_belajar.toUpperCase();
    document.getElementById('card_tanggal').textContent = s.tanggal_daftar || '-';
    openModal('modalCetakKartu');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
