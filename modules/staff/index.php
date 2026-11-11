<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Operasional Staff Administrasi Cabang
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Staff Administrasi';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

// Tangani aksi Ceklok Mandiri jika ada
handle_attendance_post_action();

$db = get_db();
$branchId = get_active_branch_id() ?? 1;

// Info detail cabang
$stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

// 1. Metrik KPI Staff Cabang
// Total Siswa Aktif
$stmtSiswaAktif = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_cabang = ? AND status_belajar = 'aktif'");
$stmtSiswaAktif->execute([$branchId]);
$totalSiswaAktif = (int)$stmtSiswaAktif->fetchColumn();

// Total Seluruh Siswa Terdaftar (termasuk alumni/cuti)
$stmtSiswaAll = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_cabang = ?");
$stmtSiswaAll->execute([$branchId]);
$totalSiswaAll = (int)$stmtSiswaAll->fetchColumn();

// Total Pegawai & Mentor di Cabang
$stmtPegawai = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE id_cabang = ? AND status_aktif = 'aktif'");
$stmtPegawai->execute([$branchId]);
$totalPegawai = (int)$stmtPegawai->fetchColumn();

// Total Mentor di Cabang
$stmtMentor = $db->prepare("SELECT COUNT(*) FROM tabel_pegawai WHERE id_cabang = ? AND jabatan_role = 'mentor' AND status_aktif = 'aktif'");
$stmtMentor->execute([$branchId]);
$totalMentor = (int)$stmtMentor->fetchColumn();

// Total Jadwal Sesi Kelas Aktif
$stmtJadwal = $db->prepare("SELECT COUNT(*) FROM jadwal_kelas WHERE id_cabang = ? AND status = 'aktif'");
$stmtJadwal->execute([$branchId]);
$totalJadwal = (int)$stmtJadwal->fetchColumn();

// Presensi Hari Ini di Cabang
$tanggalHariIni = date('Y-m-d');
$hariIni = format_hari_indonesia($tanggalHariIni);

$stmtPresensiSiswa = $db->prepare("SELECT COUNT(*) FROM tabel_absensi WHERE id_cabang = ? AND tipe_absensi = 'siswa' AND tanggal_absensi = ?");
$stmtPresensiSiswa->execute([$branchId, $tanggalHariIni]);
$totalPresensiSiswaHariIni = (int)$stmtPresensiSiswa->fetchColumn();

$stmtPresensiPegawai = $db->prepare("SELECT COUNT(*) FROM tabel_absensi WHERE id_cabang = ? AND tipe_absensi = 'pegawai_mentor' AND tanggal_absensi = ?");
$stmtPresensiPegawai->execute([$branchId, $tanggalHariIni]);
$totalPresensiPegawaiHariIni = (int)$stmtPresensiPegawai->fetchColumn();

// 2. Jadwal Kelas Tatap Muka Hari Ini
$stmtJadwalHariIni = $db->prepare("
    SELECT j.*, l.nama_layanan, l.kode_layanan, p.nama_lengkap as nama_mentor, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    JOIN tabel_pegawai p ON p.id = j.id_mentor
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_cabang = ? AND j.hari = ? AND j.status = 'aktif'
    ORDER BY j.jam_mulai ASC
");
$stmtJadwalHariIni->execute([$branchId, $hariIni]);
$jadwalHariIniList = $stmtJadwalHariIni->fetchAll();

// 3. Pendaftaran Siswa Terbaru di Cabang
$stmtSiswaTerbaru = $db->prepare("
    SELECT s.*, l.nama_layanan, p.nama_lengkap as nama_mentor
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    WHERE s.id_cabang = ?
    ORDER BY s.id DESC
    LIMIT 5
");
$stmtSiswaTerbaru->execute([$branchId]);
$siswaTerbaruList = $stmtSiswaTerbaru->fetchAll();

// 4. Data Transaksi Pembayaran Terbaru jika tabel_pembayaran ada
$pembayaranTerbaruList = [];
try {
    $stmtBayar = $db->prepare("
        SELECT pb.*, s.nama_lengkap as nama_siswa, s.nis_bisolpin, l.nama_layanan
        FROM tabel_pembayaran pb
        JOIN tabel_siswa s ON s.id = pb.id_siswa
        JOIN tabel_layanan l ON l.id = pb.id_layanan
        WHERE pb.id_cabang = ?
        ORDER BY pb.id DESC
        LIMIT 5
    ");
    $stmtBayar->execute([$branchId]);
    $pembayaranTerbaruList = $stmtBayar->fetchAll();
} catch (\Throwable $e) {
    // Tabel pembayaran belum ter-generate, akan dibuat saat modul pembayaran dibuka
    $pembayaranTerbaruList = [];
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan Staff Administrasi -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-clipboard-check"></i>
            <span>Front Office & Administrasi Operasional Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Dashboard Staff Administrasi - <?= e($cabang['nama_cabang'] ?? 'Cabang') ?>
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Kelola pendaftaran siswa, data SDM cabang, plotting jadwal kelas offline, dan administrasi kasir harian.
        </p>
    </div>

    <!-- Quick Action Buttons -->
    <div class="flex flex-wrap items-center gap-2.5">
        <a href="<?= base_url('modules/staff/siswa.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Daftar Siswa Baru</span>
        </a>
        <a href="<?= base_url('modules/staff/jadwal_plotting.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-calendar-plus text-xs"></i>
            <span>Plotting Jadwal</span>
        </a>
        <a href="<?= base_url('modules/staff/pembayaran.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-cash-register text-xs"></i>
            <span>Kasir / SPP</span>
        </a>
    </div>
</div>

<!-- Widget Ceklok Presensi Mandiri Staff -->
<?php render_attendance_widget(); ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT STAFF -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI 1: Siswa Aktif Cabang -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Siswa Aktif</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalSiswaAktif ?></span>
            <span class="text-xs text-slate-400 font-medium">Siswa Kursus</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Total Terdaftar:</span>
            <span class="font-bold text-brand-600"><?= $totalSiswaAll ?> Siswa</span>
        </div>
    </div>

    <!-- KPI 2: Pegawai & Mentor -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">SDM & Pengajar</span>
            <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalPegawai ?></span>
            <span class="text-xs text-slate-400 font-medium">Pegawai Cabang</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Mentor Pengajar:</span>
            <span class="font-bold text-sky-600"><?= $totalMentor ?> Tutor</span>
        </div>
    </div>

    <!-- KPI 3: Jadwal Kelas Aktif -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Jadwal Kelas</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalJadwal ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi Terjadwal</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Kelas Hari Ini:</span>
            <span class="font-bold text-amber-600"><?= count($jadwalHariIniList) ?> Sesi (<?= e($hariIni) ?>)</span>
        </div>
    </div>

    <!-- KPI 4: Presensi Hari Ini -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Presensi Hari Ini</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-clipboard-user"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalPresensiSiswaHariIni + $totalPresensiPegawaiHariIni ?></span>
            <span class="text-xs text-slate-400 font-medium">Total Log Masuk</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Siswa / Pegawai:</span>
            <span class="font-bold text-indigo-600"><?= $totalPresensiSiswaHariIni ?> / <?= $totalPresensiPegawaiHariIni ?></span>
        </div>
    </div>

    <!-- ROW 2: Jadwal Tatap Muka Hari Ini (Bento 7 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-7">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Kelas Tatap Muka Hari Ini (<?= e($hariIni) ?>)</h3>
                <p class="text-xs text-slate-400 mt-0.5">Pemantauan keterlaksanaan kelas tatap muka di ruang fisik cabang</p>
            </div>
            <a href="<?= base_url('modules/staff/jadwal_plotting.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Kelola Jadwal <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
            </a>
        </div>

        <?php if (empty($jadwalHariIniList)): ?>
            <div class="p-8 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                <div class="w-12 h-12 mx-auto rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-3">
                    <i class="fa-regular fa-calendar-xmark text-xl"></i>
                </div>
                <h4 class="text-sm font-bold text-slate-700">Tidak Ada Kelas Hari Ini</h4>
                <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                    Tidak ada jadwal kelas tatap muka yang dijadwalkan pada hari <?= e($hariIni) ?> di cabang ini.
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($jadwalHariIniList as $j): ?>
                    <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/70 hover:bg-white hover:border-brand-200 hover:shadow-sm transition-all flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 font-bold flex flex-col items-center justify-center flex-shrink-0 text-xs">
                                <span><?= date('H:i', strtotime($j['jam_mulai'])) ?></span>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800"><?= e($j['nama_layanan']) ?></h4>
                                <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-500">
                                    <span><i class="fa-solid fa-chalkboard-user text-slate-400 mr-1"></i><?= e($j['nama_mentor']) ?></span>
                                    <span>&bull;</span>
                                    <span><i class="fa-solid fa-door-open text-slate-400 mr-1"></i><?= e($j['nama_ruangan'] ?? 'Ruang Kelas') ?></span>
                                    <span>&bull;</span>
                                    <span class="text-slate-600 font-semibold"><i class="fa-solid fa-users text-slate-400 mr-1"></i>Maks <?= $j['kuota_siswa'] ?> Siswa</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-semibold">
                                Selesai <?= date('H:i', strtotime($j['jam_selesai'])) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ROW 2: Pendaftaran Siswa Terbaru (Bento 5 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-5">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Siswa Baru Terdaftar</h3>
                <p class="text-xs text-slate-400 mt-0.5">Entri data siswa paling akhir di cabang</p>
            </div>
            <a href="<?= base_url('modules/staff/siswa.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Lihat Semua <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
            </a>
        </div>

        <?php if (empty($siswaTerbaruList)): ?>
            <div class="p-8 text-center bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400">Belum ada siswa yang didaftarkan di cabang ini.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($siswaTerbaruList as $s): ?>
                    <div class="py-3 first:pt-0 last:pb-0 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                <?= strtoupper(substr($s['nama_lengkap'], 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <h5 class="text-xs font-bold text-slate-800 truncate"><?= e($s['nama_lengkap']) ?></h5>
                                <p class="text-[11px] text-slate-400 truncate"><?= e($s['nis_bisolpin']) ?> &bull; <?= e($s['nama_layanan']) ?></p>
                            </div>
                        </div>
                        <div class="flex-shrink-0 text-right">
                            <?= render_status_badge($s['status_belajar']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ROW 3: Transaksi Kasir Terbaru (Bento 12 Kolom) -->
    <div class="bento-card col-span-12">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Aktivitas Pembayaran & Kasir Cabang</h3>
                <p class="text-xs text-slate-400 mt-0.5">Catatan penerimaan SPP dan pendaftaran paket kursus offline</p>
            </div>
            <a href="<?= base_url('modules/staff/pembayaran.php') ?>" class="btn-brand-outline text-xs py-1.5 px-3">
                <i class="fa-solid fa-plus text-xs mr-1"></i> Entri Pembayaran
            </a>
        </div>

        <?php if (empty($pembayaranTerbaruList)): ?>
            <div class="p-6 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400">Belum ada catatan transaksi pembayaran yang tercatat pada sesi ini.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 font-bold uppercase tracking-wider border-b border-slate-100 pb-2">
                            <th class="py-2.5 px-3">Kode Transaksi</th>
                            <th class="py-2.5 px-3">Tanggal</th>
                            <th class="py-2.5 px-3">Nama Siswa</th>
                            <th class="py-2.5 px-3">Program / Layanan</th>
                            <th class="py-2.5 px-3">Metode</th>
                            <th class="py-2.5 px-3 text-right">Jumlah Bayar</th>
                            <th class="py-2.5 px-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($pembayaranTerbaruList as $pb): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="py-2.5 px-3 font-mono font-bold text-brand-600"><?= e($pb['kode_transaksi']) ?></td>
                                <td class="py-2.5 px-3 text-slate-500"><?= format_tanggal_indonesia($pb['tanggal_bayar']) ?></td>
                                <td class="py-2.5 px-3 font-semibold text-slate-800"><?= e($pb['nama_siswa']) ?></td>
                                <td class="py-2.5 px-3 text-slate-600"><?= e($pb['nama_layanan']) ?></td>
                                <td class="py-2.5 px-3 uppercase font-medium text-slate-500"><?= e($pb['metode_pembayaran']) ?></td>
                                <td class="py-2.5 px-3 text-right font-black text-slate-800"><?= format_rupiah($pb['jumlah_bayar']) ?></td>
                                <td class="py-2.5 px-3 text-center"><?= render_status_badge($pb['status_pembayaran']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

