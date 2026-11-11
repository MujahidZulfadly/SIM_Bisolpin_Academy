<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Siswa (Portal Belajar & Tracking Capaian Tatap Muka)
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Siswa';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SISWA
check_role(ROLE_SISWA);

$db = get_db();
$userId = auth_id();

// Ambil Profil Lengkap Siswa
$stmtSiswa = $db->prepare("
    SELECT s.*, l.nama_layanan, l.kode_layanan, l.kategori_jenjang, l.jumlah_pertemuan, l.target_output_produk,
           c.nama_cabang, c.alamat as alamat_cabang, c.telepon as telp_cabang,
           p.nama_lengkap as nama_mentor, p.spesialisasi_mentor, p.no_hp as hp_mentor
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    JOIN tabel_cabang c ON c.id = s.id_cabang
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    WHERE s.user_id = ?
");
$stmtSiswa->execute([$userId]);
$siswa = $stmtSiswa->fetch();

// Fallback jika belum terdaftar di tabel_siswa
if (!$siswa) {
    echo "<div class='p-8 text-center text-rose-600 font-bold'>Data profil siswa belum terhubung dengan akun Anda. Hubungi Front Office cabang.</div>";
    exit;
}

$siswaId = (int)$siswa['id'];
$branchId = (int)$siswa['id_cabang'];
$layananId = (int)$siswa['id_layanan'];

// 1. Rekap Presensi Siswa di Kelas Tatap Muka
$stmtHadir = $db->prepare("SELECT COUNT(*) FROM tabel_absensi WHERE id_siswa = ? AND status_kehadiran = 'hadir'");
$stmtHadir->execute([$siswaId]);
$totalHadir = (int)$stmtHadir->fetchColumn();

$stmtIzin = $db->prepare("SELECT COUNT(*) FROM tabel_absensi WHERE id_siswa = ? AND status_kehadiran IN ('izin', 'sakit')");
$stmtIzin->execute([$siswaId]);
$totalIzinSakit = (int)$stmtIzin->fetchColumn();

// 2. Rekap Karya / Produk Siswa
$stmtKarya = $db->prepare("
    SELECT COUNT(*) as total_karya,
           SUM(CASE WHEN status_tampil = 'dipublikasikan' THEN 1 ELSE 0 END) as karya_publish,
           AVG(CASE WHEN nilai_karya IS NOT NULL THEN nilai_karya ELSE NULL END) as rata_nilai
    FROM tabel_produk
    WHERE id_siswa = ?
");
$stmtKarya->execute([$siswaId]);
$karyaStat = $stmtKarya->fetch();
$totalKarya = (int)($karyaStat['total_karya'] ?? 0);
$karyaPublish = (int)($karyaStat['karya_publish'] ?? 0);
$rataNilai = $karyaStat['rata_nilai'] !== null ? round((float)$karyaStat['rata_nilai'], 1) : null;

// 3. Jadwal Kelas Tatap Muka Terdekat Siswa
$hariIni = format_hari_indonesia(date('Y-m-d'));
$stmtJadwal = $db->prepare("
    SELECT j.*, a.nama_aset as nama_ruangan, p.nama_lengkap as nama_mentor
    FROM jadwal_kelas j
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    JOIN tabel_pegawai p ON p.id = j.id_mentor
    WHERE j.id_cabang = ? AND j.id_layanan = ? AND j.status = 'aktif'
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC
");
$stmtJadwal->execute([$branchId, $layananId]);
$jadwalKelasList = $stmtJadwal->fetchAll();

// 4. Modul Pembelajaran Resmi dari BAK
$stmtModul = $db->prepare("
    SELECT * FROM tabel_materi
    WHERE id_layanan = ? AND status_publikasi = 'published'
    ORDER BY pertemuan_ke ASC
    LIMIT 4
");
$stmtModul->execute([$layananId]);
$modulList = $stmtModul->fetchAll();

// 5. Cek Status Evaluasi Kinerja Mentor (EDOM Prompt saat Progres >= 80%)
$mentorId = (int)($siswa['id_mentor_pembimbing'] ?? 0);
$needsMentorEvaluation = false;
$existingEvaluation = null;

if ($mentorId > 0) {
    $stmtCekEval = $db->prepare("
        SELECT id, nilai_rata_rata, created_at 
        FROM tabel_evaluasi_mentor 
        WHERE id_siswa = ? AND id_mentor = ? AND id_layanan = ?
    ");
    $stmtCekEval->execute([$siswaId, $mentorId, $layananId]);
    $existingEvaluation = $stmtCekEval->fetch();

    if (!$existingEvaluation && (float)$siswa['persentase_progres'] >= 80.0) {
        $needsMentorEvaluation = true;
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Sambutan Siswa -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Portal Akademik Siswa & Portofolio Karya</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Halo, <?= e($siswa['nama_lengkap']) ?>! 👋
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            <?= e($siswa['nama_layanan']) ?> &bull; <?= e($siswa['nama_cabang']) ?> &bull; NIS: <span class="font-mono font-bold text-teal-700"><?= e($siswa['nis_bisolpin']) ?></span>
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="<?= base_url('modules/siswa/materi.php') ?>" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-book-open text-xs"></i>
            <span>Buka Modul Belajar</span>
        </a>
        <a href="<?= base_url('modules/siswa/produk_saya.php') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-palette text-xs"></i>
            <span>Karya Saya</span>
        </a>
        <a href="<?= base_url('modules/siswa/evaluasi_mentor.php') ?>" class="px-3.5 py-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
            <i class="fa-solid fa-star text-amber-500"></i>
            <span><?= $existingEvaluation ? 'Rating Mentor: ' . number_format((float)$existingEvaluation['nilai_rata_rata'], 1) . ' ⭐' : 'Evaluasi Mentor' ?></span>
        </a>
    </div>
</div>

<?php if ($needsMentorEvaluation): ?>
<!-- Banner Notifikasi Evaluasi Mentor (Progres >= 80%) -->
<div class="mb-8 p-6 rounded-3xl bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 text-white shadow-xl shadow-amber-500/20 relative overflow-hidden flex flex-col md:flex-row md:items-center justify-between gap-6 animate-in fade-in slide-in-from-top-4 duration-300">
    <div class="absolute -right-6 -bottom-6 w-36 h-36 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
    <div class="flex items-start gap-4 z-10">
        <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-2xl shrink-0 text-amber-100 shadow-inner">
            <i class="fa-solid fa-star"></i>
        </div>
        <div>
            <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-white/25 text-white text-[11px] font-bold mb-1.5 tracking-wide">
                <i class="fa-solid fa-bullhorn text-[10px]"></i>
                <span>Progres Belajar Anda Mencapai <?= number_format((float)$siswa['persentase_progres'], 0) ?>%</span>
            </div>
            <h3 class="text-lg font-black tracking-tight leading-snug">Beri Penilaian untuk Mentor Anda</h3>
            <p class="text-xs text-amber-100 mt-1 max-w-xl leading-relaxed">
                Bantu kami meningkatkan kualitas pengajaran di BISOLPIN ACADEMY. Penilaian Anda dijamin <strong>100% anonim dan rahasia</strong>.
            </p>
        </div>
    </div>
    <div class="z-10 shrink-0">
        <a href="<?= base_url('modules/siswa/evaluasi_mentor.php') ?>" class="px-5 py-3 rounded-2xl bg-white text-amber-900 hover:bg-amber-50 font-black text-xs transition-all shadow-lg shadow-black/10 flex items-center gap-2 whitespace-nowrap hover:scale-105">
            <i class="fa-solid fa-pen-to-square text-amber-600"></i>
            <span>Beri Penilaian Sekarang &rarr;</span>
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- BENTO BOX GRID LAYOUT SISWA -->
<!-- ========================================================================= -->
<div class="bento-grid">

    <!-- KPI 1: Progres Capaian Belajar -->
    <div class="bento-card col-span-12 md:col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Capaian Belajar</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-sliders"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= number_format((float)$siswa['persentase_progres'], 0) ?>%</span>
            <span class="text-xs text-slate-400 font-medium">Tuntas</span>
        </div>

        <!-- Ruangguru-style Progress Bar & Penjelasan Konkret Progres Siswa -->
        <div class="mt-4">
            <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden shadow-inner">
                <div class="bg-gradient-to-r from-teal-500 to-emerald-500 h-3 rounded-full transition-all duration-500 shadow-sm" style="width: <?= min(100, (float)$siswa['persentase_progres']) ?>%"></div>
            </div>
            <div class="mt-2.5 flex items-center justify-between text-xs">
                <span class="font-bold text-teal-800 flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-[11px]"></i>
                    <span><?= number_format((float)$siswa['persentase_progres'], 0) ?>% Selesai (Hadir di <?= $totalHadir ?> dari <?= (int)$siswa['jumlah_pertemuan'] ?> total pertemuan kursus)</span>
                </span>
                <span class="text-slate-400 font-medium">Sesi Ke-<?= (int)$siswa['pertemuan_terakhir_diikuti'] ?></span>
            </div>
        </div>
    </div>

    <!-- KPI 2: Kehadiran Tatap Muka -->
    <div class="bento-card col-span-12 md:col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Kehadiran di Kelas</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-emerald-600"><?= $totalHadir ?></span>
            <span class="text-xs text-slate-400 font-medium">Sesi Tatap Muka</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Izin / Sakit:</span>
            <span class="font-bold text-amber-600"><?= $totalIzinSakit ?> Pertemuan</span>
        </div>
    </div>

    <!-- KPI 3: Portofolio Karya Siswa -->
    <div class="bento-card col-span-12 md:col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Karya & Produk Saya</span>
            <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-award"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= $totalKarya ?></span>
            <span class="text-xs text-slate-400 font-medium">Karya Diunggah</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Nilai Rata-Rata:</span>
            <span class="font-bold text-brand-600"><?= $rataNilai !== null ? $rataNilai . ' / 100' : 'Menunggu Nilai' ?></span>
        </div>
    </div>

    <!-- ROW 2: Target Capaian Output & Catatan Evaluasi Mentor (Bento 6 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-6 flex flex-col justify-between">
        <div>
            <div class="bento-card-header">
                <div>
                    <h3 class="text-base font-bold text-slate-800">Target Capaian & Review Mentor</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Ulasan dan evaluasi kemajuan belajar tatap muka</p>
                </div>
                <div class="w-8 h-8 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
            </div>

            <!-- Target Capaian Standar -->
            <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 mb-4">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Target Output Kursus:</span>
                <p class="text-xs font-bold text-teal-900 mt-1">
                    <i class="fa-solid fa-gem text-teal-600 mr-1.5"></i>
                    <?= e($siswa['target_output_produk'] ?? 'Menyelesaikan modul dan karya proyek mandiri.') ?>
                </p>
            </div>

            <?php if ($siswa['skor_tka'] !== null): ?>
            <!-- Rapor Simulasi TKA & Try Out -->
            <div class="p-3.5 rounded-xl bg-purple-50 border border-purple-200 mb-4">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold text-purple-700 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-square-poll-vertical"></i> Hasil Simulasi TKA & Try Out
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full bg-purple-600 text-white font-black text-xs shadow-sm">
                        Skor: <?= number_format((float)$siswa['skor_tka'], 1) ?>
                    </span>
                </div>
                <?php if (!empty($siswa['detail_tka'])): ?>
                    <p class="text-[11px] text-purple-900 font-medium mt-1.5 flex items-center gap-1.5">
                        <i class="fa-solid fa-circle-info text-purple-600"></i>
                        <span><?= e($siswa['detail_tka']) ?></span>
                    </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Catatan Mentor -->
            <div class="p-4 rounded-xl bg-gradient-to-br from-amber-50/50 to-orange-50/50 border border-amber-200 text-xs">
                <div class="flex items-center gap-2 mb-2 font-bold text-amber-900">
                    <i class="fa-solid fa-comment-dots text-amber-600"></i>
                    <span>Catatan Terkini dari Mentor Pembimbing:</span>
                </div>
                <p class="text-slate-700 italic leading-relaxed">
                    "<?= e($siswa['catatan_progres_mentor'] ?? 'Belum ada catatan progres dari mentor. Terus semangat mengikuti setiap sesi tatap muka!') ?>"
                </p>
                <?php if (!empty($siswa['nama_mentor'])): ?>
                    <p class="text-[11px] font-semibold text-slate-500 text-right mt-2">
                        &mdash; <?= e($siswa['nama_mentor']) ?> (<?= e($siswa['spesialisasi_mentor'] ?? 'Mentor') ?>)
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Kontak Mentor:</span>
            <?php if (!empty($siswa['hp_mentor'])): ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $siswa['hp_mentor']) ?>" target="_blank" class="font-bold text-emerald-600 hover:underline">
                    <i class="fa-brands fa-whatsapp"></i> Chat WhatsApp
                </a>
            <?php else: ?>
                <span class="text-slate-400 italic">Melalui Front Office</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ROW 2: Jadwal Tatap Muka Minggu Ini (Bento 6 Kolom) -->
    <div class="bento-card col-span-12 lg:col-span-6 flex flex-col justify-between">
        <div>
            <div class="bento-card-header">
                <div>
                    <h3 class="text-base font-bold text-slate-800">Jadwal Kelas Tatap Muka</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Sesi tatap muka di ruang fisik cabang <?= e($siswa['nama_cabang']) ?></p>
                </div>
                <a href="<?= base_url('modules/siswa/jadwal.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                    Lihat Lengkap <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
                </a>
            </div>

            <?php if (empty($jadwalKelasList)): ?>
                <div class="p-8 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
                    <p class="text-xs text-slate-400">Jadwal kelas tatap muka sedang dipersiapkan oleh staf cabang.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($jadwalKelasList as $jk): ?>
                        <div class="p-3.5 rounded-xl border border-slate-100 bg-slate-50/70 hover:bg-white hover:border-brand-200 hover:shadow-sm transition-all flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center font-black text-xs flex-shrink-0">
                                    <?= substr($jk['hari'], 0, 3) ?>
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-800">Hari <?= e($jk['hari']) ?></h4>
                                    <p class="text-[11px] text-slate-500 font-medium">
                                        <i class="fa-regular fa-clock mr-1 text-slate-400"></i>
                                        <?= date('H:i', strtotime($jk['jam_mulai'])) ?> - <?= date('H:i', strtotime($jk['jam_selesai'])) ?> WITA
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-xs font-bold text-slate-700 block"><?= e($jk['nama_ruangan'] ?? 'Ruang Kelas') ?></span>
                                <span class="text-[11px] text-teal-600 font-semibold"><?= e($jk['nama_mentor']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-400">
            <i class="fa-solid fa-location-dot text-slate-400 mr-1"></i>
            Lokasi Cabang: <?= e($siswa['alamat_cabang']) ?>
        </div>
    </div>

    <!-- ROW 3: Modul Pembelajaran Resmi dari BAK (Bento 12 Kolom) -->
    <div class="bento-card col-span-12">
        <div class="bento-card-header">
            <div>
                <h3 class="text-base font-bold text-slate-800">Modul Belajar Resmi (Disusun oleh BAK)</h3>
                <p class="text-xs text-slate-400 mt-0.5">Modul pembelajaran resmi format PDF/PPT untuk sesi tatap muka</p>
            </div>
            <a href="<?= base_url('modules/siswa/materi.php') ?>" class="text-xs font-bold text-brand-600 hover:text-brand-700">
                Semua Modul <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
            </a>
        </div>

        <?php if (empty($modulList)): ?>
            <div class="p-6 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400">Modul sedang difinalisasi oleh Bagian Akademik (BAK) pusat.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($modulList as $m): ?>
                    <div class="p-4 rounded-xl border border-slate-200 bg-white hover:border-brand-300 hover:shadow-md transition-all flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[10px] font-black uppercase tracking-wider text-teal-700 bg-teal-50 px-2 py-0.5 rounded border border-teal-200">
                                    Pertemuan <?= $m['pertemuan_ke'] ?>
                                </span>
                                <?php if ($m['pertemuan_ke'] <= $siswa['pertemuan_terakhir_diikuti']): ?>
                                    <span class="text-[10px] font-bold text-emerald-600"><i class="fa-solid fa-check"></i> Selesai</span>
                                <?php else: ?>
                                    <span class="text-[10px] font-semibold text-slate-400">Mendatang</span>
                                <?php endif; ?>
                            </div>

                            <h4 class="text-xs font-bold text-slate-800 line-clamp-2"><?= e($m['judul_modul']) ?></h4>
                            <p class="text-[11px] text-slate-500 font-medium mt-0.5"><?= e($m['nama_pelajaran']) ?></p>
                            <p class="text-[11px] text-slate-400 line-clamp-2 mt-2 leading-relaxed">
                                <?= e($m['ringkasan_materi']) ?>
                            </p>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                            <span class="text-[10px] font-mono text-slate-400"><?= e($m['kode_materi']) ?></span>
                            <?php if (!empty($m['file_modul'])): ?>
                                <a href="<?= base_url('assets/uploads/modul_bak/' . rawurlencode($m['file_modul'])) ?>" target="_blank" class="text-xs font-bold text-teal-600 hover:text-teal-700 inline-flex items-center gap-1">
                                    <i class="fa-solid fa-download text-[10px]"></i> Unduh PDF
                                </a>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-400 italic">Bahan Kelas</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

