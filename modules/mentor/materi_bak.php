<?php
/**
 * SIM BISOLPIN ACADEMY - Katalog Modul Resmi BAK (Ruangguru-Style Material Hub untuk Mentor)
 */

declare(strict_types=1);

$pageTitle = 'Modul Resmi Siap Ajar (BAK)';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_MENTOR);

$db = get_db();

// Ambil seluruh program layanan aktif untuk selector
$layananList = $db->query("SELECT id, kode_layanan, nama_layanan, jumlah_pertemuan, kategori_jenjang, deskripsi FROM tabel_layanan WHERE status_aktif = 'aktif' ORDER BY nama_layanan ASC")->fetchAll();

$selectedLayananId = isset($_GET['layanan_id']) && $_GET['layanan_id'] !== '' 
    ? (int)$_GET['layanan_id'] 
    : (!empty($layananList) ? (int)$layananList[0]['id'] : 0);

// Ambil info layanan terpilih
$selectedLayanan = null;
foreach ($layananList as $l) {
    if ((int)$l['id'] === $selectedLayananId) {
        $selectedLayanan = $l;
        break;
    }
}

// Ambil seluruh modul materi resmi dari BAK untuk program ini
$stmtMateri = $db->prepare("
    SELECT m.*, p.nama_lengkap as nama_penyusun_bak
    FROM tabel_materi m
    LEFT JOIN tabel_pegawai p ON p.id = m.id_pembuat_bak
    WHERE m.id_layanan = ? AND m.status_publikasi = 'published'
    ORDER BY m.pertemuan_ke ASC
");
$stmtMateri->execute([$selectedLayananId]);
$materiList = $stmtMateri->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-folder-open"></i>
            <span>Ruangguru-Style Material Hub</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Modul Resmi & Bahan Ajar BAK</h1>
        <p class="text-xs text-slate-500 mt-1">
            Materi kurikulum telah disiapkan terpusat oleh BAK. Anda tinggal membuka dan menyampaikan di kelas tatap muka.
        </p>
    </div>

    <!-- Dropdown Pemilihan Program Layanan -->
    <form method="GET" action="" class="flex items-center">
        <div class="relative">
            <select name="layanan_id" onchange="this.form.submit()" class="pl-4 pr-10 py-2.5 text-xs font-bold rounded-xl border border-slate-300 bg-white text-slate-800 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer">
                <?php foreach ($layananList as $item): ?>
                    <option value="<?= (int)$item['id'] ?>" <?= $selectedLayananId === (int)$item['id'] ? 'selected' : '' ?>>
                        📚 <?= e($item['nama_layanan']) ?> (<?= e($item['kategori_jenjang']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<!-- Banner Program Terpilih -->
<?php if ($selectedLayanan): ?>
    <div class="bg-gradient-brand text-white rounded-3xl p-6 sm:p-8 shadow-lg mb-8 relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <span class="inline-block px-3 py-1 rounded-full bg-white/20 text-xs font-semibold mb-2">
                    Kode: <?= e($selectedLayanan['kode_layanan']) ?> • <?= e($selectedLayanan['kategori_jenjang']) ?>
                </span>
                <h2 class="text-2xl font-extrabold"><?= e($selectedLayanan['nama_layanan']) ?></h2>
                <p class="text-xs text-teal-100 mt-1 max-w-2xl">
                    <?= e(!empty($selectedLayanan['deskripsi']) ? $selectedLayanan['deskripsi'] : 'Program kursus tatap muka berstandar BAK dengan modul berjenjang.') ?>
                </p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <div class="text-center px-4 py-2.5 rounded-2xl bg-white/15 backdrop-blur-md border border-white/20">
                    <span class="block text-2xl font-black text-white"><?= count($materiList) ?></span>
                    <span class="text-[10px] text-teal-100 font-semibold uppercase">Modul Siap Ajar</span>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Daftar Modul Berurutan Sesi Pertemuan (Ruangguru Card Grid) -->
<?php if (empty($materiList)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center">
        <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-file-circle-question"></i>
        </div>
        <h3 class="text-base font-bold text-slate-700">Modul Belum Diterbitkan</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
            Bagian Akademik (BAK) sedang menyusun bahan ajar resmi untuk program kursus ini.
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php foreach ($materiList as $mat): ?>
            <div class="material-card flex flex-col justify-between">
                <div>
                    <!-- Pertemuan Header -->
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <span class="material-meeting-badge">
                                <?= (int)$mat['pertemuan_ke'] ?>
                            </span>
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Sesi Pertemuan Ke-<?= (int)$mat['pertemuan_ke'] ?></span>
                                <h3 class="text-base font-bold text-slate-800 leading-snug"><?= e($mat['judul_modul']) ?></h3>
                            </div>
                        </div>
                    </div>

                    <!-- Ringkasan Materi -->
                    <div class="text-xs text-slate-600 mb-4 bg-slate-50/70 p-3.5 rounded-xl border border-slate-100">
                        <span class="font-bold text-slate-700 block text-[11px] mb-1">
                            <i class="fa-solid fa-lightbulb text-amber-500 mr-1"></i>
                            Konsep Materi:
                        </span>
                        <p class="leading-relaxed"><?= nl2br(e($mat['ringkasan_materi'])) ?></p>
                    </div>

                    <!-- PANDUAN KHUSUS MENTOR DARI BAK (CRITICAL COMPONENT) -->
                    <?php if (!empty($mat['panduan_mentor'])): ?>
                        <div class="p-3.5 rounded-xl bg-teal-50/70 border border-teal-200/80 text-xs text-teal-900 mb-4">
                            <div class="flex items-center gap-1.5 font-bold text-teal-800 text-[11px] mb-1">
                                <i class="fa-solid fa-chalkboard-user text-teal-600"></i>
                                <span>PANDUAN MENGAJAR UNTUK MENTOR DI KELAS:</span>
                            </div>
                            <p class="text-teal-900/90 leading-relaxed italic">
                                "<?= nl2br(e($mat['panduan_mentor'])) ?>"
                            </p>
                        </div>
                    <?php endif; ?>

                    <!-- Standar Tugas / Produk Siswa -->
                    <?php if (!empty($mat['standar_tugas_produk'])): ?>
                        <div class="text-xs text-slate-600 mb-4 flex items-start gap-2">
                            <i class="fa-solid fa-list-check text-brand-600 mt-0.5 text-xs"></i>
                            <div>
                                <strong class="text-slate-800 block text-[11px]">Standar Latihan / Output Siswa:</strong>
                                <span><?= e($mat['standar_tugas_produk']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer Modul Card -->
                <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs mt-2">
                    <span class="text-[11px] text-slate-400 font-mono">Kode: <?= e($mat['kode_materi']) ?></span>

                    <div class="flex items-center gap-2">
                        <?php 
                        $isLink = ($mat['tipe_lampiran'] ?? '') === 'link' || (!empty($mat['external_url']) && str_starts_with($mat['external_url'], 'http'));
                        $urlTarget = $mat['external_url'] ?? $mat['file_modul'];
                        ?>
                        <?php if ($isLink && !empty($urlTarget)): ?>
                            <a href="<?= e($urlTarget) ?>" target="_blank"
                               class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-sky-50 text-sky-700 font-bold text-xs border border-sky-200 hover:bg-sky-100 shadow-sm transition-all">
                                <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                <span>Buka Link Modul</span>
                            </a>
                        <?php elseif (!empty($mat['file_modul'])): ?>
                            <a href="<?= URL_UPLOAD_MODUL . e($mat['file_modul']) ?>" target="_blank"
                               class="btn-brand text-xs py-2 px-3.5 shadow-sm shadow-brand-500/20">
                                <i class="fa-solid fa-file-arrow-down text-xs"></i>
                                <span>Unduh Modul (PDF/PPT)</span>
                            </a>
                        <?php else: ?>
                            <span class="text-xs font-semibold text-slate-400 px-3 py-1.5 bg-slate-100 rounded-lg">
                                Ringkasan di Layar
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

