<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Pengumuman & Konfigurasi Sistem (Admin Sistem)
 * Sistem CRUD Pengumuman Multi-Target (Role & Cabang) & Diagnostik Server
 */

declare(strict_types=1);

$pageTitle = 'Manajemen Pengumuman & Konfigurasi Sistem';

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

// Definisi opsi role target
$roleOptions = [
    'semua'           => 'Semua Pengguna (Global)',
    'siswa'           => 'Khusus Siswa / Pelajar',
    'mentor'          => 'Khusus Mentor / Pengajar',
    'staff'           => 'Khusus Staff Administrasi',
    'direktur_cabang' => 'Khusus Direktur Cabang',
    'pimpinan'        => 'Khusus Pimpinan Pusat',
    'bak'             => 'Khusus Biro Adm. Keuangan (BAK)',
    'sarpras'         => 'Khusus Sarana & Prasarana',
    'admin_sistem'    => 'Khusus Tim IT / Admin Sistem',
];

// Definisi visual tipe pengumuman
$typeConfig = [
    'info' => [
        'label' => 'Info Biru',
        'sub'   => 'Informasi umum / pengumuman rutin',
        'badge' => 'bg-cyan-50 text-cyan-800 border-cyan-200',
        'icon'  => 'fa-circle-info text-cyan-600',
        'dot'   => 'bg-cyan-500',
    ],
    'warning' => [
        'label' => 'Peringatan Kuning',
        'sub'   => 'Jadwal libur / pemeliharaan sistem',
        'badge' => 'bg-amber-50 text-amber-800 border-amber-200',
        'icon'  => 'fa-triangle-exclamation text-amber-600',
        'dot'   => 'bg-amber-500',
    ],
    'danger' => [
        'label' => 'Penting Merah',
        'sub'   => 'Darurat / batas akhir / instruksi khusus',
        'badge' => 'bg-rose-50 text-rose-800 border-rose-200',
        'icon'  => 'fa-circle-exclamation text-rose-600',
        'dot'   => 'bg-rose-500',
    ],
    'success' => [
        'label' => 'Sukses Hijau',
        'sub'   => 'Pencapaian / apresiasi / kabar gembira',
        'badge' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
        'icon'  => 'fa-circle-check text-emerald-600',
        'dot'   => 'bg-emerald-500',
    ],
];

// =============================================================================
// PROSES AKSI FORM POST (CRUD PENGUMUMAN)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH PENGUMUMAN BARU (CREATE)
    if ($action === 'tambah_pengumuman') {
        $judul       = trim($_POST['judul'] ?? '');
        $pesan       = trim($_POST['pesan'] ?? '');
        $tipe        = trim($_POST['tipe'] ?? 'info');
        $targetRole  = trim($_POST['target_role'] ?? 'semua');
        $idCabangRaw = trim($_POST['id_cabang'] ?? '');
        $idCabang    = ($idCabangRaw === '' || $idCabangRaw === '0') ? null : (int)$idCabangRaw;
        $isAktif     = isset($_POST['is_aktif']) ? 1 : 0;
        $currUser    = auth_user();
        $createdBy   = $currUser['id'] ?? null;

        if ($judul === '' || $pesan === '') {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Judul dan isi pesan pengumuman wajib diisi.');
        }

        if (!array_key_exists($tipe, $typeConfig)) {
            $tipe = 'info';
        }

        if (!array_key_exists($targetRole, $roleOptions)) {
            $targetRole = 'semua';
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO tabel_pengumuman (judul, pesan, tipe, target_role, id_cabang, is_aktif, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$judul, $pesan, $tipe, $targetRole, $idCabang, $isAktif, $createdBy]);

            // Selaraskan juga ke tabel_pengaturan_sistem sebagai fallback jika status aktif
            if ($isAktif === 1) {
                $db->prepare("
                    INSERT INTO tabel_pengaturan_sistem (kunci, nilai, updated_at)
                    VALUES 
                        ('pengumuman_judul', ?, NOW()),
                        ('pengumuman_pesan', ?, NOW()),
                        ('pengumuman_tipe', ?, NOW()),
                        ('pengumuman_aktif', '1', NOW())
                    ON DUPLICATE KEY UPDATE nilai = VALUES(nilai), updated_at = NOW()
                ")->execute([$judul, $pesan, $tipe]);
            }

            log_activity('TAMBAH_PENGUMUMAN', "Membuat pengumuman baru: \"{$judul}\" (Tipe: {$tipe}, Target: {$targetRole})");

            // Siarkan notifikasi ke pengguna yang ditargetkan
            if ($isAktif === 1) {
                push_notification("Pengumuman Baru: {$judul}", substr($pesan, 0, 140), '#', null, $targetRole, $idCabang, $tipe);
            }

            redirect_with_message('modules/admin_sistem/pengaturan.php', 'success', 'Pengumuman baru berhasil diterbitkan.', 'Berhasil Dibuat');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Gagal menambahkan pengumuman: ' . $e->getMessage());
        }
    }

    // 2. EDIT PENGUMUMAN (UPDATE)
    if ($action === 'edit_pengumuman') {
        $id          = (int)($_POST['id'] ?? 0);
        $judul       = trim($_POST['judul'] ?? '');
        $pesan       = trim($_POST['pesan'] ?? '');
        $tipe        = trim($_POST['tipe'] ?? 'info');
        $targetRole  = trim($_POST['target_role'] ?? 'semua');
        $idCabangRaw = trim($_POST['id_cabang'] ?? '');
        $idCabang    = ($idCabangRaw === '' || $idCabangRaw === '0') ? null : (int)$idCabangRaw;
        $isAktif     = isset($_POST['is_aktif']) ? 1 : 0;

        if ($id <= 0 || $judul === '' || $pesan === '') {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Data pengumuman tidak lengkap.');
        }

        if (!array_key_exists($tipe, $typeConfig)) {
            $tipe = 'info';
        }

        if (!array_key_exists($targetRole, $roleOptions)) {
            $targetRole = 'semua';
        }

        try {
            $stmt = $db->prepare("
                UPDATE tabel_pengumuman
                SET judul = ?, pesan = ?, tipe = ?, target_role = ?, id_cabang = ?, is_aktif = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$judul, $pesan, $tipe, $targetRole, $idCabang, $isAktif, $id]);

            log_activity('EDIT_PENGUMUMAN', "Memperbarui pengumuman ID #{$id}: \"{$judul}\"");

            redirect_with_message('modules/admin_sistem/pengaturan.php', 'success', 'Pengumuman berhasil diperbarui.', 'Berhasil Diperbarui');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Gagal memperbarui pengumuman: ' . $e->getMessage());
        }
    }

    // 3. TOGGLE AKTIF / NONAKTIF STATUS (UPDATE FAST)
    if ($action === 'toggle_pengumuman') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'ID pengumuman tidak valid.');
        }

        try {
            $stmt = $db->prepare("
                UPDATE tabel_pengumuman 
                SET is_aktif = IF(is_aktif = 1, 0, 1), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            $stmtCek = $db->prepare("SELECT is_aktif, judul FROM tabel_pengumuman WHERE id = ?");
            $stmtCek->execute([$id]);
            $row = $stmtCek->fetch(PDO::FETCH_ASSOC);

            $statusStr = ($row && $row['is_aktif'] == 1) ? 'diaktifkan' : 'dinonaktifkan';
            log_activity('TOGGLE_PENGUMUMAN', "Status pengumuman #{$id} diubah menjadi {$statusStr}");

            redirect_with_message('modules/admin_sistem/pengaturan.php', 'success', "Status pengumuman berhasil {$statusStr}.", 'Status Berubah');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Gagal mengubah status pengumuman: ' . $e->getMessage());
        }
    }

    // 4. HAPUS PENGUMUMAN (DELETE)
    if ($action === 'hapus_pengumuman') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'ID pengumuman tidak valid.');
        }

        try {
            $stmtJudul = $db->prepare("SELECT judul FROM tabel_pengumuman WHERE id = ?");
            $stmtJudul->execute([$id]);
            $judul = $stmtJudul->fetchColumn() ?: "ID #{$id}";

            $stmt = $db->prepare("DELETE FROM tabel_pengumuman WHERE id = ?");
            $stmt->execute([$id]);

            log_activity('HAPUS_PENGUMUMAN', "Menghapus pengumuman ID #{$id}: \"{$judul}\"");

            redirect_with_message('modules/admin_sistem/pengaturan.php', 'success', "Pengumuman \"{$judul}\" telah dihapus.", 'Dihapus');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/pengaturan.php', 'error', 'Gagal menghapus pengumuman: ' . $e->getMessage());
        }
    }
}

// =============================================================================
// AMBIL DATA PENGUMUMAN & CABANG
// =============================================================================
$stmtPeng = $db->query("
    SELECT p.*, c.nama_cabang, c.kode_cabang, u.username as pembuat_username
    FROM tabel_pengumuman p
    LEFT JOIN tabel_cabang c ON p.id_cabang = c.id
    LEFT JOIN users u ON p.created_by = u.id
    ORDER BY p.is_aktif DESC, p.id DESC
");
$daftarPengumuman = $stmtPeng->fetchAll(PDO::FETCH_ASSOC);

// Data Cabang untuk Select Dropdown
$stmtCabang = $db->query("SELECT id, kode_cabang, nama_cabang FROM tabel_cabang ORDER BY nama_cabang ASC");
$daftarCabang = $stmtCabang->fetchAll(PDO::FETCH_ASSOC);

// Metrik Ringkasan
$totalPengumuman = count($daftarPengumuman);
$totalAktif      = count(array_filter($daftarPengumuman, fn($p) => (int)$p['is_aktif'] === 1));
$totalNonaktif   = $totalPengumuman - $totalAktif;
$totalKhususCbg  = count(array_filter($daftarPengumuman, fn($p) => !empty($p['id_cabang'])));

// DIAGNOSTIK SERVER & PHP EXTENSIONS
$extensions = [
    'pdo'         => extension_loaded('pdo'),
    'pdo_mysql'   => extension_loaded('pdo_mysql'),
    'mbstring'    => extension_loaded('mbstring'),
    'curl'        => extension_loaded('curl'),
    'gd'          => extension_loaded('gd'),
    'json'        => extension_loaded('json'),
    'openssl'     => extension_loaded('openssl'),
    'session'     => extension_loaded('session'),
];

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-bullhorn"></i>
            <span>Pusat Siaran & Preferensi Sistem</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Kelola Pengumuman & Konfigurasi Sistem
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Buat, sunting, filter target audiens, dan pantau seluruh siaran banner pengumuman aktif di seluruh dashboard pengguna.
        </p>
    </div>
    <div class="flex items-center gap-2.5">
        <button type="button" onclick="openModalTambahPengumuman()" class="btn-brand text-xs py-2.5 px-4 shadow-sm shadow-brand-500/20 flex items-center gap-2 font-bold">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Tambah Pengumuman Baru</span>
        </button>
    </div>
</div>

<!-- Kartu Ringkasan Metrik Pengumuman -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="card p-4 flex items-center gap-3.5 border-l-4 border-l-slate-700">
        <div class="w-11 h-11 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-lg">
            <i class="fa-solid fa-layer-group"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Pengumuman</div>
            <div class="text-xl font-black text-slate-800"><?= $totalPengumuman ?></div>
        </div>
    </div>

    <div class="card p-4 flex items-center gap-3.5 border-l-4 border-l-emerald-500">
        <div class="w-11 h-11 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center text-lg">
            <i class="fa-solid fa-tower-broadcast"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold text-emerald-600 uppercase tracking-wider">Sedang Mengudara</div>
            <div class="text-xl font-black text-emerald-700"><?= $totalAktif ?> <span class="text-xs font-medium text-slate-400">Aktif</span></div>
        </div>
    </div>

    <div class="card p-4 flex items-center gap-3.5 border-l-4 border-l-purple-500">
        <div class="w-11 h-11 rounded-xl bg-purple-100 text-purple-700 flex items-center justify-center text-lg">
            <i class="fa-solid fa-building"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold text-purple-600 uppercase tracking-wider">Target Cabang</div>
            <div class="text-xl font-black text-purple-700"><?= $totalKhususCbg ?> <span class="text-xs font-medium text-slate-400">Khusus</span></div>
        </div>
    </div>

    <div class="card p-4 flex items-center gap-3.5 border-l-4 border-l-slate-300">
        <div class="w-11 h-11 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center text-lg">
            <i class="fa-solid fa-box-archive"></i>
        </div>
        <div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Arsip / Draf</div>
            <div class="text-xl font-black text-slate-600"><?= $totalNonaktif ?> <span class="text-xs font-medium text-slate-400">Nonaktif</span></div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- TABEL DAFTAR PENGUMUMAN SISTEM (CRUD) -->
<!-- ========================================================================= -->
<div class="card overflow-hidden mb-8">
    <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-bullhorn text-teal-600"></i>
                <span>Daftar Siaran Pengumuman Terkelola</span>
            </h3>
            <p class="text-xs text-slate-400 mt-0.5">Semua banner yang disiarkan ke dashboard siswa, mentor, pimpinan, maupun staf cabang.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-slate-500">
                Menampilkan <strong><?= count($daftarPengumuman) ?></strong> data
            </span>
        </div>
    </div>

    <?php if (empty($daftarPengumuman)): ?>
    <div class="p-12 text-center">
        <div class="w-16 h-16 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center mx-auto mb-4 text-2xl">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <h4 class="text-base font-bold text-slate-800">Belum Ada Pengumuman</h4>
        <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1 mb-5">
            Buat pengumuman pertama Anda untuk memberikan informasi penting kepada siswa, mentor, atau cabang tertentu.
        </p>
        <button type="button" onclick="openModalTambahPengumuman()" class="btn-brand text-xs py-2.5 px-4 font-bold inline-flex items-center gap-2">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>Buat Pengumuman Baru</span>
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50/75 border-b border-slate-100 text-[11px] font-black uppercase text-slate-400 tracking-wider">
                    <th class="py-3.5 px-4">Pengumuman & Isi Pesan</th>
                    <th class="py-3.5 px-4">Tipe Banner</th>
                    <th class="py-3.5 px-4">Target Audiens & Cabang</th>
                    <th class="py-3.5 px-4 text-center">Status Siar</th>
                    <th class="py-3.5 px-4">Terbit / Pembuat</th>
                    <th class="py-3.5 px-4 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-xs">
                <?php foreach ($daftarPengumuman as $p): 
                    $tConf = $typeConfig[$p['tipe']] ?? $typeConfig['info'];
                    $isAktif = (int)$p['is_aktif'] === 1;
                    $roleLabel = $roleOptions[$p['target_role']] ?? ucfirst(str_replace('_', ' ', $p['target_role']));
                    $cabangLabel = !empty($p['nama_cabang']) ? $p['nama_cabang'] . ' (' . $p['kode_cabang'] . ')' : 'Seluruh Cabang (Nasional)';
                ?>
                <tr class="hover:bg-slate-50/60 transition-colors <?= $isAktif ? 'bg-white' : 'bg-slate-50/30 opacity-75' ?>">
                    <!-- Pengumuman & Pesan -->
                    <td class="py-4 px-4 max-w-md">
                        <div class="flex items-start gap-2.5">
                            <span class="w-2.5 h-2.5 rounded-full <?= $tConf['dot'] ?> shrink-0 mt-1.5" title="Tipe: <?= e($tConf['label']) ?>"></span>
                            <div class="space-y-1">
                                <h4 class="font-extrabold text-slate-800 text-sm leading-snug">
                                    <?= e($p['judul']) ?>
                                </h4>
                                <p class="text-slate-600 text-xs line-clamp-2 leading-relaxed font-normal">
                                    <?= e($p['pesan']) ?>
                                </p>
                            </div>
                        </div>
                    </td>

                    <!-- Tipe Banner -->
                    <td class="py-4 px-4 whitespace-nowrap">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-[11px] font-bold <?= $tConf['badge'] ?>">
                            <i class="fa-solid <?= $tConf['icon'] ?>"></i>
                            <span><?= e($tConf['label']) ?></span>
                        </span>
                    </td>

                    <!-- Target Audiens & Cabang -->
                    <td class="py-4 px-4">
                        <div class="space-y-1">
                            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[10px] font-bold">
                                <i class="fa-solid fa-users text-[9px] text-slate-400"></i>
                                <span><?= e($roleLabel) ?></span>
                            </div>
                            <div>
                                <?php if (!empty($p['id_cabang'])): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] text-purple-700 font-bold">
                                        <i class="fa-solid fa-building text-[10px]"></i>
                                        <span><?= e($cabangLabel) ?></span>
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] text-slate-500 font-semibold">
                                        <i class="fa-solid fa-globe text-[10px] text-slate-400"></i>
                                        <span>Nasional (Semua Cabang)</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Status Siar (Toggle 1-Click) -->
                    <td class="py-4 px-4 text-center whitespace-nowrap">
                        <form method="POST" action="" class="inline-block">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_pengumuman">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" title="Klik untuk mengubah status aktif" class="group inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-extrabold transition-all border <?= $isAktif ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200 hover:bg-slate-200' ?>">
                                <i class="fa-solid <?= $isAktif ? 'fa-circle-check text-emerald-600' : 'fa-circle-pause text-slate-400' ?>"></i>
                                <span><?= $isAktif ? 'Aktif Mengudara' : 'Nonaktif' ?></span>
                            </button>
                        </form>
                    </td>

                    <!-- Terbit / Pembuat -->
                    <td class="py-4 px-4 whitespace-nowrap text-slate-500 text-[11px]">
                        <div class="font-bold text-slate-700"><?= date('d M Y, H:i', strtotime($p['created_at'])) ?></div>
                        <div class="text-[10px] text-slate-400">Oleh: <?= e($p['pembuat_username'] ?? 'Admin Sistem') ?></div>
                    </td>

                    <!-- Aksi (Edit & Hapus) -->
                    <td class="py-4 px-4 text-right whitespace-nowrap">
                        <div class="inline-flex items-center gap-1">
                            <button type="button" onclick='openModalEditPengumuman(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs inline-flex items-center gap-1.5 transition-colors" title="Edit Pengumuman">
                                <i class="fa-solid fa-pen text-[10px]"></i>
                                <span>Edit</span>
                            </button>
                            <button type="button" onclick="openModalHapusPengumuman(<?= (int)$p['id'] ?>, '<?= e(addslashes($p['judul'])) ?>')" class="px-2 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs inline-flex items-center gap-1 transition-colors" title="Hapus Pengumuman">
                                <i class="fa-solid fa-trash-can text-[10px]"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- DIAGNOSTIK SERVER & PHP EXTENSIONS (INFORMASI SISTEM) -->
<!-- ========================================================================= -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
    <!-- Diagnostik PHP Server -->
    <div class="lg:col-span-6 card p-6">
        <div class="flex items-center gap-2.5 mb-5 pb-4 border-b border-slate-100">
            <div class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-800 flex items-center justify-center text-sm">
                <i class="fa-solid fa-server"></i>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-800">Diagnostik Lingkungan Server</h3>
                <p class="text-xs text-slate-400">Spesifikasi runtime PHP & batasan eksekusi host</p>
            </div>
        </div>

        <div class="space-y-3 text-xs">
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Versi PHP</span>
                <span class="font-bold font-mono text-slate-800"><?= PHP_VERSION ?></span>
            </div>
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Memory Limit</span>
                <span class="font-bold font-mono text-slate-800"><?= ini_get('memory_limit') ?></span>
            </div>
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Max Execution Time</span>
                <span class="font-bold font-mono text-slate-800"><?= ini_get('max_execution_time') ?> Detik</span>
            </div>
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Upload Max Filesize</span>
                <span class="font-bold font-mono text-slate-800"><?= ini_get('upload_max_filesize') ?></span>
            </div>
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Post Max Size</span>
                <span class="font-bold font-mono text-slate-800"><?= ini_get('post_max_size') ?></span>
            </div>
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Default Timezone</span>
                <span class="font-bold font-mono text-teal-800"><?= date_default_timezone_get() ?></span>
            </div>
        </div>
    </div>

    <!-- Ekstensi PHP Terinstal -->
    <div class="lg:col-span-6 card p-6">
        <div class="flex items-center gap-2.5 mb-5 pb-4 border-b border-slate-100">
            <div class="w-8 h-8 rounded-lg bg-teal-100 text-teal-800 flex items-center justify-center text-sm">
                <i class="fa-solid fa-puzzle-piece"></i>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-800">Pemeriksaan Ekstensi Wajib</h3>
                <p class="text-xs text-slate-400">Modul dependensi inti untuk operasional SIM Bisolpin</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 text-xs">
            <?php foreach ($extensions as $ext => $loaded): ?>
            <div class="p-2.5 rounded-xl border border-slate-100 bg-slate-50/50 flex items-center justify-between">
                <span class="font-mono font-bold text-slate-700"><?= $ext ?></span>
                <?php if ($loaded): ?>
                    <span class="text-[10px] font-bold text-emerald-600 flex items-center gap-1">
                        <i class="fa-solid fa-check"></i> Siap
                    </span>
                <?php else: ?>
                    <span class="text-[10px] font-bold text-rose-500 flex items-center gap-1">
                        <i class="fa-solid fa-xmark"></i> Hilang
                    </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: TAMBAH PENGUMUMAN BARU -->
<!-- ========================================================================= -->
<div id="modalTambahPengumuman" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-7 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh] animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-teal-100 text-teal-800 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-800">Buat Pengumuman Baru</h3>
                    <p class="text-[11px] text-slate-400">Siarkan pesan banner ke dashboard pengguna SIM Bisolpin</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modalTambahPengumuman')" class="text-slate-400 hover:text-slate-600 text-lg transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_pengumuman">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Judul Pengumuman <span class="text-rose-500">*</span></label>
                <input type="text" name="judul" required placeholder="misal: Jadwal Libur Lebaran & Pemeliharaan Server" class="form-input text-xs py-2 w-full">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Isi Pesan Pengumuman <span class="text-rose-500">*</span></label>
                <textarea name="pesan" rows="4" required placeholder="Tuliskan detail pengumuman yang jelas untuk para pengguna..." class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipe Banner</label>
                    <select name="tipe" class="form-input text-xs py-2 w-full">
                        <option value="info">🔵 Info Biru (Informasi Rutin)</option>
                        <option value="warning">🟡 Peringatan Kuning (Jadwal Libur / Perhatian)</option>
                        <option value="danger">🔴 Penting Merah (Darurat / Batas Waktu)</option>
                        <option value="success">🟢 Sukses Hijau (Pengumuman Positif)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Target Audiens (Role)</label>
                    <select name="target_role" class="form-input text-xs py-2 w-full">
                        <?php foreach ($roleOptions as $rKey => $rText): ?>
                            <option value="<?= e($rKey) ?>"><?= e($rText) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Cakupan Cabang</label>
                <select name="id_cabang" class="form-input text-xs py-2 w-full">
                    <option value="">🌐 Seluruh Cabang (Nasional)</option>
                    <?php foreach ($daftarCabang as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>">🏢 <?= e($cb['nama_cabang']) ?> (<?= e($cb['kode_cabang']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <span class="text-[10px] text-slate-400 mt-1 block">Pilih cabang tertentu jika pengumuman hanya berlaku untuk lokasi tersebut.</span>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-800">Status Aktifkan Langsung</div>
                    <div class="text-[10px] text-slate-400">Jika dicentang, banner akan langsung tampil di dashboard tujuan</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_aktif" value="1" checked class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <button type="button" onclick="closeModal('modalTambahPengumuman')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5 font-bold">Terbitkan Pengumuman</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: EDIT PENGUMUMAN -->
<!-- ========================================================================= -->
<div id="modalEditPengumuman" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-7 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh] animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-pen"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-800">Edit Pengumuman</h3>
                    <p class="text-[11px] text-slate-400" id="edit-subtitle">Perbarui rincian atau sasaran audiens pengumuman</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modalEditPengumuman')" class="text-slate-400 hover:text-slate-600 text-lg transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_pengumuman">
            <input type="hidden" name="id" id="edit-id">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Judul Pengumuman <span class="text-rose-500">*</span></label>
                <input type="text" name="judul" id="edit-judul" required class="form-input text-xs py-2 w-full">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Isi Pesan Pengumuman <span class="text-rose-500">*</span></label>
                <textarea name="pesan" id="edit-pesan" rows="4" required class="form-input text-xs py-2 w-full"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Tipe Banner</label>
                    <select name="tipe" id="edit-tipe" class="form-input text-xs py-2 w-full">
                        <option value="info">🔵 Info Biru (Informasi Rutin)</option>
                        <option value="warning">🟡 Peringatan Kuning (Jadwal Libur / Perhatian)</option>
                        <option value="danger">🔴 Penting Merah (Darurat / Batas Waktu)</option>
                        <option value="success">🟢 Sukses Hijau (Pengumuman Positif)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Target Audiens (Role)</label>
                    <select name="target_role" id="edit-target-role" class="form-input text-xs py-2 w-full">
                        <?php foreach ($roleOptions as $rKey => $rText): ?>
                            <option value="<?= e($rKey) ?>"><?= e($rText) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Cakupan Cabang</label>
                <select name="id_cabang" id="edit-id-cabang" class="form-input text-xs py-2 w-full">
                    <option value="">🌐 Seluruh Cabang (Nasional)</option>
                    <?php foreach ($daftarCabang as $cb): ?>
                        <option value="<?= (int)$cb['id'] ?>">🏢 <?= e($cb['nama_cabang']) ?> (<?= e($cb['kode_cabang']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-800">Status Aktif</div>
                    <div class="text-[10px] text-slate-400">Aktifkan agar banner langsung tampil di dashboard tujuan</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="is_aktif" id="edit-is-aktif" value="1" class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                </label>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2.5">
                <button type="button" onclick="closeModal('modalEditPengumuman')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5 font-bold">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: KONFIRMASI HAPUS PENGUMUMAN -->
<!-- ========================================================================= -->
<div id="modalHapusPengumuman" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 animate-in fade-in zoom-in-95 duration-150">
        <div class="w-12 h-12 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto mb-4 text-xl">
            <i class="fa-solid fa-trash-can"></i>
        </div>
        <h3 class="text-base font-bold text-slate-800 text-center">Hapus Pengumuman Ini?</h3>
        <p class="text-xs text-slate-500 text-center mt-1.5 leading-relaxed">
            Pengumuman <strong id="hapus-judul" class="text-slate-800"></strong> akan dihapus permanen dan tidak akan ditampilkan lagi di dashboard mana pun.
        </p>

        <form method="POST" action="" class="mt-6 flex items-center justify-center gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="hapus_pengumuman">
            <input type="hidden" name="id" id="hapus-id">

            <button type="button" onclick="closeModal('modalHapusPengumuman')" class="btn-brand-outline text-xs py-2.5 px-5 font-bold">
                Batal
            </button>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs transition-colors shadow-sm shadow-rose-500/20">
                Ya, Hapus Sekarang
            </button>
        </form>
    </div>
</div>

<!-- Script Interaksi Modal & Helper -->
<script>
function openModalTambahPengumuman() {
    document.getElementById('modalTambahPengumuman').classList.remove('hidden');
}

function openModalEditPengumuman(data) {
    document.getElementById('edit-id').value = data.id || '';
    document.getElementById('edit-judul').value = data.judul || '';
    document.getElementById('edit-pesan').value = data.pesan || '';
    document.getElementById('edit-tipe').value = data.tipe || 'info';
    document.getElementById('edit-target-role').value = data.target_role || 'semua';
    document.getElementById('edit-id-cabang').value = data.id_cabang || '';
    document.getElementById('edit-is-aktif').checked = (parseInt(data.is_aktif) === 1);
    document.getElementById('edit-subtitle').innerText = 'ID Pengumuman: #' + data.id;

    document.getElementById('modalEditPengumuman').classList.remove('hidden');
}

function openModalHapusPengumuman(id, judul) {
    document.getElementById('hapus-id').value = id;
    document.getElementById('hapus-judul').innerText = '"' + judul + '"';
    document.getElementById('modalHapusPengumuman').classList.remove('hidden');
}

function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.classList.add('hidden');
    }
}

// Tutup modal dengan tombol Escape
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('modalTambahPengumuman');
        closeModal('modalEditPengumuman');
        closeModal('modalHapusPengumuman');
    }
});
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
