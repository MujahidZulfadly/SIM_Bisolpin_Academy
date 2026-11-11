<?php
/**
 * SIM BISOLPIN ACADEMY - Log Pemeliharaan & Riwayat Perawatan Fasilitas Cabang
 */

declare(strict_types=1);

$pageTitle = 'Log Pemeliharaan Fasilitas';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SARPRAS
check_role(ROLE_SARPRAS);

$db = get_db();
$branchId = get_active_branch_id() ?? 1;

// Pastikan tabel_maintenance_log tersedia di database
$db->exec("
    CREATE TABLE IF NOT EXISTS `tabel_maintenance_log` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `id_cabang` INT NOT NULL,
      `id_aset` INT NOT NULL,
      `tanggal_maintenance` DATE NOT NULL,
      `jenis_perbaikan` VARCHAR(150) NOT NULL,
      `biaya_perbaikan` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `teknisi_pelaksana` VARCHAR(150) NOT NULL,
      `kondisi_sebelum` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
      `kondisi_setelah` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
      `keterangan_tindakan` TEXT NOT NULL,
      `created_by` INT NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_maint_cabang` (`id_cabang`),
      INDEX `idx_maint_aset` (`id_aset`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Seed awal riwayat pemeliharaan jika tabel masih kosong
$cekCountMaint = (int)$db->query("SELECT COUNT(*) FROM tabel_maintenance_log")->fetchColumn();
if ($cekCountMaint === 0) {
    try {
        $db->exec("
            INSERT INTO `tabel_maintenance_log` (`id`, `id_cabang`, `id_aset`, `tanggal_maintenance`, `jenis_perbaikan`, `biaya_perbaikan`, `teknisi_pelaksana`, `kondisi_sebelum`, `kondisi_setelah`, `keterangan_tindakan`, `created_by`, `created_at`) VALUES
            (1, 1, 1, '2025-01-25', 'Servis Cuci AC & Pengecekan Kelistrikan', 350000.00, 'CV Hawa Sejuk Bersama', 'perlu_perbaikan', 'baik', 'Pembersihan filter AC 2 PK dan pengisian freon R32. Temperatur ruang kelas stabil di 22C.', 7, NOW()),
            (2, 1, 2, '2025-02-02', 'Optimalisasi Jaringan Gigabit LAN & Re-imaging OS', 500000.00, 'Budi Santoso (Sarpras)', 'perlu_perbaikan', 'baik', 'Peremajaan kabel switch hub port 8 dan deploy software VS Code serta compiler C++ untuk 20 PC.', 7, NOW())
        ");
    } catch (\Throwable $e) {
        // Skip silently if error
    }
}

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH LOG PEMELIHARAAN BARU
    if ($action === 'tambah_maintenance') {
        $idAset = (int)($_POST['id_aset'] ?? 0);
        $tanggal = !empty($_POST['tanggal_maintenance']) ? trim($_POST['tanggal_maintenance']) : date('Y-m-d');
        $jenisPerbaikan = trim($_POST['jenis_perbaikan'] ?? '');
        $biaya = (float)($_POST['biaya_perbaikan'] ?? 0);
        $teknisi = trim($_POST['teknisi_pelaksana'] ?? '');
        $kondisiSebelum = trim($_POST['kondisi_sebelum'] ?? 'perlu_perbaikan');
        $kondisiSetelah = trim($_POST['kondisi_setelah'] ?? 'baik');
        $keterangan = trim($_POST['keterangan_tindakan'] ?? '');

        if (empty($idAset) || empty($jenisPerbaikan) || empty($teknisi) || empty($keterangan)) {
            redirect_with_message('modules/sarpras/maintenance.php', 'error', 'Pilih aset, jenis perbaikan, nama teknisi, dan rincian tindakan.', 'Validasi Gagal');
        }

        try {
            $db->beginTransaction();

            // 1. Catat ke tabel_maintenance_log
            $stmtInsert = $db->prepare("
                INSERT INTO tabel_maintenance_log (
                    id_cabang, id_aset, tanggal_maintenance, jenis_perbaikan,
                    biaya_perbaikan, teknisi_pelaksana, kondisi_sebelum,
                    kondisi_setelah, keterangan_tindakan, created_by, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, NOW()
                )
            ");
            $stmtInsert->execute([
                $branchId, $idAset, $tanggal, $jenisPerbaikan,
                $biaya, $teknisi, $kondisiSebelum,
                $kondisiSetelah, $keterangan, auth_id()
            ]);

            // 2. Update status kondisi & catatan di tabel_aset
            $stmtUpdateAset = $db->prepare("
                UPDATE tabel_aset SET
                    kondisi = ?,
                    catatan_maintenance = ?,
                    updated_at = NOW()
                WHERE id = ? AND id_cabang = ?
            ");
            $updateCatatan = "Servis {$tanggal} oleh {$teknisi}: {$keterangan}";
            $stmtUpdateAset->execute([$kondisiSetelah, $updateCatatan, $idAset, $branchId]);

            $db->commit();
            redirect_with_message('modules/sarpras/maintenance.php', 'success', 'Log pemeliharaan berhasil dicatat dan status kondisi aset telah diperbarui.', 'Pemeliharaan Selesai');
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal catat maintenance: " . $e->getMessage());
            redirect_with_message('modules/sarpras/maintenance.php', 'error', 'Terjadi kesalahan sistem saat menyimpan log pemeliharaan: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. HAPUS LOG MAINTENANCE
    if ($action === 'hapus_maintenance') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmtDel = $db->prepare("DELETE FROM tabel_maintenance_log WHERE id = ? AND id_cabang = ?");
            $stmtDel->execute([$id, $branchId]);

            redirect_with_message('modules/sarpras/maintenance.php', 'success', 'Catatan riwayat pemeliharaan berhasil dihapus.', 'Hapus Berhasil');
        } catch (\Throwable $e) {
            redirect_with_message('modules/sarpras/maintenance.php', 'error', 'Gagal menghapus catatan.', 'Gagal Hapus');
        }
    }
}

// AMBIL DAFTAR LOG MAINTENANCE DENGAN FILTER
$filterAset = !empty($_GET['id_aset']) ? (int)$_GET['id_aset'] : 0;
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT m.*, a.nama_aset, a.kode_aset, a.lokasi_penempatan, a.kategori_aset, u.username as inputer_name
    FROM tabel_maintenance_log m
    JOIN tabel_aset a ON a.id = m.id_aset
    LEFT JOIN users u ON u.id = m.created_by
    WHERE m.id_cabang = ?
";
$params = [$branchId];

if (!empty($filterAset)) {
    $sql .= " AND m.id_aset = ?";
    $params[] = $filterAset;
}
if (!empty($search)) {
    $sql .= " AND (a.nama_aset LIKE ? OR a.kode_aset LIKE ? OR m.jenis_perbaikan LIKE ? OR m.teknisi_pelaksana LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY m.tanggal_maintenance DESC, m.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logList = $stmt->fetchAll();

// Total Akumulasi Biaya Perbaikan Cabang
$stmtTotalBiaya = $db->prepare("SELECT SUM(biaya_perbaikan) FROM tabel_maintenance_log WHERE id_cabang = ?");
$stmtTotalBiaya->execute([$branchId]);
$totalBiayaMaintenance = (float)($stmtTotalBiaya->fetchColumn() ?: 0);

// Daftar Aset Cabang untuk Dropdown Form
$stmtAsetAll = $db->prepare("
    SELECT id, kode_aset, nama_aset, lokasi_penempatan, kondisi 
    FROM tabel_aset 
    WHERE id_cabang = ? 
    ORDER BY nama_aset ASC
");
$stmtAsetAll->execute([$branchId]);
$asetPilihan = $stmtAsetAll->fetchAll();

// Pre-fill aset jika ada GET id_aset
$prefilledAset = null;
if (!empty($filterAset)) {
    foreach ($asetPilihan as $ap) {
        if ($ap['id'] === $filterAset) {
            $prefilledAset = $ap;
            break;
        }
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-screwdriver-wrench"></i>
            <span>Pemeliharaan & Perbaikan Sarana Belajar</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Log Pemeliharaan & Perawatan Fasilitas
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pencatatan riwayat servis berkala, perbaikan perangkat laboratorium, serta anggaran biaya pemeliharaan.
        </p>
    </div>

    <div>
        <button onclick="openModal('modalInputMaintenance')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Catat Servis / Perbaikan Baru</span>
        </button>
    </div>
</div>

<!-- Bento KPI Log Pemeliharaan -->
<div class="bento-grid mb-6">
    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Biaya Pemeliharaan</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-2xl font-black text-slate-800"><?= format_rupiah($totalBiayaMaintenance) ?></span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Akumulasi Realisasi Servis Cabang</span>
        </div>
    </div>

    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Sesi Servis</span>
            <div class="w-10 h-10 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-slate-800"><?= count($logList) ?></span>
            <span class="text-xs text-slate-400 font-medium">Log Tindakan</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Perawatan Berkala & Perbaikan</span>
        </div>
    </div>

    <div class="bento-card col-span-4">
        <div class="bento-card-header">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Fasilitas Terawat</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-shield-heart"></i>
            </div>
        </div>
        <div class="flex items-baseline gap-2">
            <span class="text-3xl font-black text-emerald-600"><?= count($asetPilihan) ?></span>
            <span class="text-xs text-slate-400 font-medium">Fasilitas Terdaftar</span>
        </div>
        <div class="mt-4 pt-3 border-t border-slate-100 text-xs text-slate-500">
            <span>Standar Kualitas Pembelajaran Tatap Muka</span>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-4 mb-6">
    <form method="GET" action="" class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3 flex-1 min-w-[280px]">
            <div class="relative flex-1 min-w-[200px]">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama fasilitas, kode aset, teknisi, pekerjaan..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-52">
                <select name="id_aset" class="form-input text-xs py-2">
                    <option value="">Semua Fasilitas</option>
                    <?php foreach ($asetPilihan as $ap): ?>
                        <option value="<?= $ap['id'] ?>" <?= $filterAset === $ap['id'] ? 'selected' : '' ?>>
                            <?= e($ap['nama_aset']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterAset)): ?>
                <a href="<?= base_url('modules/sarpras/maintenance.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Ditemukan: <span class="font-bold text-slate-800"><?= count($logList) ?> Log</span>
        </div>
    </form>
</div>

<!-- Tabel Log Pemeliharaan -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <th class="py-3 px-4">Tanggal Servis</th>
                    <th class="py-3 px-4">Fasilitas & Kode Aset</th>
                    <th class="py-3 px-4">Jenis Perbaikan / Servis</th>
                    <th class="py-3 px-4">Teknisi / Vendor</th>
                    <th class="py-3 px-4 text-center">Sebelum &rarr; Sesudah</th>
                    <th class="py-3 px-4 text-right">Biaya Perbaikan</th>
                    <th class="py-3 px-4">Rincian Tindakan</th>
                    <th class="py-3 px-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($logList)): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400">
                            <i class="fa-regular fa-folder-open text-3xl mb-2 text-slate-300 block"></i>
                            Tidak ada catatan riwayat pemeliharaan yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logList as $m): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4 text-slate-500 font-medium whitespace-nowrap">
                                <?= format_tanggal_indonesia($m['tanggal_maintenance']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <h4 class="font-bold text-slate-800"><?= e($m['nama_aset']) ?></h4>
                                <p class="font-mono text-[11px] text-teal-700 font-semibold"><?= e($m['kode_aset']) ?></p>
                                <p class="text-[10px] text-slate-400"><?= e($m['lokasi_penempatan']) ?></p>
                            </td>
                            <td class="py-3 px-4 font-semibold text-slate-700">
                                <?= e($m['jenis_perbaikan']) ?>
                            </td>
                            <td class="py-3 px-4 text-slate-600 font-medium">
                                <i class="fa-solid fa-user-gear text-slate-400 mr-1"></i>
                                <?= e($m['teknisi_pelaksana']) ?>
                            </td>
                            <td class="py-3 px-4 text-center whitespace-nowrap">
                                <span class="text-[10px] uppercase font-bold text-slate-400"><?= e($m['kondisi_sebelum']) ?></span>
                                <span class="mx-1 text-slate-300">&rarr;</span>
                                <?= render_status_badge($m['kondisi_setelah']) ?>
                            </td>
                            <td class="py-3 px-4 text-right font-black text-slate-800 font-mono text-xs">
                                <?= format_rupiah($m['biaya_perbaikan']) ?>
                            </td>
                            <td class="py-3 px-4 text-slate-600 max-w-xs">
                                <p class="text-[11px] leading-relaxed line-clamp-2"><?= e($m['keterangan_tindakan']) ?></p>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <form method="POST" action="" onsubmit="return confirm('Hapus log pemeliharaan ini?');" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="hapus_maintenance">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <button type="submit" title="Hapus Log" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 inline-flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: FORM INPUT PEMELIHARAAN BARU -->
<!-- ========================================================================= -->
<div id="modalInputMaintenance" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Catat Pemeliharaan & Servis Fasilitas</h3>
                <p class="text-xs text-slate-400 mt-0.5">Status kondisi aset pada tabel inventaris akan diperbarui secara otomatis.</p>
            </div>
            <button onclick="closeModal('modalInputMaintenance')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_maintenance">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Fasilitas / Aset yang Diservis <span class="text-rose-500">*</span></label>
                <select name="id_aset" id="maint_select_aset" required class="form-input text-xs">
                    <option value="">-- Pilih Fasilitas --</option>
                    <?php foreach ($asetPilihan as $ap): ?>
                        <option value="<?= $ap['id'] ?>" <?= ($filterAset === $ap['id']) ? 'selected' : '' ?>>
                            <?= e($ap['nama_aset']) ?> (<?= e($ap['kode_aset']) ?> - Kondisi: <?= ucfirst($ap['kondisi']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Pelaksanaan Servis <span class="text-rose-500">*</span></label>
                    <input type="date" name="tanggal_maintenance" value="<?= date('Y-m-d') ?>" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Teknisi / Vendor Pelaksana <span class="text-rose-500">*</span></label>
                    <input type="text" name="teknisi_pelaksana" placeholder="Contoh: CV Hawa Sejuk / Teknisi Budi" required class="form-input text-xs">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jenis Pekerjaan / Servis <span class="text-rose-500">*</span></label>
                    <input type="text" name="jenis_perbaikan" placeholder="Contoh: Cuci AC / Ganti RAM / Re-cabling LAN" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Biaya Servis / Sparepart (Rp)</label>
                    <input type="number" name="biaya_perbaikan" value="0" min="0" step="1000" class="form-input text-xs">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kondisi Sebelum Servis <span class="text-rose-500">*</span></label>
                    <select name="kondisi_sebelum" required class="form-input text-xs">
                        <option value="perlu_perbaikan">Perlu Perbaikan</option>
                        <option value="rusak_berat">Rusak Berat</option>
                        <option value="baik">Kondisi Baik (Servis Rutin)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kondisi Setelah Servis Selesai <span class="text-rose-500">*</span></label>
                    <select name="kondisi_setelah" required class="form-input text-xs">
                        <option value="baik">Kondisi Baik (Siap Digunakan)</option>
                        <option value="perlu_perbaikan">Masih Perlu Perbaikan Lanjutan</option>
                        <option value="rusak_berat">Rusak Berat (Gagal Servis)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Rincian Tindakan & Suku Cadang yang Diganti <span class="text-rose-500">*</span></label>
                <textarea name="keterangan_tindakan" rows="3" placeholder="Jelaskan tindakan teknis, suku cadang yang diganti, serta hasil pengujian pasca perbaikan..." required class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalInputMaintenance')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Log Servis
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($filterAset)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    openModal('modalInputMaintenance');
});
</script>
<?php endif; ?>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

