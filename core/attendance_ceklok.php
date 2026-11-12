<?php
/**
 * SIM BISOLPIN ACADEMY - Core Komponen & Logika Ceklok Presensi Mandiri (Self Clock-In & Out)
 * Mendukung role: direktur_cabang, staff, mentor, sarpras, dan bak.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/flash.php';

/**
 * Cek apakah role user saat ini berhak melakukan Ceklok Mandiri
 * Sesuai SOP resmi, ceklok mandiri berlaku untuk Staff Administrasi, Mentor Pengajar, dan Sarpras.
 * Pimpinan Pusat, Direktur Cabang (Mitra), dan BAK tidak dikenakan sistem ceklok.
 */
function is_eligible_for_ceklok(?string $role = null): bool
{
    $role = $role ?? auth_role();
    return in_array($role, [
        ROLE_STAFF,
        ROLE_MENTOR,
        ROLE_SARPRAS
    ], true);
}

/**
 * Ambil data pegawai dari tabel_pegawai berdasarkan user yang login
 */
function get_logged_in_pegawai(?int $userId = null): ?array
{
    $userId = $userId ?? auth_id();
    if (!$userId) {
        return null;
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM tabel_pegawai WHERE user_id = ?");
    $stmt->execute([$userId]);
    $pegawai = $stmt->fetch();

    if ($pegawai) {
        return $pegawai;
    }

    // Jika pegawai belum terhubung di tabel_pegawai (auto-provision fallback)
    $stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $u = $stmtUser->fetch();
    if (!$u) {
        return null;
    }

    $nipAuto = sprintf("PEG-%02d-%03d", (int)($u['id_cabang'] ?? 1), $u['id']);
    $namaAuto = ucfirst($u['username']);
    try {
        $stmtInsert = $db->prepare("
            INSERT INTO tabel_pegawai (
                user_id, id_cabang, nip_pegawai, nama_lengkap, jabatan_role,
                status_kepegawaian, status_aktif, tanggal_masuk, created_at
            ) VALUES (?, ?, ?, ?, ?, 'tetap', 'aktif', CURDATE(), NOW())
        ");
        $stmtInsert->execute([
            $u['id'],
            $u['id_cabang'],
            $nipAuto,
            $namaAuto,
            $u['role']
        ]);
        $newId = (int)$db->lastInsertId();
        return [
            'id' => $newId,
            'user_id' => $u['id'],
            'id_cabang' => $u['id_cabang'],
            'nip_pegawai' => $nipAuto,
            'nama_lengkap' => $namaAuto,
            'jabatan_role' => $u['role']
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Ambil rekap data presensi hari ini untuk pegawai tertentu
 */
function get_today_pegawai_attendance(int $pegawaiId): ?array
{
    $db = get_db();
    $stmt = $db->prepare("
        SELECT * FROM tabel_absensi
        WHERE tipe_absensi = 'pegawai_mentor'
          AND id_pegawai = ?
          AND tanggal_absensi = CURDATE()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$pegawaiId]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Menghitung selisih jam kerja (durasi) antara jam masuk dan jam keluar
 */
function calculate_work_duration(string $jamMasuk, ?string $jamKeluar): string
{
    if (empty($jamKeluar)) {
        return 'Sedang Berlangsung';
    }

    $start = strtotime($jamMasuk);
    $end = strtotime($jamKeluar);
    if ($start === false || $end === false || $end < $start) {
        return '-';
    }

    $diffSeconds = $end - $start;
    $hours = floor($diffSeconds / 3600);
    $minutes = floor(($diffSeconds % 3600) / 60);

    if ($hours > 0 && $minutes > 0) {
        return "{$hours} Jam {$minutes} Menit";
    } elseif ($hours > 0) {
        return "{$hours} Jam";
    } else {
        return "{$minutes} Menit";
    }
}

/**
 * Penanganan Eksekusi POST Ceklok (Clock-In, Clock-Out, Izin/Sakit)
 */
function handle_attendance_post_action(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['attendance_action'] ?? '';
    if (!in_array($action, ['clock_in', 'clock_out', 'izin_sakit'], true)) {
        return;
    }

    validate_csrf_or_abort();

    if (!is_eligible_for_ceklok()) {
        redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Role Anda tidak memiliki akses presensi pegawai mandiri.', 'Akses Ditolak');
    }

    $pegawai = get_logged_in_pegawai();
    if (!$pegawai) {
        redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Data profil pegawai Anda tidak ditemukan dalam sistem.', 'Gagal Ceklok');
    }

    $pegawaiId = (int)$pegawai['id'];
    $db = get_db();
    $idCabang = $pegawai['id_cabang'] ?? auth_branch_id() ?? 1;

    // 1. CLOCK-IN MASUK
    if ($action === 'clock_in') {
        $todayAbsensi = get_today_pegawai_attendance($pegawaiId);
        if ($todayAbsensi) {
            redirect_with_message($_SERVER['REQUEST_URI'], 'warning', 'Anda sudah melakukan presensi untuk hari ini.', 'Presensi Sudah Ada');
        }

        $jamMasuk = date('H:i:s');
        $catatan = trim($_POST['keterangan_jurnal'] ?? 'Clock-In kehadiran mandiri via dashboard SIM-Bisolpin.');

        try {
            $stmt = $db->prepare("
                INSERT INTO tabel_absensi (
                    id_cabang, tipe_absensi, id_siswa, id_pegawai, id_materi_pertemuan,
                    tanggal_absensi, jam_masuk, jam_keluar, status_kehadiran,
                    keterangan_jurnal, diinput_oleh, created_at
                ) VALUES (
                    ?, 'pegawai_mentor', NULL, ?, NULL,
                    CURDATE(), ?, NULL, 'hadir',
                    ?, ?, NOW()
                )
            ");
            $stmt->execute([
                $idCabang,
                $pegawaiId,
                $jamMasuk,
                $catatan,
                auth_id()
            ]);

            redirect_with_message($_SERVER['REQUEST_URI'], 'success', "Berhasil Clock-In masuk pukul {$jamMasuk}. Selamat menjalankan tugas hari ini!", 'Clock-In Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal clock-in: " . $e->getMessage());
            redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Gagal memproses Clock-In: ' . $e->getMessage(), 'Kesalahan Sistem');
        }
    }

    // 2. CLOCK-OUT PULANG
    if ($action === 'clock_out') {
        $todayAbsensi = get_today_pegawai_attendance($pegawaiId);
        if (!$todayAbsensi) {
            redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Anda belum melakukan Clock-In masuk hari ini.', 'Belum Absen Masuk');
        }
        if (!empty($todayAbsensi['jam_keluar'])) {
            redirect_with_message($_SERVER['REQUEST_URI'], 'info', "Anda sudah melakukan Clock-Out pulang sebelumnya pukul {$todayAbsensi['jam_keluar']}.", 'Sudah Selesai');
        }

        $jamKeluar = date('H:i:s');
        $catatanPulang = trim($_POST['catatan_pulang'] ?? '');
        $jurnalAkhir = $todayAbsensi['keterangan_jurnal'] ?? '';
        if (!empty($catatanPulang)) {
            $jurnalAkhir .= " | Laporan harian: " . $catatanPulang;
        }

        try {
            $stmt = $db->prepare("
                UPDATE tabel_absensi SET
                    jam_keluar = ?,
                    keterangan_jurnal = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $jamKeluar,
                $jurnalAkhir,
                $todayAbsensi['id']
            ]);

            $durasi = calculate_work_duration($todayAbsensi['jam_masuk'], $jamKeluar);
            redirect_with_message($_SERVER['REQUEST_URI'], 'success', "Berhasil Clock-Out pulang pukul {$jamKeluar} (Durasi kerja: {$durasi}). Terima kasih atas dedikasi Anda!", 'Clock-Out Selesai');
        } catch (\Throwable $e) {
            error_log("Gagal clock-out: " . $e->getMessage());
            redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Gagal memproses Clock-Out: ' . $e->getMessage(), 'Kesalahan Sistem');
        }
    }

    // 3. AJUKAN IZIN / SAKIT
    if ($action === 'izin_sakit') {
        $todayAbsensi = get_today_pegawai_attendance($pegawaiId);
        if ($todayAbsensi) {
            redirect_with_message($_SERVER['REQUEST_URI'], 'warning', 'Catatan presensi hari ini sudah terekam.', 'Sudah Ada Presensi');
        }

        $status = in_array($_POST['status_kehadiran'] ?? '', ['izin', 'sakit'], true) ? $_POST['status_kehadiran'] : 'izin';
        $alasan = trim($_POST['keterangan_alasan'] ?? '');
        if (empty($alasan)) {
            redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Mohon tuliskan keterangan / alasan pengajuan izin atau sakit.', 'Validasi Gagal');
        }

        $jamNow = date('H:i:s');
        try {
            $stmt = $db->prepare("
                INSERT INTO tabel_absensi (
                    id_cabang, tipe_absensi, id_siswa, id_pegawai, id_materi_pertemuan,
                    tanggal_absensi, jam_masuk, jam_keluar, status_kehadiran,
                    keterangan_jurnal, diinput_oleh, created_at
                ) VALUES (
                    ?, 'pegawai_mentor', NULL, ?, NULL,
                    CURDATE(), ?, ?, ?,
                    ?, ?, NOW()
                )
            ");
            $stmt->execute([
                $idCabang,
                $pegawaiId,
                $jamNow,
                $jamNow,
                $status,
                "[Pengajuan " . strtoupper($status) . "] " . $alasan,
                auth_id()
            ]);

            $label = strtoupper($status);
            redirect_with_message($_SERVER['REQUEST_URI'], 'success', "Pengajuan {$label} Anda telah berhasil dicatat ke sistem presensi.", "Status {$label} Tercatat");
        } catch (\Throwable $e) {
            error_log("Gagal ajukan izin/sakit: " . $e->getMessage());
            redirect_with_message($_SERVER['REQUEST_URI'], 'error', 'Gagal mencatat izin/sakit: ' . $e->getMessage(), 'Kesalahan Sistem');
        }
    }
}

/**
 * Render Visual Widget Ceklok Presensi Mandiri
 */
function render_attendance_widget(): void
{
    if (!is_eligible_for_ceklok()) {
        return;
    }

    $pegawai = get_logged_in_pegawai();
    $pegawaiId = $pegawai ? (int)$pegawai['id'] : 0;
    $todayAbsen = $pegawaiId > 0 ? get_today_pegawai_attendance($pegawaiId) : null;

    $tanggalIndo = format_tanggal_indonesia(date('Y-m-d'));
    $hariIndo = format_hari_indonesia(date('Y-m-d'));
    $nip = $pegawai['nip_pegawai'] ?? '-';
    $nama = $pegawai['nama_lengkap'] ?? auth_user()['nama_lengkap'] ?? 'Pegawai';
    $roleName = APP_ROLES[auth_role()]['label'] ?? ucfirst(auth_role());
    // Tentukan langkah aktif (1: Belum Masuk, 2: Sedang Bekerja, 3: Sudah Pulang)
    $currentStep = 1;
    if ($todayAbsen && $todayAbsen['status_kehadiran'] === 'hadir') {
        $currentStep = empty($todayAbsen['jam_keluar']) ? 2 : 3;
    } elseif ($todayAbsen && in_array($todayAbsen['status_kehadiran'], ['izin', 'sakit'], true)) {
        $currentStep = 3;
    }
    ?>
    <!-- ========================================================================= -->
    <!-- WIDGET CEKLOK PRESENSI MANDIRI (BISOLPIN ACADEMY) -->
    <!-- ========================================================================= -->
    <div class="mb-8 rounded-3xl bg-gradient-to-r from-slate-900 via-slate-800 to-teal-950 text-white p-6 shadow-xl border border-slate-700/60 relative overflow-hidden">
        <!-- Background Glow Accent -->
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-brand-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Top Header & Alur Indikator Langkah (IMK: Mencegah Mode Error) -->
        <div class="mb-5 pb-4 border-b border-white/10 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold border border-emerald-500/30">
                    <i class="fa-solid fa-id-badge"></i>
                    <span>Ceklok Kehadiran Kerja Pegawai</span>
                </span>
                <span class="text-xs text-slate-300 hidden sm:inline">• Presensi kehadiran kerja pribadi (bukan absensi siswa)</span>
            </div>

            <!-- Stepper 3 Langkah Kehadiran -->
            <div class="flex items-center gap-1.5 text-[11px] font-bold">
                <!-- Step 1 -->
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $currentStep === 1 ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30 font-black' : ($currentStep > 1 ? 'text-emerald-400 bg-white/5' : 'text-slate-500 bg-white/5') ?>">
                    <i class="fa-solid <?= $currentStep > 1 ? 'fa-circle-check text-emerald-400' : 'fa-circle-dot' ?>"></i>
                    <span>1. Belum Masuk</span>
                </div>
                <i class="fa-solid fa-chevron-right text-[10px] text-slate-600"></i>

                <!-- Step 2 -->
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $currentStep === 2 ? 'bg-teal-500/20 text-teal-300 border border-teal-500/30 font-black ring-2 ring-teal-400/30' : ($currentStep > 2 ? 'text-emerald-400 bg-white/5' : 'text-slate-500 bg-white/5') ?>">
                    <i class="fa-solid <?= $currentStep === 2 ? 'fa-spinner fa-spin text-teal-300' : ($currentStep > 2 ? 'fa-circle-check text-emerald-400' : 'fa-circle-dot') ?>"></i>
                    <span>2. Sedang Bekerja</span>
                </div>
                <i class="fa-solid fa-chevron-right text-[10px] text-slate-600"></i>

                <!-- Step 3 -->
                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $currentStep === 3 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-black' : 'text-slate-500 bg-white/5' ?>">
                    <i class="fa-solid <?= $currentStep === 3 ? 'fa-circle-check text-emerald-400' : 'fa-circle-dot' ?>"></i>
                    <span>3. Selesai Pulang</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
            <!-- Sisi Kiri: Waktu Digital & Identitas Pegawai -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-5">
                <!-- Clock Digital Box -->
                <div class="p-4 rounded-2xl bg-white/10 backdrop-blur-md border border-white/10 text-center min-w-[170px] shadow-inner">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-teal-300 flex items-center justify-center gap-1.5 mb-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Waktu Sistem</span>
                    </div>
                    <div id="liveDigitalClock" class="text-3xl font-black font-mono tracking-wider text-white">
                        <?= date('H:i:s') ?>
                    </div>
                    <div class="text-[11px] font-medium text-slate-300 mt-1">
                        <?= e($hariIndo) ?>, <?= e($tanggalIndo) ?>
                    </div>
                </div>

                <!-- Info Pegawai -->
                <div>
                    <h3 class="text-lg font-black text-white leading-tight">
                        <?= e($nama) ?>
                    </h3>
                    <p class="text-xs text-slate-300 mt-1 flex items-center gap-2 flex-wrap">
                        <span>NIP: <strong class="font-mono text-white"><?= e($nip) ?></strong></span>
                        <span>•</span>
                        <span class="px-2 py-0.5 rounded bg-white/10 text-slate-200 font-semibold text-[10px]"><?= e($roleName) ?></span>
                    </p>
                </div>
            </div>

            <!-- Sisi Kanan: Status & Tombol Aksi Ceklok -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                <?php if (!$todayAbsen): ?>
                    <!-- KONDISI 1: BELUM CLOCK-IN HARI INI -->
                    <div class="text-left sm:text-right pr-2">
                        <span class="block text-[11px] text-slate-400 font-semibold">Status Hari Ini</span>
                        <strong class="text-amber-400 text-sm flex items-center gap-1.5 sm:justify-end">
                            <i class="fa-solid fa-clock"></i> Belum Absen Masuk
                        </strong>
                    </div>

                    <form method="POST" action="" class="inline-flex">
                        <?= csrf_field() ?>
                        <input type="hidden" name="attendance_action" value="clock_in">
                        <button type="submit" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-black text-xs shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/40 transition-all flex items-center gap-2 transform active:scale-95">
                            <i class="fa-solid fa-fingerprint text-base"></i>
                            <span>Absen Masuk (Clock In)</span>
                        </button>
                    </form>

                    <button type="button" onclick="openModalIzinSakit()" class="px-3.5 py-3 rounded-2xl bg-white/10 hover:bg-white/20 text-slate-200 hover:text-white font-bold text-xs border border-white/10 transition-all flex items-center gap-1.5">
                        <i class="fa-solid fa-calendar-minus text-amber-400"></i>
                        <span>Ajukan Izin / Sakit</span>
                    </button>

                <?php elseif ($todayAbsen['status_kehadiran'] === 'hadir' && empty($todayAbsen['jam_keluar'])): ?>
                    <!-- KONDISI 2: SUDAH MASUK, BELUM CLOCK-OUT -->
                    <div class="text-left sm:text-right pr-2">
                        <span class="block text-[11px] text-emerald-400 font-semibold flex items-center gap-1 sm:justify-end">
                            <i class="fa-solid fa-circle-check"></i> Sudah Clock-In
                        </span>
                        <strong class="text-white text-xs font-mono">
                            Masuk: <?= substr($todayAbsen['jam_masuk'], 0, 5) ?> WIB
                        </strong>
                    </div>

                    <button type="button" onclick="openModalClockOut()" class="px-5 py-3 rounded-2xl bg-gradient-to-r from-rose-500 to-amber-600 hover:from-rose-600 hover:to-amber-700 text-white font-black text-xs shadow-lg shadow-rose-500/25 hover:shadow-rose-500/40 transition-all flex items-center gap-2 transform active:scale-95">
                        <i class="fa-solid fa-right-from-bracket text-base"></i>
                        <span>Absen Pulang (Clock Out)</span>
                    </button>

                <?php elseif ($todayAbsen['status_kehadiran'] === 'hadir' && !empty($todayAbsen['jam_keluar'])): ?>
                    <!-- KONDISI 3: SELESAI CLOCK-IN & CLOCK-OUT -->
                    <div class="px-4 py-3 rounded-2xl bg-emerald-500/20 border border-emerald-500/40 text-left sm:text-right">
                        <div class="text-xs font-black text-emerald-300 flex items-center gap-1.5 sm:justify-end">
                            <i class="fa-solid fa-circle-check text-emerald-400"></i>
                            <span>Presensi Hari Ini Selesai</span>
                        </div>
                        <div class="text-[11px] text-slate-300 mt-0.5">
                            Masuk: <strong class="font-mono text-white"><?= substr($todayAbsen['jam_masuk'], 0, 5) ?></strong> - Pulang: <strong class="font-mono text-white"><?= substr($todayAbsen['jam_keluar'], 0, 5) ?></strong>
                        </div>
                        <div class="text-[10px] text-emerald-400 font-semibold mt-0.5">
                            Durasi Kerja: <?= calculate_work_duration($todayAbsen['jam_masuk'], $todayAbsen['jam_keluar']) ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- KONDISI 4: STATUS KHUSUS (IZIN / SAKIT) -->
                    <div class="px-4 py-3 rounded-2xl bg-amber-500/20 border border-amber-500/40 text-left sm:text-right">
                        <div class="text-xs font-black text-amber-300 flex items-center gap-1.5 sm:justify-end">
                            <i class="fa-solid fa-calendar-xmark text-amber-400"></i>
                            <span>Status: <?= strtoupper($todayAbsen['status_kehadiran']) ?></span>
                        </div>
                        <div class="text-[11px] text-slate-300 mt-0.5 max-w-xs truncate">
                            <?= e($todayAbsen['keterangan_jurnal']) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Shortcut Riwayat Presensi Saya -->
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="px-3.5 py-3 rounded-2xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white font-bold text-xs border border-white/10 transition-all flex items-center gap-1.5" title="Buka Riwayat Presensi Lengkap">
                    <i class="fa-solid fa-history"></i>
                    <span class="hidden xl:inline">Riwayat Saya</span>
                </a>
            </div>
        </div>
    </div>

    <!-- MODAL: AJUKAN IZIN / SAKIT -->
    <div id="modalIzinSakit" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 overflow-hidden text-slate-800 animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-black text-slate-800">Ajukan Izin / Sakit</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Pemberitahuan ketidakhadiran kerja hari ini.</p>
                </div>
                <button type="button" onclick="closeModalIzinSakit()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-400 hover:text-slate-600 flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="attendance_action" value="izin_sakit">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Jenis Keterangan <span class="text-rose-500">*</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 hover:border-amber-400 cursor-pointer text-xs font-bold">
                            <input type="radio" name="status_kehadiran" value="izin" checked class="text-amber-500 focus:ring-amber-400">
                            <span><i class="fa-solid fa-envelope-open-text text-amber-500 mr-1"></i> Izin Kerja</span>
                        </label>
                        <label class="flex items-center gap-2 p-3 rounded-xl border border-slate-200 hover:border-rose-400 cursor-pointer text-xs font-bold">
                            <input type="radio" name="status_kehadiran" value="sakit" class="text-rose-500 focus:ring-rose-400">
                            <span><i class="fa-solid fa-heart-pulse text-rose-500 mr-1"></i> Sakit</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Keterangan / Alasan Lengkap <span class="text-rose-500">*</span></label>
                    <textarea name="keterangan_alasan" rows="3" required placeholder="Tuliskan keterangan detail alasan tidak dapat hadir..." class="form-input text-xs"></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeModalIzinSakit()" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="btn-brand text-xs py-2 px-5 shadow-sm">
                        Kirim Pengajuan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: CLOCK-OUT DENGAN CATATAN AKTIVITAS -->
    <div id="modalClockOut" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl shadow-2xl max-w-md w-full p-6 overflow-hidden text-slate-800 animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h3 class="text-base font-black text-slate-800">Absen Pulang (Clock Out)</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Selesaikan sesi kehadiran kerja Anda hari ini.</p>
                </div>
                <button type="button" onclick="closeModalClockOut()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-400 hover:text-slate-600 flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="" class="mt-4 space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="attendance_action" value="clock_out">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Laporan Ringkas / Aktivitas Harian (Opsional)</label>
                    <textarea name="catatan_pulang" rows="3" placeholder="Contoh: Mengajar 2 kelas offline, menyiapkan modul, dan rekap administrasi harian selesai." class="form-input text-xs"></textarea>
                    <span class="text-[10px] text-slate-400 block mt-1">Catatan ini akan tersimpan ke dalam berita acara jurnal presensi harian Anda.</span>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeModalClockOut()" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-xs font-bold hover:bg-slate-200 transition-colors">
                        Batal
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs shadow-sm transition-all flex items-center gap-1.5">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Konfirmasi Clock-Out</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Script Jam Digital Real-Time & Modal -->
    <script>
    (function() {
        function updateClock() {
            const el = document.getElementById('liveDigitalClock');
            if (!el) return;
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            el.innerText = h + ':' + m + ':' + s;
        }
        setInterval(updateClock, 1000);
        updateClock();
    })();

    function openModalIzinSakit() {
        document.getElementById('modalIzinSakit').classList.remove('hidden');
    }
    function closeModalIzinSakit() {
        document.getElementById('modalIzinSakit').classList.add('hidden');
    }

    function openModalClockOut() {
        document.getElementById('modalClockOut').classList.remove('hidden');
    }
    function closeModalClockOut() {
        document.getElementById('modalClockOut').classList.add('hidden');
    }
    </script>
    <?php
}

