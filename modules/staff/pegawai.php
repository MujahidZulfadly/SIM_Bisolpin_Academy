<?php
/**
 * SIM BISOLPIN ACADEMY - Administrasi Pegawai & Presensi Harian Cabang
 */

declare(strict_types=1);

$pageTitle = 'Pegawai & Presensi Harian Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

$db = get_db();
$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;

// Keamanan isolasi cabang
verify_branch_access($branchId);

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 0. SOFT DELETE / NONAKTIFKAN PEGAWAI
    if ($action === 'nonaktifkan_pegawai' || $action === 'hapus_pegawai') {
        $id = (int)($_POST['id'] ?? 0);
        $stmtCek = $db->prepare("SELECT user_id, nama_lengkap, id_cabang, jabatan_role FROM tabel_pegawai WHERE id = ?");
        $stmtCek->execute([$id]);
        $row = $stmtCek->fetch();

        if (!$row) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Data pegawai tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$row['id_cabang']);

        // Staff TIDAK BISA menonaktifkan akun Direktur Cabang atau Pimpinan
        if ($row['jabatan_role'] === 'direktur_cabang' || $row['jabatan_role'] === 'pimpinan') {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Staff tidak memiliki kewenangan untuk menonaktifkan akun Direktur Cabang.', 'Akses Ditolak');
        }

        try {
            $db->beginTransaction();
            // Soft-delete: ubah status_aktif menjadi 'nonaktif'
            $stmtSoft = $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE id = ? AND id_cabang = ?");
            $stmtSoft->execute([$id, $branchId]);

            // Nonaktifkan akun di tabel users
            $stmtUser = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmtUser->execute([$row['user_id']]);

            $db->commit();
            redirect_with_message('modules/staff/pegawai.php', 'success', "Pegawai {$row['nama_lengkap']} berhasil dinonaktifkan (soft delete). Riwayat presensi mengajar tetap aman.", 'Pegawai Dinonaktifkan');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal nonaktifkan pegawai: " . $e->getMessage());
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Gagal menonaktifkan pegawai: ' . $e->getMessage(), 'Gagal');
        }
    }

    // 1. TAMBAH PEGAWAI / MENTOR BARU
    if ($action === 'tambah_pegawai') {
        $nip = trim($_POST['nip_pegawai'] ?? '');
        $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? 'password123');
        $jabatanRole = trim($_POST['jabatan_role'] ?? 'mentor');
        $spesialisasi = trim($_POST['spesialisasi_mentor'] ?? '');
        $noHp = trim($_POST['no_hp'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'tetap');
        $tanggalMasuk = !empty($_POST['tanggal_masuk']) ? trim($_POST['tanggal_masuk']) : date('Y-m-d');

        if (empty($nip) || empty($namaLengkap) || empty($username) || empty($email) || empty($noHp)) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Mohon lengkapi seluruh field wajib (NIP, Nama, Username, Email, No HP).', 'Validasi Gagal');
        }

        // Pastikan role yang dibuat adalah staf cabang (mentor, staff, sarpras)
        if (!in_array($jabatanRole, ['mentor', 'staff', 'sarpras'], true)) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Role pegawai yang diizinkan untuk cabang hanya Mentor, Staff, atau Sarpras.', 'Role Tidak Sah');
        }

        // Cek NIP di tabel_pegawai
        $stmtCekNip = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE nip_pegawai = ?");
        $stmtCekNip->execute([$nip]);
        if ((int)$stmtCekNip->fetchColumn() > 0) {
            redirect_with_message('modules/staff/pegawai.php', 'error', "NIP '{$nip}' sudah terdaftar dalam sistem.", 'NIP Duplikat');
        }

        // Cek username & email di users
        $stmtCekUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtCekUser->execute([$username, $email]);
        if ((int)$stmtCekUser->fetchColumn() > 0) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Username atau Email tersebut sudah terdaftar.', 'Akun Duplikat');
        }

        try {
            $db->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $db->prepare("
                INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmtUser->execute([$username, $email, $passwordHash, $jabatanRole, $branchId]);
            $userId = (int)$db->lastInsertId();

            $stmtPeg = $db->prepare("
                INSERT INTO tabel_pegawai (
                    user_id, id_cabang, nip_pegawai, nama_lengkap, jabatan_role,
                    spesialisasi_mentor, no_hp, alamat, status_kepegawaian, status_aktif,
                    tanggal_masuk, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'aktif',
                    ?, NOW()
                )
            ");
            $stmtPeg->execute([
                $userId, $branchId, $nip, $namaLengkap, $jabatanRole,
                $spesialisasi, $noHp, $alamat, $statusKepegawaian, $tanggalMasuk
            ]);

            $db->commit();
            redirect_with_message('modules/staff/pegawai.php', 'success', "Pegawai {$namaLengkap} ({$nip}) berhasil ditambahkan ke cabang.", 'Pendaftaran Pegawai Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal tambah pegawai: " . $e->getMessage());
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Terjadi kesalahan sistem: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. EDIT PEGAWAI
    if ($action === 'edit_pegawai') {
        $id = (int)($_POST['id'] ?? 0);
        $namaLengkap = trim($_POST['nama_lengkap'] ?? '');
        $spesialisasi = trim($_POST['spesialisasi_mentor'] ?? '');
        $noHp = trim($_POST['no_hp'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'tetap');
        $statusAktif = trim($_POST['status_aktif'] ?? 'aktif');

        if (empty($namaLengkap) || empty($noHp)) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Nama Lengkap dan Nomor Telepon wajib diisi.', 'Validasi Gagal');
        }

        // Verifikasi hak akses data di cabang
        $stmtCek = $db->prepare("SELECT user_id, id_cabang, jabatan_role FROM tabel_pegawai WHERE id = ?");
        $stmtCek->execute([$id]);
        $row = $stmtCek->fetch();

        if (!$row) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Data pegawai tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$row['id_cabang']);

        // Staff TIDAK BISA mengedit data Direktur Cabang atau Pimpinan
        if ($row['jabatan_role'] === 'direktur_cabang' || $row['jabatan_role'] === 'pimpinan') {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Staff tidak memiliki kewenangan untuk mengubah data Direktur Cabang.', 'Akses Ditolak');
        }

        try {
            $db->beginTransaction();

            $stmtUpdate = $db->prepare("
                UPDATE tabel_pegawai SET
                    nama_lengkap = ?,
                    spesialisasi_mentor = ?,
                    no_hp = ?,
                    alamat = ?,
                    status_kepegawaian = ?,
                    status_aktif = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUpdate->execute([
                $namaLengkap, $spesialisasi, $noHp, $alamat,
                $statusKepegawaian, $statusAktif, $id, $branchId
            ]);

            // Sinkronkan status akun login users
            $isActiveUser = ($statusAktif === 'aktif') ? 1 : 0;
            $stmtUser = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmtUser->execute([$isActiveUser, $row['user_id']]);

            $db->commit();
            redirect_with_message('modules/staff/pegawai.php', 'success', "Data pegawai {$namaLengkap} berhasil diperbarui.", 'Pembaruan Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal edit pegawai: " . $e->getMessage());
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Terjadi kesalahan sistem saat memperbarui data pegawai: ' . $e->getMessage(), 'Gagal Update');
        }
    }

    // 3. INPUT ABSENSI HARIAN PEGAWAI
    if ($action === 'input_absensi_pegawai') {
        $idPegawai = (int)($_POST['id_pegawai'] ?? 0);
        $tanggal = !empty($_POST['tanggal_absensi']) ? trim($_POST['tanggal_absensi']) : date('Y-m-d');
        $jamMasuk = !empty($_POST['jam_masuk']) ? trim($_POST['jam_masuk']) : date('H:i:s');
        $jamKeluar = !empty($_POST['jam_keluar']) ? trim($_POST['jam_keluar']) : null;
        $statusKehadiran = trim($_POST['status_kehadiran'] ?? 'hadir');
        $keterangan = trim($_POST['keterangan_jurnal'] ?? '');

        if (empty($idPegawai)) {
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Pilih pegawai yang akan diabsen.', 'Validasi Gagal');
        }

        // Cek apakah pegawai ini sudah diabsen pada tanggal tersebut
        $stmtCekAbsen = $db->prepare("
            SELECT COUNT(*) FROM tabel_absensi 
            WHERE id_cabang = ? AND tipe_absensi = 'pegawai_mentor' AND id_pegawai = ? AND tanggal_absensi = ?
        ");
        $stmtCekAbsen->execute([$branchId, $idPegawai, $tanggal]);
        if ((int)$stmtCekAbsen->fetchColumn() > 0) {
            redirect_with_message('modules/staff/pegawai.php', 'warning', 'Presensi pegawai tersebut untuk tanggal ini sudah pernah diinput sebelumnya.', 'Duplikasi Absensi');
        }

        try {
            $stmtAbsen = $db->prepare("
                INSERT INTO tabel_absensi (
                    id_cabang, tipe_absensi, id_siswa, id_pegawai, id_materi_pertemuan,
                    tanggal_absensi, jam_masuk, jam_keluar, status_kehadiran, keterangan_jurnal,
                    diinput_oleh, created_at
                ) VALUES (
                    ?, 'pegawai_mentor', NULL, ?, NULL,
                    ?, ?, ?, ?, ?,
                    ?, NOW()
                )
            ");
            $stmtAbsen->execute([
                $branchId, $idPegawai,
                $tanggal, $jamMasuk, $jamKeluar, $statusKehadiran, $keterangan,
                auth_id()
            ]);

            redirect_with_message('modules/staff/pegawai.php', 'success', 'Presensi harian pegawai berhasil dicatat ke sistem.', 'Presensi Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal catat absensi pegawai: " . $e->getMessage());
            redirect_with_message('modules/staff/pegawai.php', 'error', 'Terjadi kesalahan sistem saat mencatat absensi: ' . $e->getMessage(), 'Gagal Presensi');
        }
    }
}

// AMBIL DATA PEGAWAI CABANG
$stmtPegawai = $db->prepare("
    SELECT p.*, u.username, u.email
    FROM tabel_pegawai p
    JOIN users u ON u.id = p.user_id
    WHERE p.id_cabang = ?
    ORDER BY FIELD(p.jabatan_role, 'direktur_cabang', 'staff', 'mentor', 'sarpras'), p.nama_lengkap ASC
");
$stmtPegawai->execute([$branchId]);
$pegawaiList = $stmtPegawai->fetchAll();

// AMBIL REKAP ABSENSI PEGAWAI BULAN/TANGGAL INI
$filterTanggalAbsen = !empty($_GET['tanggal_absen']) ? trim($_GET['tanggal_absen']) : date('Y-m-d');

$stmtAbsensi = $db->prepare("
    SELECT a.*, p.nip_pegawai, p.nama_lengkap, p.jabatan_role, u.username as diinput_username
    FROM tabel_absensi a
    JOIN tabel_pegawai p ON p.id = a.id_pegawai
    JOIN users u ON u.id = a.diinput_oleh
    WHERE a.id_cabang = ? AND a.tipe_absensi = 'pegawai_mentor' AND a.tanggal_absensi = ?
    ORDER BY a.jam_masuk DESC
");
$stmtAbsensi->execute([$branchId, $filterTanggalAbsen]);
$absensiHariIniList = $stmtAbsensi->fetchAll();

// Generator NIP Rekomendasi
$countPegawai = (int)$db->query("SELECT COUNT(*) FROM tabel_pegawai")->fetchColumn() + 1;
$suggestedNip = sprintf("MKS-EMP-%03d", $countPegawai);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-address-book"></i>
            <span>Manajemen SDM & Front Office Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Pegawai Cabang & Presensi Harian
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Data administrasi pegawai cabang (Mentor, Staff, Sarpras) serta pencatatan absensi harian.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <button onclick="openModal('modalInputAbsensi')" class="btn-brand-outline text-xs py-2.5 px-4">
            <i class="fa-solid fa-clipboard-user text-xs mr-1"></i>
            <span>Input Presensi Pegawai</span>
        </button>
        <button onclick="openModal('modalTambahPegawai')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Tambah Pegawai Baru</span>
        </button>
    </div>
</div>

<!-- Tabs Navigasi Antara Daftar Pegawai & Rekap Presensi -->
<div class="mb-6 flex border-b border-slate-200">
    <button id="tabBtnPegawai" onclick="switchTab('pegawai')" class="py-3 px-5 text-xs font-bold border-b-2 border-brand-500 text-brand-600 transition-colors flex items-center gap-2">
        <i class="fa-solid fa-users"></i>
        <span>Daftar Pegawai Cabang (<?= count($pegawaiList) ?>)</span>
    </button>
    <button id="tabBtnPresensi" onclick="switchTab('presensi')" class="py-3 px-5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-600 transition-colors flex items-center gap-2">
        <i class="fa-solid fa-clipboard-check"></i>
        <span>Rekap Presensi Harian (<?= count($absensiHariIniList) ?>)</span>
    </button>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: DAFTAR PEGAWAI CABANG -->
<!-- ========================================================================= -->
<div id="tabPegawai" class="space-y-6">
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4">Pegawai & NIP</th>
                        <th class="py-3 px-4">Peran / Jabatan</th>
                        <th class="py-3 px-4">Spesialisasi / Keahlian</th>
                        <th class="py-3 px-4">Kontak & Alamat</th>
                        <th class="py-3 px-4 text-center">Status Kerja</th>
                        <th class="py-3 px-4 text-center">Keaktifan</th>
                        <th class="py-3 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($pegawaiList)): ?>
                        <tr>
                            <td colspan="7" class="py-10 text-center text-slate-400">
                                Belum ada pegawai yang terdaftar di cabang ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pegawaiList as $p): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center font-bold text-sm flex-shrink-0">
                                            <?= strtoupper(substr($p['nama_lengkap'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-slate-800"><?= e($p['nama_lengkap']) ?></h4>
                                            <p class="font-mono text-[11px] text-brand-600 font-semibold"><?= e($p['nip_pegawai']) ?></p>
                                            <p class="text-[11px] text-slate-400"><?= e($p['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200">
                                        <?= e(str_replace('_', ' ', $p['jabatan_role'])) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="text-slate-700 font-medium"><?= e($p['spesialisasi_mentor'] ?? '-') ?></span>
                                </td>
                                <td class="py-3 px-4">
                                    <p class="text-slate-700 font-medium"><i class="fa-brands fa-whatsapp text-emerald-500 mr-1"></i><?= e($p['no_hp']) ?></p>
                                    <p class="text-[11px] text-slate-400 truncate max-w-xs"><?= e($p['alamat'] ?? '-') ?></p>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="text-xs font-semibold capitalize text-slate-600"><?= e($p['status_kepegawaian']) ?></span>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?= render_status_badge($p['status_aktif']) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($p['jabatan_role'] === 'direktur_cabang' || $p['jabatan_role'] === 'pimpinan'): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-bold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg" title="Akun Direktur Cabang dilindungi dari perubahan oleh Staff">
                                            <i class="fa-solid fa-lock text-[10px]"></i>
                                            <span>Direktur Cabang</span>
                                        </span>
                                    <?php else: ?>
                                        <div class="inline-flex items-center gap-1.5">
                                            <button onclick="editPegawai(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Pegawai" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 inline-flex items-center justify-center transition-colors">
                                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                            </button>

                                            <?php if ($p['status_aktif'] === 'aktif'): ?>
                                                <form method="POST" action="" onsubmit="return confirm('Nonaktifkan pegawai <?= addslashes($p['nama_lengkap']) ?>? Riwayat presensi tetap tersimpan aman.');" class="inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="nonaktifkan_pegawai">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button type="submit" title="Nonaktifkan Pegawai (Soft Delete)" class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-100 inline-flex items-center justify-center transition-colors">
                                                        <i class="fa-solid fa-user-slash text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 2: REKAP PRESENSI HARIAN PEGAWAI -->
<!-- ========================================================================= -->
<div id="tabPresensi" class="space-y-6 hidden">
    <!-- Filter Tanggal Presensi -->
    <div class="card p-4">
        <form method="GET" action="" class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-700">Pilih Tanggal Presensi:</label>
                <input type="date" name="tanggal_absen" value="<?= e($filterTanggalAbsen) ?>" class="form-input text-xs py-2 w-44">
                <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                    <i class="fa-solid fa-calendar-day text-xs"></i> Tampilkan
                </button>
            </div>
            <div class="text-xs text-slate-500">
                Tanggal Terpilih: <span class="font-bold text-slate-800"><?= format_tanggal_indonesia($filterTanggalAbsen) ?></span>
            </div>
        </form>
    </div>

    <!-- Tabel Rekap Presensi -->
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                        <th class="py-3 px-4">Nama Pegawai & NIP</th>
                        <th class="py-3 px-4">Jabatan</th>
                        <th class="py-3 px-4 text-center">Jam Masuk</th>
                        <th class="py-3 px-4 text-center">Jam Keluar</th>
                        <th class="py-3 px-4 text-center">Status Kehadiran</th>
                        <th class="py-3 px-4">Keterangan / Jurnal</th>
                        <th class="py-3 px-4">Diinput Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($absensiHariIniList)): ?>
                        <tr>
                            <td colspan="7" class="py-10 text-center text-slate-400">
                                Belum ada catatan absensi pegawai untuk tanggal <?= format_tanggal_indonesia($filterTanggalAbsen) ?>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($absensiHariIniList as $ab): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-4">
                                    <h5 class="font-bold text-slate-800"><?= e($ab['nama_lengkap']) ?></h5>
                                    <p class="font-mono text-[11px] text-slate-400"><?= e($ab['nip_pegawai']) ?></p>
                                </td>
                                <td class="py-3 px-4 uppercase font-semibold text-slate-600 text-[11px]">
                                    <?= e(str_replace('_', ' ', $ab['jabatan_role'])) ?>
                                </td>
                                <td class="py-3 px-4 text-center font-mono font-bold text-teal-600">
                                    <?= date('H:i', strtotime($ab['jam_masuk'])) ?>
                                </td>
                                <td class="py-3 px-4 text-center font-mono text-slate-500">
                                    <?= !empty($ab['jam_keluar']) ? date('H:i', strtotime($ab['jam_keluar'])) : '-' ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <?= render_status_badge($ab['status_kehadiran']) ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate">
                                    <?= e($ab['keterangan_jurnal'] ?? '-') ?>
                                </td>
                                <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">
                                    <?= e($ab['diinput_username']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: TAMBAH PEGAWAI BARU -->
<!-- ========================================================================= -->
<div id="modalTambahPegawai" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Tambah Pegawai / Mentor Baru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Pegawai akan mendapatkan akses login sesuai peran yang dipilih.</p>
            </div>
            <button onclick="closeModal('modalTambahPegawai')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_pegawai">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nomor Induk Pegawai (NIP) <span class="text-rose-500">*</span></label>
                    <input type="text" name="nip_pegawai" value="<?= e($suggestedNip) ?>" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" placeholder="Contoh: Andi Pratama, S.Kom." required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jabatan / Role Pengguna <span class="text-rose-500">*</span></label>
                    <select name="jabatan_role" required class="form-input text-xs">
                        <option value="mentor">Mentor / Tutor Pengajar</option>
                        <option value="staff">Staff Administrasi & Kasir</option>
                        <option value="sarpras">Sarana & Prasarana (Sarpras)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Spesialisasi / Keahlian</label>
                    <input type="text" name="spesialisasi_mentor" placeholder="Contoh: Web Developer & Robotik" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp / Telepon <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp" placeholder="08123456789" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kepegawaian</label>
                    <select name="status_kepegawaian" class="form-input text-xs">
                        <option value="tetap">Pegawai Tetap</option>
                        <option value="kontrak">Kontrak</option>
                        <option value="mitra_pengajar">Mitra Pengajar / Part-Time</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Domisili</label>
                <textarea name="alamat" rows="2" placeholder="Alamat tempat tinggal pegawai..." class="form-input text-xs"></textarea>
            </div>

            <!-- Akun Pengguna -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200">
                <h4 class="text-xs font-bold text-slate-800 mb-2 flex items-center gap-1.5">
                    <i class="fa-solid fa-key text-teal-600"></i> Akun Login Sistem SIM-BISOLPIN
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Username <span class="text-rose-500">*</span></label>
                        <input type="text" name="username" placeholder="pegawai_nama" required class="form-input text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Email Pegawai <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" placeholder="pegawai@bisolpin.com" required class="form-input text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Password</label>
                        <input type="text" name="password" value="password123" class="form-input text-xs bg-white">
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahPegawai')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Pegawai
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: EDIT PEGAWAI -->
<!-- ========================================================================= -->
<div id="modalEditPegawai" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Data Pegawai</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="editNipDisplay">-</p>
            </div>
            <button onclick="closeModal('modalEditPegawai')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_pegawai">
            <input type="hidden" name="id" id="edit_peg_id" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" id="edit_peg_nama" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">No. WhatsApp <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp" id="edit_peg_hp" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Spesialisasi</label>
                    <input type="text" name="spesialisasi_mentor" id="edit_peg_spesialisasi" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kepegawaian</label>
                    <select name="status_kepegawaian" id="edit_peg_kepegawaian" class="form-input text-xs">
                        <option value="tetap">Tetap</option>
                        <option value="kontrak">Kontrak</option>
                        <option value="mitra_pengajar">Mitra Pengajar</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Keaktifan</label>
                    <select name="status_aktif" id="edit_peg_status_aktif" class="form-input text-xs">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat</label>
                <textarea name="alamat" id="edit_peg_alamat" rows="2" class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditPegawai')" class="btn-brand-outline text-xs py-2 px-4">
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
<!-- MODAL 3: INPUT ABSENSI HARIAN PEGAWAI -->
<!-- ========================================================================= -->
<div id="modalInputAbsensi" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Input Presensi Harian Pegawai</h3>
                <p class="text-xs text-slate-400 mt-0.5">Catat kehadiran mentor atau staf operasional cabang.</p>
            </div>
            <button onclick="closeModal('modalInputAbsensi')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="input_absensi_pegawai">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Pegawai / Mentor <span class="text-rose-500">*</span></label>
                <select name="id_pegawai" required class="form-input text-xs">
                    <option value="">-- Pilih Pegawai --</option>
                    <?php foreach ($pegawaiList as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= e($p['nama_lengkap']) ?> (<?= strtoupper($p['jabatan_role']) ?> - <?= e($p['nip_pegawai']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Absensi <span class="text-rose-500">*</span></label>
                    <input type="date" name="tanggal_absensi" value="<?= date('Y-m-d') ?>" required class="form-input text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kehadiran <span class="text-rose-500">*</span></label>
                    <select name="status_kehadiran" required class="form-input text-xs">
                        <option value="hadir">Hadir</option>
                        <option value="izin">Izin</option>
                        <option value="sakit">Sakit</option>
                        <option value="alfa">Alfa</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Masuk <span class="text-rose-500">*</span></label>
                    <input type="time" name="jam_masuk" value="<?= date('H:i') ?>" required class="form-input text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Keluar</label>
                    <input type="time" name="jam_keluar" class="form-input text-xs">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Keterangan / Catatan Kegiatan Harian</label>
                <textarea name="keterangan_jurnal" rows="2" placeholder="Catatan tugas harian atau alasan jika izin/sakit..." class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalInputAbsensi')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Presensi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    const tabPegawai = document.getElementById('tabPegawai');
    const tabPresensi = document.getElementById('tabPresensi');
    const tabBtnPegawai = document.getElementById('tabBtnPegawai');
    const tabBtnPresensi = document.getElementById('tabBtnPresensi');

    if (tab === 'pegawai') {
        tabPegawai.classList.remove('hidden');
        tabPresensi.classList.add('hidden');
        tabBtnPegawai.className = "py-3 px-5 text-xs font-bold border-b-2 border-brand-500 text-brand-600 transition-colors flex items-center gap-2";
        tabBtnPresensi.className = "py-3 px-5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-600 transition-colors flex items-center gap-2";
    } else {
        tabPegawai.classList.add('hidden');
        tabPresensi.classList.remove('hidden');
        tabBtnPegawai.className = "py-3 px-5 text-xs font-bold border-b-2 border-transparent text-slate-400 hover:text-slate-600 transition-colors flex items-center gap-2";
        tabBtnPresensi.className = "py-3 px-5 text-xs font-bold border-b-2 border-brand-500 text-brand-600 transition-colors flex items-center gap-2";
    }
}

function editPegawai(p) {
    document.getElementById('edit_peg_id').value = p.id;
    document.getElementById('editNipDisplay').textContent = 'NIP: ' + p.nip_pegawai + ' (' + p.jabatan_role.toUpperCase() + ')';
    document.getElementById('edit_peg_nama').value = p.nama_lengkap;
    document.getElementById('edit_peg_hp').value = p.no_hp;
    document.getElementById('edit_peg_spesialisasi').value = p.spesialisasi_mentor || '';
    document.getElementById('edit_peg_kepegawaian').value = p.status_kepegawaian;
    document.getElementById('edit_peg_status_aktif').value = p.status_aktif;
    document.getElementById('edit_peg_alamat').value = p.alamat || '';
    openModal('modalEditPegawai');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

