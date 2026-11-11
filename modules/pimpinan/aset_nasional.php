<?php
/**
 * SIM BISOLPIN ACADEMY - Audit Aset & Sarpras Nasional (Pimpinan Pusat)
 */

declare(strict_types=1);

$pageTitle = 'Audit Sarana & Prasarana Nasional';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_PIMPINAN);

$db = get_db();
$activeBranchId = get_active_branch_id();

$sql = "
    SELECT a.*, 
           c.nama_cabang, c.kode_cabang,
           p.nama_lengkap as nama_penanggung_jawab
    FROM tabel_aset a
    JOIN tabel_cabang c ON c.id = a.id_cabang
    LEFT JOIN tabel_pegawai p ON p.id = a.id_penanggung_jawab
";
$params = [];

if ($activeBranchId !== null) {
    $sql .= " WHERE a.id_cabang = ?";
    $params[] = $activeBranchId;
}

$sql .= " ORDER BY a.id_cabang ASC, a.kategori_aset ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$asetList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span>Inventarisasi Sarana Prasarana</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Audit Aset Fisik & Fasilitas Kursus</h1>
        <p class="text-xs text-slate-500 mt-1">
            Rekapitulasi fasilitas ruang kelas, lab komputer, dan perlengkapan tatap muka di seluruh cabang.
        </p>
    </div>
</div>

<!-- Tabel Aset -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Aset</th>
                    <th>Cabang & Penempatan</th>
                    <th>Kategori</th>
                    <th>Jumlah Unit</th>
                    <th>Kondisi Fisik</th>
                    <th>Nilai Perolehan</th>
                    <th>Penanggung Jawab</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($asetList as $a): ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($a['nama_aset']) ?></div>
                            <div class="text-xs font-mono text-slate-400">Kode: <?= e($a['kode_aset']) ?></div>
                            <?php if (!empty($a['catatan_maintenance'])): ?>
                                <div class="text-[11px] text-slate-500 mt-0.5 italic max-w-xs truncate" title="<?= e($a['catatan_maintenance']) ?>">
                                    <?= e($a['catatan_maintenance']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="font-semibold text-slate-700 text-xs"><?= e($a['nama_cabang']) ?></div>
                            <div class="text-[11px] text-slate-400"><?= e($a['lokasi_penempatan']) ?></div>
                        </td>

                        <td>
                            <span class="inline-block px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-semibold text-xs border border-slate-200 capitalize">
                                <?= str_replace('_', ' ', e($a['kategori_aset'])) ?>
                            </span>
                        </td>

                        <td>
                            <div class="font-black text-slate-800 text-sm"><?= (int)$a['jumlah_unit'] ?> Unit</div>
                        </td>

                        <td>
                            <?= render_status_badge($a['kondisi']) ?>
                        </td>

                        <td>
                            <div class="font-extrabold text-brand-700 text-sm"><?= format_rupiah($a['nilai_perolehan']) ?></div>
                            <div class="text-[10px] text-slate-400"><?= format_tanggal_indonesia($a['tanggal_perolehan']) ?></div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($a['nama_penanggung_jawab'] ?? 'Staff Sarpras') ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

