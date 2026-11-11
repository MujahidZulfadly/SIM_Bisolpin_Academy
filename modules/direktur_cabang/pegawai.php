<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen SDM, Mentor & Pegawai Cabang (Direktur Cabang)
 * Dilengkapi CRUD Penuh: Tambah, Edit, dan Nonaktifkan (Soft Delete) Pegawai Cabang
 */

declare(strict_types=1);

$pageTitle = 'SDM & Pengajar Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Direktur Cabang
check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;
verify_branch_access($branchId);
$db = get_db();

// ------------------------------------------------------------------------------
// PENANGANAN AKSI POST FORM (CRUD PEGAWAI OLEH DIREKTUR CABANG)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH PEGAWAI / MENTOR BARU KE CABANG
    if ($action === 'tambah_pegawai') {
        $nip               = trim($_POST['nip_pegawai'] ?? '');
        $namaLengkap       = trim($_POST['nama_lengkap'] ?? '');
        $username          = trim($_POST['username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $password          = trim($_POST['password'] ?? 'password123');
        $jabatanRole       = trim($_POST['jabatan_role'] ?? 'mentor');
        $spesialisasi      = trim($_POST['spesialisasi_mentor'] ?? '');
        $spesialisasiMapel = trim($_POST['spesialisasi_mapel'] ?? 'Coding');
        if ($jabatanRole === 'mentor' && empty($spesialisasi)) {
            $spesialisasi = "Pengajar {$spesialisasiMapel}";
        }
        $noHp              = trim($_POST['no_hp'] ?? '');
        $alamat            = trim($_POST['alamat'] ?? '');
        $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'tetap');
        $tanggalMasuk      = !empty($_POST['tanggal_masuk']) ? trim($_POST['tanggal_masuk']) : date('Y-m-d');

        if (empty($nip) || empty($namaLengkap) || empty($username) || empty($email) || empty($noHp)) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Mohon lengkapi NIP, Nama Lengkap, Username, Email, dan No HP.', 'Validasi Gagal');
        }

        // Direktur Cabang hanya diizinkan membuat akun subordinate (mentor, staff, sarpras)
        if (!in_array($jabatanRole, ['mentor', 'staff', 'sarpras'], true)) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Role pegawai yang dapat dibuat hanya Mentor, Staff, atau Sarpras.', 'Role Tidak Sah');
        }

        // Cek keunikan NIP
        $stmtCekNis = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE nip_pegawai = ?");
        $stmtCekNis->execute([$nip]);
        if ((int)$stmtCekNis->fetchColumn() > 0) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', "NIP '{$nip}' sudah terdaftar dalam sistem.", 'NIP Duplikat');
        }

        // Cek keunikan username & email
        $stmtCekUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtCekUser->execute([$username, $email]);
        if ((int)$stmtCekUser->fetchColumn() > 0) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Username atau Email tersebut sudah digunakan oleh akun lain.', 'Akun Duplikat');
        }

        try {
            $db->beginTransaction();

            // 1. Buat User Login Pegawai (Terkunci ke Cabang Direktur)
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $db->prepare("
                INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmtUser->execute([$username, $email, $passwordHash, $jabatanRole, $branchId]);
            $userId = (int)$db->lastInsertId();

            // 2. Buat Record di tabel_pegawai
            $stmtPeg = $db->prepare("
                INSERT INTO tabel_pegawai (
                    user_id, id_cabang, nip_pegawai, nama_lengkap, jabatan_role,
                    spesialisasi_mentor, spesialisasi_mapel, no_hp, alamat, status_kepegawaian, status_aktif,
                    tanggal_masuk, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, 'aktif',
                    ?, NOW()
                )
            ");
            $stmtPeg->execute([
                $userId, $branchId, $nip, $namaLengkap, $jabatanRole,
                $spesialisasi, $spesialisasiMapel, $noHp, $alamat, $statusKepegawaian, $tanggalMasuk
            ]);

            $db->commit();
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'success', "Pegawai {$namaLengkap} ({$nip}) berhasil ditambahkan dan akun login siap digunakan.", 'Pendaftaran Pegawai Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal tambah pegawai cabang: " . $e->getMessage());
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Terjadi kesalahan sistem: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. EDIT PEGAWAI CABANG
    if ($action === 'edit_pegawai') {
        $id                = (int)($_POST['id'] ?? 0);
        $namaLengkap       = trim($_POST['nama_lengkap'] ?? '');
        $spesialisasi      = trim($_POST['spesialisasi_mentor'] ?? '');
        $spesialisasiMapel = trim($_POST['spesialisasi_mapel'] ?? 'Coding');
        $noHp              = trim($_POST['no_hp'] ?? '');
        $alamat            = trim($_POST['alamat'] ?? '');
        $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'tetap');
        $statusAktif       = trim($_POST['status_aktif'] ?? 'aktif');

        if (empty($namaLengkap) || empty($noHp)) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Nama Lengkap dan Nomor Telepon wajib diisi.', 'Validasi Gagal');
        }

        // Verifikasi kepemilikan data di cabang
        $stmtCek = $db->prepare("SELECT user_id, id_cabang, jabatan_role FROM tabel_pegawai WHERE id = ?");
        $stmtCek->execute([$id]);
        $row = $stmtCek->fetch();

        if (!$row) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Data pegawai tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$row['id_cabang']);

        try {
            $db->beginTransaction();

            $stmtUpdate = $db->prepare("
                UPDATE tabel_pegawai SET
                    nama_lengkap = ?,
                    spesialisasi_mentor = ?,
                    spesialisasi_mapel = ?,
                    no_hp = ?,
                    alamat = ?,
                    status_kepegawaian = ?,
                    status_aktif = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUpdate->execute([
                $namaLengkap, $spesialisasi, $spesialisasiMapel, $noHp, $alamat,
                $statusKepegawaian, $statusAktif, $id, $branchId
            ]);

            // Sinkronkan status akun login users
            $isActiveUser = ($statusAktif === 'aktif') ? 1 : 0;
            $stmtUser = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmtUser->execute([$isActiveUser, $row['user_id']]);

            $db->commit();
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'success', "Data pegawai {$namaLengkap} berhasil diperbarui.", 'Pembaruan Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal edit pegawai: " . $e->getMessage());
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Terjadi kesalahan sistem saat memperbarui data pegawai: ' . $e->getMessage(), 'Gagal Update');
        }
    }

    // 3. NONAKTIFKAN PEGAWAI (SOFT DELETE)
    if ($action === 'nonaktifkan_pegawai') {
        $id = (int)($_POST['id'] ?? 0);

        $stmtCek = $db->prepare("SELECT user_id, nama_lengkap, id_cabang, jabatan_role FROM tabel_pegawai WHERE id = ?");
        $stmtCek->execute([$id]);
        $row = $stmtCek->fetch();

        if (!$row) {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Data pegawai tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$row['id_cabang']);

        // Tidak boleh menonaktifkan akun direktur cabang sendiri
        if ($row['jabatan_role'] === 'direktur_cabang') {
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Anda tidak dapat menonaktifkan akun Direktur Cabang Anda sendiri.', 'Aksi Ditolak');
        }

        try {
            $db->beginTransaction();

            // Ubah status_aktif menjadi 'nonaktif'
            $stmtSoft = $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE id = ? AND id_cabang = ?");
            $stmtSoft->execute([$id, $branchId]);

            // Nonaktifkan akses login users
            $stmtUser = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmtUser->execute([$row['user_id']]);

            $db->commit();
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'success', "Pegawai {$row['nama_lengkap']} berhasil dinonaktifkan. Akun login telah ditangguhkan tanpa menghapus riwayat operasional.", 'Pegawai Dinonaktifkan');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal nonaktifkan pegawai cabang: " . $e->getMessage());
            redirect_with_message('modules/direktur_cabang/pegawai.php', 'error', 'Gagal menonaktifkan pegawai: ' . $e->getMessage(), 'Gagal');
        }
    }
}

// ------------------------------------------------------------------------------
// QUERY DATA PEGAWAI DI CABANG INI
// ------------------------------------------------------------------------------
$stmt = $db->prepare("
    SELECT p.*, u.email, u.username,
           (SELECT COUNT(*) FROM tabel_absensi a WHERE a.id_pegawai = p.id AND a.status_kehadiran = 'hadir') as total_hadir_mengajar,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_mentor_pembimbing = p.id AND s.status_belajar = 'aktif' AND s.is_archived = 0) as total_siswa_bimbingan
    FROM tabel_pegawai p
    JOIN users u ON u.id = p.user_id
    WHERE p.id_cabang = ?
    ORDER BY CASE WHEN p.jabatan_role = 'direktur_cabang' THEN 1 WHEN p.jabatan_role = 'mentor' THEN 2 ELSE 3 END, p.created_at ASC
");
$stmt->execute([$branchId]);
$pegawaiList = $stmt->fetchAll();

// Generate Saran NIP Baru
$countPegawai = count($pegawaiList) + 1;
$suggestedNip = sprintf("PEG-%02d-%03d", $branchId, $countPegawai);

// ------------------------------------------------------------------------------
// QUERY MONITORING CEKLOK HARIAN PEGAWAI DI CABANG INI
// ------------------------------------------------------------------------------
$filterTanggalAbsen = !empty($_GET['tanggal_absen']) ? trim($_GET['tanggal_absen']) : date('Y-m-d');
$stmtAbsenCabang = $db->prepare("
    SELECT a.*, p.nama_lengkap, p.nip_pegawai, p.jabatan_role, u.username as inputer_name
    FROM tabel_absensi a
    JOIN tabel_pegawai p ON p.id = a.id_pegawai
    LEFT JOIN users u ON u.id = a.diinput_oleh
    WHERE a.id_cabang = ?
      AND a.tipe_absensi = 'pegawai_mentor'
      AND a.tanggal_absensi = ?
    ORDER BY a.jam_masuk DESC
");
$stmtAbsenCabang->execute([$branchId, $filterTanggalAbsen]);
$ceklokCabangList = $stmtAbsenCabang->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-users"></i>
            <span>Manajemen Tenaga Kerja Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">SDM, Mentor & Staff Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Pengelolaan seluruh personil internal cabang, hak akses akun, dan rekapitulasi kehadiran mengajar.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/direktur_cabang/laporan.php?jenis=pegawai') ?>" class="btn-brand-outline text-xs py-2 px-4 shadow-sm">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Cetak Rekap SDM</span>
        </a>

        <button type="button" onclick="openModalTambahPegawai()" class="btn-brand text-xs py-2 px-4 shadow-sm flex items-center gap-1.5">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Tambah Pegawai Baru</span>
        </button>
    </div>
</div>

<!-- Tab Navigasi: SDM vs Monitoring Ceklok -->
<div class="mb-6 flex items-center gap-2 border-b border-slate-200">
    <button type="button" id="btnTabSdm" onclick="switchSdmTab('sdm')" class="px-5 py-3 border-b-2 font-black text-xs transition-all border-brand-600 text-brand-600 flex items-center gap-2">
        <i class="fa-solid fa-users"></i>
        <span>Data Personil SDM Cabang</span>
        <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[10px]"><?= count($pegawaiList) ?></span>
    </button>
    <button type="button" id="btnTabCeklok" onclick="switchSdmTab('ceklok')" class="px-5 py-3 border-b-2 font-bold text-xs transition-all border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Monitoring Ceklok Pegawai Cabang</span>
        <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-[10px] font-bold"><?= count($ceklokCabangList) ?></span>
    </button>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: DATA PERSONIL SDM CABANG -->
<!-- ========================================================================= -->
<div id="tabContentSdm" class="space-y-6">

    <!-- Ringkasan Statistik SDM Cabang -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php
        $totalMentor   = count(array_filter($pegawaiList, fn($p) => $p['jabatan_role'] === 'mentor' && $p['status_aktif'] === 'aktif'));
        $totalStaff    = count(array_filter($pegawaiList, fn($p) => $p['jabatan_role'] === 'staff' && $p['status_aktif'] === 'aktif'));
        $totalSarpras  = count(array_filter($pegawaiList, fn($p) => $p['jabatan_role'] === 'sarpras' && $p['status_aktif'] === 'aktif'));
        $totalNonaktif = count(array_filter($pegawaiList, fn($p) => $p['status_aktif'] === 'nonaktif'));
        ?>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-teal-600"><?= $totalMentor ?></span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mentor Aktif</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-brand-600"><?= $totalStaff ?></span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Staff Administrasi</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-amber-600"><?= $totalSarpras ?></span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Petugas Sarpras</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-slate-400"><?= $totalNonaktif ?></span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Pegawai Nonaktif</span>
        </div>
    </div>

    <!-- Tabel Pegawai Cabang -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="datatable w-full text-left">
                <thead>
                    <tr>
                        <th>NIP & Nama Lengkap</th>
                        <th>Jabatan / Role</th>
                        <th>Spesialisasi Bidang</th>
                        <th>Kontak WhatsApp & Akun</th>
                        <th>Beban Siswa</th>
                        <th>Kehadiran Mengajar</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pegawaiList)): ?>
                        <?= render_empty_state(
                            'fa-users',
                            'Belum Ada Pegawai Cabang Terdaftar',
                            'Daftarkan tim operasional cabang (Staff Administrasi, Mentor Pengajar, Petugas Sarpras) untuk memulai aktivitas cabang.',
                            '<button type="button" onclick="openModalTambahPegawai()" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20"><i class="fa-solid fa-user-plus mr-1"></i> Tambah Pegawai Pertama</button>',
                            8
                        ) ?>
                    <?php else: ?>
                        <?php foreach ($pegawaiList as $row): 
                        $isSelf = ($row['jabatan_role'] === 'direktur_cabang');
                        $rowDataJson = htmlspecialchars(json_encode([
                            'id'                 => $row['id'],
                            'nip_pegawai'        => $row['nip_pegawai'],
                            'nama_lengkap'       => $row['nama_lengkap'],
                            'jabatan_role'       => $row['jabatan_role'],
                            'spesialisasi_mentor'=> $row['spesialisasi_mentor'] ?? '',
                            'spesialisasi_mapel' => $row['spesialisasi_mapel'] ?? 'Coding',
                            'no_hp'              => $row['no_hp'],
                            'alamat'             => $row['alamat'],
                            'status_kepegawaian' => $row['status_kepegawaian'],
                            'status_aktif'       => $row['status_aktif'],
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td>
                                <div class="font-bold text-slate-800 text-sm"><?= e($row['nama_lengkap']) ?></div>
                                <div class="text-xs font-mono text-slate-400">NIP: <?= e($row['nip_pegawai']) ?></div>
                                <div class="text-[11px] text-slate-500 capitalize">Ikatan: <?= e($row['status_kepegawaian']) ?></div>
                            </td>

                            <td>
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold <?= APP_ROLES[$row['jabatan_role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                    <?= APP_ROLES[$row['jabatan_role']]['label'] ?? ucfirst($row['jabatan_role']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="text-xs text-slate-700 font-semibold"><?= e($row['spesialisasi_mentor'] ?: '-') ?></div>
                                <div class="text-[10px] text-slate-400">Masuk: <?= format_tanggal_indonesia($row['tanggal_masuk']) ?></div>
                            </td>

                            <td>
                                <div class="text-xs text-slate-700 font-mono"><?= e($row['no_hp']) ?></div>
                                <div class="text-[11px] text-slate-400"><?= e($row['email']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono">user: <?= e($row['username']) ?></div>
                            </td>

                            <td>
                                <?php if ($row['jabatan_role'] === 'mentor'): ?>
                                    <span class="font-bold text-brand-700 text-xs"><?= (int)$row['total_siswa_bimbingan'] ?> Siswa Aktif</span>
                                <?php else: ?>
                                    <span class="text-slate-400 text-xs">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($row['jabatan_role'] === 'mentor'): ?>
                                    <span class="font-bold text-emerald-600 text-xs"><?= (int)$row['total_hadir_mengajar'] ?> Sesi Hadir</span>
                                <?php else: ?>
                                    <span class="text-slate-400 text-xs">Administrasi</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= render_status_badge($row['status_aktif']) ?>
                            </td>

                            <td class="text-right">
                                <?php if ($isSelf): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg">
                                        <i class="fa-solid fa-user-tie"></i>
                                        <span>Akun Anda</span>
                                    </span>
                                <?php else: ?>
                                    <div class="inline-flex items-center gap-1.5">
                                        <button type="button"
                                                onclick='openModalEditPegawai(<?= $rowDataJson ?>)'
                                                class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 flex items-center justify-center transition-colors"
                                                title="Edit Data Pegawai">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                                        </button>

                                        <?php if ($row['status_aktif'] === 'aktif'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENONAKTIFKAN pegawai ini? Akun login akan dinonaktifkan tanpa menghapus riwayat KBM & presensi.')" class="inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="nonaktifkan_pegawai">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit"
                                                        class="w-8 h-8 rounded-lg bg-rose-50 hover:bg-rose-500 hover:text-white text-rose-500 flex items-center justify-center transition-colors"
                                                        title="Nonaktifkan Pegawai (Soft Delete)">
                                                    <i class="fa-solid fa-user-slash text-xs"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-[10px] text-slate-400 font-bold bg-slate-100 px-2 py-1 rounded">Nonaktif</span>
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
<!-- TAB 2: MONITORING CEKLOK HARIAN PEGAWAI CABANG -->
<!-- ========================================================================= -->
<div id="tabContentCeklok" class="space-y-6 hidden">
    <!-- Filter Tanggal Ceklok -->
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-4">
        <form method="GET" action="" class="flex items-center gap-3">
            <input type="hidden" name="tab" value="ceklok">
            <label class="text-xs font-bold text-slate-700">Tanggal Monitoring:</label>
            <input type="date" name="tanggal_absen" value="<?= e($filterTanggalAbsen) ?>" class="form-input text-xs py-2 w-44">
            <button type="submit" class="btn-brand text-xs py-2 px-4 shadow-sm">
                <i class="fa-solid fa-calendar-day mr-1"></i> Tampilkan
            </button>
        </form>

        <div class="text-xs text-slate-500">
            Tanggal Terpilih: <strong class="text-slate-800"><?= format_tanggal_indonesia($filterTanggalAbsen) ?></strong>
        </div>
    </div>

    <!-- Tabel Monitoring Ceklok Cabang -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
        <?php if (empty($ceklokCabangList)): ?>
            <?= render_empty_state(
                'fa-fingerprint',
                'Belum Ada Presensi Ceklok pada Tanggal Ini',
                'Belum ada personil pegawai yang melakukan Clock-In presensi kerja pada tanggal ' . format_tanggal_indonesia($filterTanggalAbsen) . '.',
                null,
                0
            ) ?>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="datatable w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500 uppercase font-bold tracking-wider">
                            <th class="py-3 px-4">Nama Pegawai & NIP</th>
                            <th class="py-3 px-4">Jabatan</th>
                            <th class="py-3 px-4 text-center">Jam Masuk (Clock-In)</th>
                            <th class="py-3 px-4 text-center">Jam Pulang (Clock-Out)</th>
                            <th class="py-3 px-4 text-center">Durasi Kerja</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4">Laporan Aktivitas / Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($ceklokCabangList as $c): 
                            $durasiKerja = !empty($c['jam_keluar']) ? calculate_work_duration($c['jam_masuk'], $c['jam_keluar']) : 'Sedang Bekerja';
                        ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-4">
                                    <h4 class="font-bold text-slate-800 text-xs"><?= e($c['nama_lengkap']) ?></h4>
                                    <p class="font-mono text-[11px] text-slate-400"><?= e($c['nip_pegawai']) ?></p>
                                </td>

                                <td class="py-3 px-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= APP_ROLES[$c['jabatan_role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                        <?= APP_ROLES[$c['jabatan_role']]['label'] ?? ucfirst($c['jabatan_role']) ?>
                                    </span>
                                </td>

                                <td class="py-3 px-4 text-center font-mono font-bold text-emerald-600">
                                    <?= substr($c['jam_masuk'], 0, 5) ?> WIB
                                </td>

                                <td class="py-3 px-4 text-center font-mono font-bold text-rose-500">
                                    <?php if (!empty($c['jam_keluar'])): ?>
                                        <?= substr($c['jam_keluar'], 0, 5) ?> WIB
                                    <?php else: ?>
                                        <span class="text-slate-400 italic text-[11px] font-normal">Belum Pulang</span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <span class="px-2 py-0.5 rounded-full <?= !empty($c['jam_keluar']) ? 'bg-slate-100 text-slate-700 font-semibold' : 'bg-emerald-50 text-emerald-700 font-bold' ?> text-[11px]">
                                        <?= $durasiKerja ?>
                                    </span>
                                </td>

                                <td class="py-3 px-4 text-center">
                                    <?= render_status_badge($c['status_kehadiran']) ?>
                                </td>

                                <td class="py-3 px-4 text-slate-600 max-w-xs truncate" title="<?= e($c['keterangan_jurnal']) ?>">
                                    <?= e($c['keterangan_jurnal'] ?: '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM TAMBAH PEGAWAI / MENTOR (DIREKTUR CABANG) -->
<!-- ========================================================================= -->
<div id="modalTambahPegawai" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Tambah Pegawai / Mentor Baru</h3>
                <p class="text-xs text-slate-500 mt-0.5">Otomatis membuat akun login pengguna pada cabang ini.</p>
            </div>
            <button onclick="closeModal('modalTambahPegawai')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_pegawai">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">NIP Pegawai <span class="text-rose-500">*</span></label>
                    <input type="text" name="nip_pegawai" value="<?= e($suggestedNip) ?>" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" placeholder="Contoh: Rahmat Hidayat, S.Kom." required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Role / Jabatan Cabang <span class="text-rose-500">*</span></label>
                    <select name="jabatan_role" required class="form-input text-xs" onchange="toggleSpesialisasi(this.value)">
                        <option value="mentor">Mentor / Instruktur Pengajar</option>
                        <option value="staff">Staff Administrasi & Kasir</option>
                        <option value="sarpras">Petugas Sarana Prasarana (Sarpras)</option>
                    </select>
                </div>

                <div id="wrapperSpesialisasi">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mata Pelajaran Inti Bisolpin</label>
                    <select name="spesialisasi_mapel" class="form-input text-xs font-bold text-brand-700 mb-2">
                        <option value="Coding">Coding (Logika, Algoritma & Python)</option>
                        <option value="Pemrograman Web">Pemrograman Web (HTML, CSS, JS, PHP)</option>
                        <option value="Matematika">Matematika (Logika & Aljabar Sekolah)</option>
                        <option value="Bahasa Inggris">Bahasa Inggris (Grammar & Conversation)</option>
                        <option value="Calistung">Calistung (TK & SD Awal)</option>
                    </select>

                    <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Kualifikasi Tambahan</label>
                    <input type="text" name="spesialisasi_mentor" placeholder="Contoh: Pengajar Coding & Robotik Dasar" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nomor WhatsApp Aktif <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp" placeholder="081234567890" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kepegawaian</label>
                    <select name="status_kepegawaian" class="form-input text-xs">
                        <option value="tetap">Pegawai Tetap</option>
                        <option value="kontrak">Pegawai Kontrak</option>
                        <option value="mitra_pengajar">Mitra Pengajar / Freelance</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Mulai Bertugas</label>
                    <input type="date" name="tanggal_masuk" value="<?= date('Y-m-d') ?>" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email Akun Login <span class="text-rose-500">*</span></label>
                    <input type="email" name="email" placeholder="nama@bisolpin.com" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Username Login <span class="text-rose-500">*</span></label>
                    <input type="text" name="username" placeholder="mentor_rahmat" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Password Login</label>
                    <input type="text" name="password" value="password123" required class="form-input text-xs font-mono">
                    <span class="text-[10px] text-slate-400">Default: password123</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Tempat Tinggal</label>
                <textarea name="alamat" rows="2" placeholder="Alamat domisili lengkap..." class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahPegawai')" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2 px-5 shadow-sm">
                    <i class="fa-solid fa-save mr-1"></i> Simpan & Buat Akun Pegawai
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM EDIT PEGAWAI (DIREKTUR CABANG) -->
<!-- ========================================================================= -->
<div id="modalEditPegawai" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Data Pegawai Cabang</h3>
                <p id="editPegawaiNipSubtitle" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <button onclick="closeModal('modalEditPegawai')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_pegawai">
            <input type="hidden" name="id" id="editPegawaiId">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap & Gelar <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" id="editNamaLengkap" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nomor WhatsApp <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp" id="editNoHp" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mata Pelajaran Bisolpin</label>
                    <select name="spesialisasi_mapel" id="editSpesialisasiMapel" class="form-input text-xs font-bold text-brand-700">
                        <option value="Coding">Coding (Logika, Algoritma & Python)</option>
                        <option value="Pemrograman Web">Pemrograman Web (HTML, CSS, JS, PHP)</option>
                        <option value="Matematika">Matematika (Logika & Aljabar Sekolah)</option>
                        <option value="Bahasa Inggris">Bahasa Inggris (Grammar & Conversation)</option>
                        <option value="Calistung">Calistung (TK & SD Awal)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Spesialisasi Bidang</label>
                    <input type="text" name="spesialisasi_mentor" id="editSpesialisasi" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kepegawaian</label>
                    <select name="status_kepegawaian" id="editStatusKepegawaian" class="form-input text-xs">
                        <option value="tetap">Pegawai Tetap</option>
                        <option value="kontrak">Pegawai Kontrak</option>
                        <option value="mitra_pengajar">Mitra Pengajar / Freelance</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Keaktifan</label>
                    <select name="status_aktif" id="editStatusAktif" class="form-input text-xs">
                        <option value="aktif">Aktif Bekerja</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="nonaktif">Nonaktif / Berhenti</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Tempat Tinggal</label>
                <textarea name="alamat" id="editAlamat" rows="2" class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditPegawai')" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2 px-5 shadow-sm">
                    <i class="fa-solid fa-save mr-1"></i> Perbarui Data Pegawai
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalTambahPegawai() {
    document.getElementById('modalTambahPegawai').classList.remove('hidden');
}

function openModalEditPegawai(peg) {
    document.getElementById('editPegawaiId').value = peg.id;
    document.getElementById('editPegawaiNipSubtitle').innerText = 'NIP: ' + peg.nip_pegawai + ' (' + peg.jabatan_role.toUpperCase() + ')';
    document.getElementById('editNamaLengkap').value = peg.nama_lengkap;
    document.getElementById('editNoHp').value = peg.no_hp;
    document.getElementById('editSpesialisasiMapel').value = peg.spesialisasi_mapel || 'Coding';
    document.getElementById('editSpesialisasi').value = peg.spesialisasi_mentor;
    document.getElementById('editStatusKepegawaian').value = peg.status_kepegawaian;
    document.getElementById('editStatusAktif').value = peg.status_aktif;
    document.getElementById('editAlamat').value = peg.alamat;

    document.getElementById('modalEditPegawai').classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function toggleSpesialisasi(role) {
    const wrapper = document.getElementById('wrapperSpesialisasi');
    if (role === 'mentor') {
        wrapper.classList.remove('hidden');
    } else {
        wrapper.classList.add('hidden');
    }
}

function switchSdmTab(tab) {
    const contentSdm = document.getElementById('tabContentSdm');
    const contentCeklok = document.getElementById('tabContentCeklok');
    const btnSdm = document.getElementById('btnTabSdm');
    const btnCeklok = document.getElementById('btnTabCeklok');

    // Reset visibility
    contentSdm.classList.add('hidden');
    contentCeklok.classList.add('hidden');

    btnSdm.classList.remove('border-brand-600', 'text-brand-600', 'font-black');
    btnSdm.classList.add('border-transparent', 'text-slate-500');
    btnCeklok.classList.remove('border-brand-600', 'text-brand-600', 'font-black');
    btnCeklok.classList.add('border-transparent', 'text-slate-500');

    if (tab === 'ceklok') {
        contentCeklok.classList.remove('hidden');
        btnCeklok.classList.add('border-brand-600', 'text-brand-600', 'font-black');
        btnCeklok.classList.remove('border-transparent', 'text-slate-500');
    } else {
        contentSdm.classList.remove('hidden');
        btnSdm.classList.add('border-brand-600', 'text-brand-600', 'font-black');
        btnSdm.classList.remove('border-transparent', 'text-slate-500');
    }
}

// Buka tab ceklok jika ada parameter URL tab=...
window.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'ceklok' || params.get('tanggal_absen')) {
        switchSdmTab('ceklok');
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
