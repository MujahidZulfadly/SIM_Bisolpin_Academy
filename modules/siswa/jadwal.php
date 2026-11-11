<?php
/**
 * SIM BISOLPIN ACADEMY - Jadwal Kelas Tatap Muka & Riwayat Kehadiran Siswa
 */

declare(strict_types=1);

$pageTitle = 'Jadwal Tatap Muka & Presensi';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SISWA
check_role(ROLE_SISWA);

$db = get_db();
$userId = auth_id();

// Ambil Profil Siswa
$stmtSiswa = $db->prepare("
    SELECT s.*, l.nama_layanan, l.kode_layanan, l.kategori_jenjang, l.jumlah_pertemuan,
           c.nama_cabang, c.alamat as alamat_cabang, c.telepon as telp_cabang,
           p.nama_lengkap as nama_mentor, p.spesialisasi_mentor
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    JOIN tabel_cabang c ON c.id = s.id_cabang
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    WHERE s.user_id = ?
");
$stmtSiswa->execute([$userId]);
$siswa = $stmtSiswa->fetch();

if (!$siswa) {
    echo "<div class='p-8 text-center text-rose-600 font-bold'>Profil siswa tidak ditemukan.</div>";
    exit;
}

$siswaId = (int)$siswa['id'];
$branchId = (int)$siswa['id_cabang'];
$layananId = (int)$siswa['id_layanan'];

// 1. Ambil Jadwal Kelas Mingguan
$stmtJadwal = $db->prepare("
    SELECT j.*, a.nama_aset as nama_ruangan, a.lokasi_penempatan, p.nama_lengkap as nama_mentor
    FROM jadwal_kelas j
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    JOIN tabel_pegawai p ON p.id = j.id_mentor
    WHERE j.id_cabang = ? AND j.id_layanan = ? AND j.status = 'aktif'
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC
");
$stmtJadwal->execute([$branchId, $layananId]);
$jadwalList = $stmtJadwal->fetchAll();

// 2. Ambil Riwayat Kehadiran Presensi Kelas Tatap Muka
$stmtAbsen = $db->prepare("
    SELECT a.*, m.pertemuan_ke, m.judul_modul, m.nama_pelajaran, u.username as diinput_oleh_user
    FROM tabel_absensi a
    LEFT JOIN tabel_materi m ON m.id = a.id_materi_pertemuan
    LEFT JOIN users u ON u.id = a.diinput_oleh
    WHERE a.id_siswa = ? AND a.tipe_absensi = 'siswa'
    ORDER BY a.tanggal_absensi DESC, a.jam_masuk DESC
");
$stmtAbsen->execute([$siswaId]);
$riwayatAbsensi = $stmtAbsen->fetchAll();

// Rekap Kehadiran
$totalSesiPresensi = count($riwayatAbsensi);
$totalHadir = 0;
$totalIzin = 0;
$totalSakit = 0;
$totalAlfa = 0;

foreach ($riwayatAbsensi as $ra) {
    if ($ra['status_kehadiran'] === 'hadir') $totalHadir++;
    elseif ($ra['status_kehadiran'] === 'izin') $totalIzin++;
    elseif ($ra['status_kehadiran'] === 'sakit') $totalSakit++;
    elseif ($ra['status_kehadiran'] === 'alfa') $totalAlfa++;
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Jadwal Tatap Muka & Presensi Belajar</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Jadwal Belajar & Riwayat Kehadiran
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pantau sesi kelas tatap muka di cabang <?= e($siswa['nama_cabang']) ?> dan verifikasi catatan kehadiran harian Anda.
        </p>
    </div>

    <!-- Info Singkat Lokasi Fisik Cabang -->
    <div class="p-3 bg-white rounded-xl border border-slate-200 text-xs shadow-sm flex items-center gap-3">
        <div class="w-10 h-10 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-lg flex-shrink-0">
            <i class="fa-solid fa-school"></i>
        </div>
        <div>
            <span class="block font-bold text-slate-800"><?= e($siswa['nama_cabang']) ?></span>
            <span class="block text-[11px] text-slate-500 truncate max-w-xs"><?= e($siswa['alamat_cabang']) ?></span>
        </div>
    </div>
</div>

<!-- Bento KPI Kehadiran -->
<div class="bento-grid mb-6">
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Hadir Tatap Muka</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-circle-check"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-emerald-600"><?= $totalHadir ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi Masuk</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Rasio Kehadiran:</span>
            <span class="font-bold text-emerald-600">
                <?= $totalSesiPresensi > 0 ? round(($totalHadir / $totalSesiPresensi) * 100) : 100 ?>%
            </span>
        </div>
    </div>

    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Izin Belajar</span>
            <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-envelope-open-text"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-sky-600"><?= $totalIzin ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Keterangan Tertulis</span>
        </div>
    </div>

    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Sakit</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-notes-medical"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-indigo-600"><?= $totalSakit ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Surat Keterangan Sakit</span>
        </div>
    </div>

    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Tanpa Keterangan (Alfa)</span>
            <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-rose-600"><?= $totalAlfa ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Perlu Klarifikasi</span>
        </div>
    </div>
</div>

<!-- SECTION 1: JADWAL KELAS TATAP MUKA MINGGUAN -->
<div class="card p-6 mb-8">
    <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
        <div>
            <h3 class="text-base font-bold text-slate-800">Jadwal Kelas Tatap Muka Rutin</h3>
            <p class="text-xs text-slate-400 mt-0.5">Program Kursus: <strong class="text-teal-700"><?= e($siswa['nama_layanan']) ?></strong> (<?= e($siswa['kategori_jenjang']) ?>)</p>
        </div>
        <span class="text-xs font-bold text-slate-600 bg-slate-100 px-3 py-1 rounded-full">
            <?= count($jadwalList) ?> Sesi Mingguan
        </span>
    </div>

    <?php if (empty($jadwalList)): ?>
        <div class="p-8 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
            <p class="text-xs text-slate-400">Jadwal tatap muka mingguan belum dirilis oleh Front Office cabang.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($jadwalList as $j): ?>
                <div class="p-4 rounded-xl border-2 border-teal-100 bg-gradient-to-br from-white to-teal-50/30 hover:border-teal-300 hover:shadow-md transition-all">
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2.5 py-1 rounded-lg bg-teal-600 text-white font-black text-xs uppercase tracking-wider">
                            Hari <?= e($j['hari']) ?>
                        </span>
                        <span class="font-mono text-xs font-bold text-teal-800 bg-teal-100/70 px-2 py-0.5 rounded">
                            <?= date('H:i', strtotime($j['jam_mulai'])) ?> - <?= date('H:i', strtotime($j['jam_selesai'])) ?>
                        </span>
                    </div>

                    <div class="space-y-2 text-xs text-slate-600">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-door-open text-teal-500 w-4 text-center"></i>
                            <span class="font-bold text-slate-800"><?= e($j['nama_ruangan'] ?? 'Ruang Kelas Teori') ?></span>
                        </div>
                        <?php if (!empty($j['lokasi_penempatan'])): ?>
                            <p class="text-[11px] text-slate-400 pl-6"><?= e($j['lokasi_penempatan']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center gap-2 pt-1 border-t border-slate-100">
                            <i class="fa-solid fa-chalkboard-user text-teal-500 w-4 text-center"></i>
                            <span class="text-slate-700">Tutor: <strong><?= e($j['nama_mentor']) ?></strong></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- SECTION 2: RIWAYAT PRESENSI & JURNAL KELAS OFFLINE -->
<div class="card overflow-hidden">
    <div class="p-6 pb-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-slate-800">Riwayat Presensi & Berita Acara Kelas</h3>
            <p class="text-xs text-slate-400 mt-0.5">Catatan presensi dan ulasan jurnal belajar per pertemuan dari pengajar</p>
        </div>
        <span class="text-xs text-slate-500">
            Total Sesi: <strong class="text-slate-800"><?= $totalSesiPresensi ?> Pertemuan</strong>
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <th class="py-3 px-4">Tanggal Pertemuan</th>
                    <th class="py-3 px-4">Modul / Pertemuan Ke</th>
                    <th class="py-3 px-4 text-center">Jam Kehadiran</th>
                    <th class="py-3 px-4 text-center">Status Kehadiran</th>
                    <th class="py-3 px-4">Catatan Jurnal / Evaluasi Pengajar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($riwayatAbsensi)): ?>
                    <tr>
                        <td colspan="5" class="py-12 text-center text-slate-400">
                            <i class="fa-regular fa-clipboard text-3xl mb-2 text-slate-300 block"></i>
                            Belum ada catatan presensi tatap muka yang tersimpan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($riwayatAbsensi as $ra): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4 font-medium text-slate-700 whitespace-nowrap">
                                <?= format_tanggal_indonesia($ra['tanggal_absensi']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <?php if (!empty($ra['pertemuan_ke'])): ?>
                                    <span class="font-bold text-teal-800 block">Pertemuan Ke-<?= $ra['pertemuan_ke'] ?></span>
                                    <span class="text-[11px] text-slate-500"><?= e($ra['judul_modul']) ?></span>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">Sesi Kelas Tatap Muka</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center font-mono font-bold text-teal-700">
                                <?= date('H:i', strtotime($ra['jam_masuk'])) ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($ra['status_kehadiran']) ?>
                            </td>
                            <td class="py-3 px-4 text-slate-600 max-w-md">
                                <p class="text-[11px] leading-relaxed"><?= e($ra['keterangan_jurnal'] ?? 'Hadir mengikuti kelas.') ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

