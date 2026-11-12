<?php
/**
 * SIM BISOLPIN ACADEMY - Rapor Kepuasan Mengajar Mentor (EDOM & IKM)
 * Menampilkan Skor Bintang, Analisis 4 Aspek Pedagogis, dan Ulasan Anonim Siswa
 */

declare(strict_types=1);

$pageTitle = 'Rapor Kepuasan Mengajar Saya';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi role Mentor
check_role(ROLE_MENTOR);

$db = get_db();
$userId = auth_id();

// Ambil profil data pegawai mentor
$stmtMentor = $db->prepare("
    SELECT p.*, c.nama_cabang, c.kota as kota_cabang
    FROM tabel_pegawai p
    JOIN tabel_cabang c ON c.id = p.id_cabang
    WHERE p.user_id = ?
");
$stmtMentor->execute([$userId]);
$mentor = $stmtMentor->fetch();

if (!$mentor) {
    redirect_with_message('modules/mentor/index.php', 'error', 'Data profil pegawai mentor tidak ditemukan.', 'Akses Ditolak');
}

$mentorId = (int)$mentor['id'];

// Auto-migrate: Pastikan tabel_evaluasi_mentor sudah ada di database
ensure_table_evaluasi_mentor_exists($db);

$totalPenilai    = 0;
$avgKeseluruhan  = 0.0;
$avgMateri       = 0.0;
$avgPenyampaian  = 0.0;
$avgKedisiplinan = 0.0;
$avgKesabaran    = 0.0;
$ikmBadge        = get_ikm_badge(0);
$persenKepuasan  = 0;
$reviews         = [];

try {
    // 1. Ambil Ringkasan Statistik Rating & 4 Aspek Pedagogis
    $stmtStats = $db->prepare("
        SELECT 
            COUNT(*) as total_penilai,
            COALESCE(AVG(nilai_rata_rata), 0) as avg_keseluruhan,
            COALESCE(AVG(skor_materi), 0) as avg_materi,
            COALESCE(AVG(skor_penyampaian), 0) as avg_penyampaian,
            COALESCE(AVG(skor_kedisiplinan), 0) as avg_kedisiplinan,
            COALESCE(AVG(skor_kesabaran), 0) as avg_kesabaran,
            SUM(CASE WHEN nilai_rata_rata >= 4.5 THEN 1 ELSE 0 END) as cnt_bintang_5,
            SUM(CASE WHEN nilai_rata_rata >= 3.5 AND nilai_rata_rata < 4.5 THEN 1 ELSE 0 END) as cnt_bintang_4,
            SUM(CASE WHEN nilai_rata_rata >= 2.5 AND nilai_rata_rata < 3.5 THEN 1 ELSE 0 END) as cnt_bintang_3,
            SUM(CASE WHEN nilai_rata_rata < 2.5 THEN 1 ELSE 0 END) as cnt_bintang_rendah
        FROM tabel_evaluasi_mentor
        WHERE id_mentor = ?
    ");
    $stmtStats->execute([$mentorId]);
    $stats = $stmtStats->fetch();

    $totalPenilai    = (int)($stats['total_penilai'] ?? 0);
    $avgKeseluruhan  = round((float)($stats['avg_keseluruhan'] ?? 0), 2);
    $avgMateri       = round((float)($stats['avg_materi'] ?? 0), 2);
    $avgPenyampaian  = round((float)($stats['avg_penyampaian'] ?? 0), 2);
    $avgKedisiplinan = round((float)($stats['avg_kedisiplinan'] ?? 0), 2);
    $avgKesabaran    = round((float)($stats['avg_kesabaran'] ?? 0), 2);

    $ikmBadge        = get_ikm_badge($avgKeseluruhan);
    $persenKepuasan  = $avgKeseluruhan > 0 ? round(($avgKeseluruhan / 5.0) * 100, 1) : 0;

    // 2. Ambil Riwayat Ulasan & Feedback Siswa (Semua dibuat Anonim: "Siswa Bisolpin")
    $stmtReviews = $db->prepare("
        SELECT e.*, l.nama_layanan, l.kode_layanan
        FROM tabel_evaluasi_mentor e
        JOIN tabel_layanan l ON l.id = e.id_layanan
        WHERE e.id_mentor = ?
        ORDER BY e.created_at DESC
    ");
    $stmtReviews->execute([$mentorId]);
    $reviews = $stmtReviews->fetchAll();
} catch (\Throwable $e) {
    error_log("Error pada evaluasi_saya.php: " . $e->getMessage());
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-amber-900 text-xs font-bold mb-2">
            <i class="fa-solid fa-award text-amber-600"></i>
            <span>Evaluasi Dosen Oleh Mahasiswa / Siswa (EDOM)</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Rapor Kepuasan Mengajar Saya</h1>
        <p class="text-xs text-slate-500 mt-1">
            Indeks penilaian objektif dari siswa tatap muka berdasarkan 4 indikator pedagogis resmi Bisolpin Academy.
        </p>
    </div>

    <!-- Mentor Info Card -->
    <div class="flex items-center gap-3 bg-white p-3 rounded-2xl border border-slate-200 shadow-sm">
        <div class="w-10 h-10 rounded-xl bg-teal-500 text-white flex items-center justify-center font-bold text-base shadow-sm">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <div>
            <h4 class="text-xs font-bold text-slate-800"><?= e($mentor['nama_lengkap']) ?></h4>
            <p class="text-[11px] text-slate-500"><?= e($mentor['spesialisasi_mentor'] ?? 'Mentor Akademik') ?> • <?= e($mentor['nama_cabang']) ?></p>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 1. KARTU METRIK UTAMA (4 KPI CARDS) -->
<!-- ========================================================================= -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    
    <!-- KPI 1: INDEKS RATA-RATA BINTANG SAYA -->
    <div class="bg-gradient-to-br from-amber-500 via-amber-600 to-orange-600 text-white rounded-3xl p-6 shadow-xl shadow-amber-500/10 relative overflow-hidden">
        <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-amber-100">Indeks Rata-rata Bintang</span>
            <div class="w-9 h-9 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center text-amber-200">
                <i class="fa-solid fa-star"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2 mb-1">
            <span class="text-3xl sm:text-4xl font-black tracking-tight"><?= number_format($avgKeseluruhan, 2) ?></span>
            <span class="text-xs text-amber-200 font-semibold">/ 5.00</span>
        </div>
        <div class="flex items-center gap-1.5 mt-2 text-amber-200 text-sm">
            <?= render_star_rating($avgKeseluruhan) ?>
        </div>
    </div>

    <!-- KPI 2: TOTAL SISWA YANG MENILAI -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Siswa Penilai</span>
            <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-users-viewfinder"></i>
            </div>
        </div>
        <div>
            <div class="flex items-baseline gap-2 mb-1">
                <span class="text-3xl font-black text-slate-800"><?= number_format($totalPenilai) ?></span>
                <span class="text-xs text-slate-400 font-medium">Responden Siswa</span>
            </div>
            <p class="text-[11px] text-slate-500">Evaluasi setelah minimal 80% progres materi</p>
        </div>
    </div>

    <!-- KPI 3: PERSENTASE KEPUASAN (IKM) -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Tingkat Kepuasan Siswa</span>
            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
        </div>
        <div>
            <div class="flex items-baseline gap-2 mb-1">
                <span class="text-3xl font-black text-emerald-600"><?= $persenKepuasan ?>%</span>
                <span class="text-xs text-slate-400 font-medium">Puas</span>
            </div>
            <p class="text-[11px] text-slate-500">Berdasarkan rasio skor maksimal 5.0</p>
        </div>
    </div>

    <!-- KPI 4: PREDIKAT KINERJA AKADEMIK -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Predikat Kinerja Mutu</span>
            <div class="w-9 h-9 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-certificate"></i>
            </div>
        </div>
        <div>
            <div class="mb-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border <?= $ikmBadge['badge'] ?>">
                    <i class="fa-solid <?= $ikmBadge['icon'] ?>"></i>
                    <span><?= e($ikmBadge['label']) ?></span>
                </span>
            </div>
            <p class="text-[11px] text-slate-500"><?= e($ikmBadge['desc']) ?></p>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. ANALISIS GRAFIK BATANG 4 ASPEK PEDAGOGIS -->
<!-- ========================================================================= -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-8 mb-8">
    
    <!-- Kolom Kiri: Breakdown 4 Aspek (8 Kolom) -->
    <div class="lg:col-span-8 bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between pb-4 mb-6 border-b border-slate-100">
            <div>
                <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-brand-600"></i>
                    <span>Rata-Rata Capaian 4 Indikator Pedagogis</span>
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">Analisis kekuatan dan area penguatan metode pengajaran tatap muka di kelas.</p>
            </div>
            <span class="text-xs font-mono font-bold text-slate-400">Skala 1.00 - 5.00</span>
        </div>

        <div class="space-y-6">
            <!-- 1. PENGUASAAN MATERI -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs font-bold">1</span>
                        <span class="text-sm font-bold text-slate-800">Penguasaan Materi & Kurikulum</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-indigo-600"><?= number_format($avgMateri, 2) ?></span>
                        <span class="text-xs text-slate-400">/ 5.0</span>
                    </div>
                </div>
                <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-indigo-500 to-indigo-600 rounded-full transition-all duration-500" style="width: <?= ($avgMateri / 5) * 100 ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                    <span>Kedalaman teori & studi kasus praktis</span>
                    <span><?= round(($avgMateri / 5) * 100) ?>% Capaian</span>
                </div>
            </div>

            <!-- 2. KEJELASAN PENYAMPAIAN -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 rounded-lg bg-teal-50 text-teal-600 flex items-center justify-center text-xs font-bold">2</span>
                        <span class="text-sm font-bold text-slate-800">Kejelasan & Artikulasi Penyampaian</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-teal-600"><?= number_format($avgPenyampaian, 2) ?></span>
                        <span class="text-xs text-slate-400">/ 5.0</span>
                    </div>
                </div>
                <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-teal-500 to-teal-600 rounded-full transition-all duration-500" style="width: <?= ($avgPenyampaian / 5) * 100 ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                    <span>Kemudahan dipahami & runut saat menerangkan modul</span>
                    <span><?= round(($avgPenyampaian / 5) * 100) ?>% Capaian</span>
                </div>
            </div>

            <!-- 3. KEDISIPLINAN WAKTU -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-xs font-bold">3</span>
                        <span class="text-sm font-bold text-slate-800">Kedisiplinan Waktu & Jadwal Sesi</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-amber-600"><?= number_format($avgKedisiplinan, 2) ?></span>
                        <span class="text-xs text-slate-400">/ 5.0</span>
                    </div>
                </div>
                <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-amber-500 to-amber-600 rounded-full transition-all duration-500" style="width: <?= ($avgKedisiplinan / 5) * 100 ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                    <span>Ketepatan hadir di kelas dan durasi tatap muka</span>
                    <span><?= round(($avgKedisiplinan / 5) * 100) ?>% Capaian</span>
                </div>
            </div>

            <!-- 4. KESABARAN & KERAMAHAN -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-xs font-bold">4</span>
                        <span class="text-sm font-bold text-slate-800">Kesabaran & Keramahan Membimbing</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-black text-emerald-600"><?= number_format($avgKesabaran, 2) ?></span>
                        <span class="text-xs text-slate-400">/ 5.0</span>
                    </div>
                </div>
                <div class="w-full h-3 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-emerald-500 to-emerald-600 rounded-full transition-all duration-500" style="width: <?= ($avgKesabaran / 5) * 100 ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                    <span>Perhatian kepada siswa yang kesulitan & keramahan</span>
                    <span><?= round(($avgKesabaran / 5) * 100) ?>% Capaian</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Distribusi Rating & Pedoman Standar (4 Kolom) -->
    <div class="lg:col-span-4 space-y-6">
        
        <!-- Distribusi Bintang -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-chart-bar text-amber-500"></i>
                <span>Distribusi Penilaian Siswa</span>
            </h3>

            <div class="space-y-3">
                <!-- Bintang 5 -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-14 font-semibold text-slate-600 flex items-center gap-1">
                        <span>5</span> <i class="fa-solid fa-star text-amber-400 text-[10px]"></i>
                    </span>
                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full bg-emerald-500 rounded-full" style="width: <?= $totalPenilai > 0 ? ((int)$stats['cnt_bintang_5'] / $totalPenilai) * 100 : 0 ?>%"></div>
                    </div>
                    <span class="w-8 text-right font-bold text-slate-700"><?= (int)$stats['cnt_bintang_5'] ?></span>
                </div>

                <!-- Bintang 4 -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-14 font-semibold text-slate-600 flex items-center gap-1">
                        <span>4</span> <i class="fa-solid fa-star text-amber-400 text-[10px]"></i>
                    </span>
                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full bg-teal-500 rounded-full" style="width: <?= $totalPenilai > 0 ? ((int)$stats['cnt_bintang_4'] / $totalPenilai) * 100 : 0 ?>%"></div>
                    </div>
                    <span class="w-8 text-right font-bold text-slate-700"><?= (int)$stats['cnt_bintang_4'] ?></span>
                </div>

                <!-- Bintang 3 -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-14 font-semibold text-slate-600 flex items-center gap-1">
                        <span>3</span> <i class="fa-solid fa-star text-amber-400 text-[10px]"></i>
                    </span>
                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full bg-amber-500 rounded-full" style="width: <?= $totalPenilai > 0 ? ((int)$stats['cnt_bintang_3'] / $totalPenilai) * 100 : 0 ?>%"></div>
                    </div>
                    <span class="w-8 text-right font-bold text-slate-700"><?= (int)$stats['cnt_bintang_3'] ?></span>
                </div>

                <!-- Bintang 1-2 -->
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-14 font-semibold text-slate-600 flex items-center gap-1">
                        <span>1-2</span> <i class="fa-solid fa-star text-amber-400 text-[10px]"></i>
                    </span>
                    <div class="flex-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full bg-rose-500 rounded-full" style="width: <?= $totalPenilai > 0 ? ((int)$stats['cnt_bintang_rendah'] / $totalPenilai) * 100 : 0 ?>%"></div>
                    </div>
                    <span class="w-8 text-right font-bold text-slate-700"><?= (int)$stats['cnt_bintang_rendah'] ?></span>
                </div>
            </div>
        </div>

        <!-- Standar Mutu Akademik -->
        <div class="p-5 rounded-3xl bg-slate-900 text-white shadow-sm text-xs">
            <div class="flex items-center gap-2 text-amber-400 font-bold mb-2">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Standar Mutu Pengajar</span>
            </div>
            <p class="text-slate-300 leading-relaxed text-[11px] mb-3">
                Bisolpin Academy menetapkan batas ambang kepuasan pengajaran minimal <strong>3.50</strong>. Nilai di bawah ambang memerlukan pendampingan pedagogis oleh Koordinator Akademik.
            </p>
            <div class="space-y-1.5 text-[10px]">
                <div class="flex items-center justify-between text-emerald-400 font-semibold">
                    <span>>= 4.50 : Sangat Baik (Unggul)</span>
                    <i class="fa-solid fa-check"></i>
                </div>
                <div class="flex items-center justify-between text-amber-300 font-semibold">
                    <span>3.50 - 4.49 : Cukup / Baik</span>
                    <i class="fa-solid fa-minus"></i>
                </div>
                <div class="flex items-center justify-between text-rose-400 font-semibold">
                    <span>< 3.50 : Perlu Pembinaan</span>
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ========================================================================= -->
<!-- 3. KOTAK DAFTAR MASUKAN & ULASAN SISWA (100% ANONIM) -->
<!-- ========================================================================= -->
<div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 mb-6 border-b border-slate-100">
        <div>
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-comments text-brand-600"></i>
                <span>Daftar Masukan & Ulasan Siswa</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Feedback jujur dari siswa tatap muka. Identitas siswa disamarkan sebagai <strong>"Siswa Bisolpin"</strong> untuk menjaga kenyamanan komunikasi di kelas.
            </p>
        </div>

        <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-600 text-xs font-bold border border-slate-200 self-start sm:self-auto">
            <?= count($reviews) ?> Ulasan Masuk
        </span>
    </div>

    <?php if (empty($reviews)): ?>
        <div class="text-center py-12">
            <div class="w-16 h-16 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mx-auto mb-3">
                <i class="fa-regular fa-comment-dots"></i>
            </div>
            <h4 class="text-sm font-bold text-slate-700">Belum Ada Ulasan Siswa</h4>
            <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                Siswa Anda akan menerima formulir evaluasi otomatis begitu progres belajar mereka mencapai 80%. Ulasan akan tampil di sini.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($reviews as $rev): 
                $rAvg = (float)$rev['nilai_rata_rata'];
                $rBadge = get_ikm_badge($rAvg);
            ?>
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 hover:border-brand-300 hover:shadow-sm transition-all flex flex-col justify-between">
                    <div>
                        <!-- Header Review: Avatar Siswa Anonim & Rating -->
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-brand-100 text-brand-700 font-bold flex items-center justify-center text-xs shrink-0">
                                    <i class="fa-solid fa-user-graduate"></i>
                                </div>
                                <div>
                                    <span class="block text-xs font-bold text-slate-800">Siswa Bisolpin (Anonim)</span>
                                    <span class="block text-[11px] text-slate-400"><?= e($rev['nama_layanan']) ?> • <?= format_tanggal_indonesia($rev['created_at']) ?></span>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <?= render_star_rating($rAvg) ?>
                                    <span class="text-xs font-black text-slate-700 ml-1"><?= number_format($rAvg, 2) ?></span>
                                </div>
                                <span class="inline-block mt-0.5 text-[10px] font-semibold px-2 py-0.5 rounded-full <?= $rBadge['badge'] ?>">
                                    <?= e($rBadge['label']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- 4 Skor Mini Badges -->
                        <div class="grid grid-cols-4 gap-1.5 py-2 mb-3 bg-white rounded-xl border border-slate-100 text-center text-[10px]">
                            <div>
                                <span class="block text-slate-400">Materi</span>
                                <strong class="text-indigo-600"><?= (int)$rev['skor_materi'] ?>/5</strong>
                            </div>
                            <div>
                                <span class="block text-slate-400">Penyampaian</span>
                                <strong class="text-teal-600"><?= (int)$rev['skor_penyampaian'] ?>/5</strong>
                            </div>
                            <div>
                                <span class="block text-slate-400">Disiplin</span>
                                <strong class="text-amber-600"><?= (int)$rev['skor_kedisiplinan'] ?>/5</strong>
                            </div>
                            <div>
                                <span class="block text-slate-400">Kesabaran</span>
                                <strong class="text-emerald-600"><?= (int)$rev['skor_kesabaran'] ?>/5</strong>
                            </div>
                        </div>

                        <!-- Ulasan Text -->
                        <div class="text-xs text-slate-700 leading-relaxed italic relative pl-3 border-l-2 border-brand-400">
                            "<?= !empty($rev['ulasan_saran']) ? nl2br(e($rev['ulasan_saran'])) : '<span class=\"text-slate-400 font-normal\">Siswa tidak menyertakan catatan tertulis.</span>' ?>"
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[10px] text-slate-400">
                        <span><i class="fa-solid fa-lock text-[9px] mr-1"></i> Privasi Siswa Terproteksi</span>
                        <span><?= date('H:i', strtotime($rev['created_at'])) ?> WIB</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
