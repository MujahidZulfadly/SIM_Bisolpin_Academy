<?php
/**
 * SIM BISOLPIN ACADEMY - Monitoring Sarpras & Aset Cabang (Direktur Cabang)
 */

declare(strict_types=1);

$pageTitle = 'Fasilitas & Aset Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id();
verify_branch_access($branchId);
$db = get_db();

// Ambil seluruh aset di cabang ini
$stmt = $db->prepare("
    SELECT a.*, p.nama_lengkap as nama_penanggung_jawab
    FROM tabel_aset a
    LEFT JOIN tabel_pegawai p ON p.id = a.id_penanggung_jawab
    WHERE a.id_cabang = ?
    ORDER BY a.kategori_aset ASC, a.id DESC
");
$stmt->execute([$branchId]);
$asetList = $stmt->fetchAll();

// Hitung total aset
$stmtTotal = $db->prepare("SELECT COALESCE(SUM(nilai_perolehan), 0), COUNT(*) FROM tabel_aset WHERE id_cabang = ?");
$stmtTotal->execute([$branchId]);
[$totalNilaiAset, $totalUnit] = $stmtTotal->fetch(PDO::FETCH_NUM);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-couch"></i>
            <span>Inventaris Fisik Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Sarana & Prasarana Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Total Nilai Fasilitas: <strong class="text-slate-800 font-bold"><?= format_rupiah($totalNilaiAset) ?></strong> (<?= (int)$totalUnit ?> Item Terdata)
        </p>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?= base_url('modules/direktur_cabang/laporan.php?jenis=aset') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Cetak Rekap Aset</span>
        </a>
    </div>
</div>

<!-- Tabel Aset -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Aset</th>
                    <th>Kategori</th>
                    <th>Lokasi Penempatan</th>
                    <th>Jumlah Unit</th>
                    <th>Kondisi Fisik</th>
                    <th>Nilai Perolehan</th>
                    <th>PJ Sarpras</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($asetList)): ?>
                    <?= render_empty_state(
                        'fa-couch',
                        'Belum Ada Fasilitas Terdata di Cabang',
                        'Belum ada data inventaris sarana dan ruang kelas fisik di cabang Anda. Petugas Sarpras dapat mendaftarkan aset melalui portal Sarpras.',
                        null,
                        7
                    ) ?>
                <?php else: ?>
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
                            <span class="inline-block px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-700 font-semibold text-xs border border-slate-200 capitalize">
                                <?= str_replace('_', ' ', e($a['kategori_aset'])) ?>
                            </span>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($a['lokasi_penempatan']) ?></div>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= (int)$a['jumlah_unit'] ?> Unit</div>
                        </td>

                        <td>
                            <?= render_status_badge($a['kondisi']) ?>
                        </td>

                        <td>
                            <div class="font-extrabold text-brand-700 text-sm"><?= format_rupiah($a['nilai_perolehan']) ?></div>
                            <div class="text-[10px] text-slate-400"><?= format_tanggal_indonesia($a['tanggal_perolehan']) ?></div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($a['nama_penanggung_jawab'] ?? 'Teknisi Sarpras') ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

