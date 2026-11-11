<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Inventaris & Aset Fisik Cabang
 */

declare(strict_types=1);

$pageTitle = 'Inventaris Fasilitas & Aset Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SARPRAS
check_role(ROLE_SARPRAS);

$db = get_db();
$branchId = auth_branch_id() ?? get_active_branch_id() ?? 1;

// Keamanan isolasi tenant multi-cabang
verify_branch_access($branchId);

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH ASET BARU
    if ($action === 'tambah_aset') {
        $kodeAset = trim($_POST['kode_aset'] ?? '');
        $namaAset = trim($_POST['nama_aset'] ?? '');
        $kategori = trim($_POST['kategori_aset'] ?? 'perangkat_it');
        $lokasi = trim($_POST['lokasi_penempatan'] ?? '');
        $jumlahUnit = (int)($_POST['jumlah_unit'] ?? 1);
        $kondisi = trim($_POST['kondisi'] ?? 'baik');
        $tanggalPerolehan = !empty($_POST['tanggal_perolehan']) ? trim($_POST['tanggal_perolehan']) : date('Y-m-d');
        $nilaiPerolehan = (float)($_POST['nilai_perolehan'] ?? 0);
        $catatan = trim($_POST['catatan_maintenance'] ?? '');
        $idPenanggungJawab = !empty($_POST['id_penanggung_jawab']) ? (int)$_POST['id_penanggung_jawab'] : null;

        if (empty($kodeAset) || empty($namaAset) || empty($lokasi) || $jumlahUnit <= 0) {
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Mohon lengkapi kode aset, nama aset, lokasi penempatan, dan jumlah unit.', 'Validasi Gagal');
        }

        // Cek Keunikan Kode Aset
        $stmtCek = $db->prepare("SELECT COUNT(*) FROM tabel_aset WHERE kode_aset = ?");
        $stmtCek->execute([$kodeAset]);
        if ((int)$stmtCek->fetchColumn() > 0) {
            redirect_with_message('modules/sarpras/inventaris.php', 'error', "Kode aset '{$kodeAset}' sudah digunakan.", 'Kode Duplikat');
        }

        try {
            $stmtInsert = $db->prepare("
                INSERT INTO tabel_aset (
                    id_cabang, id_penanggung_jawab, kode_aset, nama_aset,
                    kategori_aset, lokasi_penempatan, jumlah_unit, kondisi,
                    tanggal_perolehan, nilai_perolehan, catatan_maintenance, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, NOW()
                )
            ");
            $stmtInsert->execute([
                $branchId, $idPenanggungJawab, $kodeAset, $namaAset,
                $kategori, $lokasi, $jumlahUnit, $kondisi,
                $tanggalPerolehan, $nilaiPerolehan, $catatan
            ]);

            redirect_with_message('modules/sarpras/inventaris.php', 'success', "Aset {$namaAset} ({$kodeAset}) berhasil dicatat dalam inventaris.", 'Aset Ditambahkan');
        } catch (\Throwable $e) {
            error_log("Gagal tambah aset: " . $e->getMessage());
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Terjadi kesalahan sistem saat menyimpan aset: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. EDIT ASET
    if ($action === 'edit_aset') {
        $id = (int)($_POST['id'] ?? 0);
        $namaAset = trim($_POST['nama_aset'] ?? '');
        $kategori = trim($_POST['kategori_aset'] ?? 'perangkat_it');
        $lokasi = trim($_POST['lokasi_penempatan'] ?? '');
        $jumlahUnit = (int)($_POST['jumlah_unit'] ?? 1);
        $kondisi = trim($_POST['kondisi'] ?? 'baik');
        $nilaiPerolehan = (float)($_POST['nilai_perolehan'] ?? 0);
        $catatan = trim($_POST['catatan_maintenance'] ?? '');
        $idPenanggungJawab = !empty($_POST['id_penanggung_jawab']) ? (int)$_POST['id_penanggung_jawab'] : null;

        if (empty($namaAset) || empty($lokasi) || $jumlahUnit <= 0) {
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Data aset yang diperbarui tidak lengkap.', 'Validasi Gagal');
        }

        try {
            $stmtUpdate = $db->prepare("
                UPDATE tabel_aset SET
                    nama_aset = ?,
                    kategori_aset = ?,
                    lokasi_penempatan = ?,
                    jumlah_unit = ?,
                    kondisi = ?,
                    nilai_perolehan = ?,
                    catatan_maintenance = ?,
                    id_penanggung_jawab = ?,
                    updated_at = NOW()
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUpdate->execute([
                $namaAset, $kategori, $lokasi, $jumlahUnit,
                $kondisi, $nilaiPerolehan, $catatan, $idPenanggungJawab,
                $id, $branchId
            ]);

            redirect_with_message('modules/sarpras/inventaris.php', 'success', "Data aset {$namaAset} berhasil diperbarui.", 'Update Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal edit aset: " . $e->getMessage());
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Gagal memperbarui data aset: ' . $e->getMessage(), 'Gagal Update');
        }
    }

    // 3. ARSIP / SOFT DELETE ASET
    if ($action === 'hapus_aset' || $action === 'arsip_aset') {
        $id = (int)($_POST['id'] ?? 0);

        $stmtCek = $db->prepare("SELECT id, nama_aset, id_cabang FROM tabel_aset WHERE id = ?");
        $stmtCek->execute([$id]);
        $asetRow = $stmtCek->fetch();

        if (!$asetRow) {
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Data aset tidak ditemukan.', 'Gagal');
        }
        verify_branch_access((int)$asetRow['id_cabang']);

        try {
            // Soft delete: update kondisi menjadi rusak_berat & beri catatan arsip agar jadwal kelas tatap muka tidak error
            $stmtSoft = $db->prepare("
                UPDATE tabel_aset 
                SET kondisi = 'rusak_berat',
                    catatan_maintenance = CONCAT(COALESCE(catatan_maintenance, ''), ' [DIARSIPKAN pada ', NOW(), ']')
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtSoft->execute([$id, $branchId]);

            redirect_with_message('modules/sarpras/inventaris.php', 'success', "Aset {$asetRow['nama_aset']} berhasil diarsipkan (soft delete). Histori jadwal kelas tetap terjaga utuh.", 'Aset Diarsipkan');
        } catch (\Throwable $e) {
            error_log("Gagal arsip aset: " . $e->getMessage());
            redirect_with_message('modules/sarpras/inventaris.php', 'error', 'Gagal mengarsipkan aset: ' . $e->getMessage(), 'Gagal');
        }
    }
}

// AMBIL DATA ASET CABANG DENGAN FILTER
$filterKategori = trim($_GET['kategori'] ?? '');
$filterKondisi = trim($_GET['kondisi'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT a.*, p.nama_lengkap as nama_penanggung_jawab
    FROM tabel_aset a
    LEFT JOIN tabel_pegawai p ON p.id = a.id_penanggung_jawab
    WHERE a.id_cabang = ?
";
$params = [$branchId];

if (!empty($filterKategori)) {
    $sql .= " AND a.kategori_aset = ?";
    $params[] = $filterKategori;
}
if (!empty($filterKondisi)) {
    $sql .= " AND a.kondisi = ?";
    $params[] = $filterKondisi;
}
if (!empty($search)) {
    $sql .= " AND (a.nama_aset LIKE ? OR a.kode_aset LIKE ? OR a.lokasi_penempatan LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY a.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$asetList = $stmt->fetchAll();

// Daftar Pegawai Sarpras untuk Penanggung Jawab
$stmtPegawai = $db->prepare("
    SELECT id, nama_lengkap, nip_pegawai, jabatan_role 
    FROM tabel_pegawai 
    WHERE id_cabang = ? AND status_aktif = 'aktif'
    ORDER BY nama_lengkap ASC
");
$stmtPegawai->execute([$branchId]);
$pegawaiList = $stmtPegawai->fetchAll();

// Rekomendasi Kode Aset: AST-MKS-XXX
$countAset = (int)$db->query("SELECT COUNT(*) FROM tabel_aset")->fetchColumn() + 1;
$suggestedKodeAset = sprintf("AST-MKS-%03d", $countAset);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span>Inventarisasi Sarana Fisik & Fasilitas</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Inventaris Aset & Sarana Cabang
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pendataan menyeluruh ruang kelas fisik, PC komputer lab, smart TV, dan kit peraga robotik praktikum tatap muka.
        </p>
    </div>

    <div>
        <button onclick="openModal('modalTambahAset')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Tambah Aset Baru</span>
        </button>
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
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama barang, kode aset, atau lokasi..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-44">
                <select name="kategori" class="form-input text-xs py-2">
                    <option value="">Semua Kategori</option>
                    <option value="ruang_kelas" <?= $filterKategori === 'ruang_kelas' ? 'selected' : '' ?>>Ruang Kelas / Lab</option>
                    <option value="perangkat_it" <?= $filterKategori === 'perangkat_it' ? 'selected' : '' ?>>Perangkat IT</option>
                    <option value="alat_peraga" <?= $filterKategori === 'alat_peraga' ? 'selected' : '' ?>>Kit Alat Peraga</option>
                    <option value="furnitur" <?= $filterKategori === 'furnitur' ? 'selected' : '' ?>>Furnitur</option>
                    <option value="lainnya" <?= $filterKategori === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
                </select>
            </div>

            <div class="w-36">
                <select name="kondisi" class="form-input text-xs py-2">
                    <option value="">Semua Kondisi</option>
                    <option value="baik" <?= $filterKondisi === 'baik' ? 'selected' : '' ?>>Kondisi Baik</option>
                    <option value="perlu_perbaikan" <?= $filterKondisi === 'perlu_perbaikan' ? 'selected' : '' ?>>Perlu Perbaikan</option>
                    <option value="rusak_berat" <?= $filterKondisi === 'rusak_berat' ? 'selected' : '' ?>>Rusak Berat</option>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterKategori) || !empty($filterKondisi)): ?>
                <a href="<?= base_url('modules/sarpras/inventaris.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Total Terdata: <span class="font-bold text-slate-800"><?= count($asetList) ?> Aset</span>
        </div>
    </form>
</div>

<!-- Tabel Daftar Aset -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                    <th class="py-3 px-4">Kode & Nama Aset</th>
                    <th class="py-3 px-4">Kategori Fasilitas</th>
                    <th class="py-3 px-4">Lokasi Penempatan</th>
                    <th class="py-3 px-4 text-center">Jumlah Unit</th>
                    <th class="py-3 px-4 text-right">Nilai Perolehan</th>
                    <th class="py-3 px-4 text-center">Kondisi Fisik</th>
                    <th class="py-3 px-4">Catatan Servis / PJ</th>
                    <th class="py-3 px-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($asetList)): ?>
                    <?= render_empty_state(
                        'fa-boxes-stacked',
                        'Belum Ada Inventaris Terdata',
                        'Catat sarana fisik cabang seperti ruang kelas, PC komputer lab, proyektor, atau perlengkapan praktikum untuk menunjang KBM offline.',
                        '<button type="button" onclick="openModal(\'modalTambahAset\')" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20"><i class="fa-solid fa-plus mr-1"></i> Tambah Aset Pertama</button>',
                        8
                    ) ?>
                <?php else: ?>
                    <?php foreach ($asetList as $a): ?>
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="py-3 px-4">
                                <h4 class="font-bold text-slate-800 hover:text-brand-600 transition-colors"><?= e($a['nama_aset']) ?></h4>
                                <p class="font-mono text-[11px] text-teal-700 font-bold"><?= e($a['kode_aset']) ?></p>
                            </td>
                            <td class="py-3 px-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-slate-100 text-slate-700">
                                    <?= e(str_replace('_', ' ', $a['kategori_aset'])) ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-slate-700 font-medium">
                                <i class="fa-solid fa-location-dot text-slate-400 mr-1 text-[11px]"></i>
                                <?= e($a['lokasi_penempatan']) ?>
                            </td>
                            <td class="py-3 px-4 text-center font-black text-slate-800 text-sm">
                                <?= $a['jumlah_unit'] ?>
                            </td>
                            <td class="py-3 px-4 text-right font-mono font-bold text-slate-800">
                                <?= format_rupiah($a['nilai_perolehan']) ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?= render_status_badge($a['kondisi']) ?>
                            </td>
                            <td class="py-3 px-4">
                                <p class="text-slate-600 truncate max-w-xs text-[11px]"><?= e($a['catatan_maintenance'] ?? 'Kondisi normal.') ?></p>
                                <?php if (!empty($a['nama_penanggung_jawab'])): ?>
                                    <p class="text-[10px] text-slate-400 mt-0.5">PJ: <?= e($a['nama_penanggung_jawab']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <div class="inline-flex items-center gap-1.5">
                                    <!-- Servis Cepat -->
                                    <a href="<?= base_url('modules/sarpras/maintenance.php?id_aset=' . $a['id']) ?>" title="Input Servis / Log" class="w-8 h-8 rounded-lg bg-teal-50 text-teal-600 hover:bg-teal-100 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-screwdriver-wrench text-xs"></i>
                                    </a>

                                    <!-- Edit -->
                                    <button onclick="editAset(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Aset" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 flex items-center justify-center transition-colors">
                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    </button>

                                    <!-- Hapus -->
                                    <form method="POST" action="" onsubmit="return confirm('Hapus aset <?= addslashes($a['nama_aset']) ?>?');" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="hapus_aset">
                                        <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                        <button type="submit" title="Hapus Aset" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 flex items-center justify-center transition-colors">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM TAMBAH ASET BARU -->
<!-- ========================================================================= -->
<div id="modalTambahAset" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Tambah Fasilitas & Aset Baru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Catat sarana pembelajaran fisik ke dalam inventaris cabang.</p>
            </div>
            <button onclick="closeModal('modalTambahAset')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_aset">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kode Registrasi Aset <span class="text-rose-500">*</span></label>
                    <input type="text" name="kode_aset" value="<?= e($suggestedKodeAset) ?>" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Fasilitas / Barang <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_aset" placeholder="Contoh: Lab Komputer Alan Turing" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Fasilitas <span class="text-rose-500">*</span></label>
                    <select name="kategori_aset" required class="form-input text-xs">
                        <option value="ruang_kelas">Ruang Kelas / Laboratorium</option>
                        <option value="perangkat_it">Perangkat IT / PC / TV</option>
                        <option value="alat_peraga">Kit Robotik / Alat Peraga</option>
                        <option value="furnitur">Furnitur / Meja Kursi</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Lokasi Penempatan <span class="text-rose-500">*</span></label>
                    <input type="text" name="lokasi_penempatan" placeholder="Contoh: Gedung A Lantai 1" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jumlah Unit <span class="text-rose-500">*</span></label>
                    <input type="number" name="jumlah_unit" value="1" min="1" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kondisi Awal</label>
                    <select name="kondisi" class="form-input text-xs">
                        <option value="baik">Kondisi Baik</option>
                        <option value="perlu_perbaikan">Perlu Perbaikan</option>
                        <option value="rusak_berat">Rusak Berat</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tanggal Perolehan</label>
                    <input type="date" name="tanggal_perolehan" value="<?= date('Y-m-d') ?>" class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nilai Perolehan / Taksiran (Rp)</label>
                    <input type="number" name="nilai_perolehan" value="0" min="0" step="1000" class="form-input text-xs">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Penanggung Jawab Fasilitas</label>
                <select name="id_penanggung_jawab" class="form-input text-xs">
                    <option value="">-- Pilih Penanggung Jawab --</option>
                    <?php foreach ($pegawaiList as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= e($p['nama_lengkap']) ?> (<?= strtoupper($p['jabatan_role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Pemeliharaan / Spesifikasi Teknis</label>
                <textarea name="catatan_maintenance" rows="2" placeholder="Catatan servis rutin atau kelengkapan perangkat..." class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahAset')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Aset
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM EDIT ASET -->
<!-- ========================================================================= -->
<div id="modalEditAset" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Data Aset & Fasilitas</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="editKodeDisplay">-</p>
            </div>
            <button onclick="closeModal('modalEditAset')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_aset">
            <input type="hidden" name="id" id="edit_aset_id" value="">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Fasilitas / Barang <span class="text-rose-500">*</span></label>
                    <input type="text" name="nama_aset" id="edit_nama_aset" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kategori Fasilitas <span class="text-rose-500">*</span></label>
                    <select name="kategori_aset" id="edit_kategori_aset" required class="form-input text-xs">
                        <option value="ruang_kelas">Ruang Kelas / Laboratorium</option>
                        <option value="perangkat_it">Perangkat IT / PC / TV</option>
                        <option value="alat_peraga">Kit Robotik / Alat Peraga</option>
                        <option value="furnitur">Furnitur / Meja Kursi</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Lokasi Penempatan <span class="text-rose-500">*</span></label>
                    <input type="text" name="lokasi_penempatan" id="edit_lokasi_penempatan" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jumlah Unit <span class="text-rose-500">*</span></label>
                    <input type="number" name="jumlah_unit" id="edit_jumlah_unit" min="1" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kondisi Fisik Saat Ini</label>
                    <select name="kondisi" id="edit_kondisi" class="form-input text-xs">
                        <option value="baik">Kondisi Baik</option>
                        <option value="perlu_perbaikan">Perlu Perbaikan</option>
                        <option value="rusak_berat">Rusak Berat</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nilai Perolehan (Rp)</label>
                    <input type="number" name="nilai_perolehan" id="edit_nilai_perolehan" min="0" step="1000" class="form-input text-xs">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Penanggung Jawab Fasilitas</label>
                <select name="id_penanggung_jawab" id="edit_id_penanggung_jawab" class="form-input text-xs">
                    <option value="">-- Tanpa PJ Khusus --</option>
                    <?php foreach ($pegawaiList as $p): ?>
                        <option value="<?= $p['id'] ?>">
                            <?= e($p['nama_lengkap']) ?> (<?= strtoupper($p['jabatan_role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Pemeliharaan / Servis</label>
                <textarea name="catatan_maintenance" id="edit_catatan_maintenance" rows="2" class="form-input text-xs"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditAset')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-save text-xs mr-1"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editAset(a) {
    document.getElementById('edit_aset_id').value = a.id;
    document.getElementById('editKodeDisplay').textContent = 'Kode Aset: ' + a.kode_aset;
    document.getElementById('edit_nama_aset').value = a.nama_aset;
    document.getElementById('edit_kategori_aset').value = a.kategori_aset;
    document.getElementById('edit_lokasi_penempatan').value = a.lokasi_penempatan;
    document.getElementById('edit_jumlah_unit').value = a.jumlah_unit;
    document.getElementById('edit_kondisi').value = a.kondisi;
    document.getElementById('edit_nilai_perolehan').value = Math.round(a.nilai_perolehan);
    document.getElementById('edit_id_penanggung_jawab').value = a.id_penanggung_jawab || '';
    document.getElementById('edit_catatan_maintenance').value = a.catatan_maintenance || '';
    openModal('modalEditAset');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

