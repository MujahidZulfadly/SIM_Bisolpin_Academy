<?php
/**
 * SIM BISOLPIN ACADEMY - Data Induk Siswa Nasional (Pimpinan Pusat)
 */

declare(strict_types=1);

$pageTitle = 'Data Siswa Nasional';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_PIMPINAN);

$db = get_db();
$activeBranchId = get_active_branch_id();

$sql = "
    SELECT s.*, 
           c.nama_cabang, c.kode_cabang,
           l.nama_layanan, l.kode_layanan,
           m.nama_lengkap as nama_mentor
    FROM tabel_siswa s
    JOIN tabel_cabang c ON c.id = s.id_cabang
    JOIN tabel_layanan l ON l.id = s.id_layanan
    LEFT JOIN tabel_pegawai m ON m.id = s.id_mentor_pembimbing
";
$params = [];

if ($activeBranchId !== null) {
    $sql .= " WHERE s.id_cabang = ?";
    $params[] = $activeBranchId;
}

$sql .= " ORDER BY s.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$siswaList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-user-graduate"></i>
            <span>Database Siswa Nasional</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Audit Progres Belajar Siswa</h1>
        <p class="text-xs text-slate-500 mt-1">
            Menampilkan data siswa aktif dan pencapaian kompetensi tatap muka di seluruh cabang.
        </p>
    </div>
</div>

<!-- Tabel Siswa -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>NIS & Nama Lengkap</th>
                    <th>Cabang</th>
                    <th>Program Layanan</th>
                    <th>Mentor Pembimbing</th>
                    <th>Progres Kelas</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($siswaList as $s): ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($s['nama_lengkap']) ?></div>
                            <div class="text-xs font-mono text-slate-400">NIS: <?= e($s['nis_bisolpin']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= e($s['asal_sekolah_instansi'] ?? '-') ?></div>
                        </td>

                        <td>
                            <div class="font-semibold text-slate-700 text-xs"><?= e($s['nama_cabang']) ?></div>
                            <div class="text-[10px] text-slate-400 font-mono"><?= e($s['kode_cabang']) ?></div>
                        </td>

                        <td>
                            <div class="font-bold text-brand-700 text-xs"><?= e($s['nama_layanan']) ?></div>
                            <div class="text-[11px] text-slate-400">Pertemuan ke-<?= (int)$s['pertemuan_terakhir_diikuti'] ?></div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($s['nama_mentor'] ?? 'Belum Ditugaskan') ?></div>
                            <?php if (!empty($s['catatan_progres_mentor'])): ?>
                                <div class="text-[11px] text-slate-400 max-w-xs truncate italic" title="<?= e($s['catatan_progres_mentor']) ?>">
                                    "<?= e($s['catatan_progres_mentor']) ?>"
                                </div>
                            <?php endif; ?>
                        </td>

                        <td class="w-44">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-bold text-slate-700"><?= (float)$s['persentase_progres'] ?>%</span>
                                <span class="text-[10px] text-slate-400">Tuntas</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-brand-500 h-2 rounded-full" style="width: <?= (float)$s['persentase_progres'] ?>%"></div>
                            </div>
                        </td>

                        <td>
                            <?= render_status_badge($s['status_belajar']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

