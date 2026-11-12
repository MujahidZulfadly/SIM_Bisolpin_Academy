<?php
/**
 * SIM BISOLPIN ACADEMY - Executive Bento Dashboard (Pimpinan Pusat)
 * Dilengkapi Ringkasan Keuangan Nasional & Filter Lintas Cabang (Patch V2)
 */

declare(strict_types=1);

$pageTitle = 'Executive Bento Dashboard';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Pimpinan Pusat
check_role(ROLE_PIMPINAN);

$db = get_db();

// Filter Cabang Aktif (Null = Seluruh Cabang / Nasional)
$activeBranchFilter = get_active_branch_id();
$allBranches = get_all_branches(false);

// 1. Metrik Ringkasan Utama (Terkait Jaringan & Operasional)
$totalCabangAktif = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'aktif'")->fetchColumn();
$totalCabangPending = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'pending_review'")->fetchColumn();

// Siswa & Pegawai (Dengan atau tanpa filter cabang)
if ($activeBranchFilter) {
    $stmtS = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_cabang = ? AND status_belajar = 'aktif' AND is_archived = 0");
    $stmtS->execute([$activeBranchFilter]);
    $totalSiswa = (int)$stmtS->fetchColumn();

    $stmtP = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE id_cabang = ? AND status_aktif = 'aktif'");
    $stmtP->execute([$activeBranchFilter]);
    $totalPegawai = (int)$stmtP->fetchColumn();

    $stmtA = $db->prepare("SELECT COALESCE(SUM(nilai_perolehan), 0) FROM tabel_aset WHERE id_cabang = ?");
    $stmtA->execute([$activeBranchFilter]);
    $totalNilaiAset = (float)$stmtA->fetchColumn();

    $stmtPr = $db->prepare("SELECT COUNT(*) FROM tabel_produk WHERE id_cabang = ? AND status_tampil = 'dipublikasikan'");
    $stmtPr->execute([$activeBranchFilter]);
    $totalProdukSiswa = (int)$stmtPr->fetchColumn();

    // FINANSIAL CABANG TERFILTER
    $stmtInc = $db->prepare("SELECT COALESCE(SUM(nominal_terbayar), 0) FROM tabel_siswa WHERE id_cabang = ? AND is_archived = 0");
    $stmtInc->execute([$activeBranchFilter]);
    $totalPendapatanMasuk = (float)$stmtInc->fetchColumn();

    $stmtPot = $db->prepare("SELECT COALESCE(SUM(l.harga_layanan), 0) FROM tabel_siswa s JOIN tabel_layanan l ON l.id = s.id_layanan WHERE s.id_cabang = ? AND s.is_archived = 0");
    $stmtPot->execute([$activeBranchFilter]);
    $totalPotensiOmzet = (float)$stmtPot->fetchColumn();

    $stmtPay = $db->prepare("SELECT status_pembayaran, COUNT(*) as cnt FROM tabel_siswa WHERE id_cabang = ? AND is_archived = 0 GROUP BY status_pembayaran");
    $stmtPay->execute([$activeBranchFilter]);
} else {
    $totalSiswa = (int)$db->query("SELECT COUNT(*) FROM tabel_siswa WHERE status_belajar = 'aktif' AND is_archived = 0")->fetchColumn();
    $totalPegawai = (int)$db->query("SELECT COUNT(*) FROM tabel_pegawai WHERE status_aktif = 'aktif'")->fetchColumn();
    $totalNilaiAset = (float)$db->query("SELECT COALESCE(SUM(nilai_perolehan), 0) FROM tabel_aset")->fetchColumn();
    $totalProdukSiswa = (int)$db->query("SELECT COUNT(*) FROM tabel_produk WHERE status_tampil = 'dipublikasikan'")->fetchColumn();

    // FINANSIAL NASIONAL
    $totalPendapatanMasuk = (float)$db->query("SELECT COALESCE(SUM(nominal_terbayar), 0) FROM tabel_siswa WHERE is_archived = 0")->fetchColumn();
    $totalPotensiOmzet = (float)$db->query("SELECT COALESCE(SUM(l.harga_layanan), 0) FROM tabel_siswa s JOIN tabel_layanan l ON l.id = s.id_layanan WHERE s.is_archived = 0")->fetchColumn();
    $stmtPay = $db->query("SELECT status_pembayaran, COUNT(*) as cnt FROM tabel_siswa WHERE is_archived = 0 GROUP BY status_pembayaran");
}

// Rekap Status Pembayaran Siswa
$countLunas = 0;
$countCicilan = 0;
$countBelumBayar = 0;
foreach ($stmtPay->fetchAll() as $pr) {
    if ($pr['status_pembayaran'] === 'lunas') $countLunas = (int)$pr['cnt'];
    elseif ($pr['status_pembayaran'] === 'cicilan') $countCicilan = (int)$pr['cnt'];
    elseif ($pr['status_pembayaran'] === 'belum_bayar') $countBelumBayar = (int)$pr['cnt'];
}
$totalSemuaSiswa = max(1, $countLunas + $countCicilan + $countBelumBayar);
$persenLunas = round(($countLunas / $totalSemuaSiswa) * 100);
$persenRealisasi = $totalPotensiOmzet > 0 ? round(($totalPendapatanMasuk / $totalPotensiOmzet) * 100, 1) : 0;

// 2. Data Grafik Siswa per Cabang
$stmtChartSiswa = $db->query("
    SELECT c.nama_cabang, COUNT(s.id) as total_siswa
    FROM tabel_cabang c
    LEFT JOIN tabel_siswa s ON s.id_cabang = c.id AND s.status_belajar = 'aktif' AND s.is_archived = 0
    WHERE c.status_mitra = 'aktif'
    GROUP BY c.id, c.nama_cabang
    ORDER BY total_siswa DESC
");
$chartSiswaData = $stmtChartSiswa->fetchAll();
$chartCabangLabels = array_map(fn($row) => $row['nama_cabang'], $chartSiswaData);
$chartCabangTotals = array_map(fn($row) => (int)$row['total_siswa'], $chartSiswaData);

// 3. Data Kondisi Aset Nasional
$stmtAsetKondisi = $db->query("
    SELECT kondisi, COUNT(*) as total 
    FROM tabel_aset 
    GROUP BY kondisi
");
$kondisiMap = ['baik' => 0, 'perlu_perbaikan' => 0, 'rusak_berat' => 0];
foreach ($stmtAsetKondisi->fetchAll() as $row) {
    $kondisiMap[$row['kondisi']] = (int)$row['total'];
}

// 4. Daftar Kemitraan Menunggu Verifikasi
$stmtPending = $db->query("
    SELECT c.*, u.username as username_pemohon
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    WHERE c.status_mitra = 'pending_review'
    ORDER BY c.created_at DESC
    LIMIT 5
");
$pendingMitraList = $stmtPending->fetchAll();

// 5. Produk Siswa Terbaru
$stmtProdukTerbaru = $db->query("
    SELECT p.*, s.nama_lengkap as nama_siswa, c.nama_cabang, m.nama_lengkap as nama_mentor
    FROM tabel_produk p
    LEFT JOIN tabel_siswa s ON s.id = p.id_siswa
    LEFT JOIN tabel_cabang c ON c.id = p.id_cabang
    LEFT JOIN tabel_pegawai m ON m.id = p.id_mentor_validator
    ORDER BY p.created_at DESC
    LIMIT 4
");
$produkTerbaruList = $stmtProdukTerbaru->fetchAll();

// 6. Indeks Kepuasan Pengajaran Nasional (IKM Engine)
ensure_table_evaluasi_mentor_exists($db);
$avgIkm = 0.0;
$avgIkmMateri = 0.0;
$avgIkmPenyampaian = 0.0;
$avgIkmDisiplin = 0.0;
$avgIkmSabar = 0.0;
$totalIkmEvaluasi = 0;
$ikmBadge = get_ikm_badge(0);

try {
    if ($activeBranchFilter) {
        $stmtIkm = $db->prepare("
            SELECT 
                COALESCE(AVG(nilai_rata_rata), 0) as avg_ikm,
                COALESCE(AVG(skor_materi), 0) as avg_materi,
                COALESCE(AVG(skor_penyampaian), 0) as avg_penyampaian,
                COALESCE(AVG(skor_kedisiplinan), 0) as avg_kedisiplinan,
                COALESCE(AVG(skor_kesabaran), 0) as avg_kesabaran,
                COUNT(*) as total_evaluasi
            FROM tabel_evaluasi_mentor 
            WHERE id_cabang = ?
        ");
        $stmtIkm->execute([$activeBranchFilter]);
    } else {
        $stmtIkm = $db->query("
            SELECT 
                COALESCE(AVG(nilai_rata_rata), 0) as avg_ikm,
                COALESCE(AVG(skor_materi), 0) as avg_materi,
                COALESCE(AVG(skor_penyampaian), 0) as avg_penyampaian,
                COALESCE(AVG(skor_kedisiplinan), 0) as avg_kedisiplinan,
                COALESCE(AVG(skor_kesabaran), 0) as avg_kesabaran,
                COUNT(*) as total_evaluasi
            FROM tabel_evaluasi_mentor
        ");
    }
    $ikmData = $stmtIkm->fetch();
    $avgIkm = round((float)($ikmData['avg_ikm'] ?? 0), 2);
    $avgIkmMateri = round((float)($ikmData['avg_materi'] ?? 0), 2);
    $avgIkmPenyampaian = round((float)($ikmData['avg_penyampaian'] ?? 0), 2);
    $avgIkmDisiplin = round((float)($ikmData['avg_kedisiplinan'] ?? 0), 2);
    $avgIkmSabar = round((float)($ikmData['avg_kesabaran'] ?? 0), 2);
    $totalIkmEvaluasi = (int)($ikmData['total_evaluasi'] ?? 0);
    $ikmBadge = get_ikm_badge($avgIkm);
} catch (\Throwable $e) {
    error_log("Error query IKM di pimpinan/index.php: " . $e->getMessage());
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan & Action Shortcut -->
<div class="mb-8 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-100 text-purple-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-crown text-purple-600"></i>
            <span>Executive Command Center Nasional</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Executive Dashboard Nasional</h1>
        <p class="text-xs text-slate-500 mt-1">
            Pantauan performa keuangan, ekspansi kemitraan cabang, dan operasional kursus offline BISOLPIN.
        </p>
    </div>

    <!-- FILTER CABANG TERPUSAT & SHORTCUT -->
    <div class="flex flex-wrap items-center gap-2.5">
        <!-- Selector Cabang -->
        <div class="flex items-center bg-white border border-slate-200 rounded-xl px-3 py-1.5 shadow-sm">
            <span class="text-xs font-bold text-slate-500 mr-2 flex items-center gap-1">
                <i class="fa-solid fa-location-dot text-brand-600"></i> Filter Cabang:
            </span>
            <select onchange="window.location.href='?switch_branch=' + this.value" class="text-xs font-bold text-slate-800 bg-transparent focus:outline-none cursor-pointer">
                <option value="all" <?= empty($activeBranchFilter) ? 'selected' : '' ?>>🌐 Seluruh Cabang (Nasional)</option>
                <?php foreach ($allBranches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= $activeBranchFilter === (int)$b['id'] ? 'selected' : '' ?>>
                        📍 <?= e($b['nama_cabang']) ?> (<?= e($b['kota']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($totalCabangPending > 0): ?>
            <a href="<?= base_url('modules/pimpinan/verifikasi_mitra.php') ?>" class="btn-brand bg-amber-500 hover:bg-amber-600 shadow-amber-500/20 text-xs py-2 px-3">
                <i class="fa-solid fa-bell animate-bounce mr-1"></i>
                <span><?= $totalCabangPending ?> Kemitraan Menunggu</span>
            </a>
        <?php endif; ?>

        <a href="<?= base_url('modules/pimpinan/monitoring_cabang.php') ?>" class="btn-brand text-xs py-2 px-3">
            <i class="fa-solid fa-building-columns text-xs mr-1"></i>
            <span>Monitoring Cabang</span>
        </a>
    </div>
</div>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI FINANSIAL 1: TOTAL PENDAPATAN KURSUS MASUK (Bento Col-4) -->
    <div class="bento-card col-span-4 bg-gradient-to-br from-emerald-900 via-emerald-800 to-teal-900 text-white border-0 shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -bottom-8 w-36 h-36 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Total Pendapatan Kursus Masuk</span>
            <div class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center text-lg text-emerald-300">
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-2xl sm:text-3xl font-black tracking-tight text-white"><?= format_rupiah($totalPendapatanMasuk) ?></span>
        </div>
        <div class="mt-3 pt-3 border-t border-white/15 flex items-center justify-between text-xs text-emerald-100">
            <span>Realisasi dari Potensi:</span>
            <span class="font-bold text-amber-300"><?= $persenRealisasi ?>% (<?= format_rupiah($totalPotensiOmzet) ?>)</span>
        </div>
    </div>

    <!-- KPI FINANSIAL 2: STATUS PEMBAYARAN SISWA (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Status Pembayaran Siswa</span>
            <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
        </div>
        
        <!-- Statistik Segmented Box -->
        <div class="grid grid-cols-3 gap-2 my-1">
            <div class="p-2.5 rounded-xl bg-emerald-50 border border-emerald-100 text-center">
                <span class="block text-lg font-black text-emerald-700"><?= $countLunas ?></span>
                <span class="text-[10px] font-bold text-emerald-800 uppercase">Lunas</span>
            </div>
            <div class="p-2.5 rounded-xl bg-amber-50 border border-amber-100 text-center">
                <span class="block text-lg font-black text-amber-700"><?= $countCicilan ?></span>
                <span class="text-[10px] font-bold text-amber-800 uppercase">Cicilan</span>
            </div>
            <div class="p-2.5 rounded-xl bg-rose-50 border border-rose-100 text-center">
                <span class="block text-lg font-black text-rose-700"><?= $countBelumBayar ?></span>
                <span class="text-[10px] font-bold text-rose-800 uppercase">Belum Bayar</span>
            </div>
        </div>

        <div class="mt-3 pt-2.5 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Rasio Pelunasan:</span>
            <span class="font-bold text-emerald-600"><?= $persenLunas ?>% Siswa Selesai Administrasi</span>
        </div>
    </div>

    <!-- KPI 3: Cabang Aktif & Pending (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Jaringan Cabang Kemitraan</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-building-columns"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalCabangAktif ?></span>
            <span class="text-xs text-slate-400 font-medium">Cabang Beroperasi</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Status Pengajuan Baru:</span>
            <span class="font-bold <?= $totalCabangPending > 0 ? 'text-amber-600' : 'text-slate-400' ?>">
                <?= $totalCabangPending ?> Permohonan
            </span>
        </div>
    </div>

    <!-- KPI 4: Peserta Kursus Aktif (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Peserta Kursus Aktif</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= number_format($totalSiswa) ?></span>
            <span class="text-xs text-slate-400 font-medium">Siswa Terdaftar</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Metode Pengajaran:</span>
            <span class="font-bold text-brand-600">100% Tatap Muka Offline</span>
        </div>
    </div>

    <!-- KPI 5: SDM & Mentor (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">SDM & Tenaga Pendidik</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalPegawai ?></span>
            <span class="text-xs text-slate-400 font-medium">Pegawai & Mentor</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Standarisasi Modul:</span>
            <span class="font-bold text-slate-700">Tersertifikasi BAK Pusat</span>
        </div>
    </div>

    <!-- KPI 6: Total Nilai Aset Fisik (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Nilai Fasilitas Aset</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-boxes-stacked"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-xl sm:text-2xl font-black text-slate-800"><?= format_rupiah($totalNilaiAset) ?></span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Fasilitas Prima:</span>
            <span class="font-bold text-emerald-600"><?= $kondisiMap['baik'] ?> Unit Terawat</span>
        </div>
    </div>

    <!-- KPI 7: INDEKS KEPUASAN PENGAJARAN NASIONAL (IKM) (Bento Col-12) -->
    <div class="bento-card col-span-12 bg-gradient-to-r from-slate-900 via-slate-800 to-indigo-950 text-white border-0 shadow-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 rounded-full bg-brand-500/10 blur-2xl pointer-events-none"></div>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <!-- Left Info -->
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl bg-amber-400/20 text-amber-300 flex items-center justify-center text-2xl shrink-0 backdrop-blur border border-amber-400/30 shadow-inner">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-bold uppercase tracking-wider text-amber-300">Indeks Kepuasan Pengajaran <?= $activeBranchFilter ? 'Cabang' : 'Nasional' ?> (IKM)</span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold border <?= $ikmBadge['badge'] ?>">
                            <i class="fa-solid <?= $ikmBadge['icon'] ?>"></i>
                            <span><?= e($ikmBadge['label']) ?></span>
                        </span>
                    </div>
                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl sm:text-4xl font-black tracking-tight text-white"><?= number_format($avgIkm, 2) ?></span>
                        <span class="text-sm text-slate-300 font-semibold">/ 5.00</span>
                        <div class="flex items-center gap-1 text-amber-400 text-sm ml-2">
                            <?= render_star_rating($avgIkm) ?>
                        </div>
                    </div>
                    <p class="text-xs text-slate-300 mt-1">
                        Akumulasi penilaian siswa tatap muka dari total <strong class="text-white"><?= number_format($totalIkmEvaluasi) ?> ulasan</strong> <?= $activeBranchFilter ? 'di cabang ini' : 'di seluruh cabang' ?>.
                    </p>
                </div>
            </div>

            <!-- Right 4 Aspects & CTA -->
            <div class="flex flex-col sm:flex-row items-center gap-4">
                <div class="grid grid-cols-4 gap-2 text-center bg-white/10 backdrop-blur p-2.5 rounded-2xl border border-white/10 text-xs w-full sm:w-auto">
                    <div class="px-2">
                        <span class="block text-[10px] text-slate-300">Materi</span>
                        <strong class="text-amber-300 text-sm"><?= number_format($avgIkmMateri, 2) ?></strong>
                    </div>
                    <div class="px-2 border-l border-white/10">
                        <span class="block text-[10px] text-slate-300">Penyampaian</span>
                        <strong class="text-teal-300 text-sm"><?= number_format($avgIkmPenyampaian, 2) ?></strong>
                    </div>
                    <div class="px-2 border-l border-white/10">
                        <span class="block text-[10px] text-slate-300">Disiplin</span>
                        <strong class="text-sky-300 text-sm"><?= number_format($avgIkmDisiplin, 2) ?></strong>
                    </div>
                    <div class="px-2 border-l border-white/10">
                        <span class="block text-[10px] text-slate-300">Kesabaran</span>
                        <strong class="text-emerald-300 text-sm"><?= number_format($avgIkmSabar, 2) ?></strong>
                    </div>
                </div>

                <a href="<?= base_url('modules/pimpinan/monitoring_cabang.php#section-ikm') ?>" class="px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-md transition-all whitespace-nowrap flex items-center gap-2">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Tinjau Perbandingan Cabang &rarr;</span>
                </a>
            </div>
        </div>
    </div>

    <!-- GRAFIK 1: Persebaran Siswa per Cabang (Bento Col-7) -->
    <div class="bento-card col-span-7">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-chart-simple text-brand-600"></i>
                    <span>Sebaran Siswa per Cabang</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Perbandingan jumlah siswa aktif yang mengikuti kursus offline</p>
            </div>
            <a href="<?= base_url('modules/pimpinan/siswa_nasional.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Lihat Semua &rarr;
            </a>
        </div>
        <div class="h-64 mt-2">
            <canvas id="chartSiswaCabang"></canvas>
        </div>
    </div>

    <!-- GRAFIK 2: Kondisi Fasilitas & Sarpras Nasional (Bento Col-5) -->
    <div class="bento-card col-span-5">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-chart-pie text-brand-600"></i>
                    <span>Kondisi Aset & Sarpras</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Proporsi kelayakan sarana belajar tatap muka</p>
            </div>
            <a href="<?= base_url('modules/pimpinan/aset_nasional.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Audit Aset &rarr;
            </a>
        </div>
        <div class="h-64 mt-2 flex items-center justify-center">
            <canvas id="chartKondisiAset"></canvas>
        </div>
    </div>

    <!-- TABEL 1: Permohonan Kemitraan Menunggu Verifikasi (Bento Col-6) -->
    <div class="bento-card col-span-6">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-user-check text-amber-500"></i>
                    <span>Pendaftaran Kemitraan Baru</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Calon Direktur Cabang yang menunggu persetujuan pusat</p>
            </div>
            <a href="<?= base_url('modules/pimpinan/verifikasi_mitra.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Proses Verifikasi &rarr;
            </a>
        </div>

        <?php if (empty($pendingMitraList)): ?>
            <div class="py-12 text-center text-slate-400 text-xs">
                <i class="fa-solid fa-circle-check text-3xl mb-2 text-emerald-400"></i>
                <p>Semua permohonan kemitraan cabang telah diproses.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($pendingMitraList as $mitra): ?>
                    <div class="py-3 flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <h4 class="font-bold text-slate-800 text-xs truncate"><?= e($mitra['nama_cabang']) ?></h4>
                            <p class="text-[11px] text-slate-400"><?= e($mitra['kota']) ?>, <?= e($mitra['provinsi']) ?></p>
                            <span class="text-[10px] text-brand-600 font-mono"><?= e($mitra['telepon']) ?></span>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a href="<?= base_url('modules/pimpinan/verifikasi_mitra.php') ?>" class="px-3 py-1.5 rounded-xl bg-amber-50 text-amber-700 hover:bg-amber-100 font-bold text-xs border border-amber-200 transition-all">
                                Review MOU &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- LIST 2: Etalase Karya Siswa Unggulan Nasional (Bento Col-6) -->
    <div class="bento-card col-span-6">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-star text-amber-500"></i>
                    <span>Etalase Produk Unggulan Siswa</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Output nyata hasil pembelajaran tatap muka di cabang</p>
            </div>
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">
                <?= $totalProdukSiswa ?> Karya Publik
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
            <?php foreach ($produkTerbaruList as $prod): ?>
                <div class="p-3 rounded-2xl border border-slate-100 bg-slate-50/50 hover:bg-slate-50 transition-all flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-[10px] text-slate-400 mb-1">
                            <span class="font-mono"><?= e($prod['nama_cabang'] ?? 'Cabang') ?></span>
                            <?php if ($prod['nilai_karya']): ?>
                                <span class="font-bold text-amber-600"><i class="fa-solid fa-star text-[9px]"></i> <?= (float)$prod['nilai_karya'] ?></span>
                            <?php endif; ?>
                        </div>
                        <h4 class="font-bold text-slate-800 text-xs line-clamp-1" title="<?= e($prod['nama_produk']) ?>">
                            <?= e($prod['nama_produk']) ?>
                        </h4>
                        <p class="text-[11px] text-slate-500 line-clamp-2 mt-1">
                            <?= e($prod['deskripsi_produk']) ?>
                        </p>
                    </div>
                    <div class="mt-3 pt-2 border-t border-slate-200/60 flex items-center justify-between text-[10px]">
                        <span class="text-slate-600 font-semibold truncate max-w-[120px]"><?= e($prod['nama_siswa']) ?></span>
                        <span class="text-emerald-600 font-bold">Terverifikasi</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Inisialisasi Grafik Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Grafik Batang: Siswa per Cabang
    const ctxSiswa = document.getElementById('chartSiswaCabang').getContext('2d');
    new Chart(ctxSiswa, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartCabangLabels) ?>,
            datasets: [{
                label: 'Jumlah Siswa Aktif',
                data: <?= json_encode($chartCabangTotals) ?>,
                backgroundColor: '#1E40AF',
                borderRadius: 8,
                barThickness: 28,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, font: { family: 'Plus Jakarta Sans', size: 11 } },
                    grid: { color: '#F1F5F9' }
                },
                x: {
                    ticks: { font: { family: 'Plus Jakarta Sans', size: 11 } },
                    grid: { display: false }
                }
            }
        }
    });

    // 2. Grafik Donat: Kondisi Aset
    const ctxAset = document.getElementById('chartKondisiAset').getContext('2d');
    new Chart(ctxAset, {
        type: 'doughnut',
        data: {
            labels: ['Kondisi Baik', 'Perlu Perbaikan', 'Rusak Berat'],
            datasets: [{
                data: [
                    <?= $kondisiMap['baik'] ?>,
                    <?= $kondisiMap['perlu_perbaikan'] ?>,
                    <?= $kondisiMap['rusak_berat'] ?>
                ],
                backgroundColor: ['#10B981', '#F59E0B', '#F43F5E'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { family: 'Plus Jakarta Sans', size: 11 } }
                }
            },
            cutout: '72%'
        }
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
