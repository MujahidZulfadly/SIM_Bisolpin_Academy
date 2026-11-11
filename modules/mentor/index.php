<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Pengajar / Mentor (Tatap Muka)
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Mengajar';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';
require_once dirname(__DIR__, 2) . '/core/attendance_ceklok.php';

// Proteksi hak akses Mentor / Pengajar
check_role(ROLE_MENTOR);

// Tangani aksi Ceklok Mandiri jika ada
handle_attendance_post_action();

$db = get_db();

// Ambil profil data pegawai mentor
$stmtPegawai = $db->prepare("SELECT id, nama_lengkap, id_cabang, nip_pegawai, spesialisasi_mentor FROM tabel_pegawai WHERE user_id = ?");
$stmtPegawai->execute([auth_id()]);
$mentor = $stmtPegawai->fetch();
$mentorId = $mentor ? (int)$mentor['id'] : 5;
$branchId = $mentor ? (int)$mentor['id_cabang'] : 1;

// 1. Hari Ini dalam Bahasa Indonesia
$hariIni = format_hari_indonesia(date('Y-m-d'));

// 2. Metrik KPI Mentor
$stmtSiswa = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_mentor_pembimbing = ? AND status_belajar = 'aktif'");
$stmtSiswa->execute([$mentorId]);
$totalSiswa = (int)$stmtSiswa->fetchColumn();

$stmtSesiMengajar = $db->prepare("SELECT COUNT(*) FROM tabel_absensi WHERE id_pegawai = ? AND status_kehadiran = 'hadir'");
$stmtSesiMengajar->execute([$mentorId]);
$totalSesiMengajar = (int)$stmtSesiMengajar->fetchColumn();

$stmtKaryaReview = $db->prepare("
    SELECT COUNT(*) 
    FROM tabel_produk p 
    JOIN tabel_siswa s ON s.id = p.id_siswa 
    WHERE s.id_mentor_pembimbing = ? AND p.status_tampil = 'menunggu_validasi'
");
$stmtKaryaReview->execute([$mentorId]);
$totalMenungguReview = (int)$stmtKaryaReview->fetchColumn();

// 3. Jadwal Mengajar Hari Ini
$stmtJadwalHariIni = $db->prepare("
    SELECT j.*, l.nama_layanan, l.kode_layanan, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_mentor = ? AND j.hari = ? AND j.status = 'aktif'
    ORDER BY j.jam_mulai ASC
");
$stmtJadwalHariIni->execute([$mentorId, $hariIni]);
$jadwalHariIniList = $stmtJadwalHariIni->fetchAll();

// 4. Seluruh Jadwal Mengajar Minggu Ini
$stmtJadwalAll = $db->prepare("
    SELECT j.*, l.nama_layanan, l.kode_layanan, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_mentor = ? AND j.status = 'aktif'
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC
");
$stmtJadwalAll->execute([$mentorId]);
$jadwalAllList = $stmtJadwalAll->fetchAll();

// 5. Akses Cepat Modul Pembelajaran Resmi dari BAK
$stmtModulBAK = $db->query("
    SELECT m.*, l.nama_layanan
    FROM tabel_materi m
    JOIN tabel_layanan l ON l.id = m.id_layanan
    WHERE m.status_publikasi = 'published'
    ORDER BY m.id_layanan ASC, m.pertemuan_ke ASC
    LIMIT 4
");
$modulBAKList = $stmtModulBAK->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan Mentor -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-chalkboard-user"></i>
            <span>Portal Tenaga Pengajar Tatap Muka</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Selamat Mengajar, <?= e($mentor['nama_lengkap'] ?? 'Mentor') ?>!
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Modul resmi telah disiapkan oleh BAK. Fokus penuh pada bimbingan kelas, jurnal mengajar, dan validasi karya siswa.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/mentor/absensi.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-clipboard-check text-xs"></i>
            <span>Isi Presensi Kelas</span>
        </a>
        <a href="<?= base_url('modules/mentor/materi_bak.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-book-open text-xs"></i>
            <span>Buka Modul BAK</span>
        </a>
    </div>
</div>

<!-- Widget Ceklok Presensi Mandiri Mentor -->
<?php render_attendance_widget(); ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT MENTOR -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI 1: Kelas Hari Ini -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Jadwal Hari Ini</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-calendar-day"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= count($jadwalHariIniList) ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi Kelas (<?= e($hariIni) ?>)</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Total Minggu Ini:</span>
            <span class="font-bold text-brand-600"><?= count($jadwalAllList) ?> Sesi</span>
        </div>
    </div>

    <!-- KPI 2: Siswa Bimbingan -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Siswa Dibimbing</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-graduate"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalSiswa ?></span>
            <span class="text-xs text-slate-400 font-medium">Siswa Aktif</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/mentor/progres_siswa.php') ?>" class="text-brand-600 font-bold hover:underline">
                Update Progres Belajar &rarr;
            </a>
        </div>
    </div>

    <!-- KPI 3: Sesi Mengajar Selesai -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Jam Tatap Muka</span>
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-business-time"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalSesiMengajar ?></span>
            <span class="text-xs text-slate-400 font-medium">Pertemuan Terlaksana</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <span class="text-slate-500">Presensi & BAP:</span>
            <span class="font-bold text-emerald-600">Terverifikasi</span>
        </div>
    </div>

    <!-- KPI 4: Karya Menunggu Validasi -->
    <div class="bento-card col-span-3">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Validasi Karya Siswa</span>
            <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-gem"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalMenungguReview ?></span>
            <span class="text-xs text-slate-400 font-medium">Menunggu Nilai</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
            <a href="<?= base_url('modules/mentor/validasi_produk.php') ?>" class="text-purple-600 font-bold hover:underline">
                Beri Nilai & Review &rarr;
            </a>
        </div>
    </div>

    <!-- JADWAL KELAS HARI INI (Bento Col-7) -->
    <div class="bento-card col-span-7">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-chalkboard text-teal-600"></i>
                    <span>Jadwal Mengajar Hari Ini (<?= e($hariIni) ?>)</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Sesi tatap muka di ruang kelas fisik cabang</p>
            </div>
            <a href="<?= base_url('modules/mentor/jadwal.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Semua Jadwal Mingguan &rarr;
            </a>
        </div>

        <?php if (empty($jadwalHariIniList)): ?>
            <div class="py-12 text-center text-slate-400 text-xs">
                <i class="fa-regular fa-calendar-check text-4xl mb-2 text-slate-300"></i>
                <p>Tidak ada jadwal mengajar tatap muka untuk hari ini. Waktu luang dapat digunakan untuk mereview karya siswa atau mempelajari modul BAK.</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($jadwalHariIniList as $jh): ?>
                    <div class="p-4 rounded-2xl border border-teal-200 bg-teal-50/40 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-teal-100 text-teal-900 font-bold text-xs mb-1">
                                <i class="fa-regular fa-clock text-[10px]"></i>
                                <span><?= substr($jh['jam_mulai'], 0, 5) ?> - <?= substr($jh['jam_selesai'], 0, 5) ?> WITA</span>
                            </div>
                            <h3 class="text-sm font-bold text-slate-800"><?= e($jh['nama_layanan']) ?></h3>
                            <p class="text-xs text-slate-500 flex items-center gap-2 mt-0.5">
                                <span class="text-teal-700 font-medium"><i class="fa-solid fa-door-open mr-1"></i><?= e($jh['nama_ruangan'] ?? 'Ruang Kelas') ?></span>
                                <span>•</span>
                                <span>Kuota: <?= (int)$jh['kuota_siswa'] ?> Siswa</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <a href="<?= base_url('modules/mentor/materi_bak.php?layanan_id=' . $jh['id_layanan']) ?>" class="btn-brand-outline text-xs py-2 px-3">
                                <i class="fa-solid fa-book-open text-xs"></i>
                                <span>Buka Modul</span>
                            </a>
                            <a href="<?= base_url('modules/mentor/absensi.php?jadwal_id=' . $jh['id']) ?>" class="btn-brand text-xs py-2 px-3">
                                <i class="fa-solid fa-clipboard-check text-xs"></i>
                                <span>Isi Absen</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODUL RESMI DARI BAK: Siap Ajar (Bento Col-5) -->
    <div class="bento-card col-span-5">
        <div class="bento-card-header">
            <div>
                <h2 class="bento-card-title text-base">
                    <i class="fa-solid fa-folder-open text-amber-500"></i>
                    <span>Modul Siap Ajar dari BAK</span>
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Materi resmi tinggal buka saat mengajar di kelas</p>
            </div>
            <a href="<?= base_url('modules/mentor/materi_bak.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Katalog Lengkap &rarr;
            </a>
        </div>

        <div class="space-y-3">
            <?php foreach ($modulBAKList as $mb): ?>
                <div class="p-3 rounded-xl border border-slate-100 hover:border-brand-200 bg-slate-50/50 hover:bg-white transition-all flex items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-teal-100 text-teal-800 font-extrabold text-xs shrink-0">
                            <?= (int)$mb['pertemuan_ke'] ?>
                        </span>
                        <div class="min-w-0">
                            <strong class="text-slate-800 truncate block"><?= e($mb['judul_modul']) ?></strong>
                            <span class="text-[10px] text-slate-400 truncate block"><?= e($mb['nama_layanan']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($mb['file_modul'])): ?>
                        <a href="<?= URL_UPLOAD_MODUL . e($mb['file_modul']) ?>" target="_blank" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center hover:bg-rose-100 transition-colors shrink-0" title="Unduh Modul">
                            <i class="fa-solid fa-file-pdf"></i>
                        </a>
                    <?php else: ?>
                        <span class="text-[10px] text-slate-400">Ringkasan</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

