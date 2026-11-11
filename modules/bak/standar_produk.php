<?php
/**
 * SIM BISOLPIN ACADEMY - Standar Capaian & Output Produk Kelulusan (BAK)
 */

declare(strict_types=1);

$pageTitle = 'Standar Capaian Produk Kelulusan';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_BAK);

$db = get_db();

// Handle update target output produk per layanan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $layananId    = (int)($_POST['layanan_id'] ?? 0);
    $targetOutput = trim($_POST['target_output_produk'] ?? '');

    try {
        $stmtUp = $db->prepare("UPDATE tabel_layanan SET target_output_produk = ? WHERE id = ?");
        $stmtUp->execute([$targetOutput, $layananId]);
        set_flash('success', 'Standar target output produk berhasil diperbarui.');
    } catch (\Throwable $e) {
        set_flash('error', 'Gagal memperbarui target output: ' . $e->getMessage());
    }
    redirect('modules/bak/standar_produk.php');
}

// Ambil seluruh program layanan beserta jumlah karya yang sudah dihasilkan siswa
$stmt = $db->query("
    SELECT l.*,
           (SELECT COUNT(*) FROM tabel_produk p WHERE p.id_layanan = l.id AND p.status_tampil = 'dipublikasikan') as total_karya_terbit,
           (SELECT COUNT(*) FROM tabel_produk p WHERE p.id_layanan = l.id AND p.status_tampil = 'menunggu_validasi') as total_menunggu,
           (SELECT COALESCE(AVG(p.nilai_karya), 0) FROM tabel_produk p WHERE p.id_layanan = l.id AND p.nilai_karya IS NOT NULL) as rata_rata_nilai
    FROM tabel_layanan l
    WHERE l.status_aktif = 'aktif'
    ORDER BY l.created_at DESC
");
$standarList = $stmt->fetchAll();

// Ambil sampel karya siswa yang telah dipublikasikan untuk audit mutu BAK
$stmtAudit = $db->query("
    SELECT p.*, s.nama_lengkap as nama_siswa, c.nama_cabang, l.nama_layanan, m.nama_lengkap as nama_mentor
    FROM tabel_produk p
    JOIN tabel_siswa s ON s.id = p.id_siswa
    JOIN tabel_cabang c ON c.id = p.id_cabang
    LEFT JOIN tabel_layanan l ON l.id = p.id_layanan
    LEFT JOIN tabel_pegawai m ON m.id = p.id_mentor_validator
    ORDER BY p.nilai_karya DESC
    LIMIT 6
");
$karyaAuditList = $stmtAudit->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-award"></i>
            <span>Standar Kualitas Kelulusan Kursus</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Rubrik Capaian Produk Siswa</h1>
        <p class="text-xs text-slate-500 mt-1">
            Penetapan kriteria portofolio wajib bagi setiap jenjang kursus tatap muka di seluruh cabang.
        </p>
    </div>
</div>

<!-- Grid Rubrik Program -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <?php foreach ($standarList as $st): ?>
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700">
                        <?= e($st['kategori_jenjang']) ?>
                    </span>
                    <span class="text-xs font-mono font-bold text-slate-400"><?= e($st['kode_layanan']) ?></span>
                </div>

                <h3 class="text-lg font-bold text-slate-800 mb-2"><?= e($st['nama_layanan']) ?></h3>

                <!-- Form Target Output -->
                <form method="POST" action="" class="space-y-3 mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="layanan_id" value="<?= (int)$st['id'] ?>">

                    <div>
                        <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                            Target Standar Produk Kelulusan:
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="text" name="target_output_produk" value="<?= e($st['target_output_produk'] ?: 'Karya Portofolio Siswa') ?>" required
                                   class="flex-1 px-3.5 py-2 text-xs rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 font-semibold text-slate-800">
                            <button type="submit" class="btn-brand text-xs py-2 px-3 shrink-0">
                                <i class="fa-solid fa-floppy-disk"></i>
                                <span>Simpan</span>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Matriks Pencapaian Siswa di Lapangan -->
                <div class="grid grid-cols-3 gap-2 p-3 rounded-2xl bg-slate-50 border border-slate-100 text-center text-xs">
                    <div>
                        <span class="block text-base font-black text-emerald-600"><?= (int)$st['total_karya_terbit'] ?></span>
                        <span class="text-[10px] text-slate-400">Karya Terbit</span>
                    </div>
                    <div>
                        <span class="block text-base font-black text-amber-600"><?= (int)$st['total_menunggu'] ?></span>
                        <span class="text-[10px] text-slate-400">Verifikasi</span>
                    </div>
                    <div>
                        <span class="block text-base font-black text-brand-600"><?= number_format((float)$st['rata_rata_nilai'], 1) ?></span>
                        <span class="text-[10px] text-slate-400">Rata-rata Nilai</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Showcase Audit Mutu Karya -->
<div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8">
    <div class="bento-card-header mb-6">
        <div>
            <h2 class="text-base font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass-chart text-teal-600"></i>
                <span>Audit Kualitas Karya Siswa Lintas Cabang</span>
            </h2>
            <p class="text-xs text-slate-400 mt-0.5">Memastikan penilaian mentor di lapangan telah memenuhi standar mutu kurikulum BAK</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($karyaAuditList as $k): ?>
            <div class="product-showcase-card">
                <div class="product-thumbnail-box">
                    <div class="text-center p-4">
                        <i class="fa-solid fa-laptop-code text-5xl text-brand-600 mb-2"></i>
                        <span class="block text-xs font-mono font-bold text-slate-500"><?= e($k['kode_produk']) ?></span>
                    </div>
                </div>
                <div class="p-5 flex-1 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-700">
                                <?= e($k['nama_cabang']) ?>
                            </span>
                            <span class="text-xs font-black text-brand-600">Skor: <?= (float)$k['nilai_karya'] ?></span>
                        </div>
                        <h4 class="text-sm font-bold text-slate-800 mb-1"><?= e($k['nama_produk']) ?></h4>
                        <p class="text-xs text-slate-500 line-clamp-2 mb-3"><?= e($k['deskripsi_produk']) ?></p>
                        
                        <?php if (!empty($k['review_mentor'])): ?>
                            <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-100 text-[11px] text-slate-600 italic mb-3">
                                "<?= e($k['review_mentor']) ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400">Siswa: <strong class="text-slate-700"><?= e($k['nama_siswa']) ?></strong></span>
                        <span class="text-[10px] text-slate-400">Validator: <?= e($k['nama_mentor'] ?? '-') ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

