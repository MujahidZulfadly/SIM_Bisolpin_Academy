<?php
/**
 * SIM BISOLPIN ACADEMY - Data Pegawai & Mentor Nasional (Pimpinan Pusat)
 * Dilengkapi Pembuatan Akun Bagian Akademik (BAK Pusat) & Manajemen Status Pegawai Nasional
 */

declare(strict_types=1);

$pageTitle = 'Data Pegawai & Mentor Nasional';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Pimpinan Pusat
check_role(ROLE_PIMPINAN);

$db = get_db();
$activeBranchId = get_active_branch_id();

// ------------------------------------------------------------------------------
// PENANGANAN AKSI POST FORM (PIMPINAN PUSAT)
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH AKUN TIM BAGIAN AKADEMIK (BAK PUSAT)
    if ($action === 'tambah_bak_pusat') {
        $nip               = trim($_POST['nip_pegawai'] ?? '');
        $namaLengkap       = trim($_POST['nama_lengkap'] ?? '');
        $username          = trim($_POST['username'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $password          = trim($_POST['password'] ?? 'password123');
        $spesialisasi      = trim($_POST['spesialisasi_mentor'] ?? 'Pengembangan Kurikulum & Silabus Nasional');
        $noHp              = trim($_POST['no_hp'] ?? '');
        $alamat            = trim($_POST['alamat'] ?? '');
        $statusKepegawaian = trim($_POST['status_kepegawaian'] ?? 'tetap');
        $tanggalMasuk      = !empty($_POST['tanggal_masuk']) ? trim($_POST['tanggal_masuk']) : date('Y-m-d');

        if (empty($nip) || empty($namaLengkap) || empty($username) || empty($email) || empty($noHp)) {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Mohon lengkapi seluruh field wajib (NIP, Nama, Username, Email, No HP).', 'Validasi Gagal');
        }

        // Cek keunikan NIP
        $stmtCekNip = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE nip_pegawai = ?");
        $stmtCekNip->execute([$nip]);
        if ((int)$stmtCekNip->fetchColumn() > 0) {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', "NIP '{$nip}' sudah terdaftar dalam sistem.", 'NIP Duplikat');
        }

        // Cek keunikan Username & Email di users
        $stmtCekUser = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtCekUser->execute([$username, $email]);
        if ((int)$stmtCekUser->fetchColumn() > 0) {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Username atau Email tersebut sudah terdaftar.', 'Akun Duplikat');
        }

        try {
            $db->beginTransaction();

            // 1. Buat User Login BAK (id_cabang = NULL untuk Tim Pusat)
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUser = $db->prepare("
                INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at)
                VALUES (?, ?, ?, 'bak', NULL, 1, NOW())
            ");
            $stmtUser->execute([$username, $email, $passwordHash]);
            $userId = (int)$db->lastInsertId();

            // 2. Buat Record di tabel_pegawai (id_cabang = NULL)
            $stmtPeg = $db->prepare("
                INSERT INTO tabel_pegawai (
                    user_id, id_cabang, nip_pegawai, nama_lengkap, jabatan_role,
                    spesialisasi_mentor, no_hp, alamat, status_kepegawaian, status_aktif,
                    tanggal_masuk, created_at
                ) VALUES (
                    ?, NULL, ?, ?, 'bak',
                    ?, ?, ?, ?, 'aktif',
                    ?, NOW()
                )
            ");
            $stmtPeg->execute([
                $userId, $nip, $namaLengkap,
                $spesialisasi, $noHp, $alamat, $statusKepegawaian, $tanggalMasuk
            ]);

            $db->commit();
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'success', "Akun Tim Akademik (BAK Pusat) {$namaLengkap} ({$nip}) berhasil dibuat dan siap bertugas.", 'Akun BAK Berhasil Dibuat');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal buat akun BAK: " . $e->getMessage());
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Terjadi kesalahan sistem: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. TOGGLE STATUS KEAKTIFAN PEGAWAI NASIONAL (AKTIFKAN / NONAKTIFKAN)
    if ($action === 'toggle_status_pegawai') {
        $id        = (int)($_POST['id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? 'aktif');

        if (!in_array($newStatus, ['aktif', 'nonaktif'], true)) {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Status tidak valid.', 'Gagal');
        }

        $stmtCek = $db->prepare("SELECT user_id, nama_lengkap, jabatan_role FROM tabel_pegawai WHERE id = ?");
        $stmtCek->execute([$id]);
        $pegRow = $stmtCek->fetch();

        if (!$pegRow) {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Data pegawai tidak ditemukan.', 'Gagal');
        }

        // Cegah pimpinan menonaktifkan akun pimpinan pusat sendiri
        if ($pegRow['jabatan_role'] === 'pimpinan') {
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Akun Pimpinan Pusat tidak dapat dinonaktifkan.', 'Aksi Ditolak');
        }

        try {
            $db->beginTransaction();

            $stmtUpPeg = $db->prepare("UPDATE tabel_pegawai SET status_aktif = ? WHERE id = ?");
            $stmtUpPeg->execute([$newStatus, $id]);

            $isActiveUser = ($newStatus === 'aktif') ? 1 : 0;
            $stmtUpUser = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmtUpUser->execute([$isActiveUser, $pegRow['user_id']]);

            $db->commit();
            $labelStatus = ($newStatus === 'aktif') ? 'diaktifkan kembali' : 'dinonaktifkan';
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'success', "Status pegawai {$pegRow['nama_lengkap']} berhasil {$labelStatus}.", 'Pembaruan Berhasil');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal ubah status pegawai: " . $e->getMessage());
            redirect_with_message('modules/pimpinan/pegawai_nasional.php', 'error', 'Gagal memperbarui status pegawai: ' . $e->getMessage(), 'Gagal');
        }
    }
}

// ------------------------------------------------------------------------------
// QUERY DATA PEGAWAI
// ------------------------------------------------------------------------------
$sql = "
    SELECT p.*, 
           c.nama_cabang, c.kode_cabang,
           u.email, u.username
    FROM tabel_pegawai p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN tabel_cabang c ON c.id = p.id_cabang
";
$params = [];

if ($activeBranchId !== null) {
    $sql .= " WHERE p.id_cabang = ?";
    $params[] = $activeBranchId;
}

$sql .= " ORDER BY CASE WHEN p.jabatan_role = 'pimpinan' THEN 1 WHEN p.jabatan_role = 'bak' THEN 2 WHEN p.jabatan_role = 'direktur_cabang' THEN 3 ELSE 4 END, p.created_at ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pegawaiList = $stmt->fetchAll();

// Hitung Saran NIP BAK Baru
$stmtCountBak = $db->query("SELECT COUNT(*) FROM tabel_pegawai WHERE jabatan_role = 'bak'");
$countBak = ((int)$stmtCountBak->fetchColumn()) + 1;
$suggestedNipBak = sprintf("PST-BAK-%03d", $countBak);

// ------------------------------------------------------------------------------
// QUERY MONITORING CEKLOK NASIONAL & DIREKTUR CABANG
// ------------------------------------------------------------------------------
$filterTanggalAbsen = !empty($_GET['tanggal_absen']) ? trim($_GET['tanggal_absen']) : date('Y-m-d');
$sqlCeklok = "
    SELECT a.*, p.nama_lengkap, p.nip_pegawai, p.jabatan_role, c.nama_cabang, c.kode_cabang, u.username as inputer_name
    FROM tabel_absensi a
    JOIN tabel_pegawai p ON p.id = a.id_pegawai
    LEFT JOIN tabel_cabang c ON c.id = a.id_cabang
    LEFT JOIN users u ON u.id = a.diinput_oleh
    WHERE a.tipe_absensi = 'pegawai_mentor'
      AND p.jabatan_role IN ('staff', 'mentor', 'sarpras')
      AND a.tanggal_absensi = ?
";
$paramsCeklok = [$filterTanggalAbsen];
if ($activeBranchId !== null) {
    $sqlCeklok .= " AND a.id_cabang = ?";
    $paramsCeklok[] = $activeBranchId;
}
$sqlCeklok .= " ORDER BY CASE WHEN p.jabatan_role = 'direktur_cabang' THEN 1 WHEN p.jabatan_role = 'bak' THEN 2 ELSE 3 END, a.jam_masuk DESC";
$stmtCeklok = $db->prepare($sqlCeklok);
$stmtCeklok->execute($paramsCeklok);
$ceklokNasionalList = $stmtCeklok->fetchAll();

// Statistik Ringkasan Nasional
$totalAll      = count($pegawaiList);
$totalBak      = count(array_filter($pegawaiList, fn($p) => $p['jabatan_role'] === 'bak'));
$totalMentor   = count(array_filter($pegawaiList, fn($p) => $p['jabatan_role'] === 'mentor'));
$totalStaffSar = count(array_filter($pegawaiList, fn($p) => in_array($p['jabatan_role'], ['staff', 'sarpras'], true)));

// EKSPOR EXCEL PEGAWAI / MONITORING NASIONAL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $mode = $_GET['tab'] ?? 'pegawai';
    if ($mode === 'ceklok') {
        $columns = [
            'no'           => ['label' => 'No', 'type' => 'center', 'width' => 45],
            'tanggal'      => ['label' => 'Tanggal', 'type' => 'date', 'width' => 110],
            'cabang'       => ['label' => 'Cabang', 'type' => 'text', 'width' => 170],
            'nip'          => ['label' => 'NIP', 'type' => 'text', 'width' => 120],
            'nama_lengkap' => ['label' => 'Nama Pegawai', 'type' => 'text', 'width' => 180],
            'jabatan_role' => ['label' => 'Peran / Jabatan', 'type' => 'center', 'width' => 120],
            'jam_masuk'    => ['label' => 'Jam Masuk', 'type' => 'center', 'width' => 90],
            'jam_keluar'   => ['label' => 'Jam Keluar', 'type' => 'center', 'width' => 90],
            'status'       => ['label' => 'Status Kehadiran', 'type' => 'badge', 'width' => 120],
            'jurnal'       => ['label' => 'Jurnal / Keterangan', 'type' => 'text', 'width' => 220]
        ];
        $rows = [];
        $no = 1;
        foreach ($ceklokNasionalList as $c) {
            $rows[] = [
                'no'           => $no++,
                'tanggal'      => $c['tanggal_absensi'],
                'cabang'       => ($c['nama_cabang'] ?? 'Pusat') . ' (' . ($c['kode_cabang'] ?? 'PST') . ')',
                'nip'          => $c['nip_pegawai'],
                'nama_lengkap' => $c['nama_lengkap'],
                'jabatan_role' => strtoupper($c['jabatan_role']),
                'jam_masuk'    => !empty($c['jam_masuk']) ? date('H:i', strtotime($c['jam_masuk'])) : '-',
                'jam_keluar'   => !empty($c['jam_keluar']) ? date('H:i', strtotime($c['jam_keluar'])) : '-',
                'status'       => $c['status_kehadiran'],
                'jurnal'       => $c['keterangan_jurnal'] ?: '-'
            ];
        }
        $metadata = [
            'Judul'        => 'Monitoring Ceklok Presensi SDM Nasional',
            'Tanggal'      => format_tanggal_indonesia($filterTanggalAbsen),
            'Total Catatan'=> count($rows) . ' Presensi',
            'Waktu Ekspor' => date('d-m-Y H:i') . ' WITA'
        ];
        export_to_excel('monitoring_ceklok_nasional_' . str_replace('-', '', $filterTanggalAbsen), 'MONITORING CEKLOK PRESENSI SDM NASIONAL', $columns, $rows, $metadata);
    } else {
        $columns = [
            'no'             => ['label' => 'No', 'type' => 'center', 'width' => 45],
            'nip'            => ['label' => 'NIP', 'type' => 'text', 'width' => 120],
            'nama_lengkap'   => ['label' => 'Nama Lengkap', 'type' => 'text', 'width' => 190],
            'jabatan_role'   => ['label' => 'Jabatan / Peran', 'type' => 'center', 'width' => 120],
            'cabang'         => ['label' => 'Penempatan Cabang', 'type' => 'text', 'width' => 170],
            'spesialisasi'   => ['label' => 'Spesialisasi / Keahlian', 'type' => 'text', 'width' => 170],
            'email'          => ['label' => 'Email', 'type' => 'text', 'width' => 180],
            'no_hp'          => ['label' => 'No. HP', 'type' => 'text', 'width' => 130],
            'status_kerja'   => ['label' => 'Status Kepegawaian', 'type' => 'center', 'width' => 130],
            'status_aktif'   => ['label' => 'Status Akun', 'type' => 'badge', 'width' => 100],
            'tanggal_masuk'  => ['label' => 'Tgl Bergabung', 'type' => 'date', 'width' => 110]
        ];
        $rows = [];
        $no = 1;
        foreach ($pegawaiList as $p) {
            $cabangLabel = !empty($p['nama_cabang']) ? ($p['nama_cabang'] . ' (' . $p['kode_cabang'] . ')') : 'Kantor Pusat / Nasional';
            $rows[] = [
                'no'             => $no++,
                'nip'            => $p['nip_pegawai'],
                'nama_lengkap'   => $p['nama_lengkap'],
                'jabatan_role'   => strtoupper($p['jabatan_role']),
                'cabang'         => $cabangLabel,
                'spesialisasi'   => $p['spesialisasi_mentor'] ?: ($p['spesialisasi_mapel'] ?? '-'),
                'email'          => $p['email'] ?: '-',
                'no_hp'          => $p['no_hp'] ?: ($p['no_telepon'] ?? '-'),
                'status_kerja'   => ucfirst($p['status_kepegawaian'] ?? 'tetap'),
                'status_aktif'   => $p['status_aktif'],
                'tanggal_masuk'  => $p['tanggal_masuk'] ?? date('Y-m-d', strtotime($p['created_at']))
            ];
        }
        $metadata = [
            'Cakupan'       => 'Seluruh SDM Bisolpin Academy Nasional',
            'Total Pegawai' => count($rows) . " Orang (BAK: {$totalBak}, Mentor: {$totalMentor}, Staff/Sarpras: {$totalStaffSar})",
            'Waktu Ekspor'  => date('d-m-Y H:i') . ' WITA'
        ];
        export_to_excel('sdm_pegawai_nasional_' . date('Ymd_His'), 'REKAPITULASI DATA SDM & MENTOR NASIONAL', $columns, $rows, $metadata);
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-users"></i>
            <span>Manajemen SDM & Mentor Nasional</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Data Pegawai & Pengajar Nasional</h1>
        <p class="text-xs text-slate-500 mt-1">
            Daftar seluruh tim akademik (BAK), direktur cabang, mentor pengajar, staff administrasi, dan petugas sarpras.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="?export=excel" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-file-excel"></i>
            <span>Ekspor Excel (.xls)</span>
        </a>
        <button type="button" onclick="openModalTambahBak()" class="btn-brand text-xs py-2 px-4 shadow-sm flex items-center gap-1.5">
            <i class="fa-solid fa-user-graduate text-xs"></i>
            <span>Tambah Akun BAK Pusat</span>
        </button>
    </div>
</div>

<!-- Tab Navigasi: SDM Nasional vs Monitoring Ceklok -->
<div class="mb-6 flex items-center gap-2 border-b border-slate-200">
    <button type="button" id="btnTabPegawai" onclick="switchPimpinanTab('pegawai')" class="px-5 py-3 border-b-2 font-black text-xs transition-all border-brand-600 text-brand-600 flex items-center gap-2">
        <i class="fa-solid fa-users"></i>
        <span>Data Pegawai & Pengajar Nasional</span>
        <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-[10px]"><?= $totalAll ?></span>
    </button>
    <button type="button" id="btnTabCeklokNasional" onclick="switchPimpinanTab('ceklok')" class="px-5 py-3 border-b-2 font-bold text-xs transition-all border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <span>Monitoring Presensi Pegawai & Mentor Nasional</span>
        <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-[10px] font-bold"><?= count($ceklokNasionalList) ?></span>
    </button>
</div>

<!-- ========================================================================= -->
<!-- TAB 1: DATA PEGAWAI NASIONAL -->
<!-- ========================================================================= -->
<div id="tabContentPegawai" class="space-y-6">
    <!-- Statistik Ringkasan -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-slate-800"><?= $totalAll ?></span>
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Pegawai</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-indigo-600"><?= $totalBak ?></span>
            <span class="text-xs font-bold text-indigo-700 uppercase tracking-wider">Tim BAK Pusat</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-teal-600"><?= $totalMentor ?></span>
            <span class="text-xs font-bold text-teal-700 uppercase tracking-wider">Mentor Pengajar</span>
        </div>
        <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
            <span class="block text-2xl font-black text-amber-600"><?= $totalStaffSar ?></span>
            <span class="text-xs font-bold text-amber-700 uppercase tracking-wider">Staff & Sarpras</span>
        </div>
    </div>

    <!-- Tabel Pegawai Nasional -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="datatable w-full text-left">
                <thead>
                    <tr>
                        <th>NIP & Nama Lengkap</th>
                        <th>Jabatan / Role</th>
                        <th>Penempatan</th>
                        <th>Spesialisasi / Keahlian</th>
                        <th>Kontak & Akun Login</th>
                        <th>Status</th>
                        <th class="text-right">Aksi Pimpinan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pegawaiList as $row): 
                        $isPimpinan = ($row['jabatan_role'] === 'pimpinan');
                    ?>
                        <tr>
                            <td>
                                <div class="font-bold text-slate-800 text-sm"><?= e($row['nama_lengkap']) ?></div>
                                <div class="text-xs font-mono text-slate-400">NIP: <?= e($row['nip_pegawai']) ?></div>
                            </td>

                            <td>
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-bold <?= APP_ROLES[$row['jabatan_role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                    <?= APP_ROLES[$row['jabatan_role']]['label'] ?? ucfirst($row['jabatan_role']) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($row['id_cabang'] === null): ?>
                                    <span class="inline-flex items-center gap-1 text-xs font-bold text-indigo-700 bg-indigo-50 px-2 py-0.5 rounded-md">
                                        <i class="fa-solid fa-landmark text-[10px]"></i>
                                        <span>Kantor Pusat</span>
                                    </span>
                                <?php else: ?>
                                    <div class="font-semibold text-slate-700 text-xs"><?= e($row['nama_cabang']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= e($row['kode_cabang']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="text-xs text-slate-700 font-medium"><?= e($row['spesialisasi_mentor'] ?: '-') ?></div>
                                <div class="text-[10px] text-slate-400 capitalize">Ikatan: <?= e($row['status_kepegawaian']) ?></div>
                            </td>

                            <td>
                                <div class="text-xs text-slate-700 font-mono"><?= e($row['no_hp']) ?></div>
                                <div class="text-[11px] text-slate-400"><?= e($row['email']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono">user: <?= e($row['username']) ?></div>
                            </td>

                            <td>
                                <?= render_status_badge($row['status_aktif']) ?>
                            </td>

                            <td class="text-right">
                                <?php if ($isPimpinan): ?>
                                    <span class="text-xs text-slate-400 italic">Akun Super Admin</span>
                                <?php else: ?>
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin mengubah status keaktifan akun <?= addslashes($row['nama_lengkap']) ?>?')" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status_pegawai">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <?php if ($row['status_aktif'] === 'aktif'): ?>
                                            <input type="hidden" name="new_status" value="nonaktif">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-rose-50 hover:bg-rose-500 hover:text-white text-rose-600 text-xs font-bold transition-all shadow-sm flex items-center gap-1 inline-flex" title="Nonaktifkan Akun">
                                                <i class="fa-solid fa-user-slash text-[10px]"></i>
                                                <span>Nonaktifkan</span>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="aktif">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-500 hover:text-white text-emerald-600 text-xs font-bold transition-all shadow-sm flex items-center gap-1 inline-flex" title="Aktifkan Kembali Akun">
                                                <i class="fa-solid fa-user-check text-[10px]"></i>
                                                <span>Aktifkan</span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TAB 2: MONITORING CEKLOK NASIONAL & DIREKTUR CABANG -->
<!-- ========================================================================= -->
<div id="tabContentCeklokNasional" class="space-y-6 hidden">
    <!-- Filter Tanggal & Status -->
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm flex flex-wrap items-center justify-between gap-4">
        <form method="GET" action="" class="flex items-center gap-3">
            <input type="hidden" name="tab" value="ceklok">
            <?php if (isset($_GET['switch_branch'])): ?>
                <input type="hidden" name="switch_branch" value="<?= e((string)$_GET['switch_branch']) ?>">
            <?php endif; ?>
            <label class="text-xs font-bold text-slate-700">Tanggal Monitoring:</label>
            <input type="date" name="tanggal_absen" value="<?= e($filterTanggalAbsen) ?>" class="form-input text-xs py-2 w-44">
            <button type="submit" class="btn-brand text-xs py-2 px-4 shadow-sm">
                <i class="fa-solid fa-calendar-day mr-1"></i> Tampilkan
            </button>
        </form>

        <div class="text-xs text-slate-500">
            Periode: <strong class="text-slate-800"><?= format_tanggal_indonesia($filterTanggalAbsen) ?></strong>
            <?php if ($activeBranchId !== null): ?>
                <span class="ml-2 px-2 py-0.5 rounded bg-brand-50 text-brand-700 font-bold border border-brand-200">
                    Filter: <?= e(get_active_branch_name()) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabel Monitoring Ceklok Nasional -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
        <?php if (empty($ceklokNasionalList)): ?>
            <?= render_empty_state(
                'fa-clock-rotate-left',
                'Belum Ada Presensi Ceklok pada Tanggal Ini',
                'Belum ada staf operasional atau mentor yang melakukan presensi kerja pada tanggal ' . format_tanggal_indonesia($filterTanggalAbsen) . '.',
                null,
                0
            ) ?>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="datatable w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500 uppercase font-bold tracking-wider">
                            <th class="py-3 px-4">Nama Pegawai & NIP</th>
                            <th class="py-3 px-4">Role Jabatan</th>
                            <th class="py-3 px-4">Penempatan</th>
                            <th class="py-3 px-4 text-center">Clock-In (Masuk)</th>
                            <th class="py-3 px-4 text-center">Clock-Out (Pulang)</th>
                            <th class="py-3 px-4 text-center">Durasi Kerja</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4">Berita Acara / Laporan Kerja</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($ceklokNasionalList as $c): 
                            $durasiKerja = !empty($c['jam_keluar']) ? calculate_work_duration($c['jam_masuk'], $c['jam_keluar']) : 'Sedang Bekerja';
                            $isDirektur = ($c['jabatan_role'] === 'direktur_cabang');
                            $isBak = ($c['jabatan_role'] === 'bak');
                        ?>
                            <tr class="hover:bg-slate-50/70 transition-colors <?= $isDirektur ? 'bg-indigo-50/30' : ($isBak ? 'bg-blue-50/20' : '') ?>">
                                <td class="py-3 px-4">
                                    <h4 class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                                        <?php if ($isDirektur): ?>
                                            <i class="fa-solid fa-user-tie text-indigo-600"></i>
                                        <?php endif; ?>
                                        <span><?= e($c['nama_lengkap']) ?></span>
                                    </h4>
                                    <p class="font-mono text-[11px] text-slate-400"><?= e($c['nip_pegawai']) ?></p>
                                </td>

                                <td class="py-3 px-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= APP_ROLES[$c['jabatan_role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                        <?= APP_ROLES[$c['jabatan_role']]['label'] ?? ucfirst($c['jabatan_role']) ?>
                                    </span>
                                </td>

                                <td class="py-3 px-4">
                                    <?php if ($c['id_cabang'] === null): ?>
                                        <span class="text-xs font-bold text-indigo-700">Kantor Pusat</span>
                                    <?php else: ?>
                                        <span class="text-xs font-semibold text-slate-700"><?= e($c['nama_cabang']) ?></span>
                                    <?php endif; ?>
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
<!-- MODAL: FORM TAMBAH TIM AKADEMIK PUSAT (BAK) -->
<!-- ========================================================================= -->
<div id="modalTambahBak" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-3xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold mb-1">
                    <i class="fa-solid fa-graduation-cap"></i>
                    <span>Otoritas Akademik Pusat</span>
                </div>
                <h3 class="text-lg font-black text-slate-800">Tambah Akun Tim Akademik (BAK Pusat)</h3>
                <p class="text-xs text-slate-500 mt-0.5">Membuat akun staf Bagian Akademik untuk mengelola kurikulum dan modul nasional.</p>
            </div>
            <button onclick="closeModalTambahBak()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_bak_pusat">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">NIP Pegawai Pusat <span class="text-rose-500">*</span></label>
                    <input type="text" name="nip_pegawai" value="<?= e($suggestedNipBak) ?>" required class="form-input text-xs font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Lengkap & Gelar Akademik <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_lengkap" placeholder="Contoh: Dr. Ir. Ahmad Dahlan, M.T." required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Spesialisasi / Bidang Kurikulum</label>
                    <input type="text" name="spesialisasi_mentor" value="Kurikulum & Modul Kursus Nasional" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nomor WhatsApp Aktif <span class="text-rose-500">*</span></label>
                    <input type="text" name="no_hp" placeholder="081234567890" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email Resmi Bisolpin <span class="text-rose-500">*</span></label>
                    <input type="email" name="email" placeholder="bak.akademik@bisolpin.com" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Username Login <span class="text-rose-500">*</span></label>
                    <input type="text" name="username" placeholder="bak_ahmad" required class="form-input text-xs font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Password Login</label>
                    <input type="text" name="password" value="password123" required class="form-input text-xs font-mono">
                    <span class="text-[10px] text-slate-400">Default: password123</span>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Kepegawaian</label>
                    <select name="status_kepegawaian" class="form-input text-xs">
                        <option value="tetap">Pegawai Tetap</option>
                        <option value="kontrak">Pegawai Kontrak</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Domisili</label>
                <textarea name="alamat" rows="2" placeholder="Alamat lengkap..." class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeModalTambahBak()" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold text-xs transition-colors">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2 px-5 shadow-sm">
                    <i class="fa-solid fa-save mr-1"></i> Buat Akun BAK Pusat
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalTambahBak() {
    document.getElementById('modalTambahBak').classList.remove('hidden');
}

function closeModalTambahBak() {
    document.getElementById('modalTambahBak').classList.add('hidden');
}

function switchPimpinanTab(tab) {
    const contentPegawai = document.getElementById('tabContentPegawai');
    const contentCeklok = document.getElementById('tabContentCeklokNasional');
    const btnPegawai = document.getElementById('btnTabPegawai');
    const btnCeklok = document.getElementById('btnTabCeklokNasional');

    if (tab === 'ceklok') {
        contentPegawai.classList.add('hidden');
        contentCeklok.classList.remove('hidden');

        btnCeklok.classList.add('border-brand-600', 'text-brand-600', 'font-black');
        btnCeklok.classList.remove('border-transparent', 'text-slate-500');

        btnPegawai.classList.remove('border-brand-600', 'text-brand-600', 'font-black');
        btnPegawai.classList.add('border-transparent', 'text-slate-500');
    } else {
        contentCeklok.classList.add('hidden');
        contentPegawai.classList.remove('hidden');

        btnPegawai.classList.add('border-brand-600', 'text-brand-600', 'font-black');
        btnPegawai.classList.remove('border-transparent', 'text-slate-500');

        btnCeklok.classList.remove('border-brand-600', 'text-brand-600', 'font-black');
        btnCeklok.classList.add('border-transparent', 'text-slate-500');
    }
}

// Buka tab ceklok jika ada parameter URL tab=ceklok
window.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'ceklok' || params.get('tanggal_absen')) {
        switchPimpinanTab('ceklok');
        setTimeout(function() {
            if (typeof $ !== 'undefined' && $.fn.DataTable) {
                $(document).trigger('click');
            }
        }, 150);
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
