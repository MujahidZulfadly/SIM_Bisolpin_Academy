<?php
/**
 * SIM BISOLPIN ACADEMY - Presensi & Berita Acara Pengajaran (BAP) Kelas Offline (Mentor)
 */

declare(strict_types=1);

$pageTitle = 'Presensi & BAP Kelas Tatap Muka';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_MENTOR);

$db = get_db();

// Ambil data mentor & validasi hak akses cabang
$stmtPegawai = $db->prepare("SELECT id, nama_lengkap, id_cabang FROM tabel_pegawai WHERE user_id = ?");
$stmtPegawai->execute([auth_id()]);
$mentor = $stmtPegawai->fetch();
$mentorId = $mentor ? (int)$mentor['id'] : 5;
$branchId = $mentor ? (int)$mentor['id_cabang'] : 1;

// Keamanan isolasi cabang
verify_branch_access($branchId);

// Handle Form POST: Simpan Presensi & BAP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $jadwalId        = (int)($_POST['jadwal_id'] ?? 0);
    $tanggalAbsensi  = $_POST['tanggal_absensi'] ?? date('Y-m-d');
    $jamMasuk        = $_POST['jam_masuk'] ?? date('H:i:00');
    $jamKeluar       = $_POST['jam_keluar'] ?: date('H:i:00', strtotime('+90 minutes'));
    $materiId        = !empty($_POST['id_materi_pertemuan']) ? (int)$_POST['id_materi_pertemuan'] : null;
    $keteranganBAP   = trim($_POST['keterangan_jurnal'] ?? '');
    $attendances     = $_POST['attendance'] ?? [];

    if ($jadwalId <= 0 || empty($attendances)) {
        set_flash('error', 'Pilih jadwal kelas dan lakukan presensi setidaknya untuk 1 siswa.');
    } else {
        // Ambil info jadwal dan verifikasi hak cabang
        $stmtJ = $db->prepare("SELECT * FROM jadwal_kelas WHERE id = ? AND id_mentor = ?");
        $stmtJ->execute([$jadwalId, $mentorId]);
        $jadwal = $stmtJ->fetch();

        if (!$jadwal) {
            set_flash('error', 'Jadwal kelas tidak valid atau tidak ditugaskan kepada Anda.');
        } else {
            verify_branch_access((int)$jadwal['id_cabang']);

            try {
                $db->beginTransaction();

                // 1. Simpan Presensi Mengajar Mentor Sendiri
                $stmtInsMentor = $db->prepare("
                    INSERT INTO tabel_absensi 
                    (id_cabang, tipe_absensi, id_siswa, id_pegawai, id_materi_pertemuan, tanggal_absensi, jam_masuk, jam_keluar, status_kehadiran, keterangan_jurnal, diinput_oleh, created_at)
                    VALUES (?, 'pegawai_mentor', NULL, ?, ?, ?, ?, ?, 'hadir', ?, ?, NOW())
                ");
                $stmtInsMentor->execute([
                    $branchId, $mentorId, $materiId, $tanggalAbsensi,
                    $jamMasuk, $jamKeluar, $keteranganBAP, auth_id()
                ]);

                // 2. Simpan Presensi Seluruh Siswa di Kelas
                $stmtInsSiswa = $db->prepare("
                    INSERT INTO tabel_absensi 
                    (id_cabang, tipe_absensi, id_siswa, id_pegawai, id_materi_pertemuan, tanggal_absensi, jam_masuk, jam_keluar, status_kehadiran, keterangan_jurnal, diinput_oleh, created_at)
                    VALUES (?, 'siswa', ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $pertemuanKe = 1;
                if ($materiId) {
                    $stmtMat = $db->prepare("SELECT pertemuan_ke FROM tabel_materi WHERE id = ?");
                    $stmtMat->execute([$materiId]);
                    $matRow = $stmtMat->fetch();
                    if ($matRow) $pertemuanKe = (int)$matRow['pertemuan_ke'];
                }

                $syncedCount = 0;
                foreach ($attendances as $siswaId => $status) {
                    $siswaId = (int)$siswaId;
                    $statusVal = in_array($status, ['hadir', 'izin', 'sakit', 'alfa'], true) ? $status : 'hadir';

                    // Cek kepemilikan siswa di cabang aktif
                    $stmtCekSiswa = $db->prepare("SELECT id_cabang FROM tabel_siswa WHERE id = ?");
                    $stmtCekSiswa->execute([$siswaId]);
                    $sRow = $stmtCekSiswa->fetch();
                    if (!$sRow || (int)$sRow['id_cabang'] !== $branchId) {
                        continue;
                    }

                    $stmtInsSiswa->execute([
                        $branchId, $siswaId, $materiId, $tanggalAbsensi,
                        $jamMasuk, $jamKeluar, $statusVal, $keteranganBAP, auth_id()
                    ]);

                    // PANGGIL FUNGSI INTI PATCH 1: Otomatis sinkronkan progres siswa berdasarkan kehadiran offline riil
                    sync_student_progress($db, $siswaId);
                    $syncedCount++;
                }

                $db->commit();
                set_flash('success', "Presensi & BAP pertemuan ke-{$pertemuanKe} berhasil disimpan! Progres {$syncedCount} siswa telah disinkronkan secara otomatis.");
                redirect('modules/mentor/absensi.php');

            } catch (\Throwable $e) {
                $db->rollBack();
                set_flash('error', 'Gagal menyimpan absensi: ' . $e->getMessage());
            }
        }
    }
}

// Ambil seluruh jadwal mentor
$stmtJadwal = $db->prepare("
    SELECT j.*, l.nama_layanan, l.kode_layanan, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_mentor = ? AND j.id_cabang = ? AND j.status = 'aktif'
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu')
");
$stmtJadwal->execute([$mentorId, $branchId]);
$jadwalList = $stmtJadwal->fetchAll();

// Jadwal terpilih untuk form
$selectedJadwalId = isset($_GET['jadwal_id']) && $_GET['jadwal_id'] !== ''
    ? (int)$_GET['jadwal_id']
    : (!empty($jadwalList) ? (int)$jadwalList[0]['id'] : 0);

$selectedJadwal = null;
foreach ($jadwalList as $j) {
    if ((int)$j['id'] === $selectedJadwalId) {
        $selectedJadwal = $j;
        break;
    }
}

// Ambil daftar siswa untuk jadwal layanan terpilih di cabang ini
$siswaKelas = [];
$modulOptions = [];

if ($selectedJadwal) {
    $stmtSiswa = $db->prepare("
        SELECT s.* 
        FROM tabel_siswa s
        WHERE s.id_layanan = ? AND s.id_cabang = ? AND s.status_belajar = 'aktif' AND s.is_archived = 0
        ORDER BY s.nama_lengkap ASC
    ");
    $stmtSiswa->execute([(int)$selectedJadwal['id_layanan'], $branchId]);
    $siswaKelas = $stmtSiswa->fetchAll();

    // Ambil opsi modul BAK untuk program layanan ini
    $stmtModul = $db->prepare("
        SELECT id, pertemuan_ke, kode_materi, judul_modul, deskripsi_materi, tipe_lampiran, external_url, file_modul
        FROM tabel_materi 
        WHERE id_layanan = ? AND status_publikasi = 'published'
        ORDER BY pertemuan_ke ASC
    ");
    $stmtModul->execute([(int)$selectedJadwal['id_layanan']]);
    $modulOptions = $stmtModul->fetchAll();
}

// TAB 1: Riwayat Presensi Kelas Siswa
$stmtHistorySiswa = $db->prepare("
    SELECT a.*, s.nama_lengkap as nama_siswa, s.nis_bisolpin, m.judul_modul, m.pertemuan_ke
    FROM tabel_absensi a
    JOIN tabel_siswa s ON s.id = a.id_siswa
    LEFT JOIN tabel_materi m ON m.id = a.id_materi_pertemuan
    WHERE a.id_cabang = ? AND a.diinput_oleh = ? AND a.tipe_absensi = 'siswa'
    ORDER BY a.tanggal_absensi DESC, a.created_at DESC
    LIMIT 25
");
$stmtHistorySiswa->execute([$branchId, auth_id()]);
$historySiswaList = $stmtHistorySiswa->fetchAll();

// TAB 2: Riwayat Kehadiran Mengajar Mentor Sendiri
$stmtHistoryMentor = $db->prepare("
    SELECT a.*, m.judul_modul, m.pertemuan_ke
    FROM tabel_absensi a
    LEFT JOIN tabel_materi m ON m.id = a.id_materi_pertemuan
    WHERE a.id_cabang = ? AND a.id_pegawai = ? AND a.tipe_absensi = 'pegawai_mentor'
    ORDER BY a.tanggal_absensi DESC, a.created_at DESC
    LIMIT 25
");
$stmtHistoryMentor->execute([$branchId, $mentorId]);
$historyMentorList = $stmtHistoryMentor->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-clipboard-check"></i>
            <span>Berita Acara Pengajaran (BAP) & Auto-Progress Siswa</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Presensi Kelas Tatap Muka</h1>
        <p class="text-xs text-slate-500 mt-1">
            Input absensi kehadiran siswa offline. Sistem akan <strong>otomatis menghitung ulang progres belajar siswa</strong> ke database tanpa perlu diketik manual.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="px-3.5 py-2 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-bold border border-teal-200 transition-all flex items-center gap-1.5 shadow-sm">
            <i class="fa-solid fa-user-clock"></i>
            <span>Ceklok Kerja Mandiri Saya</span>
        </a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

    <!-- KOLOM KIRI: FORM PRESENSI KELAS (Col-7) -->
    <div class="lg:col-span-7 bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <h2 class="text-base font-bold text-slate-800 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-pen-clip text-brand-600"></i>
            <span>Formulir Presensi & Jurnal Mengajar</span>
        </h2>

        <!-- Dropdown Pilih Kelas -->
        <div class="mb-5">
            <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Pilih Jadwal Kelas Tatap Muka</label>
            <select onchange="window.location.href='?jadwal_id=' + this.value" class="w-full px-4 py-2.5 text-xs font-bold rounded-xl border border-slate-300 bg-slate-50 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                <?php foreach ($jadwalList as $jl): ?>
                    <option value="<?= (int)$jl['id'] ?>" <?= $selectedJadwalId === (int)$jl['id'] ? 'selected' : '' ?>>
                        🗓️ <?= e($jl['hari']) ?> (<?= substr($jl['jam_mulai'], 0, 5) ?> - <?= substr($jl['jam_selesai'], 0, 5) ?>) • <?= e($jl['nama_layanan']) ?> (<?= e($jl['nama_ruangan'] ?? 'Ruang Kelas') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!$selectedJadwal): ?>
            <div class="p-8 text-center text-slate-400 text-xs">
                <i class="fa-solid fa-calendar-xmark text-3xl mb-2 text-slate-300"></i>
                <p>Anda belum memiliki jadwal mengajar aktif pada cabang ini.</p>
            </div>
        <?php else: ?>
            <form method="POST" action="" class="space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="jadwal_id" value="<?= (int)$selectedJadwal['id'] ?>">

                <!-- Info Pertemuan & Jam -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Tanggal Kelas</label>
                        <input type="date" name="tanggal_absensi" value="<?= date('Y-m-d') ?>" required class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 font-semibold">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Jam Masuk</label>
                        <input type="time" name="jam_masuk" value="<?= substr($selectedJadwal['jam_mulai'], 0, 5) ?>" required class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 font-semibold">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 mb-1">Jam Selesai</label>
                        <input type="time" name="jam_keluar" value="<?= substr($selectedJadwal['jam_selesai'], 0, 5) ?>" required class="w-full px-3 py-2 text-xs rounded-xl border border-slate-200 font-semibold">
                    </div>
                </div>

                <!-- Modul BAK yang Diajarkan & Panel Akses Bahan Ajar Terintegrasi -->
                <div class="p-4 rounded-2xl bg-teal-50/60 border border-teal-200/80">
                    <label class="block text-xs font-bold text-teal-950 mb-1.5 flex items-center justify-between">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-book-open-reader text-teal-600"></i>
                            <span>Bahan Ajar BAK untuk Pertemuan Ini</span>
                        </span>
                        <span class="text-[10px] font-semibold text-teal-700">Materi Terintegrasi</span>
                    </label>
                    <select id="select-materi" name="id_materi_pertemuan" onchange="updateMateriPreview()" class="w-full px-3.5 py-2 text-xs font-semibold rounded-xl border border-teal-200 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="">-- Pilih Sesi Modul BAK yang Diajarkan Hari Ini --</option>
                        <?php foreach ($modulOptions as $mo): ?>
                            <option value="<?= (int)$mo['id'] ?>" data-pertemuan="<?= (int)$mo['pertemuan_ke'] ?>" data-judul="<?= e($mo['judul_modul']) ?>" data-kode="<?= e($mo['kode_materi']) ?>" data-deskripsi="<?= e($mo['deskripsi_materi'] ?? 'Materi praktikum dan teori tatap muka resmi disiapkan oleh Bagian Akademik (BAK).') ?>" data-tipe="<?= e($mo['tipe_lampiran']) ?>" data-url="<?= e($mo['external_url'] ?? '') ?>" data-file="<?= e($mo['file_modul'] ?? '') ?>">
                                Sesi <?= (int)$mo['pertemuan_ke'] ?>: <?= e($mo['judul_modul']) ?> (<?= e($mo['kode_materi']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Preview Akses Bahan Ajar Terintegrasi (Tanpa Pindah Menu) -->
                    <div id="materi-preview-card" class="mt-3 p-3.5 rounded-xl bg-white border border-teal-100 shadow-sm hidden">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    <span id="prev-kode" class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-teal-100 text-teal-800">-</span>
                                    <span id="prev-sesi" class="text-xs font-bold text-slate-700">Sesi -</span>
                                </div>
                                <h4 id="prev-judul" class="text-sm font-black text-slate-800 leading-snug">-</h4>
                                <p id="prev-deskripsi" class="text-xs text-slate-500 mt-1 line-clamp-2 leading-relaxed">-</p>
                            </div>
                            <div id="prev-action-container" class="shrink-0 pt-1">
                                <!-- Tombol Aksi Buka/Unduh File atau Link disuntikkan via JS -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Jurnal KBM / Catatan Pertemuan -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">Jurnal Pengajaran & Ringkasan Kegiatan Offline</label>
                    <textarea name="keterangan_jurnal" rows="2" placeholder="Tuliskan materi yang dibahas, respon pemahaman siswa, atau latihan praktek di kelas hari ini..." class="w-full px-3.5 py-2 text-xs rounded-xl border border-slate-200"></textarea>
                </div>

                <!-- Daftar Siswa untuk Absensi -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700">Daftar Kehadiran Siswa</label>
                            <span class="text-[11px] text-slate-400">Centang kehadiran kelas tatap muka hari ini</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="setAllAttendance('hadir')" class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-bold border border-emerald-200 transition-all flex items-center gap-1">
                                <i class="fa-solid fa-check-double text-[10px]"></i>
                                <span>Tandai Semua Hadir</span>
                            </button>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600"><?= count($siswaKelas) ?> Siswa</span>
                        </div>
                    </div>

                    <?php if (empty($siswaKelas)): ?>
                        <?= render_empty_state('fa-user-slash', 'Belum Ada Siswa Terdaftar', 'Belum ada siswa aktif yang terdaftar pada program layanan ini di cabang Anda.', null, 0) ?>
                    <?php else: ?>
                        <div class="divide-y divide-slate-100 border border-slate-200 rounded-2xl overflow-hidden">
                            <?php foreach ($siswaKelas as $s): ?>
                                <div class="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white hover:bg-slate-50/50">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <strong class="text-xs font-bold text-slate-800 block"><?= e($s['nama_lengkap']) ?></strong>
                                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 font-bold border border-teal-200">
                                                Progres: <?= number_format((float)$s['persentase_progres'], 0) ?>%
                                            </span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 font-mono">NIS: <?= e($s['nis_bisolpin']) ?> • Sesi Terakhir: ke-<?= (int)$s['pertemuan_terakhir_diikuti'] ?></span>
                                    </div>

                                    <!-- Radio Status Kehadiran -->
                                    <div class="flex items-center gap-2 sm:gap-3 text-xs font-semibold flex-wrap">
                                        <label class="flex items-center gap-1.5 cursor-pointer text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 hover:bg-emerald-100">
                                            <input type="radio" name="attendance[<?= $s['id'] ?>]" value="hadir" checked class="attendance-radio text-emerald-600 focus:ring-emerald-500">
                                            <span>Hadir</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-sky-700 bg-sky-50 px-2.5 py-1 rounded-lg border border-sky-200 hover:bg-sky-100">
                                            <input type="radio" name="attendance[<?= $s['id'] ?>]" value="izin" class="attendance-radio text-sky-600 focus:ring-sky-500">
                                            <span>Izin</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200 hover:bg-indigo-100">
                                            <input type="radio" name="attendance[<?= $s['id'] ?>]" value="sakit" class="attendance-radio text-indigo-600 focus:ring-indigo-500">
                                            <span>Sakit</span>
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer text-rose-700 bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-200 hover:bg-rose-100">
                                            <input type="radio" name="attendance[<?= $s['id'] ?>]" value="alfa" class="attendance-radio text-rose-600 focus:ring-rose-500">
                                            <span>Alfa</span>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full btn-brand py-3 text-xs font-bold rounded-xl shadow-lg shadow-brand-500/20">
                                <i class="fa-solid fa-floppy-disk mr-1"></i>
                                <span>Simpan Presensi & Sinkronkan Progres Siswa Otomatis</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

            </form>
        <?php endif; ?>
    </div>

    <!-- KOLOM KANAN: RIWAYAT DENGAN 2 TAB BERSIH (Col-5) -->
    <div class="lg:col-span-5 bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-teal-600"></i>
                    <span>Log Riwayat Presensi</span>
                </h2>
            </div>

            <!-- TAB CONTROLLER (2 TAB BERSIH) -->
            <div class="flex border-b border-slate-200 mb-4 bg-slate-100 p-1 rounded-xl">
                <button type="button" onclick="switchAttendanceTab('tab-siswa')" id="btn-tab-siswa"
                        class="flex-1 py-2 text-xs font-bold rounded-lg text-brand-700 bg-white shadow-sm transition-all text-center">
                    <i class="fa-solid fa-users mr-1"></i>
                    <span>Absensi Kelas Siswa</span>
                </button>
                <button type="button" onclick="switchAttendanceTab('tab-mentor')" id="btn-tab-mentor"
                        class="flex-1 py-2 text-xs font-semibold rounded-lg text-slate-500 hover:text-slate-800 transition-all text-center">
                    <i class="fa-solid fa-chalkboard-user mr-1"></i>
                    <span>Kehadiran Mengajar Saya</span>
                </button>
            </div>

            <!-- PANEL TAB 1: ABSENSI KELAS SISWA -->
            <div id="tab-siswa" class="tab-panel">
                <p class="text-[11px] text-slate-400 mb-3">Catatan kehadiran siswa kelas tatap muka yang Anda input:</p>
                <?php if (empty($historySiswaList)): ?>
                    <div class="py-12 text-center text-slate-400 text-xs">
                        <i class="fa-solid fa-calendar-xmark text-3xl mb-2 text-slate-300"></i>
                        <p>Belum ada rekaman presensi siswa.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-2.5 overflow-y-auto max-h-[500px] pr-1">
                        <?php foreach ($historySiswaList as $h): ?>
                            <div class="p-3 rounded-xl border border-slate-100 bg-slate-50/70 text-xs flex flex-col gap-1.5 hover:bg-slate-50 transition-all">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <strong class="text-slate-800 font-bold"><?= e($h['nama_siswa']) ?></strong>
                                        <span class="text-[10px] text-slate-400 block font-mono">NIS: <?= e($h['nis_bisolpin']) ?></span>
                                    </div>
                                    <?= render_status_badge($h['status_kehadiran']) ?>
                                </div>
                                <div class="text-[11px] text-slate-500 flex items-center justify-between">
                                    <span>📅 <?= format_tanggal_indonesia($h['tanggal_absensi']) ?></span>
                                    <span>⏰ <?= substr($h['jam_masuk'], 0, 5) ?> WITA</span>
                                </div>
                                <?php if (!empty($h['judul_modul'])): ?>
                                    <div class="text-[10px] text-teal-700 font-semibold bg-teal-50/80 px-2 py-0.5 rounded border border-teal-100 truncate">
                                        <i class="fa-solid fa-book mr-1"></i>Sesi <?= (int)$h['pertemuan_ke'] ?>: <?= e($h['judul_modul']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PANEL TAB 2: ABSENSI KEHADIRAN MENGAJAR MENTOR -->
            <div id="tab-mentor" class="tab-panel hidden">
                <p class="text-[11px] text-slate-400 mb-3">Log kehadiran mengajar Anda untuk verifikasi honor cabang:</p>
                <?php if (empty($historyMentorList)): ?>
                    <div class="py-12 text-center text-slate-400 text-xs">
                        <i class="fa-solid fa-calendar-check text-3xl mb-2 text-slate-300"></i>
                        <p>Belum ada rekaman kehadiran mengajar.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-2.5 overflow-y-auto max-h-[500px] pr-1">
                        <?php foreach ($historyMentorList as $hm): ?>
                            <div class="p-3 rounded-xl border border-emerald-100 bg-emerald-50/30 text-xs flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-800 flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-emerald-600"></i>
                                        Sesi Mengajar Kelas Tatap Muka
                                    </span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        Terverifikasi
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-600 flex items-center justify-between font-mono">
                                    <span>📅 <?= format_tanggal_indonesia($hm['tanggal_absensi']) ?></span>
                                    <span>⏱️ <?= substr($hm['jam_masuk'], 0, 5) ?> - <?= $hm['jam_keluar'] ? substr($hm['jam_keluar'], 0, 5) : '-' ?> WITA</span>
                                </div>
                                <?php if (!empty($hm['keterangan_jurnal'])): ?>
                                    <p class="text-[11px] text-slate-600 bg-white p-2 rounded-lg border border-slate-200/60 italic">
                                        "<?= e($hm['keterangan_jurnal']) ?>"
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div>

<script>
function setAllAttendance(val) {
    document.querySelectorAll('.attendance-radio[value="' + val + '"]').forEach(radio => {
        radio.checked = true;
    });
}

function updateMateriPreview() {
    const sel = document.getElementById('select-materi');
    const card = document.getElementById('materi-preview-card');
    const container = document.getElementById('prev-action-container');
    
    if (!sel || !sel.value) {
        card.classList.add('hidden');
        return;
    }

    const opt = sel.options[sel.selectedIndex];
    const pertemuan = opt.getAttribute('data-pertemuan');
    const judul = opt.getAttribute('data-judul');
    const kode = opt.getAttribute('data-kode');
    const deskripsi = opt.getAttribute('data-deskripsi');
    const tipe = opt.getAttribute('data-tipe');
    const url = opt.getAttribute('data-url');
    const file = opt.getAttribute('data-file');

    document.getElementById('prev-sesi').innerText = 'Sesi Pertemuan Ke-' + pertemuan;
    document.getElementById('prev-kode').innerText = kode;
    document.getElementById('prev-judul').innerText = judul;
    document.getElementById('prev-deskripsi').innerText = deskripsi;

    let actionBtn = '';
    if (tipe === 'link' && url) {
        actionBtn = '<a href="' + url + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold shadow-sm transition-all">' +
            '<i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i><span>Buka Link Materi</span></a>';
    } else if (file) {
        actionBtn = '<a href="<?= base_url('assets/uploads/modul_bak/') ?>' + encodeURIComponent(file) + '" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold shadow-sm transition-all">' +
            '<i class="fa-solid fa-file-pdf text-[10px]"></i><span>Buka Berkas Modul</span></a>';
    } else {
        actionBtn = '<span class="text-xs text-slate-400 italic">Belum ada file</span>';
    }
    container.innerHTML = actionBtn;
    card.classList.remove('hidden');
}

// Inisialisasi preview jika sudah ada materi terpilih saat load
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('select-materi');
    if (sel && sel.value) {
        updateMateriPreview();
    }
});

function switchAttendanceTab(tabId) {
    document.querySelectorAll('.tab-panel').forEach(el => el.classList.add('hidden'));
    document.getElementById(tabId).classList.remove('hidden');

    const btnSiswa = document.getElementById('btn-tab-siswa');
    const btnMentor = document.getElementById('btn-tab-mentor');

    if (tabId === 'tab-siswa') {
        btnSiswa.className = "flex-1 py-2 text-xs font-bold rounded-lg text-brand-700 bg-white shadow-sm transition-all text-center";
        btnMentor.className = "flex-1 py-2 text-xs font-semibold rounded-lg text-slate-500 hover:text-slate-800 transition-all text-center";
    } else {
        btnMentor.className = "flex-1 py-2 text-xs font-bold rounded-lg text-brand-700 bg-white shadow-sm transition-all text-center";
        btnSiswa.className = "flex-1 py-2 text-xs font-semibold rounded-lg text-slate-500 hover:text-slate-800 transition-all text-center";
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
