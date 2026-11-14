<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Master Cabang & Tenant (Admin Sistem)
 */

declare(strict_types=1);

$pageTitle = 'Master Cabang & Tenant';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role ADMIN_SISTEM
check_role(ROLE_ADMIN_SISTEM);

$db = get_db();
ensure_database_schema_integrity($db);

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH CABANG BARU
    if ($action === 'tambah_cabang') {
        $kodeCabang  = strtoupper(trim($_POST['kode_cabang'] ?? ''));
        $namaCabang  = trim($_POST['nama_cabang'] ?? '');
        $alamat      = trim($_POST['alamat'] ?? '');
        $kota        = trim($_POST['kota'] ?? '');
        $provinsi    = trim($_POST['provinsi'] ?? 'Sulawesi Selatan');
        $telepon     = trim($_POST['telepon'] ?? '');
        $emailCabang = trim($_POST['email_cabang'] ?? '');
        $latRaw      = trim($_POST['latitude'] ?? '');
        $lngRaw      = trim($_POST['longitude'] ?? '');
        $latitude    = ($latRaw !== '') ? (float)$latRaw : null;
        $longitude   = ($lngRaw !== '') ? (float)$lngRaw : null;
        $idDirektur  = !empty($_POST['id_direktur_cabang']) ? (int)$_POST['id_direktur_cabang'] : null;
        $statusMitra = in_array($_POST['status_mitra'] ?? '', ['aktif', 'pending_review', 'nonaktif'], true) ? $_POST['status_mitra'] : 'aktif';

        if (empty($kodeCabang) || empty($namaCabang) || empty($kota)) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', 'Kode cabang, nama cabang, dan kota wajib diisi.', 'Validasi Gagal');
        }

        // Cek keunikan Kode Cabang
        $stmtCek = $db->prepare("SELECT COUNT(*) FROM tabel_cabang WHERE kode_cabang = ?");
        $stmtCek->execute([$kodeCabang]);
        if ((int)$stmtCek->fetchColumn() > 0) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', "Kode cabang '{$kodeCabang}' sudah digunakan.", 'Kode Duplikat');
        }

        try {
            $stmtIns = $db->prepare("
                INSERT INTO tabel_cabang (
                    kode_cabang, nama_cabang, id_direktur_cabang, alamat,
                    kota, provinsi, telepon, email_cabang, latitude, longitude, status_mitra, tanggal_bergabung, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW()
                )
            ");
            $stmtIns->execute([
                $kodeCabang, $namaCabang, $idDirektur, $alamat,
                $kota, $provinsi, $telepon, $emailCabang, $latitude, $longitude, $statusMitra
            ]);
            $newCabangId = (int)$db->lastInsertId();

            // Jika ada direktur yang ditunjuk, sinkronkan id_cabang di users
            if ($idDirektur !== null) {
                $stmtUpUser = $db->prepare("UPDATE users SET id_cabang = ? WHERE id = ?");
                $stmtUpUser->execute([$newCabangId, $idDirektur]);
            }

            log_activity('TAMBAH_CABANG', "Mendaftarkan cabang baru: {$namaCabang} ({$kodeCabang}) ID: {$newCabangId}");

            redirect_with_message('modules/admin_sistem/cabang.php', 'success', "Cabang '{$namaCabang}' ({$kodeCabang}) berhasil ditambahkan.", 'Cabang Dibuat');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', 'Gagal menambah cabang: ' . $e->getMessage());
        }
    }

    // 2. EDIT CABANG
    if ($action === 'edit_cabang') {
        $id          = (int)($_POST['id'] ?? 0);
        $kodeCabang  = strtoupper(trim($_POST['kode_cabang'] ?? ''));
        $namaCabang  = trim($_POST['nama_cabang'] ?? '');
        $alamat      = trim($_POST['alamat'] ?? '');
        $kota        = trim($_POST['kota'] ?? '');
        $provinsi    = trim($_POST['provinsi'] ?? '');
        $telepon     = trim($_POST['telepon'] ?? '');
        $emailCabang = trim($_POST['email_cabang'] ?? '');
        $latRaw      = trim($_POST['latitude'] ?? '');
        $lngRaw      = trim($_POST['longitude'] ?? '');
        $latitude    = ($latRaw !== '') ? (float)$latRaw : null;
        $longitude   = ($lngRaw !== '') ? (float)$lngRaw : null;
        $idDirektur  = !empty($_POST['id_direktur_cabang']) ? (int)$_POST['id_direktur_cabang'] : null;
        $statusMitra = in_array($_POST['status_mitra'] ?? '', ['aktif', 'pending_review', 'nonaktif', 'ditolak', 'menunggu_aktivasi_admin'], true) ? $_POST['status_mitra'] : 'aktif';

        if ($id <= 0 || empty($namaCabang) || empty($kodeCabang)) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', 'Kode Cabang dan Nama Cabang wajib diisi.');
        }

        // Validasi keunikan kode cabang (mencegah bentrok dengan cabang lain)
        $stmtCekKode = $db->prepare("SELECT COUNT(*) FROM tabel_cabang WHERE kode_cabang = ? AND id != ?");
        $stmtCekKode->execute([$kodeCabang, $id]);
        if ((int)$stmtCekKode->fetchColumn() > 0) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', "Kode Cabang '{$kodeCabang}' sudah digunakan oleh cabang lain. Harap gunakan kode unik.");
        }

        try {
            $stmtUp = $db->prepare("
                UPDATE tabel_cabang SET
                    kode_cabang = ?, nama_cabang = ?, id_direktur_cabang = ?, alamat = ?,
                    kota = ?, provinsi = ?, telepon = ?, email_cabang = ?, latitude = ?, longitude = ?, status_mitra = ?
                WHERE id = ?
            ");
            $stmtUp->execute([
                $kodeCabang, $namaCabang, $idDirektur, $alamat,
                $kota, $provinsi, $telepon, $emailCabang, $latitude, $longitude, $statusMitra, $id
            ]);

            // Sinkronkan id_cabang direktur jika ditunjuk
            if ($idDirektur !== null) {
                $stmtUpUser = $db->prepare("UPDATE users SET id_cabang = ? WHERE id = ?");
                $stmtUpUser->execute([$id, $idDirektur]);
            }

            log_activity('EDIT_CABANG', "Memperbarui data cabang ID {$id} ({$namaCabang} - Kode: {$kodeCabang})");

            redirect_with_message('modules/admin_sistem/cabang.php', 'success', "Data cabang '{$namaCabang}' ({$kodeCabang}) berhasil diperbarui.", 'Update Berhasil');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/cabang.php', 'error', 'Gagal update cabang: ' . $e->getMessage());
        }
    }
}

// QUERY DATA CABANG
$filterStatus = trim($_GET['status'] ?? '');
$search       = trim($_GET['q'] ?? '');

$sql = "
    SELECT c.*, 
           u.username as direktur_username, u.email as direktur_email,
           p.nama_lengkap as nama_direktur, p.nip_pegawai as nip_direktur,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_cabang = c.id AND s.is_archived = 0) as total_siswa,
           (SELECT COUNT(*) FROM tabel_pegawai pg WHERE pg.id_cabang = c.id AND pg.status_aktif = 'aktif') as total_pegawai
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($filterStatus)) {
    $sql .= " AND c.status_mitra = ?";
    $params[] = $filterStatus;
}
if (!empty($search)) {
    $sql .= " AND (c.nama_cabang LIKE ? OR c.kode_cabang LIKE ? OR c.kota LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY c.id ASC";
$stmtCabang = $db->prepare($sql);
$stmtCabang->execute($params);
$cabangList = $stmtCabang->fetchAll();

// Daftar User Bertipe direktur_cabang untuk Form Pilihan
$direkturChoices = $db->query("
    SELECT u.id, u.username, u.email, p.nama_lengkap
    FROM users u
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    WHERE u.role = 'direktur_cabang' AND u.is_active = 1
    ORDER BY u.username ASC
")->fetchAll();

// EKSPOR EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $columns = [
        'no'            => ['label' => 'No', 'type' => 'center', 'width' => 45],
        'kode_cabang'   => ['label' => 'Kode Cabang', 'type' => 'text', 'width' => 120],
        'nama_cabang'   => ['label' => 'Nama Cabang', 'type' => 'text', 'width' => 200],
        'kota'          => ['label' => 'Kota', 'type' => 'text', 'width' => 130],
        'provinsi'      => ['label' => 'Provinsi', 'type' => 'text', 'width' => 140],
        'telepon'       => ['label' => 'Kontak Telp', 'type' => 'text', 'width' => 130],
        'direktur'      => ['label' => 'Direktur Cabang', 'type' => 'text', 'width' => 180],
        'status'        => ['label' => 'Status Mitra', 'type' => 'badge', 'width' => 120],
        'total_siswa'   => ['label' => 'Total Siswa', 'type' => 'number', 'width' => 90],
        'total_pegawai' => ['label' => 'Total SDM', 'type' => 'number', 'width' => 90]
    ];

    $rows = [];
    $no = 1;
    foreach ($cabangList as $c) {
        $rows[] = [
            'no'            => $no++,
            'kode_cabang'   => $c['kode_cabang'],
            'nama_cabang'   => $c['nama_cabang'],
            'kota'          => $c['kota'],
            'provinsi'      => $c['provinsi'],
            'telepon'       => $c['telepon'] ?: '-',
            'direktur'      => $c['nama_direktur'] ?: ($c['direktur_username'] ?: 'Belum ditunjuk'),
            'status'        => $c['status_mitra'],
            'total_siswa'   => (int)$c['total_siswa'],
            'total_pegawai' => (int)$c['total_pegawai']
        ];
    }

    $metadata = [
        'Cakupan'       => 'Master Cabang SIM Bisolpin',
        'Total Cabang'  => count($rows) . ' Cabang',
        'Waktu Ekspor'  => date('d-m-Y H:i') . ' WITA'
    ];

    export_to_excel('master_cabang_bisolpin_' . date('Ymd_His'), 'MASTER DATA CABANG & TENANT KEMITRAAN BISOLPIN ACADEMY', $columns, $rows, $metadata);
}

// Rekomendasi Kode Cabang Baru
$countCbg = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang")->fetchColumn() + 1;
$suggestedKode = sprintf("CBG-%02d", $countCbg);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-network-wired"></i>
            <span>Multi-Tenant Branch Management</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Master Cabang & Tenant Kemitraan
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Konfigurasi data cabang operasional, penugasan akun Direktur Cabang, dan pemantauan rasio siswa serta SDM lokal.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="?status=<?= urlencode($filterStatus) ?>&q=<?= urlencode($search) ?>&export=excel" class="px-3.5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm shadow-emerald-600/20 flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-file-excel text-xs"></i>
            <span>Ekspor Excel (.xls)</span>
        </a>
        <button type="button" onclick="openModalTambahCabang()" class="btn-brand text-xs py-2.5 px-4 shadow-sm shadow-brand-500/20 flex items-center gap-1.5 font-bold">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Tambah Cabang Baru</span>
        </button>
    </div>
</div>

<!-- Tabel Cabang -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-600">
                    <th class="py-3 px-4 font-bold">Kode & Nama Cabang</th>
                    <th class="py-3 px-4 font-bold">Wilayah & Alamat</th>
                    <th class="py-3 px-4 font-bold">Kontak & Email</th>
                    <th class="py-3 px-4 font-bold">Direktur Cabang</th>
                    <th class="py-3 px-4 font-bold text-center">Rasio Siswa / SDM</th>
                    <th class="py-3 px-4 font-bold text-center">Status Mitra</th>
                    <th class="py-3 px-4 font-bold text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($cabangList as $c): ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="py-3 px-4">
                        <span class="font-bold text-slate-800 text-sm block"><?= e($c['nama_cabang']) ?></span>
                        <span class="inline-block px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-100 text-slate-700 border border-slate-200 mt-1">
                            <?= e($c['kode_cabang']) ?>
                        </span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="font-semibold text-slate-700 block"><?= e($c['kota']) ?>, <?= e($c['provinsi']) ?></span>
                        <span class="text-[11px] text-slate-400 block truncate max-w-xs"><?= e($c['alamat']) ?></span>
                    </td>
                    <td class="py-3 px-4">
                        <span class="font-medium text-slate-700 block"><?= e($c['telepon'] ?: '-') ?></span>
                        <span class="text-[11px] text-slate-400 block"><?= e($c['email_cabang'] ?: '-') ?></span>
                    </td>
                    <td class="py-3 px-4">
                        <?php if (!empty($c['nama_direktur']) || !empty($c['direktur_username'])): ?>
                            <span class="font-bold text-teal-800 block"><?= e($c['nama_direktur'] ?: $c['direktur_username']) ?></span>
                            <span class="text-[10px] text-slate-400 font-mono"><?= e($c['direktur_email'] ?: '-') ?></span>
                        <?php else: ?>
                            <span class="text-slate-400 italic">Belum ditugaskan</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <span class="font-bold text-brand-700"><?= (int)$c['total_siswa'] ?> Siswa</span>
                        <span class="text-[11px] text-slate-400 block">• <?= (int)$c['total_pegawai'] ?> SDM</span>
                    </td>
                    <td class="py-3 px-4 text-center">
                        <?php if ($c['status_mitra'] === 'aktif'): ?>
                            <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                            </span>
                        <?php elseif ($c['status_mitra'] === 'pending_review'): ?>
                            <span class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full border border-amber-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Pending Review
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-[10px] font-bold text-rose-700 bg-rose-50 px-2.5 py-1 rounded-full border border-rose-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Nonaktif
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4 text-right">
                        <button type="button" onclick='openModalEditCabang(<?= json_encode($c) ?>)' class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs inline-flex items-center gap-1 transition-colors">
                            <i class="fa-solid fa-pen text-[10px]"></i> Edit
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH CABANG BARU -->
<!-- ========================================================================= -->
<div id="modalTambahCabang" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <h3 class="text-base font-bold text-slate-800">Tambah Cabang Kemitraan Baru</h3>
            <button onclick="closeModal('modalTambahCabang')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_cabang">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kode Cabang <span class="text-rose-500">*</span></label>
                    <input type="text" name="kode_cabang" required value="<?= e($suggestedKode) ?>" class="form-input text-xs py-2 w-full font-mono uppercase">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Operasional</label>
                    <select name="status_mitra" class="form-input text-xs py-2 w-full">
                        <option value="aktif">Aktif</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Nama Cabang <span class="text-rose-500">*</span></label>
                <input type="text" name="nama_cabang" required placeholder="misal: Bisolpin Cabang Daya Makassar" class="form-input text-xs py-2 w-full">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kota / Kabupaten <span class="text-rose-500">*</span></label>
                    <input type="text" name="kota" required placeholder="misal: Kota Makassar" class="form-input text-xs py-2 w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Provinsi <span class="text-rose-500">*</span></label>
                    <input type="text" name="provinsi" required value="Sulawesi Selatan" class="form-input text-xs py-2 w-full">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Lengkap</label>
                <textarea name="alamat" rows="2" placeholder="Jl. Contoh No. 123..." class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Telepon Cabang</label>
                    <input type="text" name="telepon" placeholder="0411-xxxxxx / 0812..." class="form-input text-xs py-2 w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email Cabang</label>
                    <input type="email" name="email_cabang" placeholder="cabang@bisolpin.com" class="form-input text-xs py-2 w-full">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Latitude GPS <span class="text-[10px] text-slate-400 font-normal">(Peta)</span></label>
                    <input type="text" name="latitude" placeholder="-5.147665" class="form-input text-xs py-2 w-full font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Longitude GPS <span class="text-[10px] text-slate-400 font-normal">(Peta)</span></label>
                    <input type="text" name="longitude" placeholder="119.432732" class="form-input text-xs py-2 w-full font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Direktur Cabang (Akun Pengguna)</label>
                <select name="id_direktur_cabang" class="form-input text-xs py-2 w-full">
                    <option value="">-- Belum Ditunjuk --</option>
                    <?php foreach ($direkturChoices as $dc): ?>
                        <option value="<?= $dc['id'] ?>"><?= e($dc['username']) ?> - <?= e($dc['nama_lengkap'] ?: $dc['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalTambahCabang')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Cabang</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT CABANG -->
<!-- ========================================================================= -->
<div id="modalEditCabang" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-base font-bold text-slate-800">Edit Data Cabang</h3>
                <p class="text-xs text-slate-400" id="edit-cabang-title">-</p>
            </div>
            <button onclick="closeModal('modalEditCabang')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_cabang">
            <input type="hidden" id="edit-cbg-id" name="id" value="">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kode Cabang <span class="text-rose-500">*</span></label>
                    <input type="text" id="edit-cbg-kode" name="kode_cabang" required class="form-input text-xs py-2 w-full font-mono font-bold uppercase focus:border-brand-500" placeholder="Contoh: CBG-MKS-01">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Operasional</label>
                    <select id="edit-cbg-status" name="status_mitra" class="form-input text-xs py-2 w-full">
                        <option value="aktif">Aktif</option>
                        <option value="menunggu_aktivasi_admin">Menunggu Aktivasi Admin</option>
                        <option value="pending_review">Pending Review</option>
                        <option value="nonaktif">Nonaktif</option>
                        <option value="ditolak">Ditolak</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Nama Cabang</label>
                <input type="text" id="edit-cbg-nama" name="nama_cabang" required class="form-input text-xs py-2 w-full">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kota / Kabupaten</label>
                    <input type="text" id="edit-cbg-kota" name="kota" required class="form-input text-xs py-2 w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Provinsi</label>
                    <input type="text" id="edit-cbg-provinsi" name="provinsi" required class="form-input text-xs py-2 w-full">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Alamat Lengkap</label>
                <textarea id="edit-cbg-alamat" name="alamat" rows="2" class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Telepon</label>
                    <input type="text" id="edit-cbg-telepon" name="telepon" class="form-input text-xs py-2 w-full">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Email</label>
                    <input type="email" id="edit-cbg-email" name="email_cabang" class="form-input text-xs py-2 w-full">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Latitude GPS <span class="text-[10px] text-slate-400 font-normal">(Peta)</span></label>
                    <input type="text" id="edit-cbg-latitude" name="latitude" placeholder="-5.147665" class="form-input text-xs py-2 w-full font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Longitude GPS <span class="text-[10px] text-slate-400 font-normal">(Peta)</span></label>
                    <input type="text" id="edit-cbg-longitude" name="longitude" placeholder="119.432732" class="form-input text-xs py-2 w-full font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Direktur Cabang (Akun Pengguna)</label>
                <select id="edit-cbg-direktur" name="id_direktur_cabang" class="form-input text-xs py-2 w-full">
                    <option value="">-- Belum Ditunjuk --</option>
                    <?php foreach ($direkturChoices as $dc): ?>
                        <option value="<?= $dc['id'] ?>"><?= e($dc['username']) ?> - <?= e($dc['nama_lengkap'] ?: $dc['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalEditCabang')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalTambahCabang() {
    document.getElementById('modalTambahCabang').classList.remove('hidden');
}

function openModalEditCabang(cbg) {
    document.getElementById('edit-cbg-id').value = cbg.id;
    document.getElementById('edit-cbg-kode').value = cbg.kode_cabang;
    document.getElementById('edit-cbg-nama').value = cbg.nama_cabang;
    document.getElementById('edit-cbg-kota').value = cbg.kota;
    document.getElementById('edit-cbg-provinsi').value = cbg.provinsi;
    document.getElementById('edit-cbg-alamat').value = cbg.alamat;
    document.getElementById('edit-cbg-telepon').value = cbg.telepon || '';
    document.getElementById('edit-cbg-email').value = cbg.email_cabang || '';
    document.getElementById('edit-cbg-latitude').value = cbg.latitude || '';
    document.getElementById('edit-cbg-longitude').value = cbg.longitude || '';
    document.getElementById('edit-cbg-direktur').value = cbg.id_direktur_cabang || '';
    document.getElementById('edit-cbg-status').value = cbg.status_mitra;
    document.getElementById('edit-cabang-title').innerText = cbg.nama_cabang + ' (' + cbg.kode_cabang + ')';
    document.getElementById('modalEditCabang').classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';

