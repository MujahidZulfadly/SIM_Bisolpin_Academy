<?php
/**
 * SIM BISOLPIN ACADEMY - Evaluasi Mutu Pengajaran & Kinerja Mentor (Direktur Cabang)
 * Ranking Kinerja Mentor Cabang, Predikat IKM, dan Filter Detail Ulasan Siswa
 */

declare(strict_types=1);

$pageTitle = 'Evaluasi Mutu Mentor Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Direktur Cabang
check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;
verify_branch_access($branchId);
$db = get_db();

// Auto-migrate: Pastikan tabel_evaluasi_mentor sudah ada di database
ensure_table_evaluasi_mentor_exists($db);

// Ambil info Cabang
$stmtCabang = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

// 1. Ringkasan Statistik Cabang
$totalEvaluasiCabang = 0;
$avgIkmCabang        = 0.0;
$ikmCabangBadge      = get_ikm_badge(0);
$totalMentorAktif    = 0;
$rankingList         = [];
$reviewsList         = [];
$countUnggul         = 0;
$countPerluPembinaan = 0;

try {
    $stmtBranchStats = $db->prepare("
        SELECT 
            COUNT(e.id) as total_evaluasi,
            COALESCE(AVG(e.nilai_rata_rata), 0) as avg_ikm_cabang,
            COUNT(DISTINCT e.id_mentor) as total_mentor_dinilai
        FROM tabel_evaluasi_mentor e
        WHERE e.id_cabang = ?
    ");
    $stmtBranchStats->execute([$branchId]);
    $branchStats = $stmtBranchStats->fetch();

    $totalEvaluasiCabang = (int)($branchStats['total_evaluasi'] ?? 0);
    $avgIkmCabang        = round((float)($branchStats['avg_ikm_cabang'] ?? 0), 2);
    $ikmCabangBadge      = get_ikm_badge($avgIkmCabang);

    // Hitung total mentor aktif di cabang ini
    $stmtTotalMentor = $db->prepare("
        SELECT COUNT(*) FROM tabel_pegawai 
        WHERE id_cabang = ? AND jabatan_role = 'mentor' AND status_aktif = 'aktif'
    ");
    $stmtTotalMentor->execute([$branchId]);
    $totalMentorAktif = (int)$stmtTotalMentor->fetchColumn();

    // 2. Ranking Kinerja Seluruh Mentor di Cabang Ini
    $stmtRanking = $db->prepare("
        SELECT 
            p.id as mentor_id,
            p.nama_lengkap,
            p.nip_pegawai,
            p.spesialisasi_mentor,
            p.status_aktif,
            p.status_kepegawaian,
            COUNT(e.id) as total_penilai,
            COALESCE(AVG(e.nilai_rata_rata), 0) as avg_rating,
            COALESCE(AVG(e.skor_materi), 0) as avg_materi,
            COALESCE(AVG(e.skor_penyampaian), 0) as avg_penyampaian,
            COALESCE(AVG(e.skor_kedisiplinan), 0) as avg_kedisiplinan,
            COALESCE(AVG(e.skor_kesabaran), 0) as avg_kesabaran
        FROM tabel_pegawai p
        LEFT JOIN tabel_evaluasi_mentor e ON e.id_mentor = p.id AND e.id_cabang = p.id_cabang
        WHERE p.id_cabang = ? AND p.jabatan_role = 'mentor' AND p.status_aktif = 'aktif'
        GROUP BY p.id, p.nama_lengkap, p.nip_pegawai, p.spesialisasi_mentor, p.status_aktif, p.status_kepegawaian
        ORDER BY avg_rating DESC, total_penilai DESC, p.nama_lengkap ASC
    ");
    $stmtRanking->execute([$branchId]);
    $rankingList = $stmtRanking->fetchAll();

    // Hitung metrik mentor unggul vs butuh pembinaan
    foreach ($rankingList as $r) {
        $score = (float)$r['avg_rating'];
        if ($r['total_penilai'] > 0) {
            if ($score >= 4.50) {
                $countUnggul++;
            } elseif ($score < 3.50) {
                $countPerluPembinaan++;
            }
        }
    }

    // 3. Filter Detail Ulasan Siswa per Mentor
    $filterMentorId = isset($_GET['mentor_id']) && $_GET['mentor_id'] !== 'all' && is_numeric($_GET['mentor_id']) ? (int)$_GET['mentor_id'] : null;

    $queryReviews = "
        SELECT e.*, l.nama_layanan, l.kode_layanan, p.nama_lengkap as nama_mentor, p.nip_pegawai, p.spesialisasi_mentor
        FROM tabel_evaluasi_mentor e
        JOIN tabel_layanan l ON l.id = e.id_layanan
        JOIN tabel_pegawai p ON p.id = e.id_mentor
        WHERE e.id_cabang = ?
    ";
    $params = [$branchId];

    if ($filterMentorId) {
        $queryReviews .= " AND e.id_mentor = ?";
        $params[] = $filterMentorId;
    }
    $queryReviews .= " ORDER BY e.created_at DESC";

    $stmtReviews = $db->prepare($queryReviews);
    $stmtReviews->execute($params);
    $reviewsList = $stmtReviews->fetchAll();
} catch (\Throwable $e) {
    error_log("Error pada evaluasi_mentor.php: " . $e->getMessage());
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-ranking-star text-teal-600"></i>
            <span>Evaluasi Mutu & Supervisi Akademik</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Evaluasi Mutu & Kinerja Mentor</h1>
        <p class="text-xs text-slate-500 mt-1">
            Indeks Kepuasan Mengajar (IKM) cabang <strong><?= e($cabang['nama_cabang'] ?? 'Cabang') ?></strong> berdasarkan penilaian objektif siswa tatap muka.
        </p>
    </div>

    <!-- Info Cabang Badge -->
    <div class="flex items-center gap-2 bg-white px-3.5 py-2 rounded-2xl border border-slate-200 shadow-sm text-xs">
        <i class="fa-solid fa-building-columns text-brand-600"></i>
        <div>
            <span class="block font-bold text-slate-800"><?= e($cabang['nama_cabang'] ?? 'Cabang') ?></span>
            <span class="block text-[11px] text-slate-500"><?= e($cabang['kota'] ?? '') ?> • Kode: <?= e($cabang['kode_cabang'] ?? '') ?></span>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 1. KARTU METRIK MUTU CABANG (4 KPI CARDS) -->
<!-- ========================================================================= -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    
    <!-- KPI 1: RATA-RATA IKM CABANG -->
    <div class="bg-gradient-to-br from-teal-600 via-teal-700 to-emerald-800 text-white rounded-3xl p-6 shadow-xl shadow-teal-500/10 relative overflow-hidden">
        <div class="absolute -right-6 -bottom-6 w-32 h-32 rounded-full bg-white/10 blur-xl pointer-events-none"></div>
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-teal-100">Rata-Rata IKM Cabang</span>
            <div class="w-9 h-9 rounded-xl bg-white/20 backdrop-blur flex items-center justify-center text-teal-200">
                <i class="fa-solid fa-star"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2 mb-1">
            <span class="text-3xl sm:text-4xl font-black tracking-tight"><?= number_format($avgIkmCabang, 2) ?></span>
            <span class="text-xs text-teal-200 font-semibold">/ 5.00</span>
        </div>
        <div class="flex items-center gap-1.5 mt-2 text-teal-200 text-sm">
            <?= render_star_rating($avgIkmCabang) ?>
        </div>
    </div>

    <!-- KPI 2: TOTAL EVALUASI MASUK -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Evaluasi Masuk</span>
            <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-clipboard-check"></i>
            </div>
        </div>
        <div>
            <div class="flex items-baseline gap-2 mb-1">
                <span class="text-3xl font-black text-slate-800"><?= number_format($totalEvaluasiCabang) ?></span>
                <span class="text-xs text-slate-400 font-medium">Ulasan Siswa</span>
            </div>
            <p class="text-[11px] text-slate-500"><?= $totalMentorAktif ?> mentor aktif terdaftar di cabang</p>
        </div>
    </div>

    <!-- KPI 3: MENTOR UNGGUL -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Mentor Unggul (>= 4.5)</span>
            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-trophy"></i>
            </div>
        </div>
        <div>
            <div class="flex items-baseline gap-2 mb-1">
                <span class="text-3xl font-black text-emerald-600"><?= $countUnggul ?></span>
                <span class="text-xs text-slate-400 font-medium">Pengajar Teladan</span>
            </div>
            <p class="text-[11px] text-emerald-700">Rekomendasi insentif & promosi</p>
        </div>
    </div>

    <!-- KPI 4: PERLU PEMBINAAN -->
    <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm flex flex-col justify-between">
        <div class="flex items-center justify-between mb-2">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Perlu Pembinaan (< 3.5)</span>
            <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-base">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
        </div>
        <div>
            <div class="flex items-baseline gap-2 mb-1">
                <span class="text-3xl font-black text-rose-600"><?= $countPerluPembinaan ?></span>
                <span class="text-xs text-slate-400 font-medium">Mentor</span>
            </div>
            <p class="text-[11px] text-slate-500"><?= $countPerluPembinaan > 0 ? 'Perlu supervisi & pendampingan kelas' : 'Semua mentor memenuhi standar mutu' ?></p>
        </div>
    </div>
</div>

<!-- ALERT KEBIJAKAN SUPERVISI -->
<div class="mb-8 p-4 rounded-2xl bg-amber-50 border border-amber-200 flex items-start gap-3.5 shadow-sm text-xs">
    <div class="w-8 h-8 rounded-xl bg-amber-500 text-white flex items-center justify-center text-sm shrink-0 shadow-sm">
        <i class="fa-solid fa-scale-balanced"></i>
    </div>
    <div class="text-amber-900 leading-relaxed">
        <strong class="font-bold">Pedoman Supervisi & Evaluasi Kontrak Pengajar Bisolpin:</strong>
        <p class="mt-0.5 text-amber-800">
            Hasil evaluasi ini menjadi instrumen resmi evaluasi kinerja triwulan, dasar pertimbangan kenaikan insentif mengajar, serta dasar persetujuan <strong>perpanjangan kontrak kerja mentor</strong> di Cabang <?= e($cabang['nama_cabang'] ?? '') ?>. Pastikan pembinaan dilakukan bagi mentor dengan skor di bawah 3.50.
        </p>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. TABEL RANKING KINERJA SELURUH MENTOR CABANG -->
<!-- ========================================================================= -->
<div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 mb-6 border-b border-slate-100">
        <div>
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-medal text-amber-500"></i>
                <span>Tabel Ranking Kinerja Mentor Cabang</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Urutan mentor terbaik berdasarkan akumulasi penilaian bintang siswa untuk 4 aspek pengajaran tatap muka.
            </p>
        </div>
        <span class="text-xs font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-full self-start sm:self-auto">
            <?= count($rankingList) ?> Mentor Terdaftar
        </span>
    </div>

    <?php if (empty($rankingList)): ?>
        <div class="text-center py-10">
            <i class="fa-solid fa-user-xmark text-4xl text-slate-300 mb-2"></i>
            <p class="text-sm font-bold text-slate-700">Belum Ada Data Mentor di Cabang Ini</p>
            <p class="text-xs text-slate-400 mt-1">Silakan tambahkan mentor baru melalui menu Tim Pegawai & Mentor.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="bg-slate-50 text-slate-700 font-bold uppercase text-[10px] tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="py-3.5 px-4 text-center w-14">Rank</th>
                        <th class="py-3.5 px-4">Nama Mentor & NIP</th>
                        <th class="py-3.5 px-3 text-center">Penilai</th>
                        <th class="py-3.5 px-3 text-center">Materi</th>
                        <th class="py-3.5 px-3 text-center">Penyampaian</th>
                        <th class="py-3.5 px-3 text-center">Disiplin</th>
                        <th class="py-3.5 px-3 text-center">Kesabaran</th>
                        <th class="py-3.5 px-4 text-center">Rata-Rata Bintang</th>
                        <th class="py-3.5 px-4 text-center">Predikat Mutu</th>
                        <th class="py-3.5 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php 
                    $rank = 1;
                    foreach ($rankingList as $m): 
                        $avgScore = round((float)$m['avg_rating'], 2);
                        $badge = get_ikm_badge($avgScore);
                        $penilaiCount = (int)$m['total_penilai'];
                    ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <!-- RANK BADGE -->
                            <td class="py-4 px-4 text-center">
                                <?php if ($rank === 1 && $penilaiCount > 0): ?>
                                    <span class="w-7 h-7 rounded-full bg-amber-100 text-amber-700 font-black inline-flex items-center justify-center text-xs shadow-sm">
                                        🥇 1
                                    </span>
                                <?php elseif ($rank === 2 && $penilaiCount > 0): ?>
                                    <span class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-black inline-flex items-center justify-center text-xs shadow-sm">
                                        🥈 2
                                    </span>
                                <?php elseif ($rank === 3 && $penilaiCount > 0): ?>
                                    <span class="w-7 h-7 rounded-full bg-amber-50 text-amber-800 font-black inline-flex items-center justify-center text-xs border border-amber-200 shadow-sm">
                                        🥉 3
                                    </span>
                                <?php else: ?>
                                    <span class="font-bold text-slate-400">#<?= $rank ?></span>
                                <?php endif; ?>
                            </td>

                            <!-- NAMA & SPESIALISASI -->
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl bg-brand-50 text-brand-700 flex items-center justify-center font-bold text-xs shrink-0">
                                        <?= strtoupper(substr($m['nama_lengkap'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <span class="block font-bold text-slate-800 hover:text-brand-600"><?= e($m['nama_lengkap']) ?></span>
                                        <span class="block text-[11px] text-slate-400">NIP: <?= e($m['nip_pegawai']) ?> • <?= e($m['spesialisasi_mentor'] ?? 'Mentor') ?></span>
                                    </div>
                                </div>
                            </td>

                            <!-- TOTAL PENILAI -->
                            <td class="py-4 px-3 text-center">
                                <span class="font-bold text-slate-800"><?= $penilaiCount ?></span>
                                <span class="block text-[10px] text-slate-400">Siswa</span>
                            </td>

                            <!-- SKOR 4 ASPEK -->
                            <td class="py-4 px-3 text-center font-semibold text-indigo-600">
                                <?= $penilaiCount > 0 ? number_format((float)$m['avg_materi'], 2) : '-' ?>
                            </td>
                            <td class="py-4 px-3 text-center font-semibold text-teal-600">
                                <?= $penilaiCount > 0 ? number_format((float)$m['avg_penyampaian'], 2) : '-' ?>
                            </td>
                            <td class="py-4 px-3 text-center font-semibold text-amber-600">
                                <?= $penilaiCount > 0 ? number_format((float)$m['avg_kedisiplinan'], 2) : '-' ?>
                            </td>
                            <td class="py-4 px-3 text-center font-semibold text-emerald-600">
                                <?= $penilaiCount > 0 ? number_format((float)$m['avg_kesabaran'], 2) : '-' ?>
                            </td>

                            <!-- RATA-RATA BINTANG -->
                            <td class="py-4 px-4 text-center">
                                <?php if ($penilaiCount > 0): ?>
                                    <div class="flex flex-col items-center gap-0.5">
                                        <div class="flex items-center gap-1">
                                            <?= render_star_rating($avgScore) ?>
                                            <span class="font-black text-slate-800 ml-1"><?= number_format($avgScore, 2) ?></span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-[11px] text-slate-400 italic">Belum dinilai</span>
                                <?php endif; ?>
                            </td>

                            <!-- PREDIKAT MUTU BADGE -->
                            <td class="py-4 px-4 text-center">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $badge['badge'] ?>">
                                    <i class="fa-solid <?= $badge['icon'] ?>"></i>
                                    <span><?= e($badge['label']) ?></span>
                                </span>
                            </td>

                            <!-- AKSI FILTER ULASAN -->
                            <td class="py-4 px-4 text-center">
                                <a href="?mentor_id=<?= (int)$m['mentor_id'] ?>#section-ulasan" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-brand-50 text-slate-700 hover:text-brand-700 text-xs font-bold transition-all border border-slate-200">
                                    <i class="fa-regular fa-comment-dots"></i>
                                    <span>Lihat Ulasan</span>
                                </a>
                            </td>
                        </tr>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- 3. DETAIL ULASAN SISWA PER MENTOR (FILTER & SUPERVISI) -->
<!-- ========================================================================= -->
<div id="section-ulasan" class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm scroll-mt-20">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 mb-6 border-b border-slate-100">
        <div>
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-comments text-brand-600"></i>
                <span>Detail Ulasan Siswa untuk Supervisi Kinerja</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Evaluasi kualitatif langsung dari siswa tatap muka. Data ini menjadi bukti otentik perbaikan mutu pembelajaran.
            </p>
        </div>

        <!-- Filter Dropdown Mentor -->
        <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-slate-500">Filter Mentor:</span>
            <select onchange="window.location.href='?mentor_id=' + this.value + '#section-ulasan'" class="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="all" <?= empty($filterMentorId) ? 'selected' : '' ?>>Semua Mentor di Cabang</option>
                <?php foreach ($rankingList as $rm): ?>
                    <option value="<?= (int)$rm['mentor_id'] ?>" <?= $filterMentorId === (int)$rm['mentor_id'] ? 'selected' : '' ?>>
                        <?= e($rm['nama_lengkap']) ?> (<?= (int)$rm['total_penilai'] ?> ulasan)
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (!empty($filterMentorId)): ?>
                <a href="?mentor_id=all#section-ulasan" class="px-2.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold" title="Reset Filter">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($reviewsList)): ?>
        <div class="text-center py-12">
            <div class="w-16 h-16 rounded-3xl bg-slate-100 text-slate-400 flex items-center justify-center text-2xl mx-auto mb-3">
                <i class="fa-regular fa-comments"></i>
            </div>
            <h4 class="text-sm font-bold text-slate-700">Belum Ada Ulasan Siswa</h4>
            <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                Belum ada siswa yang mengisi formulir evaluasi untuk filter mentor yang dipilih.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($reviewsList as $rev): 
                $rAvg = (float)$rev['nilai_rata_rata'];
                $rBadge = get_ikm_badge($rAvg);
            ?>
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 hover:border-brand-300 hover:shadow-sm transition-all flex flex-col justify-between">
                    <div>
                        <!-- Header Review: Mentor Target & Rating -->
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-teal-100 text-teal-800 font-bold flex items-center justify-center text-xs shrink-0">
                                    <i class="fa-solid fa-chalkboard-user"></i>
                                </div>
                                <div>
                                    <span class="block text-xs font-bold text-slate-800">Mentor: <?= e($rev['nama_mentor']) ?></span>
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

                        <!-- Teks Ulasan Anonim Siswa -->
                        <div class="text-xs text-slate-700 leading-relaxed italic relative pl-3 border-l-2 border-brand-400">
                            "<?= !empty($rev['ulasan_saran']) ? nl2br(e($rev['ulasan_saran'])) : '<span class=\"text-slate-400 font-normal\">Siswa tidak memberikan catatan tertulis tambahan.</span>' ?>"
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-200/60 flex items-center justify-between text-[10px] text-slate-400">
                        <span><i class="fa-solid fa-user-shield text-[9px] mr-1"></i> Penilai: <strong>Siswa Bisolpin (Anonim)</strong></span>
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
