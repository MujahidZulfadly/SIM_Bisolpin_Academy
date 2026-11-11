<?php
/**
 * SIM BISOLPIN ACADEMY - Branch Executive Bento Dashboard (Direktur Cabang)
 * Dilengkapi Ringkasan Keuangan Cabang, Status Pembayaran Siswa, dan Isolasi Multi-Tenant
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Direktur Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses Direktur Cabang
check_role(ROLE_DIREKTUR_CABANG);

// Tangani aksi Ceklok Mandiri jika ada
handle_attendance_post_action();

$branchId = auth_branch_id();
if (!$branchId) {
    die("Akses Ditolak: Akun Anda tidak terafiliasi dengan cabang resmi manapun.");
}

// Keamanan isolasi tenant cabang
verify_branch_access($branchId);

$db = get_db();

// 1. Data Cabang Aktif
$stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

if (!$cabang || $cabang['status_mitra'] !== 'aktif') {
    die("<div style='font-family:sans-serif;padding:30px;max-width:600px;margin:60px auto;border:1px solid #fcd34d;border-radius:16px;background:#fffbeb;color:#92400e;'>"
        . "<h2 style='margin-top:0;'>⏳ Kemitraan Cabang Belum Aktif</h2>"
        . "<p>Status kemitraan cabang <strong>" . e($cabang['nama_cabang'] ?? 'Cabang') . "</strong> saat ini: <code>" . e($cabang['status_mitra'] ?? 'pending_review') . "</code>.</p>"
        . "<p>Mohon menunggu verifikasi dari Pimpinan Pusat BISOLPIN.</p>"
        . "<a href='" . base_url('modules/auth/logout.php') . "' style='display:inline-block;padding:10px 18px;background:#1BA89C;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;'>Keluar</a>"
        . "</div>");
}

// 2. Metrik Operasional Utama Cabang
$stmtTotalSiswa = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_cabang = ? AND status_belajar = 'aktif' AND is_archived = 0");
$stmtTotalSiswa->execute([$branchId]);
$totalSiswa = (int)$stmtTotalSiswa->fetchColumn();

$stmtTotalMentor = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE id_cabang = ? AND jabatan_role = 'mentor' AND status_aktif = 'aktif'");
$stmtTotalMentor->execute([$branchId]);
$totalMentor = (int)$stmtTotalMentor->fetchColumn();

$stmtTotalPegawai = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE id_cabang = ? AND status_aktif = 'aktif'");
$stmtTotalPegawai->execute([$branchId]);
$totalPegawai = (int)$stmtTotalPegawai->fetchColumn();

$stmtTotalAset = $db->prepare("SELECT COALESCE(SUM(nilai_perolehan), 0) FROM tabel_aset WHERE id_cabang = ?");
$stmtTotalAset->execute([$branchId]);
$totalNilaiAset = (float)$stmtTotalAset->fetchColumn();

$stmtCountAset = $db->prepare("SELECT COUNT(*) FROM tabel_aset WHERE id_cabang = ?");
$stmtCountAset->execute([$branchId]);
$countAset = (int)$stmtCountAset->fetchColumn();

$stmtTotalProduk = $db->prepare("SELECT COUNT(*) FROM tabel_produk WHERE id_cabang = ? AND status_tampil = 'dipublikasikan'");
$stmtTotalProduk->execute([$branchId]);
$totalProduk = (int)$stmtTotalProduk->fetchColumn();

// 3. Metrik Finansial Cabang (Patch V2)
$stmtIncome = $db->prepare("SELECT COALESCE(SUM(nominal_terbayar), 0) FROM tabel_siswa WHERE id_cabang = ? AND is_archived = 0");
$stmtIncome->execute([$branchId]);
$totalPendapatanCabang = (float)$stmtIncome->fetchColumn();

$stmtPotensi = $db->prepare("SELECT COALESCE(SUM(l.harga_layanan), 0) FROM tabel_siswa s JOIN tabel_layanan l ON l.id = s.id_layanan WHERE s.id_cabang = ? AND s.is_archived = 0");
$stmtPotensi->execute([$branchId]);
$totalPotensiCabang = (float)$stmtPotensi->fetchColumn();

$stmtPay = $db->prepare("SELECT status_pembayaran, COUNT(*) as cnt FROM tabel_siswa WHERE id_cabang = ? AND is_archived = 0 GROUP BY status_pembayaran");
$stmtPay->execute([$branchId]);
$payRows = $stmtPay->fetchAll();

$countLunas = 0;
$countCicilan = 0;
$countBelumBayar = 0;
foreach ($payRows as $pr) {
    if ($pr['status_pembayaran'] === 'lunas') $countLunas = (int)$pr['cnt'];
    elseif ($pr['status_pembayaran'] === 'cicilan') $countCicilan = (int)$pr['cnt'];
    elseif ($pr['status_pembayaran'] === 'belum_bayar') $countBelumBayar = (int)$pr['cnt'];
}
$totalSemuaSiswa = max(1, $countLunas + $countCicilan + $countBelumBayar);
$persenLunas = round(($countLunas / $totalSemuaSiswa) * 100);
$persenRealisasi = $totalPotensiCabang > 0 ? round(($totalPendapatanCabang / $totalPotensiCabang) * 100, 1) : 0;

// 4. Jadwal Kelas Tatap Muka di Cabang Ini
$stmtJadwal = $db->prepare("
    SELECT j.*, l.nama_layanan, l.kode_layanan, m.nama_lengkap as nama_mentor, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    JOIN tabel_pegawai m ON m.id = j.id_mentor
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_cabang = ? AND j.status = 'aktif'
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC
");
$stmtJadwal->execute([$branchId]);
$jadwalList = $stmtJadwal->fetchAll();

// 5. Rekap Absensi Siswa Terbaru di Cabang
$stmtAbsensi = $db->prepare("
    SELECT a.*, s.nama_lengkap as nama_siswa, m.judul_modul
    FROM tabel_absensi a
    JOIN tabel_siswa s ON s.id = a.id_siswa
    LEFT JOIN tabel_materi m ON m.id = a.id_materi_pertemuan
    WHERE a.id_cabang = ? AND a.tipe_absensi = 'siswa'
    ORDER BY a.tanggal_absensi DESC, a.jam_masuk DESC
    LIMIT 5
");
$stmtAbsensi->execute([$branchId]);
$absensiList = $stmtAbsensi->fetchAll();

// 6. Produk / Karya Siswa Terbaru di Cabang Ini
$stmtProduk = $db->prepare("
    SELECT p.*, s.nama_lengkap as nama_siswa, l.nama_layanan
    FROM tabel_produk p
    JOIN tabel_siswa s ON s.id = p.id_siswa
    LEFT JOIN tabel_layanan l ON l.id = p.id_layanan
    WHERE p.id_cabang = ?
    ORDER BY p.created_at DESC
    LIMIT 3
");
$stmtProduk->execute([$branchId]);
$produkList = $stmtProduk->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan Cabang -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 text-indigo-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-building-circle-check"></i>
            <span>Portal Manajemen Mitra Cabang Resmi</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight"><?= e($cabang['nama_cabang']) ?></h1>
        <p class="text-xs text-slate-500 mt-1 flex items-center gap-2">
            <span>Kode Cabang: <strong class="font-mono text-slate-700"><?= e($cabang['kode_cabang']) ?></strong></span>
            <span>•</span>
            <span>Bergabung sejak: <?= format_tanggal_indonesia($cabang['tanggal_bergabung']) ?></span>
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/direktur_cabang/laporan.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-print text-xs mr-1"></i>
            <span>Cetak Laporan Cabang</span>
        </a>
    </div>
</div>

<!-- Widget Ceklok Presensi Mandiri Direktur Cabang -->
<?php render_attendance_widget(); ?>

<?php if ($totalPegawai === 0 || $countAset === 0 || $totalSiswa === 0): ?>
    <!-- ========================================================================= -->
    <!-- PANDUAN LANGKAH AWAL CABANG BARU (CHECKLIST 1-2-3 ONBOARDING) -->
    <!-- ========================================================================= -->
    <div class="mb-8 p-6 rounded-3xl bg-white border border-teal-200 shadow-sm relative overflow-hidden">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-teal-50 text-teal-700 text-xs font-bold border border-teal-200 mb-1.5">
                    <i class="fa-solid fa-list-check"></i>
                    <span>Panduan Onboarding Cabang Baru</span>
                </div>
                <h3 class="text-base font-black text-slate-800">Checklist Kesiapan Operasional Cabang</h3>
                <p class="text-xs text-slate-500 mt-0.5">Selesaikan 3 langkah awal ini untuk memastikan cabang Anda siap menjalankan kegiatan belajar mengajar.</p>
            </div>
            <div class="flex items-center gap-2">
                <?php
                $completedSteps = ($totalPegawai > 0 ? 1 : 0) + ($countAset > 0 ? 1 : 0) + ($totalSiswa > 0 ? 1 : 0);
                $stepPercentage = round(($completedSteps / 3) * 100);
                ?>
                <div class="text-right">
                    <span class="text-xs font-bold text-slate-700 block"><?= $completedSteps ?> dari 3 Selesai</span>
                    <span class="text-[10px] text-teal-600 font-bold"><?= $stepPercentage ?>% Kesiapan</span>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-700 font-black text-sm flex items-center justify-center border border-teal-200">
                    <?= $completedSteps ?>/3
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5">
            <!-- Langkah 1: Tim SDM & Mentor -->
            <div class="p-4 rounded-2xl border <?= $totalPegawai > 0 ? 'bg-emerald-50/50 border-emerald-200' : 'bg-slate-50 border-slate-200' ?> flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="w-7 h-7 rounded-lg <?= $totalPegawai > 0 ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' ?> font-black text-xs flex items-center justify-center">1</span>
                        <?php if ($totalPegawai > 0): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <i class="fa-solid fa-circle-check"></i> <?= $totalPegawai ?> Pegawai
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                <i class="fa-solid fa-circle-exclamation"></i> Belum Ada
                            </span>
                        <?php endif; ?>
                    </div>
                    <h4 class="text-sm font-bold text-slate-800">Daftarkan Tim Pegawai</h4>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">Tambahkan akun login untuk Staff Front Office, Mentor Pengajar, dan Petugas Sarpras.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-200/60">
                    <a href="<?= base_url('modules/direktur_cabang/pegawai.php') ?>" class="text-xs font-bold <?= $totalPegawai > 0 ? 'text-emerald-700 hover:text-emerald-800' : 'text-teal-600 hover:text-teal-700' ?> inline-flex items-center gap-1">
                        <span><?= $totalPegawai > 0 ? 'Lihat Tim Pegawai' : 'Tambah Pegawai Sekarang' ?></span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>
            </div>

            <!-- Langkah 2: Fasilitas & Ruang Kelas -->
            <div class="p-4 rounded-2xl border <?= $countAset > 0 ? 'bg-emerald-50/50 border-emerald-200' : 'bg-slate-50 border-slate-200' ?> flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="w-7 h-7 rounded-lg <?= $countAset > 0 ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' ?> font-black text-xs flex items-center justify-center">2</span>
                        <?php if ($countAset > 0): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <i class="fa-solid fa-circle-check"></i> <?= $countAset ?> Aset
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                <i class="fa-solid fa-circle-exclamation"></i> Belum Ada
                            </span>
                        <?php endif; ?>
                    </div>
                    <h4 class="text-sm font-bold text-slate-800">Data Ruang & Aset Kelas</h4>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">Catat ruang kelas, laptop komputer lab, proyektor, dan sarana belajar tatap muka.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-200/60">
                    <a href="<?= base_url('modules/direktur_cabang/aset.php') ?>" class="text-xs font-bold <?= $countAset > 0 ? 'text-emerald-700 hover:text-emerald-800' : 'text-teal-600 hover:text-teal-700' ?> inline-flex items-center gap-1">
                        <span><?= $countAset > 0 ? 'Lihat Fasilitas & Ruang' : 'Catat Aset Sekarang' ?></span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>
            </div>

            <!-- Langkah 3: Pendaftaran Siswa -->
            <div class="p-4 rounded-2xl border <?= $totalSiswa > 0 ? 'bg-emerald-50/50 border-emerald-200' : 'bg-slate-50 border-slate-200' ?> flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="w-7 h-7 rounded-lg <?= $totalSiswa > 0 ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' ?> font-black text-xs flex items-center justify-center">3</span>
                        <?php if ($totalSiswa > 0): ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700">
                                <i class="fa-solid fa-circle-check"></i> <?= $totalSiswa ?> Siswa
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full border border-amber-200">
                                <i class="fa-solid fa-circle-exclamation"></i> Belum Ada
                            </span>
                        <?php endif; ?>
                    </div>
                    <h4 class="text-sm font-bold text-slate-800">Pendaftaran Siswa Baru</h4>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">Terima siswa perdana dan tentukan jadwal kelas tatap muka beserta mentor pembimbingnya.</p>
                </div>
                <div class="mt-4 pt-3 border-t border-slate-200/60">
                    <a href="<?= base_url('modules/direktur_cabang/siswa.php') ?>" class="text-xs font-bold <?= $totalSiswa > 0 ? 'text-emerald-700 hover:text-emerald-800' : 'text-teal-600 hover:text-teal-700' ?> inline-flex items-center gap-1">
                        <span><?= $totalSiswa > 0 ? 'Lihat Siswa Aktif' : 'Buka Menu Siswa' ?></span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT DIREKTUR CABANG -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI FINANSIAL 1: TOTAL PENDAPATAN KURSUS CABANG (Bento Col-4) -->
    <div class="bento-card col-span-4 bg-gradient-to-br from-emerald-900 via-emerald-800 to-teal-900 text-white border-0 shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -bottom-8 w-36 h-36 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-200">Total Pendapatan Kursus Cabang</span>
            <div class="w-10 h-10 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center text-lg text-emerald-300">
                <i class="fa-solid fa-cash-register"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2 mb-2">
            <span class="text-2xl sm:text-3xl font-black tracking-tight text-white"><?= format_rupiah($totalPendapatanCabang) ?></span>
        </div>
        <div class="mt-3 pt-3 border-t border-white/15 flex items-center justify-between text-xs text-emerald-100">
            <span>Target Omzet Cabang:</span>
            <span class="font-bold text-amber-300"><?= $persenRealisasi ?>% (<?= format_rupiah($totalPotensiCabang) ?>)</span>
        </div>
    </div>

    <!-- KPI FINANSIAL 2: STATUS PEMBAYARAN SISWA CABANG (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Status Pembayaran Siswa</span>
            <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>
        
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
            <span>Pelunasan:</span>
            <span class="font-bold text-emerald-600"><?= $persenLunas ?>% Siswa Tertib Bayar</span>
        </div>
    </div>

    <!-- KPI 1: Siswa Aktif Cabang (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Siswa Terdaftar</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalSiswa ?></span>
            <span class="text-xs text-slate-400 font-medium">Siswa Aktif</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/direktur_cabang/siswa.php') ?>" class="text-brand-600 font-bold hover:underline">
                Kelola Siswa Cabang &rarr;
            </a>
        </div>
    </div>

    <!-- KPI 2: Mentor Aktif Cabang (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Tenaga Pengajar</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalMentor ?></span>
            <span class="text-xs text-slate-400 font-medium">Mentor Aktif</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/direktur_cabang/pegawai.php') ?>" class="text-brand-600 font-bold hover:underline">
                Daftar Mentor & Staff &rarr;
            </a>
        </div>
    </div>

    <!-- KPI 3: Nilai Sarana & Fasilitas Cabang (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Investasi Aset</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-couch"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-xl sm:text-2xl font-black text-slate-800"><?= format_rupiah($totalNilaiAset) ?></span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/direktur_cabang/aset.php') ?>" class="text-brand-600 font-bold hover:underline">
                Audit Fasilitas Cabang &rarr;
            </a>
        </div>
    </div>

    <!-- KPI 4: Karya & Produk Siswa Cabang (Bento Col-4) -->
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Portofolio Kelulusan</span>
            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-gem"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalProduk ?></span>
            <span class="text-xs text-slate-400 font-medium">Karya Terbit</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/direktur_cabang/produk_showcase.php') ?>" class="text-brand-600 font-bold hover:underline">
                Lihat Galeri Produk &rarr;
            </a>
        </div>
    </div>

    <!-- TABEL 1: Jadwal Kelas Tatap Muka Cabang (Bento Col-7) -->
    <div class="bento-card col-span-7">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-calendar-day text-brand-600"></i>
                    <span>Jadwal Kelas Tatap Muka Cabang</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Plotting ruangan fisik dan jam mengajar mentor</p>
            </div>
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700">
                <?= count($jadwalList) ?> Jadwal Aktif
            </span>
        </div>

        <?php if (empty($jadwalList)): ?>
            <div class="py-10 text-center text-slate-400 text-xs">
                <i class="fa-regular fa-calendar-xmark text-3xl mb-2 text-slate-300"></i>
                <p>Belum ada jadwal kelas aktif di cabang ini.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-400 font-bold uppercase tracking-wider">
                            <th class="py-2.5">Hari & Waktu</th>
                            <th class="py-2.5">Layanan / Program</th>
                            <th class="py-2.5">Mentor</th>
                            <th class="py-2.5">Ruang Kelas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($jadwalList as $j): ?>
                            <tr>
                                <td class="py-2.5 font-bold text-slate-700">
                                    <span class="px-2 py-0.5 rounded bg-brand-50 text-brand-700 font-mono text-[11px] mr-1.5"><?= e($j['hari']) ?></span>
                                    <span><?= substr($j['jam_mulai'], 0, 5) ?> - <?= substr($j['jam_selesai'], 0, 5) ?></span>
                                </td>
                                <td class="py-2.5">
                                    <span class="font-bold text-slate-800"><?= e($j['nama_layanan']) ?></span>
                                </td>
                                <td class="py-2.5 text-slate-600"><?= e($j['nama_mentor']) ?></td>
                                <td class="py-2.5 text-slate-500"><?= e($j['nama_ruangan'] ?? 'Ruang Kelas Standar') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- LOG 2: Rekap Absensi Siswa Terbaru (Bento Col-5) -->
    <div class="bento-card col-span-5">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-clock-rotate-left text-teal-600"></i>
                    <span>Aktivitas KBM Terkini</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Siswa yang baru mengikuti kelas offline</p>
            </div>
            <a href="<?= base_url('modules/direktur_cabang/laporan.php?jenis=absensi') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Rekap &rarr;
            </a>
        </div>

        <?php if (empty($absensiList)): ?>
            <div class="py-10 text-center text-slate-400 text-xs">
                <i class="fa-solid fa-users-slash text-3xl mb-2 text-slate-300"></i>
                <p>Belum ada rekaman presensi kelas minggu ini.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($absensiList as $abs): ?>
                    <div class="py-2.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <h4 class="font-bold text-slate-800 text-xs truncate"><?= e($abs['nama_siswa']) ?></h4>
                            <p class="text-[11px] text-slate-400 truncate">
                                <?= e($abs['judul_modul'] ?? 'Sesi Tatap Muka') ?> • <?= format_tanggal_indonesia($abs['tanggal_absensi']) ?>
                            </p>
                        </div>
                        <div class="flex-shrink-0">
                            <?= render_status_badge($abs['status_kehadiran']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
