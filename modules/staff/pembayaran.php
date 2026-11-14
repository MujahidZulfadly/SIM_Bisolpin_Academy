<?php
/**
 * SIM BISOLPIN ACADEMY - Administrasi Kasir & Pencatatan Pembayaran Kursus Cabang
 */

declare(strict_types=1);

$pageTitle = 'Kasir & Pembayaran Layanan';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

$db = get_db();
$branchId = get_active_branch_id() ?? 1;

// Keamanan isolasi tenant cabang
verify_branch_access($branchId);

// Pastikan tabel_pembayaran tersedia di database
$db->exec("
    CREATE TABLE IF NOT EXISTS `tabel_pembayaran` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `id_cabang` INT NOT NULL,
      `id_siswa` INT NOT NULL,
      `id_layanan` INT NOT NULL,
      `kode_transaksi` VARCHAR(50) NOT NULL UNIQUE,
      `tanggal_bayar` DATE NOT NULL,
      `jumlah_bayar` DECIMAL(12,2) NOT NULL,
      `metode_pembayaran` ENUM('tunai','transfer_bank','qris') NOT NULL DEFAULT 'tunai',
      `status_pembayaran` ENUM('lunas','cicilan','menunggu_konfirmasi') NOT NULL DEFAULT 'lunas',
      `bukti_transfer` VARCHAR(255) NULL,
      `catatan` TEXT NULL,
      `diterima_oleh` INT NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_bayar_cabang` (`id_cabang`),
      INDEX `idx_bayar_siswa` (`id_siswa`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Seed awal jika masih kosong
$cekCount = (int)$db->query("SELECT COUNT(*) FROM tabel_pembayaran")->fetchColumn();
if ($cekCount === 0) {
    try {
        $db->exec("
            INSERT INTO `tabel_pembayaran` (`id`, `id_cabang`, `id_siswa`, `id_layanan`, `kode_transaksi`, `tanggal_bayar`, `jumlah_bayar`, `metode_pembayaran`, `status_pembayaran`, `catatan`, `diterima_oleh`, `created_at`) VALUES
            (1, 1, 1, 1, 'INV-202502-001', '2025-02-01', 1850000.00, 'transfer_bank', 'lunas', 'Pembayaran lunas pendaftaran kursus Junior Web Developer & Robotik.', 6, NOW()),
            (2, 1, 2, 1, 'INV-202502-002', '2025-02-03', 1000000.00, 'tunai', 'cicilan', 'Pembayaran cicilan tahap 1 kursus tatap muka.', 6, NOW())
        ");
    } catch (\Throwable $e) {
        // Skip silently if error
    }
}

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH PEMBAYARAN BARU
    if ($action === 'tambah_pembayaran') {
        $idSiswa = (int)($_POST['id_siswa'] ?? 0);
        $idLayanan = (int)($_POST['id_layanan'] ?? 0);
        $tanggalBayar = !empty($_POST['tanggal_bayar']) ? trim($_POST['tanggal_bayar']) : date('Y-m-d');
        $jumlahBayar = (float)($_POST['jumlah_bayar'] ?? 0);
        $metode = trim($_POST['metode_pembayaran'] ?? 'tunai');
        $status = trim($_POST['status_pembayaran'] ?? 'lunas');
        $catatan = trim($_POST['catatan'] ?? '');

        if (empty($idSiswa) || empty($idLayanan) || $jumlahBayar <= 0) {
            redirect_with_message('modules/staff/pembayaran.php', 'error', 'Pilih siswa, program layanan, dan masukkan nominal bayar yang valid.', 'Validasi Gagal');
        }

        // Generate Kode Transaksi Unik: INV-YYYYMM-XXXX
        $kodeTransaksi = sprintf("INV-%s-%04d", date('Ym'), rand(100, 9999));

        try {
            $db->beginTransaction();

            $stmtInsert = $db->prepare("
                INSERT INTO tabel_pembayaran (
                    id_cabang, id_siswa, id_layanan, kode_transaksi,
                    tanggal_bayar, jumlah_bayar, metode_pembayaran,
                    status_pembayaran, catatan, diterima_oleh, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, NOW()
                )
            ");
            $stmtInsert->execute([
                $branchId, $idSiswa, $idLayanan, $kodeTransaksi,
                $tanggalBayar, $jumlahBayar, $metode,
                $status, $catatan, auth_id()
            ]);

            // Sinkronkan nominal_terbayar dan status_pembayaran ke tabel_siswa
            $stmtSumAll = $db->prepare("SELECT COALESCE(SUM(jumlah_bayar), 0) FROM tabel_pembayaran WHERE id_siswa = ?");
            $stmtSumAll->execute([$idSiswa]);
            $totalTerbayarSiswa = (float)$stmtSumAll->fetchColumn();

            $stmtSiswaLay = $db->prepare("SELECT s.id_layanan, l.harga_layanan FROM tabel_siswa s JOIN tabel_layanan l ON l.id = s.id_layanan WHERE s.id = ?");
            $stmtSiswaLay->execute([$idSiswa]);
            $siswaLay = $stmtSiswaLay->fetch();
            $hargaUtama = $siswaLay ? (float)$siswaLay['harga_layanan'] : 0.0;

            $statusBayarSiswa = 'belum_bayar';
            if ($totalTerbayarSiswa >= $hargaUtama && $hargaUtama > 0) {
                $statusBayarSiswa = 'lunas';
            } elseif ($totalTerbayarSiswa > 0) {
                $statusBayarSiswa = 'cicilan';
            }

            $stmtUpSiswa = $db->prepare("UPDATE tabel_siswa SET nominal_terbayar = ?, status_pembayaran = ? WHERE id = ?");
            $stmtUpSiswa->execute([$totalTerbayarSiswa, $statusBayarSiswa, $idSiswa]);

            // Sinkronkan juga pada riwayat_layanan_siswa untuk paket spesifik
            $stmtSumLay = $db->prepare("SELECT COALESCE(SUM(jumlah_bayar), 0) FROM tabel_pembayaran WHERE id_siswa = ? AND id_layanan = ?");
            $stmtSumLay->execute([$idSiswa, $idLayanan]);
            $totalLay = (float)$stmtSumLay->fetchColumn();

            $stmtPrice = $db->prepare("SELECT harga_layanan FROM tabel_layanan WHERE id = ?");
            $stmtPrice->execute([$idLayanan]);
            $hargaLayananTarget = (float)($stmtPrice->fetchColumn() ?: 0);

            $statusLay = 'belum_bayar';
            if ($totalLay >= $hargaLayananTarget && $hargaLayananTarget > 0) {
                $statusLay = 'lunas';
            } elseif ($totalLay > 0) {
                $statusLay = 'cicilan';
            }

            $stmtUpRls = $db->prepare("UPDATE riwayat_layanan_siswa SET nominal_terbayar = ?, status_pembayaran = ? WHERE id_siswa = ? AND id_layanan = ?");
            $stmtUpRls->execute([$totalLay, $statusLay, $idSiswa, $idLayanan]);

            $db->commit();

            redirect_with_message('modules/staff/pembayaran.php', 'success', "Kwitansi {$kodeTransaksi} sebesar " . format_rupiah($jumlahBayar) . " berhasil dicatat.", 'Transaksi Berhasil');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Gagal catat pembayaran: " . $e->getMessage());
            redirect_with_message('modules/staff/pembayaran.php', 'error', 'Gagal mencatat transaksi: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. HAPUS PEMBAYARAN
    if ($action === 'hapus_pembayaran') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->beginTransaction();

            $stmtGet = $db->prepare("SELECT id_siswa, id_layanan FROM tabel_pembayaran WHERE id = ? AND id_cabang = ?");
            $stmtGet->execute([$id, $branchId]);
            $trx = $stmtGet->fetch();

            if ($trx) {
                $idSiswa = (int)$trx['id_siswa'];
                $idLayanan = (int)$trx['id_layanan'];

                $stmtDel = $db->prepare("DELETE FROM tabel_pembayaran WHERE id = ? AND id_cabang = ?");
                $stmtDel->execute([$id, $branchId]);

                // Sinkronkan ulang nominal_terbayar dan status_pembayaran ke tabel_siswa
                $stmtSumAll = $db->prepare("SELECT COALESCE(SUM(jumlah_bayar), 0) FROM tabel_pembayaran WHERE id_siswa = ?");
                $stmtSumAll->execute([$idSiswa]);
                $totalTerbayarSiswa = (float)$stmtSumAll->fetchColumn();

                $stmtSiswaLay = $db->prepare("SELECT s.id_layanan, l.harga_layanan FROM tabel_siswa s JOIN tabel_layanan l ON l.id = s.id_layanan WHERE s.id = ?");
                $stmtSiswaLay->execute([$idSiswa]);
                $siswaLay = $stmtSiswaLay->fetch();
                $hargaUtama = $siswaLay ? (float)$siswaLay['harga_layanan'] : 0.0;

                $statusBayarSiswa = 'belum_bayar';
                if ($totalTerbayarSiswa >= $hargaUtama && $hargaUtama > 0) {
                    $statusBayarSiswa = 'lunas';
                } elseif ($totalTerbayarSiswa > 0) {
                    $statusBayarSiswa = 'cicilan';
                }

                $stmtUpSiswa = $db->prepare("UPDATE tabel_siswa SET nominal_terbayar = ?, status_pembayaran = ? WHERE id = ?");
                $stmtUpSiswa->execute([$totalTerbayarSiswa, $statusBayarSiswa, $idSiswa]);

                // Sinkronkan juga pada riwayat_layanan_siswa
                $stmtSumLay = $db->prepare("SELECT COALESCE(SUM(jumlah_bayar), 0) FROM tabel_pembayaran WHERE id_siswa = ? AND id_layanan = ?");
                $stmtSumLay->execute([$idSiswa, $idLayanan]);
                $totalLay = (float)$stmtSumLay->fetchColumn();

                $stmtPrice = $db->prepare("SELECT harga_layanan FROM tabel_layanan WHERE id = ?");
                $stmtPrice->execute([$idLayanan]);
                $hargaLayananTarget = (float)($stmtPrice->fetchColumn() ?: 0);

                $statusLay = 'belum_bayar';
                if ($totalLay >= $hargaLayananTarget && $hargaLayananTarget > 0) {
                    $statusLay = 'lunas';
                } elseif ($totalLay > 0) {
                    $statusLay = 'cicilan';
                }

                $stmtUpRls = $db->prepare("UPDATE riwayat_layanan_siswa SET nominal_terbayar = ?, status_pembayaran = ? WHERE id_siswa = ? AND id_layanan = ?");
                $stmtUpRls->execute([$totalLay, $statusLay, $idSiswa, $idLayanan]);
            }

            $db->commit();
            redirect_with_message('modules/staff/pembayaran.php', 'success', 'Catatan transaksi pembayaran berhasil dihapus.', 'Hapus Berhasil');
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            redirect_with_message('modules/staff/pembayaran.php', 'error', 'Gagal menghapus transaksi.', 'Gagal Hapus');
        }
    }
}

// METRIK KEUANGAN CABANG
$stmtTotalMasuk = $db->prepare("SELECT SUM(jumlah_bayar) FROM tabel_pembayaran WHERE id_cabang = ?");
$stmtTotalMasuk->execute([$branchId]);
$totalPemasukan = (float)($stmtTotalMasuk->fetchColumn() ?: 0);

$stmtLunas = $db->prepare("SELECT COUNT(*) FROM tabel_pembayaran WHERE id_cabang = ? AND status_pembayaran = 'lunas'");
$stmtLunas->execute([$branchId]);
$countLunas = (int)$stmtLunas->fetchColumn();

$stmtCicilan = $db->prepare("SELECT COUNT(*) FROM tabel_pembayaran WHERE id_cabang = ? AND status_pembayaran = 'cicilan'");
$stmtCicilan->execute([$branchId]);
$countCicilan = (int)$stmtCicilan->fetchColumn();

// DAFTAR TRANSAKSI PEMBAYARAN
$filterStatus = trim($_GET['status'] ?? '');
$filterMetode = trim($_GET['metode'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT pb.*, s.nama_lengkap as nama_siswa, s.nis_bisolpin, s.no_hp_siswa, l.nama_layanan, l.harga_layanan, u.username as nama_kasir
    FROM tabel_pembayaran pb
    JOIN tabel_siswa s ON s.id = pb.id_siswa
    JOIN tabel_layanan l ON l.id = pb.id_layanan
    LEFT JOIN users u ON u.id = pb.diterima_oleh
    WHERE pb.id_cabang = ?
";
$params = [$branchId];

if (!empty($filterStatus)) {
    $sql .= " AND pb.status_pembayaran = ?";
    $params[] = $filterStatus;
}
if (!empty($filterMetode)) {
    $sql .= " AND pb.metode_pembayaran = ?";
    $params[] = $filterMetode;
}
if (!empty($search)) {
    $sql .= " AND (pb.kode_transaksi LIKE ? OR s.nama_lengkap LIKE ? OR s.nis_bisolpin LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY pb.tanggal_bayar DESC, pb.id DESC";

$stmtList = $db->prepare($sql);
$stmtList->execute($params);
$transaksiList = $stmtList->fetchAll();

// Daftar Siswa Cabang untuk Dropdown Form
$stmtSiswa = $db->prepare("
    SELECT s.id, s.nis_bisolpin, s.nama_lengkap, s.id_layanan, l.nama_layanan, l.harga_layanan
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    WHERE s.id_cabang = ? AND s.status_belajar = 'aktif'
    ORDER BY s.nama_lengkap ASC
");
$stmtSiswa->execute([$branchId]);
$siswaPilihan = $stmtSiswa->fetchAll();

// Map Data Siswa ke JSON untuk Auto-Fill JS
$siswaMap = [];
foreach ($siswaPilihan as $sp) {
    $siswaMap[$sp['id']] = [
        'id_layanan'    => $sp['id_layanan'],
        'nama_layanan'  => $sp['nama_layanan'],
        'harga_layanan' => (float)$sp['harga_layanan']
    ];
}

// EKSPOR LAPORAN KEUANGAN & PEMBAYARAN KE EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
    $stmtCabang->execute([$branchId]);
    $cabang = $stmtCabang->fetch();

    $columns = [
        'no'             => ['label' => 'No', 'type' => 'center', 'width' => 45],
        'kode_transaksi' => ['label' => 'No. Kuitansi / Trx', 'type' => 'text', 'width' => 140],
        'tanggal_bayar'  => ['label' => 'Tanggal Bayar', 'type' => 'date', 'width' => 110],
        'nis'            => ['label' => 'NIS', 'type' => 'text', 'width' => 120],
        'nama_siswa'     => ['label' => 'Nama Siswa', 'type' => 'text', 'width' => 180],
        'no_hp'          => ['label' => 'No. HP Siswa', 'type' => 'text', 'width' => 130],
        'nama_layanan'   => ['label' => 'Program Kursus', 'type' => 'text', 'width' => 180],
        'biaya_kursus'   => ['label' => 'Biaya Program', 'type' => 'currency', 'width' => 130],
        'jumlah_bayar'   => ['label' => 'Nominal Bayar', 'type' => 'currency', 'width' => 130],
        'metode'         => ['label' => 'Metode', 'type' => 'center', 'width' => 110],
        'status'         => ['label' => 'Status Bayar', 'type' => 'badge', 'width' => 110],
        'kasir'          => ['label' => 'Penerima / Kasir', 'type' => 'center', 'width' => 120],
        'catatan'        => ['label' => 'Catatan Transaksi', 'type' => 'text', 'width' => 200]
    ];

    $rows = [];
    $no = 1;
    $totalFilteredNominal = 0;
    foreach ($transaksiList as $t) {
        $nominal = (float)$t['jumlah_bayar'];
        $totalFilteredNominal += $nominal;

        $metodeMap = [
            'tunai'         => 'Tunai (Cash)',
            'transfer_bank' => 'Transfer Bank',
            'qris'          => 'QRIS Dinamis'
        ];

        $rows[] = [
            'no'             => $no++,
            'kode_transaksi' => $t['kode_transaksi'],
            'tanggal_bayar'  => $t['tanggal_bayar'],
            'nis'            => $t['nis_bisolpin'],
            'nama_siswa'     => $t['nama_siswa'],
            'no_hp'          => $t['no_hp_siswa'] ?: '-',
            'nama_layanan'   => $t['nama_layanan'],
            'biaya_kursus'   => (float)$t['harga_layanan'],
            'jumlah_bayar'   => $nominal,
            'metode'         => $metodeMap[$t['metode_pembayaran']] ?? strtoupper($t['metode_pembayaran']),
            'status'         => $t['status_pembayaran'],
            'kasir'          => $t['nama_kasir'] ?: 'Staff',
            'catatan'        => $t['catatan'] ?: '-'
        ];
    }

    $metadata = [
        'Nama Cabang'        => $cabang['nama_cabang'] ?? 'Bisolpin Cabang',
        'Kode Cabang'        => $cabang['kode_cabang'] ?? '-',
        'Filter Status'      => !empty($filterStatus) ? ucfirst($filterStatus) : 'Semua Status',
        'Filter Metode'      => !empty($filterMetode) ? ucfirst($filterMetode) : 'Semua Metode',
        'Kata Kunci Cari'    => !empty($search) ? $search : '-',
        'Total Transaksi'    => count($rows) . ' Transaksi',
        'Total Kas Diterima' => format_rupiah($totalFilteredNominal),
        'Waktu Ekspor'       => date('d-m-Y H:i') . ' WITA'
    ];

    $summary = [
        'label'   => 'TOTAL KAS DITERIMA',
        'colspan' => 8,
        'values'  => [
            'jumlah_bayar' => $totalFilteredNominal
        ]
    ];

    $filename = 'laporan_keuangan_kasir_' . preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($cabang['kode_cabang'] ?? 'cabang')) . '_' . date('Ymd_His');

    export_to_excel($filename, 'LAPORAN PENERIMAAN KAS & PEMBAYARAN SISWA CABANG', $columns, $rows, $metadata, $summary);
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-cash-register"></i>
            <span>Front Office & Kasir Keuangan Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Kasir & Pembayaran Layanan Kursus
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Catat penerimaan biaya pendaftaran, SPP kursus offline, dan cetak kuitansi resmi bertanda tangan kasir.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="?status=<?= urlencode($filterStatus) ?>&metode=<?= urlencode($filterMetode) ?>&q=<?= urlencode($search) ?>&export=excel" class="px-3.5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm shadow-emerald-600/20 flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-file-excel text-xs"></i>
            <span>Ekspor Excel (.xls)</span>
        </a>
        <button onclick="openModal('modalEntriBayar')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Entri Pembayaran Baru</span>
        </button>
    </div>
</div>

<!-- Bento KPI Keuangan Kasir -->
<div class="bento-grid mb-6">
    <!-- KPI 1: Total Pemasukan -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Penerimaan Kasir</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-vault"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-800"><?= format_rupiah($totalPemasukan) ?></span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Total Transaksi:</span>
            <span class="font-bold text-brand-600"><?= count($transaksiList) ?> Transaksi</span>
        </div>
    </div>

    <!-- KPI 2: Transaksi Lunas -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Transaksi Lunas</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-emerald-600"><?= $countLunas ?></span>
            <span class="text-xs text-slate-400 font-medium">Kwitansi Lunas</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Rasio Kelunasan:</span>
            <span class="font-bold text-emerald-600">
                <?= count($transaksiList) > 0 ? round(($countLunas / count($transaksiList)) * 100) : 0 ?>%
            </span>
        </div>
    </div>

    <!-- KPI 3: Transaksi Cicilan -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Transaksi Cicilan / Bertahap</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-receipt"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-amber-600"><?= $countCicilan ?></span>
            <span class="text-xs text-slate-400 font-medium">Kwitansi Bertahap</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Status:</span>
            <span class="font-bold text-amber-600">Perlu Pemantauan Sisa</span>
        </div>
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
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor invoice, nama siswa, NIS..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-36">
                <select name="status" class="form-input text-xs py-2">
                    <option value="">Semua Status</option>
                    <option value="lunas" <?= $filterStatus === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                    <option value="cicilan" <?= $filterStatus === 'cicilan' ? 'selected' : '' ?>>Cicilan</option>
                    <option value="menunggu_konfirmasi" <?= $filterStatus === 'menunggu_konfirmasi' ? 'selected' : '' ?>>Menunggu</option>
                </select>
            </div>

            <div class="w-36">
                <select name="metode" class="form-input text-xs py-2">
                    <option value="">Semua Metode</option>
                    <option value="tunai" <?= $filterMetode === 'tunai' ? 'selected' : '' ?>>Tunai</option>
                    <option value="transfer_bank" <?= $filterMetode === 'transfer_bank' ? 'selected' : '' ?>>Transfer Bank</option>
                    <option value="qris" <?= $filterMetode === 'qris' ? 'selected' : '' ?>>QRIS</option>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterStatus) || !empty($filterMetode)): ?>
                <a href="<?= base_url('modules/staff/pembayaran.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset Filter
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Ditemukan: <span class="font-bold text-slate-800"><?= count($transaksiList) ?> Data</span>
        </div>
    </form>
</div>

<!-- Tabel Riwayat Pembayaran -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <th class="py-3 px-4">Kode Kwitansi</th>
                    <th class="py-3 px-4">Tanggal Bayar</th>
                    <th class="py-3 px-4">Nama Siswa & NIS</th>
                    <th class="py-3 px-4">Program Kursus</th>
                    <th class="py-3 px-4">Metode Bayar</th>
                    <th class="py-3 px-4 text-right">Nominal Bayar</th>
                    <th class="py-3 px-4 text-center">Status</th>
                    <th class="py-3 px-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($transaksiList)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400">
                            <i class="fa-regular fa-folder-open text-3xl mb-2 text-slate-300 block"></i>
                            Tidak ada transaksi pembayaran yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transaksiList as $tb): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold text-teal-700">
                                <?= e($tb['kode_transaksi']) ?>
                            </td>
                            <td class="py-3 px-4 text-slate-500">
                                <?= format_tanggal_indonesia($tb['tanggal_bayar']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <h4 class="font-bold text-slate-800"><?= e($tb['nama_siswa']) ?></h4>
                                <p class="font-mono text-[11px] text-slate-400"><?= e($tb['nis_bisolpin']) ?></p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="font-semibold text-slate-700 block"><?= e($tb['nama_layanan']) ?></span>
                                <span class="text-[11px] text-slate-400">Tarif: <?= format_rupiah($tb['harga_layanan']) ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="uppercase font-semibold text-slate-600 bg-slate-100 px-2 py-0.5 rounded text-[11px]">
                                    <?= e(str_replace('_', ' ', $tb['metode_pembayaran'])) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-right font-black text-slate-800 text-sm">
                                <?= format_rupiah($tb['jumlah_bayar']) ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($tb['status_pembayaran']) ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <div class="inline-flex items-center gap-1.5">
                                    <!-- Cetak Kuitansi -->
                                    <button onclick="cetakKwitansi(<?= htmlspecialchars(json_encode($tb), ENT_QUOTES, 'UTF-8') ?>)" title="Cetak Kwitansi Resmi" class="w-8 h-8 rounded-lg bg-teal-50 text-teal-600 hover:bg-teal-100 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-print text-xs"></i>
                                    </button>
                                    <!-- Hapus -->
                                    <form method="POST" action="" onsubmit="return confirm('Hapus catatan kwitansi <?= addslashes($tb['kode_transaksi']) ?>?');" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hapus_pembayaran">
                                        <input type="hidden" name="id" value="<?= $tb['id'] ?>">
                                        <button type="submit" title="Hapus Kwitansi" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 flex items-center justify-center transition-colors">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
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
<!-- MODAL 1: FORM ENTRI PEMBAYARAN BARU -->
<!-- ========================================================================= -->
<div id="modalEntriBayar" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Entri Pembayaran Kursus Baru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Catat pembayaran tunai, transfer, atau QRIS dari siswa.</p>
            </div>
            <button onclick="closeModal('modalEntriBayar')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_pembayaran">
            <input type="hidden" name="id_layanan" id="input_id_layanan" value="">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Siswa Terdaftar <span class="text-rose-500">*</span></label>
                <select name="id_siswa" id="select_siswa" onchange="onSiswaChange()" required class="form-input text-xs">
                    <option value="">-- Pilih Siswa --</option>
                    <?php foreach ($siswaPilihan as $sp): ?>
                        <option value="<?= $sp['id'] ?>">
                            <?= e($sp['nama_lengkap']) ?> (<?= e($sp['nis_bisolpin']) ?> - <?= e($sp['nama_layanan']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Detail Program Otomatis -->
            <div id="infoLayananBox" class="p-3 bg-slate-50 rounded-xl border border-slate-200 hidden">
                <span class="block text-[10px] text-slate-400 font-bold uppercase">Program Kursus Terdaftar:</span>
                <div class="flex items-center justify-between mt-0.5">
                    <span id="displayNamaLayanan" class="text-xs font-bold text-slate-800">-</span>
                    <span id="displayTarifLayanan" class="text-xs font-black text-teal-700">-</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Bayar <span class="text-rose-500">*</span></label>
                    <input type="date" name="tanggal_bayar" value="<?= date('Y-m-d') ?>" required class="form-input text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jumlah Bayar (Rp) <span class="text-rose-500">*</span></label>
                    <input type="number" name="jumlah_bayar" id="input_jumlah_bayar" min="1000" step="1000" placeholder="Contoh: 1850000" required class="form-input text-xs font-bold text-slate-800">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Metode Pembayaran <span class="text-rose-500">*</span></label>
                    <select name="metode_pembayaran" required class="form-input text-xs">
                        <option value="tunai">Tunai di Front Office</option>
                        <option value="transfer_bank">Transfer Bank Resmi</option>
                        <option value="qris">QRIS Bisolpin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Pembayaran <span class="text-rose-500">*</span></label>
                    <select name="status_pembayaran" required class="form-input text-xs">
                        <option value="lunas">Lunas</option>
                        <option value="cicilan">Cicilan / Bertahap</option>
                        <option value="menunggu_konfirmasi">Menunggu Konfirmasi</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Transaksi</label>
                <textarea name="catatan" rows="2" placeholder="Keterangan tambahan (cth: pelunasan tahap 2)..." class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEntriBayar')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-receipt text-xs mr-1"></i> Simpan & Cetak Kwitansi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: CETAK KWITANSI RESMI PEMBAYARAN -->
<!-- ========================================================================= -->
<div id="modalCetakKwitansi" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-black text-slate-800">Kwitansi Resmi BISOLPIN</h3>
            <button onclick="closeModal('modalCetakKwitansi')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Area Cetak Kwitansi Format Standar Lembaga -->
        <div id="printAreaKwitansi" class="mt-4 p-6 border-2 border-slate-300 rounded-xl bg-white text-slate-800 space-y-4">
            <!-- Kop Surat Kwitansi -->
            <div class="flex items-center justify-between pb-3 border-b-2 border-slate-800">
                <div class="flex items-center gap-2.5">
                    <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="w-10 h-10 object-contain">
                    <div>
                        <h4 class="text-xs font-black tracking-wider uppercase">BISOLPIN ACADEMY</h4>
                        <p class="text-[10px] text-slate-500">KURSUS & BIMBINGAN BELAJAR TATAP MUKA</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-[9px] font-mono text-slate-400 block">NOMOR KWITANSI</span>
                    <span id="kw_nomor" class="font-mono text-xs font-bold text-teal-800">-</span>
                </div>
            </div>

            <!-- Isi Kwitansi -->
            <div class="space-y-2 text-xs">
                <div class="flex">
                    <span class="w-36 text-slate-500">Telah Terima Dari:</span>
                    <span id="kw_nama" class="font-bold text-slate-800 flex-1">-</span>
                </div>
                <div class="flex">
                    <span class="w-36 text-slate-500">Nomor Induk Siswa:</span>
                    <span id="kw_nis" class="font-mono font-semibold text-slate-700 flex-1">-</span>
                </div>
                <div class="flex">
                    <span class="w-36 text-slate-500">Untuk Pembayaran:</span>
                    <span id="kw_layanan" class="font-medium text-slate-700 flex-1">-</span>
                </div>
                <div class="flex">
                    <span class="w-36 text-slate-500">Metode Pembayaran:</span>
                    <span id="kw_metode" class="uppercase font-semibold text-slate-700 flex-1">-</span>
                </div>
                <div class="flex">
                    <span class="w-36 text-slate-500">Keterangan:</span>
                    <span id="kw_catatan" class="italic text-slate-600 flex-1">-</span>
                </div>
            </div>

            <!-- Box Jumlah Uang -->
            <div class="p-3 bg-teal-50 border border-teal-200 rounded-lg flex items-center justify-between">
                <span class="text-xs font-bold text-teal-900 uppercase">JUMLAH PEMBAYARAN:</span>
                <span id="kw_jumlah" class="text-base font-black text-teal-700">Rp 0</span>
            </div>

            <!-- Tanda Tangan Kasir -->
            <div class="pt-4 flex items-center justify-between text-[11px] text-slate-600">
                <div>
                    <span class="text-[10px] text-slate-400 block">TANGGAL TRANSAKSI</span>
                    <span id="kw_tanggal" class="font-semibold">-</span>
                </div>
                <div class="text-center">
                    <span class="text-[10px] text-slate-400 block">KASIR / FRONT OFFICE</span>
                    <div class="h-10"></div>
                    <span id="kw_kasir" class="font-bold border-t border-slate-400 pt-1 block px-4">Kasir Cabang</span>
                </div>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-2">
            <button onclick="closeModal('modalCetakKwitansi')" class="btn-brand-outline text-xs py-2 px-4">
                Tutup
            </button>
            <button onclick="window.print()" class="btn-brand text-xs py-2 px-4">
                <i class="fa-solid fa-print mr-1"></i> Cetak Kwitansi
            </button>
        </div>
    </div>
</div>

<script>
const siswaMap = <?= json_encode($siswaMap) ?>;

function onSiswaChange() {
    const sel = document.getElementById('select_siswa');
    const val = sel.value;
    const box = document.getElementById('infoLayananBox');
    const inputLayanan = document.getElementById('input_id_layanan');
    const inputJumlah = document.getElementById('input_jumlah_bayar');

    if (val && siswaMap[val]) {
        const data = siswaMap[val];
        inputLayanan.value = data.id_layanan;
        document.getElementById('displayNamaLayanan').textContent = data.nama_layanan;
        document.getElementById('displayTarifLayanan').textContent = 'Tarif: Rp ' + Number(data.harga_layanan).toLocaleString('id-ID');
        if (!inputJumlah.value || inputJumlah.value == 0) {
            inputJumlah.value = data.harga_layanan;
        }
        box.classList.remove('hidden');
    } else {
        inputLayanan.value = '';
        box.classList.add('hidden');
    }
}

function cetakKwitansi(tb) {
    document.getElementById('kw_nomor').textContent = tb.kode_transaksi;
    document.getElementById('kw_nama').textContent = tb.nama_siswa;
    document.getElementById('kw_nis').textContent = tb.nis_bisolpin;
    document.getElementById('kw_layanan').textContent = tb.nama_layanan;
    document.getElementById('kw_metode').textContent = tb.metode_pembayaran.replace('_', ' ');
    document.getElementById('kw_catatan').textContent = tb.catatan || 'Pembayaran kursus tatap muka.';
    document.getElementById('kw_jumlah').textContent = 'Rp ' + Number(tb.jumlah_bayar).toLocaleString('id-ID');
    document.getElementById('kw_tanggal').textContent = tb.tanggal_bayar;
    document.getElementById('kw_kasir').textContent = tb.nama_kasir || 'Kasir Bisolpin';
    openModal('modalCetakKwitansi');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

