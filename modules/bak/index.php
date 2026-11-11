<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Standardisasi Kurikulum & Materi (BAK Pusat)
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Kurikulum & Standarisasi';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses Bagian Akademik (BAK)
check_role(ROLE_BAK);

// Tangani aksi Ceklok Mandiri jika ada
handle_attendance_post_action();

$db = get_db();

// 1. Metrik Utama BAK
$totalLayanan = (int)$db->query("SELECT COUNT(*) FROM tabel_layanan WHERE status_aktif = 'aktif'")->fetchColumn();
$totalMateriPublished = (int)$db->query("SELECT COUNT(*) FROM tabel_materi WHERE status_publikasi = 'published'")->fetchColumn();
$totalMateriDraft = (int)$db->query("SELECT COUNT(*) FROM tabel_materi WHERE status_publikasi = 'draft'")->fetchColumn();
$totalSiswa = (int)$db->query("SELECT COUNT(*) FROM tabel_siswa WHERE status_belajar = 'aktif'")->fetchColumn();

// 2. Data Grafik Sebaran Modul per Layanan
$stmtChart = $db->query("
    SELECT l.nama_layanan, COUNT(m.id) as total_modul, l.jumlah_pertemuan
    FROM tabel_layanan l
    LEFT JOIN tabel_materi m ON m.id_layanan = l.id
    WHERE l.status_aktif = 'aktif'
    GROUP BY l.id, l.nama_layanan, l.jumlah_pertemuan
    ORDER BY total_modul DESC
");
$chartData = $stmtChart->fetchAll();
$chartLabels = array_map(fn($r) => $r['nama_layanan'], $chartData);
$chartTotals = array_map(fn($r) => (int)$r['total_modul'], $chartData);
$chartTargets = array_map(fn($r) => (int)$r['jumlah_pertemuan'], $chartData);

// 3. Modul Materi Terbaru yang Diunggah
$stmtMateriTerbaru = $db->query("
    SELECT m.*, l.nama_layanan, p.nama_lengkap as nama_pembuat
    FROM tabel_materi m
    JOIN tabel_layanan l ON l.id = m.id_layanan
    LEFT JOIN tabel_pegawai p ON p.id = m.id_pembuat_bak
    ORDER BY m.created_at DESC
    LIMIT 5
");
$materiTerbaruList = $stmtMateriTerbaru->fetchAll();

// 4. Status Kelengkapan Modul per Layanan
$stmtKelengkapan = $db->query("
    SELECT l.*, 
           (SELECT COUNT(*) FROM tabel_materi m WHERE m.id_layanan = l.id AND m.status_publikasi = 'published') as modul_terbit,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_layanan = l.id AND s.status_belajar = 'aktif') as total_siswa
    FROM tabel_layanan l
    WHERE l.status_aktif = 'aktif'
    ORDER BY modul_terbit ASC
");
$kelengkapanList = $stmtKelengkapan->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan BAK -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-book-bookmark"></i>
            <span>Bagian Akademik & Standardisasi Nasional (BAK)</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Pusat Kurikulum & Modul Pembelajaran</h1>
        <p class="text-xs text-slate-500 mt-1">
            Standarisasi bahan ajar resmi, silabus tatap muka, dan instruksi mentor untuk seluruh cabang BISOLPIN.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/bak/materi.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-cloud-arrow-up text-xs"></i>
            <span>Upload Modul Baru</span>
        </a>
        <a href="<?= base_url('modules/bak/layanan.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-layer-group text-xs"></i>
            <span>Kelola Kurikulum</span>
        </a>
    </div>
</div>

<!-- Widget Ceklok Presensi Mandiri Tim BAK -->
<?php render_attendance_widget(); ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT BAK -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI 1: Program Layanan Aktif -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Program Kursus</span>
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalLayanan ?></span>
            <span class="text-xs text-slate-400 font-medium">Layanan Aktif</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/bak/layanan.php') ?>" class="text-brand-600 font-bold hover:underline">
                Struktur Kurikulum &rarr;
            </a>
        </div>
    </div>

    <!-- KPI 2: Modul Terbit Resmi -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Modul Resmi Terbit</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-file-circle-check"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalMateriPublished ?></span>
            <span class="text-xs text-slate-400 font-medium">Modul Published</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Siap diajarkan:</span>
            <span class="font-bold text-emerald-600">Oleh Semua Mentor</span>
        </div>
    </div>

    <!-- KPI 3: Modul Masih Draft -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Bahan Ajar Draft</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-file-pen"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalMateriDraft ?></span>
            <span class="text-xs text-slate-400 font-medium">Modul Draft</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Status Penyusunan:</span>
            <span class="font-bold text-amber-600">Perlu Review BAK</span>
        </div>
    </div>

    <!-- KPI 4: Siswa Belajar Materi BAK -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Sasaran Peserta</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= number_format($totalSiswa) ?></span>
            <span class="text-xs text-slate-400 font-medium">Siswa Belajar</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Standarisasi:</span>
            <span class="font-bold text-brand-600">100% Seluruh Cabang</span>
        </div>
    </div>

    <!-- GRAFIK: Sebaran Modul per Layanan (Bento Col-8) -->
    <div class="bento-card col-span-8">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-chart-column text-brand-600"></i>
                    <span>Sebaran Modul Terbit vs Target Sesi Pertemuan</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Memastikan setiap pertemuan tatap muka telah memiliki modul resmi</p>
            </div>
            <a href="<?= base_url('modules/bak/materi.php') ?>" class="text-xs font-semibold text-brand-600 hover:text-brand-700">
                Lihat Modul &rarr;
            </a>
        </div>
        <div class="h-64 relative">
            <canvas id="chartModulLayanan"></canvas>
        </div>
    </div>

    <!-- TABEL KELENGKAPAN: Status Kesiapan Kurikulum (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-list-check text-teal-600"></i>
                    <span>Kesiapan Modul Program</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Kelengkapan bahan ajar per layanan</p>
            </div>
        </div>

        <div class="space-y-3">
            <?php foreach ($kelengkapanList as $prog): ?>
                <?php
                $target = (int)$prog['jumlah_pertemuan'];
                $terbit = (int)$prog['modul_terbit'];
                $pct = $target > 0 ? round(($terbit / $target) * 100) : 0;
                ?>
                <div class="p-3 rounded-xl border border-slate-100 bg-slate-50/50">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <strong class="text-slate-800 truncate block"><?= e($prog['nama_layanan']) ?></strong>
                        <span class="font-bold text-slate-600 shrink-0 ml-2"><?= $terbit ?> / <?= $target ?> Sesi</span>
                    </div>
                    <div class="w-full bg-slate-200 rounded-full h-1.5 overflow-hidden">
                        <div class="bg-brand-500 h-1.5 rounded-full" style="width: <?= min($pct, 100) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TABEL MODUL TERBARU BAK (Bento Col-12) -->
    <div class="bento-card col-span-12">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-file-pdf text-rose-500"></i>
                    <span>Modul Pembelajaran Resmi Terbaru dari BAK</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Bahan ajar tatap muka yang siap digunakan oleh seluruh mentor di kelas</p>
            </div>
            <a href="<?= base_url('modules/bak/materi.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Kelola Seluruh Modul &rarr;
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-100 text-slate-400 uppercase tracking-wider text-[10px]">
                        <th class="pb-2.5">Kode & Modul</th>
                        <th class="pb-2.5">Program Kursus</th>
                        <th class="pb-2.5 text-center">Sesi Ke-</th>
                        <th class="pb-2.5">Panduan Mentor di Kelas</th>
                        <th class="pb-2.5">File Berkas</th>
                        <th class="pb-2.5 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($materiTerbaruList as $m): ?>
                        <tr>
                            <td class="py-3">
                                <strong class="text-slate-800 text-sm block"><?= e($m['judul_modul']) ?></strong>
                                <span class="font-mono text-slate-400 text-[11px]"><?= e($m['kode_materi']) ?> • <?= e($m['nama_pelajaran']) ?></span>
                            </td>

                            <td class="py-3 text-slate-700 font-medium">
                                <?= e($m['nama_layanan']) ?>
                            </td>

                            <td class="py-3 text-center">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-teal-50 text-teal-800 font-extrabold text-xs border border-teal-200">
                                    <?= (int)$m['pertemuan_ke'] ?>
                                </span>
                            </td>

                            <td class="py-3 text-slate-600 max-w-xs truncate" title="<?= e($m['panduan_mentor'] ?? '-') ?>">
                                <?= e($m['panduan_mentor'] ?? 'Gunakan instruksi standar KBM') ?>
                            </td>

                            <td class="py-3">
                                <?php if (!empty($m['file_modul'])): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-rose-50 text-rose-700 font-semibold text-xs border border-rose-200">
                                        <i class="fa-solid fa-file-pdf"></i>
                                        <span><?= e($m['file_modul']) ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">Belum diunggah</span>
                                <?php endif; ?>
                            </td>

                            <td class="py-3 text-right">
                                <?= render_status_badge($m['status_publikasi']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Inisialisasi Chart.js BAK -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('chartModulLayanan');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [
                    {
                        label: 'Modul Tersedia',
                        data: <?= json_encode($chartTotals) ?>,
                        backgroundColor: '#1BA89C',
                        borderRadius: 6
                    },
                    {
                        label: 'Target Sesi Pertemuan',
                        data: <?= json_encode($chartTargets) ?>,
                        backgroundColor: '#E2E8F0',
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 2 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

