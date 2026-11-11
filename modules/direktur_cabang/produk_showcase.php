<?php
/**
 * SIM BISOLPIN ACADEMY - Showcase Karya & Produk Siswa Cabang (Direktur Cabang)
 */

declare(strict_types=1);

$pageTitle = 'Galeri Karya Siswa Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id();
verify_branch_access($branchId);
$db = get_db();

// Ambil seluruh karya di cabang ini
$stmt = $db->prepare("
    SELECT p.*, 
           s.nama_lengkap as nama_siswa, s.nis_bisolpin,
           l.nama_layanan, l.kode_layanan,
           m.nama_lengkap as nama_mentor
    FROM tabel_produk p
    JOIN tabel_siswa s ON s.id = p.id_siswa
    LEFT JOIN tabel_layanan l ON l.id = p.id_layanan
    LEFT JOIN tabel_pegawai m ON m.id = p.id_mentor_validator
    WHERE p.id_cabang = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$branchId]);
$produkList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-100 text-purple-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-gem"></i>
            <span>Etalase Portofolio Kelulusan</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Karya & Produk Hasil Belajar Siswa</h1>
        <p class="text-xs text-slate-500 mt-1">
            Bukti kompetensi nyata dari pembelajaran tatap muka yang telah dinilai dan divalidasi oleh mentor.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?= base_url('modules/direktur_cabang/laporan.php?jenis=produk') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Cetak Rekap Karya</span>
        </a>
    </div>
</div>

<?php if (empty($produkList)): ?>
    <div class="bg-white rounded-3xl border border-slate-200 p-12 text-center">
        <div class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-laptop-code"></i>
        </div>
        <h3 class="text-base font-bold text-slate-700">Belum Ada Karya yang Diunggah</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
            Siswa di cabang ini belum menyelesaikan modul pembuatan tugas akhir atau mentor belum memvalidasi produk.
        </p>
    </div>
<?php else: ?>
    <!-- Grid Showcase Card -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($produkList as $pk): ?>
            <div class="product-showcase-card">
                <div class="product-thumbnail-box">
                    <div class="text-center p-4">
                        <i class="fa-solid fa-code text-5xl text-brand-600 mb-2"></i>
                        <span class="block text-xs font-mono font-bold text-slate-500"><?= e($pk['kode_produk']) ?></span>
                    </div>
                </div>

                <div class="p-6 flex-1 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-teal-50 text-teal-800 border border-teal-200">
                                <?= e($pk['nama_layanan'] ?? 'Program Kursus') ?>
                            </span>
                            <?php if ($pk['nilai_karya']): ?>
                                <span class="text-xs font-black px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    Skor: <?= (float)$pk['nilai_karya'] ?>/100
                                </span>
                            <?php endif; ?>
                        </div>

                        <h3 class="text-base font-bold text-slate-800 mb-1 leading-snug"><?= e($pk['nama_produk']) ?></h3>
                        <p class="text-xs text-slate-500 line-clamp-3 mb-4"><?= e($pk['deskripsi_produk']) ?></p>

                        <!-- Review Mentor Box -->
                        <?php if (!empty($pk['review_mentor'])): ?>
                            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100 text-xs mb-4">
                                <span class="block text-[10px] text-slate-400 font-semibold uppercase">Catatan Mentor:</span>
                                <p class="text-slate-600 italic mt-0.5">"<?= e($pk['review_mentor']) ?>"</p>
                                <span class="block text-[10px] text-slate-400 mt-1">— <?= e($pk['nama_mentor'] ?? 'Mentor') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                        <div>
                            <span class="block text-[10px] text-slate-400">Pembuat:</span>
                            <strong class="text-slate-700"><?= e($pk['nama_siswa']) ?></strong>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php 
                            $isLink = ($pk['tipe_lampiran'] ?? '') === 'link' || (!empty($pk['external_url']) && str_starts_with($pk['external_url'], 'http')) || (!empty($pk['file_atau_url_karya']) && str_starts_with($pk['file_atau_url_karya'], 'http'));
                            $linkVal = $pk['external_url'] ?? $pk['file_atau_url_karya'];
                            ?>
                            <?php if ($isLink && !empty($linkVal)): ?>
                                <a href="<?= e($linkVal) ?>" target="_blank" class="px-2.5 py-1 rounded-lg bg-sky-50 hover:bg-sky-100 text-sky-700 font-bold text-xs transition-colors flex items-center gap-1 border border-sky-200">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                    <span>Buka Link</span>
                                </a>
                            <?php elseif (!empty($pk['file_atau_url_karya'])): ?>
                                <a href="<?= base_url('assets/uploads/produk_siswa/' . rawurlencode($pk['file_atau_url_karya'])) ?>" target="_blank" class="px-2.5 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold text-xs transition-colors flex items-center gap-1 border border-indigo-200">
                                    <i class="fa-solid fa-download text-[10px]"></i>
                                    <span>Unduh File</span>
                                </a>
                            <?php endif; ?>
                            <?= render_status_badge($pk['status_tampil']) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

