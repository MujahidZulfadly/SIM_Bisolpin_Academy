<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Sarana & Prasarana Cabang
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Sarana & Prasarana';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses role SARPRAS
check_role(ROLE_SARPRAS);

// Tangani aksi Ceklok Mandiri jika ada
handle_attendance_post_action();

$db = get_db();
$branchId = get_active_branch_id() ?? 1;

// 1. Info Cabang
$stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

// 2. Metrik KPI Aset Cabang
$stmtTotalAset = $db->prepare("SELECT COUNT(*), SUM(jumlah_unit), SUM(nilai_perolehan) FROM tabel_aset WHERE id_cabang = ?");
$stmtTotalAset->execute([$branchId]);
$rowTotal = $stmtTotalAset->fetch(PDO::FETCH_NUM);
$totalItemAset = (int)($rowTotal[0] ?? 0);
$totalUnitFisik = (int)($rowTotal[1] ?? 0);
$totalNilaiAset = (float)($rowTotal[2] ?? 0);

// Aset Kondisi Baik
$stmtBaik = $db->prepare("SELECT COUNT(*), SUM(jumlah_unit) FROM tabel_aset WHERE id_cabang = ? AND kondisi = 'baik'");
$stmtBaik->execute([$branchId]);
$rowBaik = $stmtBaik->fetch(PDO::FETCH_NUM);
$countBaik = (int)($rowBaik[0] ?? 0);
$unitBaik = (int)($rowBaik[1] ?? 0);

// Aset Perlu Perbaikan
$stmtPerlu = $db->prepare("SELECT COUNT(*), SUM(jumlah_unit) FROM tabel_aset WHERE id_cabang = ? AND kondisi = 'perlu_perbaikan'");
$stmtPerlu->execute([$branchId]);
$rowPerlu = $stmtPerlu->fetch(PDO::FETCH_NUM);
$countPerlu = (int)($rowPerlu[0] ?? 0);
$unitPerlu = (int)($rowPerlu[1] ?? 0);

// Aset Rusak Berat
$stmtRusak = $db->prepare("SELECT COUNT(*), SUM(jumlah_unit) FROM tabel_aset WHERE id_cabang = ? AND kondisi = 'rusak_berat'");
$stmtRusak->execute([$branchId]);
$rowRusak = $stmtRusak->fetch(PDO::FETCH_NUM);
$countRusak = (int)($rowRusak[0] ?? 0);
$unitRusak = (int)($rowRusak[1] ?? 0);

// 3. Distribusi Aset per Kategori untuk Chart.js
$stmtKategori = $db->prepare("
    SELECT kategori_aset, COUNT(*) as total_item, SUM(jumlah_unit) as total_unit, SUM(nilai_perolehan) as total_nilai
    FROM tabel_aset
    WHERE id_cabang = ?
    GROUP BY kategori_aset
");
$stmtKategori->execute([$branchId]);
$kategoriList = $stmtKategori->fetchAll();

$chartLabels = [];
$chartDataUnits = [];
$kategoriMapLabel = [
    'ruang_kelas'  => 'Ruang Kelas / Lab',
    'perangkat_it' => 'Perangkat IT / Komputer',
    'alat_peraga'  => 'Kit Robotik / Alat Peraga',
    'furnitur'     => 'Furnitur & Meja Belajar',
    'lainnya'      => 'Fasilitas Lainnya'
];
foreach ($kategoriList as $k) {
    $chartLabels[] = $kategoriMapLabel[$k['kategori_aset']] ?? ucfirst($k['kategori_aset']);
    $chartDataUnits[] = (int)$k['total_unit'];
}

// 4. Daftar Aset yang Membutuhkan Perhatian / Perbaikan Segera
$stmtPerbaikan = $db->prepare("
    SELECT * FROM tabel_aset 
    WHERE id_cabang = ? AND kondisi IN ('perlu_perbaikan', 'rusak_berat')
    ORDER BY FIELD(kondisi, 'rusak_berat', 'perlu_perbaikan'), updated_at DESC
");
$stmtPerbaikan->execute([$branchId]);
$asetPerluPerbaikanList = $stmtPerbaikan->fetchAll();

// 5. Riwayat Log Maintenance Terakhir jika tabel_maintenance_log ada
$maintenanceTerakhirList = [];
try {
    $stmtMaint = $db->prepare("
        SELECT m.*, a.nama_aset, a.kode_aset
        FROM tabel_maintenance_log m
        JOIN tabel_aset a ON a.id = m.id_aset
        WHERE m.id_cabang = ?
        ORDER BY m.tanggal_maintenance DESC, m.id DESC
        LIMIT 5
    ");
    $stmtMaint->execute([$branchId]);
    $maintenanceTerakhirList = $stmtMaint->fetchAll();
} catch (\Throwable $e) {
    // Abaikan jika tabel maintenance log belum di-generate
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan Sarpras -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-toolbox"></i>
            <span>Manajemen Sarana, Prasarana & Fasilitas Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Dashboard Fasilitas & Sarpras - <?= e($cabang['nama_cabang'] ?? 'Cabang') ?>
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pantau ketersediaan lab, PC komputer, kit robotik praktikum tatap muka, dan catatan log servis berkala.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/sarpras/inventaris.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-boxes-stacked text-xs"></i>
            <span>Kelola Inventaris</span>
        </a>
        <a href="<?= base_url('modules/sarpras/maintenance.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-screwdriver-wrench text-xs"></i>
            <span>Log Pemeliharaan</span>
        </a>
    </div>
</div>

<!-- Widget Ceklok Presensi Mandiri Sarpras -->
<?php render_attendance_widget(); ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT SARPRAS -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI 1: Total Nilai Aset -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Nilai Fasilitas</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-coins"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-800"><?= format_rupiah($totalNilaiAset) ?></span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Item / Unit Fisik:</span>
            <span class="font-bold text-brand-600"><?= $totalItemAset ?> Item (<?= $totalUnitFisik ?> Unit)</span>
        </div>
    </div>

    <!-- KPI 2: Kondisi Prima / Baik -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Kondisi Prima (Baik)</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-emerald-600"><?= $unitBaik ?></span>
            <span class="text-xs text-slate-400 font-medium">Unit Siap Pakai</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Kesiapan Fasilitas:</span>
            <span class="font-bold text-emerald-600">
                <?= $totalUnitFisik > 0 ? round(($unitBaik / $totalUnitFisik) * 100) : 0 ?>% Operasional
            </span>
        </div>
    </div>

    <!-- KPI 3: Perlu Servis / Perbaikan -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Perlu Servis / Perbaikan</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-amber-600"><?= $unitPerlu ?></span>
            <span class="text-xs text-slate-400 font-medium">Unit Perlu Servis</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Status:</span>
            <span class="font-bold text-amber-600"><?= $countPerlu ?> Item Terdata</span>
        </div>
    </div>

    <!-- KPI 4: Rusak Berat -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Rusak Berat / Afkir</span>
            <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-ban"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-rose-600"><?= $unitRusak ?></span>
            <span class="text-xs text-slate-400 font-medium">Unit Rusak Berat</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Tindakan:</span>
            <span class="font-bold text-rose-600">Penggantian / Servis Total</span>
        </div>
    </div>

    <!-- ROW 2: Grafik Distribusi Kategori Aset (Bento 5 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-5">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Distribusi Kategori Fasilitas</h3>
                <p class="text-xs text-slate-400 mt-0.5">Komposisi jumlah unit fisik sarana belajar</p>
            </div>
        </div>
        <div class="p-4 flex items-center justify-center min-h-[260px]">
            <canvas id="kategoriAsetChart" class="max-h-[240px]"></canvas>
        </div>
    </div>

    <!-- ROW 2: Fasilitas Prioritas Perbaikan (Bento 7 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-7">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Prioritas Perbaikan Fasilitas</h3>
                <p class="text-xs text-slate-400 mt-0.5">Daftar sarana belajar yang memerlukan perbaikan dan suku cadang</p>
            </div>
            <a href="<?= base_url('modules/sarpras/maintenance.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Input Servis <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
            </a>
        </div>

        <?php if (empty($asetPerluPerbaikanList)): ?>
            <div class="p-8 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                <div class="w-12 h-12 mx-auto rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl mb-3">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h4 class="text-sm font-bold text-slate-700">Seluruh Fasilitas Dalam Kondisi Baik</h4>
                <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                    Tidak ada fasilitas, alat peraga, maupun komputer yang saat ini berstatus perlu perbaikan di cabang ini.
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($asetPerluPerbaikanList as $ap): ?>
                    <div class="p-3.5 rounded-xl border border-slate-200 bg-slate-50/70 hover:bg-white transition-all flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl <?= $ap['kondisi'] === 'rusak_berat' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' ?> flex items-center justify-center text-lg flex-shrink-0">
                                <i class="fa-solid fa-wrench"></i>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-slate-800"><?= e($ap['nama_aset']) ?></h4>
                                <p class="text-[11px] text-slate-500 font-medium">
                                    <span class="font-mono text-brand-600"><?= e($ap['kode_aset']) ?></span> &bull; 
                                    <span><?= e($ap['lokasi_penempatan']) ?></span> &bull; 
                                    <strong><?= $ap['jumlah_unit'] ?> Unit</strong>
                                </p>
                                <p class="text-[11px] text-rose-600 italic mt-0.5">
                                    Catatan: <?= e($ap['catatan_maintenance'] ?? 'Memerlukan pengecekan teknis.') ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?= render_status_badge($ap['kondisi']) ?>
                            <a href="<?= base_url('modules/sarpras/maintenance.php?id_aset=' . $ap['id']) ?>" class="btn-brand-outline text-xs py-1 px-3">
                                Servis
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ROW 3: Riwayat Log Pemeliharaan Terakhir (Bento 12 Kolom) -->
    <div class="bento-card col-span-12">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Riwayat Pemeliharaan & Servis Fasilitas</h3>
                <p class="text-xs text-slate-400 mt-0.5">Log pemeliharaan berkala, teknisi pelaksana, dan biaya servis</p>
            </div>
            <a href="<?= base_url('modules/sarpras/maintenance.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Buka Log Lengkap <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
            </a>
        </div>

        <?php if (empty($maintenanceTerakhirList)): ?>
            <div class="p-6 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400">Belum ada riwayat pencatatan servis atau pemeliharaan berkala.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 font-bold uppercase tracking-wider border-b border-slate-100 pb-2">
                            <th class="py-2.5 px-3">Tanggal Servis</th>
                            <th class="py-2.5 px-3">Nama Fasilitas & Kode</th>
                            <th class="py-2.5 px-3">Jenis Pekerjaan</th>
                            <th class="py-2.5 px-3">Teknisi Pelaksana</th>
                            <th class="py-2.5 px-3 text-right">Biaya Servis</th>
                            <th class="py-2.5 px-3 text-center">Status Akhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($maintenanceTerakhirList as $mt): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-2.5 px-3 text-slate-500 font-medium"><?= format_tanggal_indonesia($mt['tanggal_maintenance']) ?></td>
                                <td class="py-2.5 px-3 font-semibold text-slate-800">
                                    <?= e($mt['nama_aset']) ?>
                                    <span class="block text-[10px] font-mono text-slate-400"><?= e($mt['kode_aset']) ?></span>
                                </td>
                                <td class="py-2.5 px-3 text-slate-700"><?= e($mt['jenis_perbaikan']) ?></td>
                                <td class="py-2.5 px-3 text-slate-600"><?= e($mt['teknisi_pelaksana']) ?></td>
                                <td class="py-2.5 px-3 text-right font-black text-slate-800"><?= format_rupiah($mt['biaya_perbaikan']) ?></td>
                                <td class="py-2.5 px-3 text-center"><?= render_status_badge($mt['kondisi_setelah']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Inisialisasi Chart.js untuk Kategori Aset -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('kategoriAsetChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                data: <?= json_encode($chartDataUnits) ?>,
                backgroundColor: [
                    '#1BA89C', // Bisolpin Teal
                    '#0284C7', // Sky
                    '#F59E0B', // Amber
                    '#8B5CF6', // Purple
                    '#64748B'  // Slate
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        padding: 14,
                        font: { size: 11, family: "'Plus Jakarta Sans', sans-serif", weight: '600' },
                        color: '#475569'
                    }
                }
            },
            cutout: '68%'
        }
    });
});
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

