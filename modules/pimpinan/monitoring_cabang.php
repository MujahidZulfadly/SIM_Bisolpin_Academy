<?php
/**
 * SIM BISOLPIN ACADEMY - Monitoring Performa Cabang Nasional (Pimpinan Pusat)
 * Dilengkapi Modal Inspeksi Detail Cabang & Sinkronisasi Filter Multi-Cabang
 */

declare(strict_types=1);

$pageTitle = 'Monitoring Seluruh Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Pimpinan Pusat
check_role(ROLE_PIMPINAN);

$db = get_db();

// Ambil atau proses switch_branch
$activeBranchId = get_active_branch_id();
$activeBranchName = get_active_branch_name();

// Ambil statistik lengkap per cabang
$stmt = $db->query("
    SELECT c.*,
           u.username as username_direktur, u.email as email_direktur,
           p.nama_lengkap as nama_direktur, p.nip_pegawai, p.no_hp as no_hp_direktur,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.status_belajar = 'aktif' AND s.is_archived = 0) as total_siswa_aktif,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.status_pembayaran = 'lunas' AND s.is_archived = 0) as total_siswa_lunas,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.status_pembayaran = 'cicilan' AND s.is_archived = 0) as total_siswa_cicilan,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.status_pembayaran = 'belum_bayar' AND s.is_archived = 0) as total_siswa_belum_bayar,
           (SELECT COALESCE(SUM(s.nominal_terbayar), 0) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.is_archived = 0) as total_pendapatan_terbayar,
           (SELECT COUNT(*) FROM tabel_pegawai pg WHERE pg.id_cabang = c.id AND pg.status_aktif = 'aktif') as total_pegawai_aktif,
           (SELECT COUNT(*) FROM tabel_pegawai m WHERE m.id_cabang = c.id AND m.jabatan_role = 'mentor' AND m.status_aktif = 'aktif') as total_mentor,
           (SELECT COUNT(*) FROM tabel_aset a WHERE a.id_cabang = c.id) as total_aset,
           (SELECT COALESCE(SUM(a.nilai_perolehan), 0) FROM tabel_aset a WHERE a.id_cabang = c.id) as total_nilai_aset,
           (SELECT COUNT(*) FROM tabel_produk pr WHERE pr.id_cabang = c.id AND pr.status_tampil = 'dipublikasikan') as total_produk
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    ORDER BY c.status_mitra ASC, total_siswa_aktif DESC
");
$cabangList = $stmt->fetchAll();

// Siapkan data JSON untuk modal interaktif
$cabangJsonMap = [];
foreach ($cabangList as $c) {
    $cabangJsonMap[$c['id']] = [
        'id'                      => (int)$c['id'],
        'kode_cabang'             => $c['kode_cabang'],
        'nama_cabang'             => $c['nama_cabang'],
        'alamat'                  => $c['alamat'],
        'kota'                    => $c['kota'],
        'provinsi'                => $c['provinsi'],
        'telepon'                 => $c['telepon'],
        'email_cabang'            => $c['email_cabang'],
        'status_mitra'            => $c['status_mitra'],
        'nomor_izin_mitra'        => $c['nomor_izin_mitra'] ?? '-',
        'dokumen_kemitraan'       => $c['dokumen_kemitraan'],
        'dokumen_url'             => !empty($c['dokumen_kemitraan']) ? URL_UPLOAD_MITRA . $c['dokumen_kemitraan'] : null,
        'tanggal_bergabung'       => $c['tanggal_bergabung'] ? format_tanggal_indonesia($c['tanggal_bergabung']) : 'Belum Ditentukan',
        'nama_direktur'           => $c['nama_direktur'] ?? $c['username_direktur'] ?? 'Belum Ditugaskan',
        'nip_direktur'            => $c['nip_pegawai'] ?? '-',
        'email_direktur'          => $c['email_direktur'] ?? $c['email_cabang'],
        'no_hp_direktur'          => $c['no_hp_direktur'] ?? $c['telepon'],
        'total_siswa_aktif'       => (int)$c['total_siswa_aktif'],
        'total_siswa_lunas'       => (int)$c['total_siswa_lunas'],
        'total_siswa_cicilan'     => (int)$c['total_siswa_cicilan'],
        'total_siswa_belum_bayar' => (int)$c['total_siswa_belum_bayar'],
        'total_pendapatan'        => format_rupiah((float)$c['total_pendapatan_terbayar']),
        'total_pegawai_aktif'     => (int)$c['total_pegawai_aktif'],
        'total_mentor'            => (int)$c['total_mentor'],
        'total_aset'              => (int)$c['total_aset'],
        'total_nilai_aset'        => format_rupiah((float)$c['total_nilai_aset']),
        'total_produk'            => (int)$c['total_produk'],
    ];
}

// Ambil perbandingan IKM dan evaluasi mutu antar-cabang
ensure_table_evaluasi_mentor_exists($db);
$ikmCabangList = [];
$topMentorMap = [];

try {
    $stmtIkmCabang = $db->query("
        SELECT 
            c.id as cabang_id,
            c.kode_cabang,
            c.nama_cabang,
            c.kota,
            c.provinsi,
            c.status_mitra,
            COUNT(e.id) as total_evaluasi,
            COALESCE(AVG(e.nilai_rata_rata), 0) as avg_ikm,
            COALESCE(AVG(e.skor_materi), 0) as avg_materi,
            COALESCE(AVG(e.skor_penyampaian), 0) as avg_penyampaian,
            COALESCE(AVG(e.skor_kedisiplinan), 0) as avg_kedisiplinan,
            COALESCE(AVG(e.skor_kesabaran), 0) as avg_kesabaran,
            SUM(CASE WHEN e.nilai_rata_rata < 3.50 THEN 1 ELSE 0 END) as total_komplain
        FROM tabel_cabang c
        LEFT JOIN tabel_evaluasi_mentor e ON e.id_cabang = c.id
        WHERE c.status_mitra = 'aktif'
        GROUP BY c.id, c.kode_cabang, c.nama_cabang, c.kota, c.provinsi, c.status_mitra
        ORDER BY avg_ikm DESC, total_evaluasi DESC, c.nama_cabang ASC
    ");
    $ikmCabangList = $stmtIkmCabang->fetchAll();

    // Mentor terbaik di setiap cabang
    $stmtTopMentor = $db->query("
        SELECT 
            e.id_cabang,
            p.nama_lengkap as nama_top_mentor,
            p.spesialisasi_mentor,
            AVG(e.nilai_rata_rata) as top_rating,
            COUNT(e.id) as total_ulasan
        FROM tabel_evaluasi_mentor e
        JOIN tabel_pegawai p ON p.id = e.id_mentor
        GROUP BY e.id_cabang, e.id_mentor, p.nama_lengkap, p.spesialisasi_mentor
        ORDER BY e.id_cabang, top_rating DESC, total_ulasan DESC
    ");
    foreach ($stmtTopMentor->fetchAll() as $tm) {
        $cid = (int)$tm['id_cabang'];
        if (!isset($topMentorMap[$cid])) {
            $topMentorMap[$cid] = $tm;
        }
    }
} catch (\Throwable $e) {
    error_log("Error query IKM di monitoring_cabang.php: " . $e->getMessage());
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-network-wired"></i>
            <span>Monitoring Jaringan Mitra</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Kinerja & Operasional Multi-Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Matriks ketercapaian siswa, ketersediaan mentor, dan kelayakan sarana prasarana di masing-masing kota.
        </p>
    </div>

    <div class="flex items-center gap-2.5">
        <?php if ($activeBranchId !== null): ?>
            <a href="?switch_branch=all" class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all shadow-sm flex items-center gap-1.5">
                <i class="fa-solid fa-arrows-rotate"></i>
                <span>Tampilkan Seluruh Cabang</span>
            </a>
        <?php endif; ?>
        <a href="<?= base_url('modules/pimpinan/verifikasi_mitra.php') ?>" class="btn-brand-outline text-xs py-2 px-4 shadow-sm">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Kelola Status Mitra Cabang</span>
        </a>
    </div>
</div>

<?php if ($activeBranchId !== null): ?>
    <!-- Banner Notifikasi Fokus Cabang Aktif -->
    <div class="mb-6 p-4 rounded-2xl bg-gradient-to-r from-brand-50 to-amber-50/60 border border-brand-200 flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-brand-500 text-white flex items-center justify-center font-bold shadow-sm shrink-0">
                <i class="fa-solid fa-crosshairs text-lg"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-brand-700">Fokus Peninjauan Cabang Aktif</span>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>
                <h2 class="text-base font-black text-slate-800"><?= e($activeBranchName) ?></h2>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="<?= base_url('modules/pimpinan/index.php') ?>" class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shadow-sm transition-all flex items-center gap-1.5">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Buka di Executive Dashboard</span>
            </a>
            <a href="?switch_branch=all" class="px-3 py-2 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold shadow-sm transition-all flex items-center gap-1">
                <i class="fa-solid fa-xmark"></i>
                <span>Reset Filter</span>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Grid Kartu Cabang -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
    <?php foreach ($cabangList as $c): 
        $isFocus = ($activeBranchId === (int)$c['id']);
        $jsonPayload = htmlspecialchars(json_encode($cabangJsonMap[$c['id']]), ENT_QUOTES, 'UTF-8');
    ?>
        <div class="bg-white rounded-3xl border <?= $isFocus ? 'border-2 border-brand-500 shadow-xl ring-4 ring-brand-100 bg-brand-50/10' : 'border-slate-200 shadow-sm' ?> p-6 flex flex-col justify-between hover:border-brand-300 hover:shadow-md transition-all relative">
            <?php if ($isFocus): ?>
                <div class="absolute -top-3 right-6 bg-brand-600 text-white text-[10px] font-bold px-3 py-0.5 rounded-full shadow-sm flex items-center gap-1">
                    <i class="fa-solid fa-eye"></i>
                    <span>Sedang Ditinjau</span>
                </div>
            <?php endif; ?>

            <div>
                <!-- Status & Kode -->
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[11px] font-mono px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 font-bold border border-slate-200">
                        <?= e($c['kode_cabang']) ?>
                    </span>
                    <?= render_status_badge($c['status_mitra']) ?>
                </div>

                <h2 class="text-base font-bold text-slate-800 mb-1 leading-snug">
                    <?= e($c['nama_cabang']) ?>
                </h2>
                <p class="text-xs text-slate-500 mb-4 flex items-center gap-1.5">
                    <i class="fa-solid fa-location-dot text-brand-600 text-[10px]"></i>
                    <span><?= e($c['kota']) ?>, <?= e($c['provinsi']) ?></span>
                </p>

                <!-- Direktur Cabang Box -->
                <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 mb-4 flex items-center gap-3 text-xs">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold shrink-0">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="block text-[10px] text-slate-400 font-semibold uppercase">Direktur Cabang</span>
                        <strong class="text-slate-800 truncate block"><?= e($c['nama_direktur'] ?? $c['username_direktur'] ?? 'Belum Ditugaskan') ?></strong>
                    </div>
                </div>

                <!-- Matriks Statistik Cabang -->
                <div class="grid grid-cols-3 gap-2 text-center text-xs py-2 border-y border-slate-100">
                    <div>
                        <span class="block text-lg font-black text-emerald-600"><?= (int)$c['total_siswa_aktif'] ?></span>
                        <span class="text-[10px] text-slate-400 font-semibold">Siswa Aktif</span>
                    </div>
                    <div>
                        <span class="block text-lg font-black text-teal-600"><?= (int)$c['total_mentor'] ?></span>
                        <span class="text-[10px] text-slate-400 font-semibold">Mentor</span>
                    </div>
                    <div>
                        <span class="block text-lg font-black text-amber-600"><?= (int)$c['total_produk'] ?></span>
                        <span class="text-[10px] text-slate-400 font-semibold">Karya Siswa</span>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <span>Estimasi Aset Sarpras:</span>
                    <strong class="text-slate-800"><?= format_rupiah((float)$c['total_nilai_aset']) ?></strong>
                </div>
            </div>

            <!-- Tombol Aksi Inspeksi -->
            <div class="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                <span class="text-[11px] text-slate-400 truncate max-w-[120px]">
                    <i class="fa-solid fa-phone text-[10px] mr-0.5"></i> <?= e($c['telepon']) ?>
                </span>
                
                <button type="button"
                        onclick='openModalTinjau(<?= $jsonPayload ?>)'
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl <?= $isFocus ? 'bg-brand-600 hover:bg-brand-700 text-white' : 'bg-brand-50 hover:bg-brand-500 hover:text-white text-brand-700' ?> font-bold text-xs transition-all shadow-sm">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                    <span>Tinjau Cabang</span>
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================================== -->
<!-- TABEL PERBANDINGAN KEPUASAN SISWA ANTAR-CABANG (BENCHMARKING IKM NASIONAL) -->
<!-- ============================================================================== -->
<div id="section-ikm" class="bg-white rounded-3xl p-6 border border-slate-200 shadow-sm mb-8 scroll-mt-20">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 mb-6 border-b border-slate-100">
        <div>
            <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-900 text-[10px] font-bold mb-1.5">
                <i class="fa-solid fa-award text-amber-600"></i>
                <span>Academic Quality Benchmarking</span>
            </div>
            <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-ranking-star text-amber-500"></i>
                <span>Perbandingan Kepuasan Siswa & Mutu Pengajaran Antar-Cabang (IKM)</span>
            </h3>
            <p class="text-xs text-slate-500 mt-0.5">
                Evaluasi mutu pembelajaran tatap muka seluruh cabang mitra. Deteksi cabang dengan mentor terbaik dan cabang yang berpotensi memiliki kendala kepuasan belajar.
            </p>
        </div>

        <span class="text-xs font-bold text-slate-600 bg-slate-100 px-3 py-1 rounded-full border border-slate-200 self-start sm:self-auto">
            <?= count($ikmCabangList) ?> Cabang Aktif Dipantau
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-600">
            <thead class="bg-slate-50 text-slate-700 font-bold uppercase text-[10px] tracking-wider border-b border-slate-200">
                <tr>
                    <th class="py-3.5 px-4 text-center w-14">Rank</th>
                    <th class="py-3.5 px-4">Cabang & Wilayah</th>
                    <th class="py-3.5 px-4 text-center">Rata-Rata IKM</th>
                    <th class="py-3.5 px-3 text-center">Responden</th>
                    <th class="py-3.5 px-3 text-center">Materi</th>
                    <th class="py-3.5 px-3 text-center">Penyampaian</th>
                    <th class="py-3.5 px-3 text-center">Disiplin</th>
                    <th class="py-3.5 px-3 text-center">Kesabaran</th>
                    <th class="py-3.5 px-4">Mentor Terbaik di Cabang</th>
                    <th class="py-3.5 px-3 text-center">Keluhan / Komplain</th>
                    <th class="py-3.5 px-4 text-center">Status Mutu</th>
                    <th class="py-3.5 px-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php 
                $rankIkm = 1;
                foreach ($ikmCabangList as $cab): 
                    $avgCabScore = round((float)$cab['avg_ikm'], 2);
                    $cabBadge = get_ikm_badge($avgCabScore);
                    $totEval = (int)$cab['total_evaluasi'];
                    $komplainCount = (int)$cab['total_komplain'];
                    $topM = $topMentorMap[(int)$cab['cabang_id']] ?? null;
                    $isFocusThis = ($activeBranchId === (int)$cab['cabang_id']);
                ?>
                    <tr class="hover:bg-slate-50/80 transition-colors <?= $isFocusThis ? 'bg-brand-50/20' : '' ?>">
                        <!-- RANK -->
                        <td class="py-4 px-4 text-center font-bold">
                            <?php if ($rankIkm === 1 && $totEval > 0): ?>
                                <span class="w-7 h-7 rounded-full bg-amber-100 text-amber-800 font-black inline-flex items-center justify-center text-xs shadow-sm">
                                    🥇 1
                                </span>
                            <?php elseif ($rankIkm === 2 && $totEval > 0): ?>
                                <span class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-black inline-flex items-center justify-center text-xs shadow-sm">
                                    🥈 2
                                </span>
                            <?php elseif ($rankIkm === 3 && $totEval > 0): ?>
                                <span class="w-7 h-7 rounded-full bg-amber-50 text-amber-800 font-black inline-flex items-center justify-center text-xs border border-amber-200 shadow-sm">
                                    🥉 3
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400">#<?= $rankIkm ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- CABANG -->
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-2.5">
                                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-100 font-bold border border-slate-200 text-slate-600 shrink-0">
                                    <?= e($cab['kode_cabang']) ?>
                                </span>
                                <div>
                                    <strong class="block text-slate-800 text-xs"><?= e($cab['nama_cabang']) ?></strong>
                                    <span class="block text-[11px] text-slate-400"><?= e($cab['kota']) ?>, <?= e($cab['provinsi']) ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- RATA-RATA IKM -->
                        <td class="py-4 px-4 text-center">
                            <?php if ($totEval > 0): ?>
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="flex items-center gap-1">
                                        <?= render_star_rating($avgCabScore) ?>
                                        <span class="font-black text-slate-800 ml-1"><?= number_format($avgCabScore, 2) ?></span>
                                    </div>
                                    <span class="text-[10px] text-slate-400">Skala 5.0</span>
                                </div>
                            <?php else: ?>
                                <span class="text-[11px] text-slate-400 italic">Belum ada evaluasi</span>
                            <?php endif; ?>
                        </td>

                        <!-- TOTAL EVALUASI -->
                        <td class="py-4 px-3 text-center font-bold text-slate-700">
                            <?= $totEval ?>
                            <span class="block text-[10px] text-slate-400 font-normal">Siswa</span>
                        </td>

                        <!-- 4 ASPEK SKOR -->
                        <td class="py-4 px-3 text-center font-semibold text-indigo-600">
                            <?= $totEval > 0 ? number_format((float)$cab['avg_materi'], 2) : '-' ?>
                        </td>
                        <td class="py-4 px-3 text-center font-semibold text-teal-600">
                            <?= $totEval > 0 ? number_format((float)$cab['avg_penyampaian'], 2) : '-' ?>
                        </td>
                        <td class="py-4 px-3 text-center font-semibold text-amber-600">
                            <?= $totEval > 0 ? number_format((float)$cab['avg_kedisiplinan'], 2) : '-' ?>
                        </td>
                        <td class="py-4 px-3 text-center font-semibold text-emerald-600">
                            <?= $totEval > 0 ? number_format((float)$cab['avg_kesabaran'], 2) : '-' ?>
                        </td>

                        <!-- TOP MENTOR DI CABANG -->
                        <td class="py-4 px-4">
                            <?php if ($topM): ?>
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-lg bg-teal-50 text-teal-700 flex items-center justify-center text-xs font-bold shrink-0">
                                        <i class="fa-solid fa-chalkboard-user"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <span class="block font-bold text-slate-800 truncate text-[11px]"><?= e($topM['nama_top_mentor']) ?></span>
                                        <span class="block text-[10px] text-emerald-600 font-semibold">
                                            <i class="fa-solid fa-star text-amber-400 text-[9px]"></i> <?= number_format((float)$topM['top_rating'], 2) ?> (<?= (int)$topM['total_ulasan'] ?> ulasan)
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-[11px] text-slate-400 italic">Belum ada mentor dinilai</span>
                            <?php endif; ?>
                        </td>

                        <!-- KELUHAN / KOMPLAIN -->
                        <td class="py-4 px-3 text-center">
                            <?php if ($komplainCount > 0): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                    <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
                                    <span><?= $komplainCount ?> Komplain</span>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-100">
                                    <i class="fa-solid fa-check text-emerald-500 text-[9px]"></i>
                                    <span>Nol Keluhan</span>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- PREDIKAT MUTU -->
                        <td class="py-4 px-4 text-center">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $cabBadge['badge'] ?>">
                                <i class="fa-solid <?= $cabBadge['icon'] ?>"></i>
                                <span><?= e($cabBadge['label']) ?></span>
                            </span>
                        </td>

                        <!-- AKSI FOKUS -->
                        <td class="py-4 px-4 text-center">
                            <?php if ($isFocusThis): ?>
                                <span class="px-2.5 py-1.5 rounded-xl bg-brand-600 text-white text-[11px] font-bold shadow-sm inline-flex items-center gap-1">
                                    <i class="fa-solid fa-check"></i> Aktif
                                </span>
                            <?php else: ?>
                                <a href="?switch_branch=<?= (int)$cab['cabang_id'] ?>" class="px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-brand-50 text-slate-700 hover:text-brand-700 text-[11px] font-bold transition-all border border-slate-200 inline-flex items-center gap-1" title="Fokus Peninjauan ke Cabang Ini">
                                    <i class="fa-solid fa-crosshairs"></i> Fokus
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php 
                $rankIkm++;
                endforeach; 
                ?>
            </tbody>
        </table>
    </div>

    <!-- INSIGHT SUPERVISI PIMPINAN PUSAT -->
    <div class="mt-6 pt-4 border-t border-slate-100 grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
        <div class="p-3.5 rounded-2xl bg-teal-50 border border-teal-100 flex items-start gap-3">
            <div class="w-8 h-8 rounded-xl bg-teal-500 text-white flex items-center justify-center font-bold shrink-0">
                <i class="fa-solid fa-award"></i>
            </div>
            <div>
                <strong class="text-teal-900 block font-bold">Best Branch Performance:</strong>
                <p class="text-teal-700 text-[11px] mt-0.5">
                    Cabang dengan nilai IKM tertinggi berhak mendapatkan plakat sertifikasi mutu dan insentif manajemen dari Pimpinan Pusat.
                </p>
            </div>
        </div>

        <div class="p-3.5 rounded-2xl bg-amber-50 border border-amber-100 flex items-start gap-3">
            <div class="w-8 h-8 rounded-xl bg-amber-500 text-white flex items-center justify-center font-bold shrink-0">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div>
                <strong class="text-amber-900 block font-bold">Ambang Batas Keluhan:</strong>
                <p class="text-amber-800 text-[11px] mt-0.5">
                    Cabang dengan skor di bawah 3.50 atau memiliki ulasan komplain wajib mengirimkan laporan evaluasi pedagogis kepada Bagian Akademik (BAK).
                </p>
            </div>
        </div>

        <div class="p-3.5 rounded-2xl bg-indigo-50 border border-indigo-100 flex items-start gap-3">
            <div class="w-8 h-8 rounded-xl bg-indigo-500 text-white flex items-center justify-center font-bold shrink-0">
                <i class="fa-solid fa-sync"></i>
            </div>
            <div>
                <strong class="text-indigo-900 block font-bold">Sinkronisasi Real-Time:</strong>
                <p class="text-indigo-700 text-[11px] mt-0.5">
                    Data IKM terhubung langsung dengan modul Siswa secara anonim begitu siswa mencapai batas minimal 80% progres tatap muka.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================================== -->
<!-- MODAL INTERAKTIF: INSPEKSI & DETAIL LENGKAP CABANG (PIMPINAN PUSAT) -->
<!-- ============================================================================== -->
<div id="modalTinjauCabang" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm hidden transition-opacity">
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-200 w-full max-w-3xl overflow-hidden transform transition-all max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="p-6 bg-gradient-to-r from-slate-900 to-slate-800 text-white flex items-start justify-between relative">
            <div>
                <div class="flex items-center gap-2 mb-1.5">
                    <span id="modalKodeCabang" class="text-[10px] font-mono px-2 py-0.5 rounded-md bg-white/20 text-white font-bold"></span>
                    <span id="modalStatusBadge"></span>
                </div>
                <h3 id="modalNamaCabang" class="text-xl font-black text-white tracking-tight"></h3>
                <p id="modalWilayah" class="text-xs text-slate-300 mt-0.5 flex items-center gap-1">
                    <i class="fa-solid fa-location-dot text-brand-400 text-xs"></i>
                    <span id="modalWilayahText"></span>
                </p>
            </div>
            <button type="button" onclick="closeModalTinjau()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="p-6 overflow-y-auto space-y-6 flex-1 text-slate-700">
            <!-- Grid Informasi Legalitas & Kontak -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Box Profil Direktur -->
                <div class="p-4 rounded-2xl bg-indigo-50/60 border border-indigo-100 flex flex-col justify-between">
                    <div>
                        <span class="text-[10px] font-bold text-indigo-700 uppercase tracking-wider block mb-1">
                            <i class="fa-solid fa-user-tie mr-1"></i> Direktur Cabang Bertanggung Jawab
                        </span>
                        <h4 id="modalNamaDirektur" class="text-sm font-black text-slate-800"></h4>
                        <div class="text-xs text-slate-500 mt-2 space-y-1">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-id-card text-indigo-500 w-4"></i>
                                <span id="modalNipDirektur"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-envelope text-indigo-500 w-4"></i>
                                <span id="modalEmailDirektur" class="truncate"></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-phone text-indigo-500 w-4"></i>
                                <span id="modalHpDirektur"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Box Legalitas & Kontak Cabang -->
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-col justify-between">
                    <div>
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider block mb-1">
                            <i class="fa-solid fa-certificate mr-1 text-teal-600"></i> Legalitas & Izin Kemitraan
                        </span>
                        <div class="text-xs text-slate-700 space-y-1.5 mt-2">
                            <div>
                                <span class="text-slate-400 block text-[10px]">Nomor Izin Kemitraan:</span>
                                <strong id="modalNoIzin" class="font-mono text-slate-800"></strong>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[10px]">Tanggal Resmi Bergabung:</span>
                                <span id="modalTglGabung" class="font-semibold text-slate-800"></span>
                            </div>
                            <div id="modalDokumenContainer" class="pt-1">
                                <!-- Tombol Download Dokumen diisi via JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alamat Lengkap & Kontak -->
            <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-xs">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">
                    <i class="fa-solid fa-building mr-1 text-brand-600"></i> Alamat Fisik Gedung / Ruang Kelas Offline
                </span>
                <p id="modalAlamatLengkap" class="font-medium text-slate-700 leading-relaxed"></p>
                <div class="mt-2 pt-2 border-t border-slate-200/60 flex items-center justify-between text-slate-500">
                    <span><i class="fa-solid fa-phone mr-1"></i> <span id="modalTelpCabang"></span></span>
                    <span><i class="fa-solid fa-at mr-1"></i> <span id="modalEmailCabang"></span></span>
                </div>
            </div>

            <!-- Matriks Statistik Operasional & Keuangan Cabang -->
            <div>
                <h4 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                    <i class="fa-solid fa-chart-simple text-brand-600"></i>
                    <span>Matriks Kinerja Operasional & Keuangan Cabang</span>
                </h4>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                    <div class="p-3 rounded-2xl bg-emerald-50 border border-emerald-100">
                        <span id="modalStatSiswa" class="block text-xl font-black text-emerald-700">0</span>
                        <span class="text-[10px] font-bold text-emerald-800 uppercase">Siswa Aktif</span>
                        <span id="modalStatLunas" class="block text-[10px] text-emerald-600 mt-1 font-medium"></span>
                    </div>

                    <div class="p-3 rounded-2xl bg-amber-50 border border-amber-100">
                        <span id="modalStatPendapatan" class="block text-sm font-black text-amber-800 truncate">Rp 0</span>
                        <span class="text-[10px] font-bold text-amber-800 uppercase">Total Bayar Masuk</span>
                        <span id="modalStatCicilan" class="block text-[10px] text-amber-700 mt-1 font-medium"></span>
                    </div>

                    <div class="p-3 rounded-2xl bg-teal-50 border border-teal-100">
                        <span id="modalStatMentor" class="block text-xl font-black text-teal-700">0</span>
                        <span class="text-[10px] font-bold text-teal-800 uppercase">Mentor Pengajar</span>
                        <span id="modalStatPegawai" class="block text-[10px] text-teal-600 mt-1 font-medium"></span>
                    </div>

                    <div class="p-3 rounded-2xl bg-indigo-50 border border-indigo-100">
                        <span id="modalStatAsetNilai" class="block text-sm font-black text-indigo-800 truncate">Rp 0</span>
                        <span class="text-[10px] font-bold text-indigo-800 uppercase">Estimasi Aset</span>
                        <span id="modalStatAsetJml" class="block text-[10px] text-indigo-600 mt-1 font-medium"></span>
                    </div>
                </div>
            </div>

            <!-- Menu Tindak Lanjut Inspeksi (Quick Action Grid) -->
            <div class="p-4 rounded-2xl bg-slate-900 text-white">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-2">
                    <i class="fa-solid fa-bolt mr-1 text-brand-400"></i> Aksi Peninjauan Lintas Sistem (Super Admin)
                </span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                    <a id="btnBukaDashboard" href="#" class="p-2.5 rounded-xl bg-white/10 hover:bg-brand-600 font-bold flex items-center gap-2.5 transition-colors">
                        <i class="fa-solid fa-chart-pie text-brand-400 text-sm w-5 text-center"></i>
                        <div>
                            <span class="block text-white">Buka di Executive Dashboard</span>
                            <span class="text-[10px] text-slate-400 block font-normal">Tinjau grafik & metrik Bento Grid cabang ini</span>
                        </div>
                    </a>

                    <a id="btnBukaSiswa" href="#" class="p-2.5 rounded-xl bg-white/10 hover:bg-emerald-600 font-bold flex items-center gap-2.5 transition-colors">
                        <i class="fa-solid fa-user-graduate text-emerald-400 text-sm w-5 text-center"></i>
                        <div>
                            <span class="block text-white">Lihat Data Siswa Cabang</span>
                            <span class="text-[10px] text-slate-400 block font-normal">Audit absensi & riwayat pembayaran siswa</span>
                        </div>
                    </a>

                    <a id="btnBukaPegawai" href="#" class="p-2.5 rounded-xl bg-white/10 hover:bg-teal-600 font-bold flex items-center gap-2.5 transition-colors">
                        <i class="fa-solid fa-users text-teal-400 text-sm w-5 text-center"></i>
                        <div>
                            <span class="block text-white">Lihat Pegawai & Mentor</span>
                            <span class="text-[10px] text-slate-400 block font-normal">Audit ketersediaan pengajar tatap muka</span>
                        </div>
                    </a>

                    <a id="btnBukaAset" href="#" class="p-2.5 rounded-xl bg-white/10 hover:bg-amber-600 font-bold flex items-center gap-2.5 transition-colors">
                        <i class="fa-solid fa-boxes-stacked text-amber-400 text-sm w-5 text-center"></i>
                        <div>
                            <span class="block text-white">Audit Inventaris Sarpras</span>
                            <span class="text-[10px] text-slate-400 block font-normal">Kelayakan fasilitas kelas fisik & lab</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
            <button type="button" onclick="closeModalTinjau()" class="px-4 py-2 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs transition-colors">
                Tutup
            </button>
            <a id="btnSetFilterAktif" href="#" class="btn-brand text-xs py-2 px-4 shadow-sm flex items-center gap-1.5">
                <i class="fa-solid fa-filter"></i>
                <span>Jadikan Filter Cabang Aktif</span>
            </a>
        </div>
    </div>
</div>

<script>
// Data Cabang Map untuk JavaScript
const cabangDataMap = <?= json_encode($cabangJsonMap) ?>;

function openModalTinjau(c) {
    if (!c) return;

    // Header Info
    document.getElementById('modalKodeCabang').innerText = c.kode_cabang;
    document.getElementById('modalNamaCabang').innerText = c.nama_cabang;
    document.getElementById('modalWilayahText').innerText = c.kota + ', ' + c.provinsi;
    
    // Status Badge
    let badgeClass = 'bg-slate-500';
    let badgeText = c.status_mitra;
    if (c.status_mitra === 'aktif') {
        badgeClass = 'bg-emerald-500';
        badgeText = 'Mitra Aktif';
    } else if (c.status_mitra === 'pending_review') {
        badgeClass = 'bg-amber-500';
        badgeText = 'Pending Review';
    } else if (c.status_mitra === 'ditolak') {
        badgeClass = 'bg-rose-500';
        badgeText = 'Kemitraan Ditolak';
    }
    document.getElementById('modalStatusBadge').innerHTML = `<span class="${badgeClass} text-white font-bold text-[10px] px-2.5 py-0.5 rounded-full uppercase tracking-wider">${badgeText}</span>`;

    // Direktur Box
    document.getElementById('modalNamaDirektur').innerText = c.nama_direktur;
    document.getElementById('modalNipDirektur').innerText = 'NIP/ID: ' + c.nip_direktur;
    document.getElementById('modalEmailDirektur').innerText = c.email_direktur;
    document.getElementById('modalHpDirektur').innerText = c.no_hp_direktur;

    // Legalitas Box
    document.getElementById('modalNoIzin').innerText = c.nomor_izin_mitra;
    document.getElementById('modalTglGabung').innerText = c.tanggal_bergabung;
    
    // Dokumen MoU
    const docContainer = document.getElementById('modalDokumenContainer');
    if (c.dokumen_url) {
        docContainer.innerHTML = `
            <a href="${c.dokumen_url}" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-bold text-teal-600 hover:text-teal-700 underline">
                <i class="fa-solid fa-file-pdf"></i> Unduh Berkas MoU Kemitraan
            </a>
        `;
    } else {
        docContainer.innerHTML = `<span class="text-[11px] text-slate-400 italic">Belum ada berkas MoU terunggah</span>`;
    }

    // Alamat & Kontak
    document.getElementById('modalAlamatLengkap').innerText = c.alamat;
    document.getElementById('modalTelpCabang').innerText = c.telepon;
    document.getElementById('modalEmailCabang').innerText = c.email_cabang;

    // Matriks Statistik
    document.getElementById('modalStatSiswa').innerText = c.total_siswa_aktif;
    document.getElementById('modalStatLunas').innerText = `Lunas: ${c.total_siswa_lunas} | Cicilan: ${c.total_siswa_cicilan}`;
    document.getElementById('modalStatPendapatan').innerText = c.total_pendapatan;
    document.getElementById('modalStatCicilan').innerText = `Belum Bayar: ${c.total_siswa_belum_bayar} siswa`;
    document.getElementById('modalStatMentor').innerText = c.total_mentor;
    document.getElementById('modalStatPegawai').innerText = `Total Pegawai: ${c.total_pegawai_aktif}`;
    document.getElementById('modalStatAsetNilai').innerText = c.total_nilai_aset;
    document.getElementById('modalStatAsetJml').innerText = `Total Sarpras: ${c.total_aset} unit`;

    // Action Links
    const baseUrl = '<?= base_url() ?>';
    document.getElementById('btnBukaDashboard').href = baseUrl + 'modules/pimpinan/index.php?switch_branch=' + c.id;
    document.getElementById('btnBukaSiswa').href     = baseUrl + 'modules/pimpinan/siswa_nasional.php?switch_branch=' + c.id;
    document.getElementById('btnBukaPegawai').href   = baseUrl + 'modules/pimpinan/pegawai_nasional.php?switch_branch=' + c.id;
    document.getElementById('btnBukaAset').href      = baseUrl + 'modules/pimpinan/aset_nasional.php?switch_branch=' + c.id;
    document.getElementById('btnSetFilterAktif').href = '?switch_branch=' + c.id;

    // Tampilkan Modal
    const modal = document.getElementById('modalTinjauCabang');
    modal.classList.remove('hidden');
}

function closeModalTinjau() {
    const modal = document.getElementById('modalTinjauCabang');
    modal.classList.add('hidden');
}

// Tutup modal ketika klik backdrop atau tombol Escape
document.getElementById('modalTinjauCabang').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModalTinjau();
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModalTinjau();
    }
});

// Otomatis buka modal jika ada parameter switch_branch atau tinjau di URL (misal: ?switch_branch=1)
window.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const branchParam = urlParams.get('switch_branch') || urlParams.get('tinjau');
    if (branchParam && branchParam !== 'all') {
        const branchId = parseInt(branchParam, 10);
        if (cabangDataMap[branchId]) {
            openModalTinjau(cabangDataMap[branchId]);
        }
    }
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
