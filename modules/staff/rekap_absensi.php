<?php
/**
 * SIM BISOLPIN ACADEMY - Rekapitulasi Presensi Pegawai & Mentor Cabang
 * Modul Staff Front Office untuk audit kehadiran SDM dan pelaporan berkala ke Direktur Cabang.
 */

declare(strict_types=1);

$pageTitle = 'Rekapitulasi Presensi SDM Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

$db = get_db();
$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;
verify_branch_access($branchId);

// Ambil info Cabang
$stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

// Ambil info Direktur Cabang untuk tanda tangan laporan
$stmtDirektur = $db->prepare("
    SELECT p.nama_lengkap as nama_direktur, p.nip_pegawai as nip_direktur
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    WHERE c.id = ?
");
$stmtDirektur->execute([$branchId]);
$direkturInfo = $stmtDirektur->fetch();
$namaDirektur = $direkturInfo['nama_direktur'] ?? 'Direktur Cabang';
$nipDirektur  = $direkturInfo['nip_direktur'] ?? '-';

// Penanganan Form POST (Koreksi / Input Presensi Manual oleh Staff)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. Input Presensi Pegawai Manual
    if ($action === 'input_presensi_manual') {
        $idPegawai   = (int)($_POST['id_pegawai'] ?? 0);
        $tanggal     = !empty($_POST['tanggal_absensi']) ? trim($_POST['tanggal_absensi']) : date('Y-m-d');
        $jamMasuk    = !empty($_POST['jam_masuk']) ? trim($_POST['jam_masuk']) . ':00' : '08:00:00';
        $jamKeluar   = !empty($_POST['jam_keluar']) ? trim($_POST['jam_keluar']) . ':00' : null;
        $status      = trim($_POST['status_kehadiran'] ?? 'hadir');
        $keterangan  = trim($_POST['keterangan_jurnal'] ?? '');

        if (empty($idPegawai)) {
            redirect_with_message('modules/staff/rekap_absensi.php', 'error', 'Pilih pegawai yang akan dicatatkan presensinya.', 'Validasi Gagal');
        }

        // Cek duplikasi
        $stmtCek = $db->prepare("
            SELECT id FROM tabel_absensi 
            WHERE id_cabang = ? AND tipe_absensi = 'pegawai_mentor' AND id_pegawai = ? AND tanggal_absensi = ?
        ");
        $stmtCek->execute([$branchId, $idPegawai, $tanggal]);
        if ($stmtCek->fetch()) {
            redirect_with_message('modules/staff/rekap_absensi.php', 'warning', 'Pegawai tersebut sudah memiliki catatan presensi pada tanggal yang dipilih.', 'Duplikasi Terdeteksi');
        }

        try {
            $stmtIns = $db->prepare("
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
            $stmtIns->execute([
                $branchId, $idPegawai, $tanggal, $jamMasuk, $jamKeluar, $status, $keterangan, auth_id()
            ]);
            redirect_with_message('modules/staff/rekap_absensi.php', 'success', 'Presensi pegawai berhasil ditambahkan ke rekapitulasi.', 'Tersimpan');
        } catch (\Throwable $e) {
            redirect_with_message('modules/staff/rekap_absensi.php', 'error', 'Gagal mencatat presensi: ' . $e->getMessage(), 'Kesalahan Sistem');
        }
    }

    // 2. Koreksi / Update Presensi
    if ($action === 'koreksi_presensi') {
        $idAbsen    = (int)($_POST['id_absensi'] ?? 0);
        $jamMasuk   = !empty($_POST['jam_masuk']) ? trim($_POST['jam_masuk']) : '08:00:00';
        $jamKeluar  = !empty($_POST['jam_keluar']) ? trim($_POST['jam_keluar']) : null;
        $status     = trim($_POST['status_kehadiran'] ?? 'hadir');
        $keterangan = trim($_POST['keterangan_jurnal'] ?? '');

        if ($idAbsen <= 0) {
            redirect_with_message('modules/staff/rekap_absensi.php', 'error', 'Data presensi tidak valid.');
        }

        try {
            $stmtUp = $db->prepare("
                UPDATE tabel_absensi 
                SET jam_masuk = ?, jam_keluar = ?, status_kehadiran = ?, keterangan_jurnal = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUp->execute([$jamMasuk, $jamKeluar, $status, $keterangan, $idAbsen, $branchId]);
            redirect_with_message('modules/staff/rekap_absensi.php', 'success', 'Data presensi pegawai berhasil diperbarui.', 'Koreksi Berhasil');
        } catch (\Throwable $e) {
            redirect_with_message('modules/staff/rekap_absensi.php', 'error', 'Gagal update presensi: ' . $e->getMessage());
        }
    }
}

// Filter Periode
$filterBulan   = (int)($_GET['bulan'] ?? date('n'));
$filterTahun   = (int)($_GET['tahun'] ?? date('Y'));
$filterPegawai = !empty($_GET['pegawai_id']) ? (int)$_GET['pegawai_id'] : 0;
$filterStatus  = trim($_GET['status'] ?? '');
$isPrintMode   = isset($_GET['print']) && $_GET['print'] === '1';

// Daftar Pegawai Cabang (Staff, Mentor, & Sarpras yang wajib presensi)
$stmtPeg = $db->prepare("
    SELECT id, nip_pegawai, nama_lengkap, jabatan_role, spesialisasi_mentor
    FROM tabel_pegawai
    WHERE id_cabang = ? AND jabatan_role IN ('staff', 'mentor', 'sarpras') AND status_aktif = 'aktif'
    ORDER BY jabatan_role ASC, nama_lengkap ASC
");
$stmtPeg->execute([$branchId]);
$daftarPegawai = $stmtPeg->fetchAll();

// Query Rekapitulasi Presensi Staff, Mentor & Sarpras Cabang
$querySql = "
    SELECT a.*, p.nama_lengkap, p.nip_pegawai, p.jabatan_role, p.spesialisasi_mentor,
           u.username as inputer_username
    FROM tabel_absensi a
    JOIN tabel_pegawai p ON p.id = a.id_pegawai
    LEFT JOIN users u ON u.id = a.diinput_oleh
    WHERE a.id_cabang = ?
      AND a.tipe_absensi = 'pegawai_mentor'
      AND MONTH(a.tanggal_absensi) = ?
      AND YEAR(a.tanggal_absensi) = ?
      AND p.jabatan_role IN ('staff', 'mentor', 'sarpras')
";
$queryParams = [$branchId, $filterBulan, $filterTahun];

if ($filterPegawai > 0) {
    $querySql .= " AND a.id_pegawai = ?";
    $queryParams[] = $filterPegawai;
}
if (!empty($filterStatus)) {
    $querySql .= " AND a.status_kehadiran = ?";
    $queryParams[] = $filterStatus;
}

$querySql .= " ORDER BY a.tanggal_absensi DESC, a.jam_masuk DESC";
$stmtRekap = $db->prepare($querySql);
$stmtRekap->execute($queryParams);
$rekapList = $stmtRekap->fetchAll();

// Statistik Ringkasan
$totalCatatan = count($rekapList);
$countHadir   = count(array_filter($rekapList, fn($r) => $r['status_kehadiran'] === 'hadir'));
$countIzin    = count(array_filter($rekapList, fn($r) => $r['status_kehadiran'] === 'izin'));
$countSakit   = count(array_filter($rekapList, fn($r) => $r['status_kehadiran'] === 'sakit'));
$countAlfa    = count(array_filter($rekapList, fn($r) => $r['status_kehadiran'] === 'alfa'));

$namaBulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// =============================================================================
// JIKA MODE CETAK LAPORAN RESMI (PRINT FORMAT KE DIREKTUR CABANG)
// =============================================================================
if ($isPrintMode):
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Presensi SDM - <?= e($cabang['nama_cabang']) ?> (<?= $namaBulanIndo[$filterBulan] ?> <?= $filterTahun ?>)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { font-size: 11px; }
            .no-print { display: none !important; }
            @page { margin: 15mm; size: A4 landscape; }
        }
    </style>
</head>
<body class="bg-white text-slate-800 p-8 font-sans">

    <!-- Tombol Cetak Browser -->
    <div class="no-print mb-6 flex items-center justify-between bg-slate-50 p-4 rounded-xl border border-slate-200">
        <div>
            <span class="text-xs font-bold text-slate-700">Mode Pratinjau Dokumen Rekapitulasi</span>
            <p class="text-[11px] text-slate-400">Siap dicetak atau disimpan sebagai berkas PDF untuk diserahkan ke Direktur Cabang.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-sm">
                <span>Cetak / Simpan PDF</span>
            </button>
            <a href="<?= base_url('modules/staff/rekap_absensi.php?bulan=' . $filterBulan . '&tahun=' . $filterTahun) ?>" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-xs font-bold">
                Kembali ke Aplikasi
            </a>
        </div>
    </div>

    <!-- Kop Surat Resmi Cabang -->
    <div class="border-b-2 border-slate-900 pb-4 mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="h-16 w-16 object-contain shrink-0">
            <div>
                <h1 class="text-xl font-black tracking-tight text-slate-900 uppercase">BISOLPIN ACADEMY</h1>
                <h2 class="text-sm font-bold text-teal-800"><?= e($cabang['nama_cabang']) ?></h2>
                <p class="text-xs text-slate-500 mt-0.5"><?= e($cabang['alamat']) ?>, <?= e($cabang['kota']) ?>, <?= e($cabang['provinsi']) ?> | Telp: <?= e($cabang['telepon']) ?></p>
            </div>
        </div>
        <div class="text-right">
            <span class="inline-block px-3 py-1 bg-slate-100 text-slate-800 text-xs font-mono font-bold rounded border border-slate-300">
                KODE: <?= e($cabang['kode_cabang']) ?>
            </span>
            <p class="text-[10px] text-slate-400 mt-1">Dicetak pada: <?= date('d/m/Y H:i') ?> WIB</p>
        </div>
    </div>

    <!-- Judul Dokumen -->
    <div class="text-center my-4">
        <h3 class="text-base font-black tracking-wide uppercase text-slate-900">
            LAPORAN REKAPITULASI PRESENSI SDM (STAFF, MENTOR & SARPRAS)
        </h3>
        <p class="text-xs text-slate-600">
            Periode: <strong><?= $namaBulanIndo[$filterBulan] ?> <?= $filterTahun ?></strong>
        </p>
    </div>

    <!-- Statistik Ringkas Cetak -->
    <div class="grid grid-cols-4 gap-3 my-4 text-center">
        <div class="p-2 border border-slate-300 rounded bg-slate-50">
            <span class="block text-xs text-slate-500 font-semibold">Total Presensi</span>
            <strong class="text-sm text-slate-800"><?= $totalCatatan ?></strong>
        </div>
        <div class="p-2 border border-slate-300 rounded bg-slate-50">
            <span class="block text-xs text-slate-500 font-semibold">Hadir Tepat Waktu</span>
            <strong class="text-sm text-emerald-700"><?= $countHadir ?></strong>
        </div>
        <div class="p-2 border border-slate-300 rounded bg-slate-50">
            <span class="block text-xs text-slate-500 font-semibold">Izin / Sakit</span>
            <strong class="text-sm text-amber-700"><?= $countIzin + $countSakit ?></strong>
        </div>
        <div class="p-2 border border-slate-300 rounded bg-slate-50">
            <span class="block text-xs text-slate-500 font-semibold">Tanpa Keterangan (Alfa)</span>
            <strong class="text-sm text-rose-700"><?= $countAlfa ?></strong>
        </div>
    </div>

    <!-- Tabel Data Rekap Cetak -->
    <table class="w-full text-xs text-left border-collapse border border-slate-300 my-4">
        <thead>
            <tr class="bg-slate-100 text-slate-800 font-bold uppercase text-[10px]">
                <th class="border border-slate-300 p-2 text-center w-8">No</th>
                <th class="border border-slate-300 p-2">NIP & Nama Pegawai</th>
                <th class="border border-slate-300 p-2">Peran</th>
                <th class="border border-slate-300 p-2 text-center">Tanggal & Hari</th>
                <th class="border border-slate-300 p-2 text-center">Jam Masuk</th>
                <th class="border border-slate-300 p-2 text-center">Jam Pulang</th>
                <th class="border border-slate-300 p-2 text-center">Durasi</th>
                <th class="border border-slate-300 p-2 text-center">Status</th>
                <th class="border border-slate-300 p-2">Laporan Kegiatan / Jurnal</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rekapList)): ?>
                <tr>
                    <td colspan="9" class="p-4 text-center text-slate-400 italic">
                        Tidak ada catatan presensi pada periode <?= $namaBulanIndo[$filterBulan] ?> <?= $filterTahun ?>.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($rekapList as $r): 
                    $durasi = !empty($r['jam_keluar']) ? calculate_work_duration($r['jam_masuk'], $r['jam_keluar']) : 'Sedang Bekerja';
                ?>
                    <tr class="border-b border-slate-200">
                        <td class="border border-slate-300 p-2 text-center font-mono"><?= $no++ ?></td>
                        <td class="border border-slate-300 p-2">
                            <strong><?= e($r['nama_lengkap']) ?></strong>
                            <div class="font-mono text-[10px] text-slate-500"><?= e($r['nip_pegawai']) ?></div>
                        </td>
                        <td class="border border-slate-300 p-2 capitalize">
                            <?= ucfirst($r['jabatan_role']) ?>
                        </td>
                        <td class="border border-slate-300 p-2 text-center">
                            <div><?= format_tanggal_indonesia($r['tanggal_absensi']) ?></div>
                            <span class="text-[10px] text-slate-500"><?= format_hari_indonesia($r['tanggal_absensi']) ?></span>
                        </td>
                        <td class="border border-slate-300 p-2 text-center font-mono font-bold">
                            <?= substr($r['jam_masuk'], 0, 5) ?> WIB
                        </td>
                        <td class="border border-slate-300 p-2 text-center font-mono">
                            <?= !empty($r['jam_keluar']) ? substr($r['jam_keluar'], 0, 5) . ' WIB' : '-' ?>
                        </td>
                        <td class="border border-slate-300 p-2 text-center font-mono">
                            <?= $durasi ?>
                        </td>
                        <td class="border border-slate-300 p-2 text-center uppercase font-bold text-[10px]">
                            <?= e($r['status_kehadiran']) ?>
                        </td>
                        <td class="border border-slate-300 p-2 text-slate-700">
                            <?= e($r['keterangan_jurnal'] ?: '-') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Tanda Tangan Dua Kolom (Staff Administrasi vs Direktur Cabang) -->
    <div class="mt-12 flex justify-between text-center text-xs">
        <div class="w-64">
            <p class="text-slate-500 mb-1">Dibuat & Direkap Oleh,</p>
            <p class="font-bold text-slate-700">Staff Administrasi Cabang</p>
            <div class="h-20"></div>
            <p class="font-bold underline text-slate-800"><?= e($_SESSION['user']['nama_lengkap']) ?></p>
            <p class="text-[11px] text-slate-500 font-mono">Staff Front Office</p>
        </div>

        <div class="w-64">
            <p class="text-slate-500 mb-1"><?= e($cabang['kota']) ?>, <?= format_tanggal_indonesia(date('Y-m-d')) ?></p>
            <p class="font-bold text-slate-700">Mengetahui & Menyetujui,</p>
            <div class="h-20"></div>
            <p class="font-bold underline text-slate-800"><?= e($namaDirektur) ?></p>
            <p class="text-[11px] text-slate-500 font-mono">Direktur Cabang (NIP: <?= e($nipDirektur) ?>)</p>
        </div>
    </div>

</body>
</html>
<?php 
exit;
endif;

// =============================================================================
// MODE STANDAR APLIKASI (WEB DASHBOARD STAFF)
// =============================================================================
require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-clipboard-user"></i>
            <span>Administrasi SDM Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Rekapitulasi Presensi SDM Cabang
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Data rekapitulasi kehadiran harian Staff, Mentor, dan Sarpras untuk diserahkan sebagai laporan berkala ke Direktur Cabang.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <button type="button" onclick="openModal('modalInputPresensi')" class="btn-brand text-xs py-2 px-3.5 shadow-sm shadow-brand-500/20">
            <i class="fa-solid fa-plus text-xs mr-1"></i>
            <span>Input Presensi Manual</span>
        </button>
        <a href="?bulan=<?= $filterBulan ?>&tahun=<?= $filterTahun ?>&pegawai_id=<?= $filterPegawai ?>&status=<?= e($filterStatus) ?>&print=1" target="_blank" class="btn-brand-outline text-xs py-2 px-3.5 bg-white shadow-sm flex items-center gap-1.5 font-bold">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Cetak Laporan untuk Direktur</span>
        </a>
    </div>
</div>

<!-- Kartu Ringkasan KPI Bulan Terpilih -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-slate-800"><?= $totalCatatan ?></span>
        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Presensi</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-emerald-600"><?= $countHadir ?></span>
        <span class="text-xs font-bold text-emerald-700 uppercase tracking-wider">Hadir Tepat Waktu</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-amber-600"><?= $countIzin + $countSakit ?></span>
        <span class="text-xs font-bold text-amber-700 uppercase tracking-wider">Izin & Sakit</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-rose-500"><?= $countAlfa ?></span>
        <span class="text-xs font-bold text-rose-700 uppercase tracking-wider">Tanpa Keterangan</span>
    </div>
</div>

<!-- Filter Bar -->
<div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm mb-6 flex flex-wrap items-center justify-between gap-4">
    <form method="GET" action="" class="flex flex-wrap items-center gap-3">
        <!-- Filter Bulan -->
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Bulan</label>
            <select name="bulan" class="form-input text-xs py-1.5 w-32 font-bold text-slate-700">
                <?php foreach ($namaBulanIndo as $num => $nama): ?>
                    <option value="<?= $num ?>" <?= $filterBulan === $num ? 'selected' : '' ?>><?= $nama ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filter Tahun -->
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Tahun</label>
            <select name="tahun" class="form-input text-xs py-1.5 w-24 font-bold text-slate-700">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $filterTahun === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Filter Pegawai -->
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Pegawai / Mentor</label>
            <select name="pegawai_id" class="form-input text-xs py-1.5 w-48 font-bold text-slate-700">
                <option value="0">Semua SDM (Staff, Mentor, Sarpras)</option>
                <?php foreach ($daftarPegawai as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $filterPegawai === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['nama_lengkap']) ?> (<?= ucfirst($p['jabatan_role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filter Status -->
        <div>
            <label class="block text-[10px] font-bold uppercase text-slate-400 mb-1">Status Kehadiran</label>
            <select name="status" class="form-input text-xs py-1.5 w-36 font-bold text-slate-700">
                <option value="">Semua Status</option>
                <option value="hadir" <?= $filterStatus === 'hadir' ? 'selected' : '' ?>>Hadir</option>
                <option value="izin" <?= $filterStatus === 'izin' ? 'selected' : '' ?>>Izin</option>
                <option value="sakit" <?= $filterStatus === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                <option value="alfa" <?= $filterStatus === 'alfa' ? 'selected' : '' ?>>Alfa</option>
            </select>
        </div>

        <div class="self-end pt-4">
            <button type="submit" class="btn-brand text-xs py-2 px-4 shadow-sm">
                <i class="fa-solid fa-filter mr-1"></i> Terapkan
            </button>
        </div>
    </form>

    <div class="text-xs text-slate-500 font-medium">
        Menampilkan data periode: <strong class="text-slate-800"><?= $namaBulanIndo[$filterBulan] ?> <?= $filterTahun ?></strong>
    </div>
</div>

<!-- Tabel Data Rekapitulasi Presensi -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left text-xs">
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 uppercase font-bold tracking-wider">
                    <th class="py-3 px-4">Nama Pegawai & NIP</th>
                    <th class="py-3 px-4">Role / Jabatan</th>
                    <th class="py-3 px-4 text-center">Tanggal & Hari</th>
                    <th class="py-3 px-4 text-center">Jam Masuk</th>
                    <th class="py-3 px-4 text-center">Jam Pulang</th>
                    <th class="py-3 px-4 text-center">Durasi</th>
                    <th class="py-3 px-4 text-center">Status</th>
                    <th class="py-3 px-4">Catatan Jurnal / Keterangan</th>
                    <th class="py-3 px-4 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($rekapList)): ?>
                    <?= render_empty_state(
                        'fa-clipboard-user',
                        'Belum Ada Catatan Presensi pada Periode Ini',
                        'Belum ada rekaman presensi mandiri atau manual untuk SDM Cabang pada ' . $namaBulanIndo[$filterBulan] . ' ' . $filterTahun . '.',
                        '<button type="button" onclick="openModal(\'modalInputPresensi\')" class="btn-brand text-xs py-2 px-4 shadow-sm"><i class="fa-solid fa-plus mr-1"></i> Input Presensi Manual</button>',
                        9
                    ) ?>
                <?php else: ?>
                    <?php foreach ($rekapList as $r): 
                        $durasi = !empty($r['jam_keluar']) ? calculate_work_duration($r['jam_masuk'], $r['jam_keluar']) : 'Sedang Bekerja';
                        $dataJson = htmlspecialchars(json_encode([
                            'id'                => (int)$r['id'],
                            'nama_lengkap'      => $r['nama_lengkap'],
                            'nip_pegawai'       => $r['nip_pegawai'],
                            'tanggal_absensi'   => $r['tanggal_absensi'],
                            'jam_masuk'         => substr($r['jam_masuk'], 0, 5),
                            'jam_keluar'        => !empty($r['jam_keluar']) ? substr($r['jam_keluar'], 0, 5) : '',
                            'status_kehadiran'  => $r['status_kehadiran'],
                            'keterangan_jurnal' => $r['keterangan_jurnal'] ?? '',
                        ]), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4">
                                <h4 class="font-bold text-slate-800 text-xs"><?= e($r['nama_lengkap']) ?></h4>
                                <p class="font-mono text-[11px] text-slate-400"><?= e($r['nip_pegawai']) ?></p>
                            </td>

                            <td class="py-3 px-4">
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= APP_ROLES[$r['jabatan_role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                    <?= APP_ROLES[$r['jabatan_role']]['label'] ?? ucfirst($r['jabatan_role']) ?>
                                </span>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <span class="font-bold text-slate-800 block"><?= format_tanggal_indonesia($r['tanggal_absensi']) ?></span>
                                <span class="text-[11px] text-slate-400 font-medium"><?= format_hari_indonesia($r['tanggal_absensi']) ?></span>
                            </td>

                            <td class="py-3 px-4 text-center font-mono font-bold text-emerald-600">
                                <?= substr($r['jam_masuk'], 0, 5) ?> WIB
                            </td>

                            <td class="py-3 px-4 text-center font-mono font-bold text-rose-500">
                                <?php if (!empty($r['jam_keluar'])): ?>
                                    <?= substr($r['jam_keluar'], 0, 5) ?> WIB
                                <?php else: ?>
                                    <span class="text-slate-400 italic text-[11px] font-normal">Belum Pulang</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 px-4 text-center font-mono">
                                <span class="px-2 py-0.5 rounded-full <?= !empty($r['jam_keluar']) ? 'bg-slate-100 text-slate-700 font-semibold' : 'bg-emerald-50 text-emerald-700 font-bold' ?> text-[11px]">
                                    <?= $durasi ?>
                                </span>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($r['status_kehadiran']) ?>
                            </td>

                            <td class="py-3 px-4 text-slate-600 max-w-xs truncate" title="<?= e($r['keterangan_jurnal']) ?>">
                                <?= e($r['keterangan_jurnal'] ?: '-') ?>
                            </td>

                            <td class="py-3 px-4 text-right">
                                <button type="button" onclick='openModalKoreksi(<?= $dataJson ?>)' class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-600 text-slate-600 font-bold text-[11px] transition-colors" title="Koreksi Data Presensi">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM INPUT PRESENSI MANUAL -->
<!-- ========================================================================= -->
<div id="modalInputPresensi" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 overflow-hidden">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-800">Input Presensi Manual</h3>
                <p class="text-[11px] text-slate-400">Catat kehadiran staf atau mentor yang berhalangan ceklok mandiri.</p>
            </div>
            <button type="button" onclick="closeModal('modalInputPresensi')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-3.5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="input_presensi_manual">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Pegawai *</label>
                <select name="id_pegawai" required class="form-input text-xs py-2 w-full">
                    <option value="">-- Pilih Pegawai (Staff / Mentor / Sarpras) --</option>
                    <?php foreach ($daftarPegawai as $p): ?>
                        <option value="<?= (int)$p['id'] ?>">
                            <?= e($p['nama_lengkap']) ?> (<?= ucfirst($p['jabatan_role']) ?> - NIP: <?= e($p['nip_pegawai']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Presensi *</label>
                <input type="date" name="tanggal_absensi" value="<?= date('Y-m-d') ?>" required class="form-input text-xs py-2 w-full">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Masuk *</label>
                    <input type="time" name="jam_masuk" value="08:00" required class="form-input text-xs py-2 w-full font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Pulang</label>
                    <input type="time" name="jam_keluar" value="17:00" class="form-input text-xs py-2 w-full font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Status Kehadiran *</label>
                <select name="status_kehadiran" required class="form-input text-xs py-2 w-full">
                    <option value="hadir">Hadir (Bekerja Normal)</option>
                    <option value="izin">Izin Resmi</option>
                    <option value="sakit">Sakit</option>
                    <option value="alfa">Alfa (Tanpa Keterangan)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Jurnal / Keterangan</label>
                <textarea name="keterangan_jurnal" rows="2" placeholder="Catatan tugas harian atau alasan izin..." class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalInputPresensi')" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Presensi</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM KOREKSI PRESENSI -->
<!-- ========================================================================= -->
<div id="modalKoreksiPresensi" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 overflow-hidden">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div>
                <h3 class="text-base font-bold text-slate-800">Koreksi Data Presensi</h3>
                <p id="koreksiSubJudul" class="text-[11px] text-slate-400">Perbarui catatan jam kerja atau status kehadiran.</p>
            </div>
            <button type="button" onclick="closeModal('modalKoreksiPresensi')" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-3.5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="koreksi_presensi">
            <input type="hidden" id="koreksiId" name="id_absensi" value="">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Masuk</label>
                    <input type="time" id="koreksiJamMasuk" name="jam_masuk" required class="form-input text-xs py-2 w-full font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Pulang</label>
                    <input type="time" id="koreksiJamKeluar" name="jam_keluar" class="form-input text-xs py-2 w-full font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Status Kehadiran</label>
                <select id="koreksiStatus" name="status_kehadiran" required class="form-input text-xs py-2 w-full">
                    <option value="hadir">Hadir</option>
                    <option value="izin">Izin</option>
                    <option value="sakit">Sakit</option>
                    <option value="alfa">Alfa</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Jurnal / Keterangan</label>
                <textarea id="koreksiKeterangan" name="keterangan_jurnal" rows="2" class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalKoreksiPresensi')" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function openModalKoreksi(data) {
    document.getElementById('koreksiId').value = data.id;
    document.getElementById('koreksiSubJudul').innerText = data.nama_lengkap + ' (' + data.nip_pegawai + ') - Tanggal: ' + data.tanggal_absensi;
    document.getElementById('koreksiJamMasuk').value = data.jam_masuk;
    document.getElementById('koreksiJamKeluar').value = data.jam_keluar;
    document.getElementById('koreksiStatus').value = data.status_kehadiran;
    document.getElementById('koreksiKeterangan').value = data.keterangan_jurnal;

    openModal('modalKoreksiPresensi');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

