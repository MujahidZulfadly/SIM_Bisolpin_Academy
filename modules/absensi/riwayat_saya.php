<?php
/**
 * SIM BISOLPIN ACADEMY - Riwayat Ceklok Presensi Mandiri Pegawai
 * Dapat diakses oleh role: direktur_cabang, staff, mentor, sarpras, dan bak.
 */

declare(strict_types=1);

$pageTitle = 'Riwayat Presensi Ceklok Saya';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Pastikan user adalah pegawai yang berhak melakukan presensi
if (!is_eligible_for_ceklok()) {
    redirect_with_message('index.php', 'error', 'Presensi ceklok mandiri hanya berlaku bagi Staff Administrasi, Mentor Pengajar, dan Sarpras.', 'Akses Ditolak');
}

// Proses Ceklok jika ada submit di halaman ini
handle_attendance_post_action();

$db = get_db();
$pegawai = get_logged_in_pegawai();
if (!$pegawai) {
    redirect_with_message('index.php', 'error', 'Data profil pegawai Anda tidak ditemukan dalam sistem.', 'Gagal');
}
$pegawaiId = (int)$pegawai['id'];

// Filter Bulan & Tahun
$filterBulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');
$filterTahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// Query Riwayat Presensi Saya
$stmt = $db->prepare("
    SELECT * FROM tabel_absensi
    WHERE tipe_absensi = 'pegawai_mentor'
      AND id_pegawai = ?
      AND MONTH(tanggal_absensi) = ?
      AND YEAR(tanggal_absensi) = ?
    ORDER BY tanggal_absensi DESC, jam_masuk DESC
");
$stmt->execute([$pegawaiId, $filterBulan, $filterTahun]);
$riwayatList = $stmt->fetchAll();

// Statistik Rekapitulasi Bulan Ini
$totalHadir = 0;
$totalIzin  = 0;
$totalSakit = 0;
$totalDetikKerja = 0;
$jumlahHariSelesai = 0;

foreach ($riwayatList as $r) {
    if ($r['status_kehadiran'] === 'hadir') {
        $totalHadir++;
        if (!empty($r['jam_masuk']) && !empty($r['jam_keluar'])) {
            $tIn = strtotime($r['jam_masuk']);
            $tOut = strtotime($r['jam_keluar']);
            if ($tIn && $tOut && $tOut > $tIn) {
                $totalDetikKerja += ($tOut - $tIn);
                $jumlahHariSelesai++;
            }
        }
    } elseif ($r['status_kehadiran'] === 'izin') {
        $totalIzin++;
    } elseif ($r['status_kehadiran'] === 'sakit') {
        $totalSakit++;
    }
}

$rataRataJamKerja = $jumlahHariSelesai > 0 ? round(($totalDetikKerja / $jumlahHariSelesai) / 3600, 1) : 0;
$namaBulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Log Kehadiran Pegawai</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Riwayat Presensi Ceklok Saya</h1>
        <p class="text-xs text-slate-500 mt-1">
            Rekam jejak jam masuk, jam pulang, dan durasi kerja harian Anda di BISOLPIN ACADEMY.
        </p>
    </div>

    <!-- Filter Bulan & Tahun -->
    <form method="GET" action="" class="flex items-center gap-2 flex-wrap">
        <select name="bulan" class="form-input text-xs py-2 w-36">
            <?php foreach ($namaBulanIndo as $num => $nama): ?>
                <option value="<?= $num ?>" <?= $filterBulan === $num ? 'selected' : '' ?>>
                    <?= $nama ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="tahun" class="form-input text-xs py-2 w-28">
            <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
                <option value="<?= $y ?>" <?= $filterTahun === $y ? 'selected' : '' ?>>
                    <?= $y ?>
                </option>
            <?php endfor; ?>
        </select>

        <button type="submit" class="btn-brand text-xs py-2 px-4 shadow-sm">
            <i class="fa-solid fa-filter text-xs mr-1"></i> Terapkan
        </button>
    </form>
</div>

<!-- Widget Ceklok Hari Ini -->
<?php render_attendance_widget(); ?>

<!-- Matriks Ringkasan Presensi Bulan Terpilih -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-emerald-600"><?= $totalHadir ?></span>
        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Hadir Bulan Ini</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-amber-600"><?= $totalIzin ?></span>
        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Izin Kerja</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-rose-500"><?= $totalSakit ?></span>
        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Sakit</span>
    </div>
    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="block text-2xl font-black text-brand-600"><?= $rataRataJamKerja ?> Jam</span>
        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Rata-Rata Kerja / Hari</span>
    </div>
</div>

<!-- Tabel Riwayat Presensi -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-slate-800">
            Daftar Presensi: <span class="text-brand-600"><?= $namaBulanIndo[$filterBulan] ?> <?= $filterTahun ?></span>
        </h3>
        <span class="text-xs text-slate-400 font-medium">Total <?= count($riwayatList) ?> catatan kehadiran</span>
    </div>

    <?php if (empty($riwayatList)): ?>
        <?= render_empty_state(
            'fa-clock-rotate-left',
            'Belum Ada Catatan Ceklok',
            'Belum ada rekaman presensi ceklok Anda pada periode ' . $namaBulanIndo[$filterBulan] . ' ' . $filterTahun . '.',
            null,
            0
        ) ?>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="datatable w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 uppercase font-bold tracking-wider">
                        <th class="py-3 px-4">Tanggal Presensi</th>
                        <th class="py-3 px-4 text-center">Jam Masuk (Clock-In)</th>
                        <th class="py-3 px-4 text-center">Jam Pulang (Clock-Out)</th>
                        <th class="py-3 px-4 text-center">Durasi Kerja</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4">Berita Acara / Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($riwayatList as $row): 
                        $durasi = calculate_work_duration($row['jam_masuk'], $row['jam_keluar']);
                    ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4">
                                <strong class="font-bold text-slate-800 block text-xs">
                                    <?= format_tanggal_indonesia($row['tanggal_absensi']) ?>
                                </strong>
                                <span class="text-[11px] text-slate-400 font-medium">
                                    <?= format_hari_indonesia($row['tanggal_absensi']) ?>
                                </span>
                            </td>

                            <td class="py-3 px-4 text-center font-mono font-bold text-emerald-600">
                                <i class="fa-solid fa-fingerprint text-[10px] mr-1"></i>
                                <?= substr($row['jam_masuk'], 0, 5) ?> WIB
                            </td>

                            <td class="py-3 px-4 text-center font-mono font-bold text-rose-500">
                                <i class="fa-solid fa-right-from-bracket text-[10px] mr-1"></i>
                                <?= !empty($row['jam_keluar']) ? substr($row['jam_keluar'], 0, 5) . ' WIB' : '<span class="text-slate-400 italic font-normal text-[11px]">Belum Pulang</span>' ?>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 font-semibold text-[11px]">
                                    <?= $durasi ?>
                                </span>
                            </td>

                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($row['status_kehadiran']) ?>
                            </td>

                            <td class="py-3 px-4 text-slate-600 max-w-sm truncate" title="<?= e($row['keterangan_jurnal']) ?>">
                                <?= e($row['keterangan_jurnal'] ?: '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

