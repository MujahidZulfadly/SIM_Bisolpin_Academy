<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Akun Pengguna Global (Admin Sistem)
 */

declare(strict_types=1);

$pageTitle = 'Manajemen Akun Pengguna';

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

    // 1. TAMBAH USER BARU
    if ($action === 'tambah_user') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? 'password123';
        $role     = trim($_POST['role'] ?? 'siswa');
        $idCabang = !empty($_POST['id_cabang']) ? (int)$_POST['id_cabang'] : null;
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if (empty($username) || empty($email) || empty($password)) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Username, email, dan kata sandi wajib diisi.', 'Validasi Gagal');
        }

        // Cek duplikasi username & email
        $stmtCek = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtCek->execute([$username, $email]);
        if ((int)$stmtCek->fetchColumn() > 0) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Username atau email tersebut sudah terdaftar dalam sistem.', 'Duplikasi Terdeteksi');
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            $stmtIns = $db->prepare("
                INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([$username, $email, $passwordHash, $role, $idCabang, $isActive]);
            $newId = (int)$db->lastInsertId();

            log_activity('TAMBAH_USER', "Membuat akun baru: {$username} ({$role}) ID: {$newId}");

            redirect_with_message('modules/admin_sistem/users.php', 'success', "Akun pengguna '{$username}' berhasil dibuat.", 'Akun Ditambahkan');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Gagal membuat akun: ' . $e->getMessage(), 'Kesalahan Sistem');
        }
    }

    // 2. EDIT USER
    if ($action === 'edit_user') {
        $id       = (int)($_POST['id'] ?? 0);
        $email    = trim($_POST['email'] ?? '');
        $role     = trim($_POST['role'] ?? 'siswa');
        $idCabang = !empty($_POST['id_cabang']) ? (int)$_POST['id_cabang'] : null;
        $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

        if ($id <= 0 || empty($email)) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Data pengguna tidak valid.');
        }

        try {
            $stmtUp = $db->prepare("
                UPDATE users
                SET email = ?, role = ?, id_cabang = ?, is_active = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$email, $role, $idCabang, $isActive, $id]);

            log_activity('EDIT_USER', "Memperbarui data pengguna ID {$id} ({$email}, role: {$role}, aktif: {$isActive})");

            redirect_with_message('modules/admin_sistem/users.php', 'success', 'Perubahan data pengguna berhasil disimpan.', 'Update Berhasil');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Gagal memperbarui pengguna: ' . $e->getMessage());
        }
    }

    // 3. RESET PASSWORD
    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $newPass = $_POST['new_password'] ?? 'password123';

        if ($id <= 0 || empty($newPass)) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Kata sandi baru tidak boleh kosong.');
        }

        try {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $stmtPass = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmtPass->execute([$hash, $id]);

            log_activity('RESET_PASSWORD', "Mereset kata sandi akun pengguna ID {$id}");

            redirect_with_message('modules/admin_sistem/users.php', 'success', 'Kata sandi pengguna berhasil direset menjadi: ' . e($newPass), 'Password Direset');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Gagal mereset kata sandi: ' . $e->getMessage());
        }
    }

    // 4. TOGGLE STATUS AKTIF (SUSPEND / ACTIVATE)
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $targetStatus = (int)($_POST['target_status'] ?? 0);

        try {
            $stmtTog = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmtTog->execute([$targetStatus, $id]);

            $label = $targetStatus === 1 ? 'diaktifkan kembali' : 'disuspend / dinonaktifkan';
            log_activity('TOGGLE_STATUS', "Mengubah status akun ID {$id} menjadi: " . ($targetStatus === 1 ? 'Aktif' : 'Nonaktif'));

            redirect_with_message('modules/admin_sistem/users.php', 'success', "Akun pengguna berhasil {$label}.", 'Status Diperbarui');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/users.php', 'error', 'Gagal mengubah status akun: ' . $e->getMessage());
        }
    }
}

// FILTER & QUERY PENGGUNA
$filterRole   = trim($_GET['role'] ?? '');
$filterCabang = !empty($_GET['cabang']) ? (int)$_GET['cabang'] : 0;
$filterStatus = trim($_GET['status'] ?? '');
$search       = trim($_GET['q'] ?? '');

$sql = "
    SELECT u.*, c.nama_cabang, c.kode_cabang,
           COALESCE(p.nama_lengkap, s.nama_lengkap, u.username) as display_name,
           COALESCE(p.nip_pegawai, s.nis_bisolpin, '-') as nomor_induk
    FROM users u
    LEFT JOIN tabel_cabang c ON c.id = u.id_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    LEFT JOIN tabel_siswa s ON s.user_id = u.id
    WHERE 1=1
";
$params = [];

if (!empty($filterRole)) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}
if ($filterCabang > 0) {
    $sql .= " AND u.id_cabang = ?";
    $params[] = $filterCabang;
}
if ($filterStatus !== '') {
    $sql .= " AND u.is_active = ?";
    $params[] = (int)$filterStatus;
}
if (!empty($search)) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ? OR p.nama_lengkap LIKE ? OR s.nama_lengkap LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY u.id DESC";
$stmtUsers = $db->prepare($sql);
$stmtUsers->execute($params);
$userList = $stmtUsers->fetchAll();

// Daftar Seluruh Cabang untuk Filter & Form
$allCabang = $db->query("SELECT id, kode_cabang, nama_cabang, kota FROM tabel_cabang ORDER BY nama_cabang ASC")->fetchAll();

// EKSPOR EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $columns = [
        'no'           => ['label' => 'No', 'type' => 'center', 'width' => 45],
        'username'     => ['label' => 'Username', 'type' => 'text', 'width' => 140],
        'display_name' => ['label' => 'Nama Lengkap', 'type' => 'text', 'width' => 180],
        'nomor_induk'  => ['label' => 'NIP / NIS', 'type' => 'text', 'width' => 120],
        'email'        => ['label' => 'Email', 'type' => 'text', 'width' => 180],
        'role'         => ['label' => 'Role Akses', 'type' => 'center', 'width' => 130],
        'cabang'       => ['label' => 'Penempatan Cabang', 'type' => 'text', 'width' => 170],
        'status'       => ['label' => 'Status Akun', 'type' => 'badge', 'width' => 100],
        'last_login'   => ['label' => 'Login Terakhir', 'type' => 'center', 'width' => 140],
        'created_at'   => ['label' => 'Tgl Registrasi', 'type' => 'date', 'width' => 110]
    ];

    $rows = [];
    $no = 1;
    foreach ($userList as $u) {
        $rows[] = [
            'no'           => $no++,
            'username'     => $u['username'],
            'display_name' => $u['display_name'],
            'nomor_induk'  => $u['nomor_induk'],
            'email'        => $u['email'],
            'role'         => strtoupper(str_replace('_', ' ', $u['role'])),
            'cabang'       => !empty($u['nama_cabang']) ? ($u['nama_cabang'] . ' (' . $u['kode_cabang'] . ')') : 'Kantor Pusat / Nasional',
            'status'       => (int)$u['is_active'] === 1 ? 'aktif' : 'nonaktif',
            'last_login'   => !empty($u['last_login']) ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Belum pernah',
            'created_at'   => date('Y-m-d', strtotime($u['created_at']))
        ];
    }

    $metadata = [
        'Cakupan'       => 'Master Akun Pengguna SIM Bisolpin',
        'Filter Role'   => !empty($filterRole) ? ucfirst($filterRole) : 'Semua Role',
        'Total User'    => count($rows) . ' Pengguna',
        'Waktu Ekspor'  => date('d-m-Y H:i') . ' WITA'
    ];

    export_to_excel('master_users_bisolpin_' . date('Ymd_His'), 'MASTER DATA AKUN PENGGUNA SIM BISOLPIN ACADEMY', $columns, $rows, $metadata);
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-users-gear"></i>
            <span>Identity & Access Management (IAM)</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Manajemen Akun Pengguna Global
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Kelola seluruh akun login, hak akses RBAC, reset kata sandi, dan suspensi akun di seluruh cabang.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="?role=<?= urlencode($filterRole) ?>&cabang=<?= $filterCabang ?>&status=<?= urlencode($filterStatus) ?>&q=<?= urlencode($search) ?>&export=excel" class="px-3.5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm shadow-emerald-600/20 flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-file-excel text-xs"></i>
            <span>Ekspor Excel (.xls)</span>
        </a>
        <button type="button" onclick="openModalTambahUser()" class="btn-brand text-xs py-2.5 px-4 shadow-sm shadow-brand-500/20 flex items-center gap-1.5 font-bold">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Tambah User Baru</span>
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
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari username, nama, atau email..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-40">
                <select name="role" class="form-input text-xs py-2">
                    <option value="">Semua Role</option>
                    <?php foreach (APP_ROLES as $rk => $rm): ?>
                        <option value="<?= $rk ?>" <?= $filterRole === $rk ? 'selected' : '' ?>><?= $rm['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-44">
                <select name="cabang" class="form-input text-xs py-2">
                    <option value="">Semua Cabang</option>
                    <?php foreach ($allCabang as $cb): ?>
                        <option value="<?= $cb['id'] ?>" <?= $filterCabang === (int)$cb['id'] ? 'selected' : '' ?>>
                            <?= e($cb['nama_cabang']) ?> (<?= e($cb['kode_cabang']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-32">
                <select name="status" class="form-input text-xs py-2">
                    <option value="">Semua Status</option>
                    <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterRole) || $filterCabang > 0 || $filterStatus !== ''): ?>
                <a href="<?= base_url('modules/admin_sistem/users.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset Filter
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Ditemukan: <span class="font-bold text-slate-800"><?= count($userList) ?> Akun</span>
        </div>
    </form>
</div>

<!-- Tabel Pengguna -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-600">
                    <th class="py-3 px-4 font-bold">No</th>
                    <th class="py-3 px-4 font-bold">Username & Email</th>
                    <th class="py-3 px-4 font-bold">Nama Lengkap & NIP/NIS</th>
                    <th class="py-3 px-4 font-bold">Role Akses</th>
                    <th class="py-3 px-4 font-bold">Penempatan Cabang</th>
                    <th class="py-3 px-4 font-bold text-center">Status</th>
                    <th class="py-3 px-4 font-bold">Login Terakhir</th>
                    <th class="py-3 px-4 font-bold text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($userList)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-12 text-slate-400">
                            <i class="fa-solid fa-user-slash text-3xl mb-2 text-slate-300 block"></i>
                            Tidak ada akun pengguna yang cocok dengan kriteria filter saat ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($userList as $u): ?>
                    <tr class="hover:bg-slate-50/70 transition-colors">
                        <td class="py-3 px-4 text-slate-400 font-mono"><?= $no++ ?></td>
                        <td class="py-3 px-4">
                            <span class="font-bold text-slate-800 block"><?= e($u['username']) ?></span>
                            <span class="text-[11px] text-slate-400"><?= e($u['email']) ?></span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-bold text-slate-700 block"><?= e($u['display_name']) ?></span>
                            <span class="text-[11px] text-slate-400 font-mono"><?= e($u['nomor_induk']) ?></span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-bold <?= APP_ROLES[$u['role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                <?= APP_ROLES[$u['role']]['label'] ?? ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-semibold text-slate-700 block"><?= e($u['nama_cabang'] ?? 'Pusat / Nasional') ?></span>
                            <?php if (!empty($u['kode_cabang'])): ?>
                                <span class="text-[10px] text-slate-400 font-mono"><?= e($u['kode_cabang']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <?php if ((int)$u['is_active'] === 1): ?>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Suspended
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-slate-500">
                            <?= !empty($u['last_login']) ? date('d M Y, H:i', strtotime($u['last_login'])) : '<span class="text-slate-300 italic">Belum pernah</span>' ?>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" onclick='openModalEditUser(<?= json_encode($u) ?>)' class="w-7 h-7 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition-colors" title="Edit Data Pengguna">
                                    <i class="fa-solid fa-pen text-[11px]"></i>
                                </button>
                                <button type="button" onclick='openModalResetPass(<?= $u['id'] ?>, "<?= e($u['username']) ?>")' class="w-7 h-7 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-600 flex items-center justify-center transition-colors" title="Reset Kata Sandi">
                                    <i class="fa-solid fa-key text-[11px]"></i>
                                </button>

                                <form method="POST" action="" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin <?= (int)$u['is_active'] === 1 ? 'menonaktifkan' : 'mengaktifkan' ?> akun ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="target_status" value="<?= (int)$u['is_active'] === 1 ? 0 : 1 ?>">
                                    <?php if ((int)$u['is_active'] === 1): ?>
                                        <button type="submit" class="w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 flex items-center justify-center transition-colors" title="Nonaktifkan Akun">
                                            <i class="fa-solid fa-ban text-[11px]"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="w-7 h-7 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-600 flex items-center justify-center transition-colors" title="Aktifkan Kembali">
                                            <i class="fa-solid fa-check text-[11px]"></i>
                                        </button>
                                    <?php endif; ?>
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
<!-- MODAL 1: TAMBAH USER BARU -->
<!-- ========================================================================= -->
<div id="modalTambahUser" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <h3 class="text-base font-bold text-slate-800">Tambah Akun Pengguna Baru</h3>
            <button onclick="closeModal('modalTambahUser')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_user">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Username <span class="text-rose-500">*</span></label>
                <input type="text" name="username" required placeholder="misal: bima.mentor" class="form-input text-xs py-2 w-full">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Email Resmi <span class="text-rose-500">*</span></label>
                <input type="email" name="email" required placeholder="misal: bima@bisolpin.com" class="form-input text-xs py-2 w-full">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Kata Sandi Awal <span class="text-rose-500">*</span></label>
                <input type="text" name="password" required value="password123" class="form-input text-xs py-2 w-full font-mono">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Role Hak Akses <span class="text-rose-500">*</span></label>
                    <select name="role" required class="form-input text-xs py-2 w-full">
                        <?php foreach (APP_ROLES as $rk => $rm): ?>
                            <option value="<?= $rk ?>"><?= $rm['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Cabang Penempatan</label>
                    <select name="id_cabang" class="form-input text-xs py-2 w-full">
                        <option value="">Kantor Pusat / Global</option>
                        <?php foreach ($allCabang as $cb): ?>
                            <option value="<?= $cb['id'] ?>"><?= e($cb['nama_cabang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="inline-flex items-center gap-2 cursor-pointer mt-1">
                    <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-xs font-semibold text-slate-700">Langsung aktifkan akun ini</span>
                </label>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalTambahUser')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: EDIT USER -->
<!-- ========================================================================= -->
<div id="modalEditUser" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-base font-bold text-slate-800">Edit Akun Pengguna</h3>
                <p class="text-xs text-slate-400" id="edit-user-title">-</p>
            </div>
            <button onclick="closeModal('modalEditUser')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" id="edit-id" name="id" value="">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Email</label>
                <input type="email" id="edit-email" name="email" required class="form-input text-xs py-2 w-full">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Role</label>
                    <select id="edit-role" name="role" required class="form-input text-xs py-2 w-full">
                        <?php foreach (APP_ROLES as $rk => $rm): ?>
                            <option value="<?= $rk ?>"><?= $rm['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Cabang</label>
                    <select id="edit-cabang" name="id_cabang" class="form-input text-xs py-2 w-full">
                        <option value="">Kantor Pusat / Global</option>
                        <?php foreach ($allCabang as $cb): ?>
                            <option value="<?= $cb['id'] ?>"><?= e($cb['nama_cabang']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Status Akun</label>
                <select id="edit-status" name="is_active" class="form-input text-xs py-2 w-full">
                    <option value="1">Aktif</option>
                    <option value="0">Nonaktif / Suspended</option>
                </select>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalEditUser')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 3: RESET PASSWORD -->
<!-- ========================================================================= -->
<div id="modalResetPass" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 sm:p-8 shadow-2xl border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="text-base font-bold text-slate-800">Reset Kata Sandi</h3>
            <button onclick="closeModal('modalResetPass')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" id="reset-id" name="id" value="">

            <p class="text-xs text-slate-500">
                Atur kata sandi baru untuk akun <strong id="reset-username" class="text-slate-800">-</strong>:
            </p>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Kata Sandi Baru</label>
                <input type="text" name="new_password" required value="password123" class="form-input text-xs py-2 w-full font-mono">
                <span class="text-[10px] text-slate-400 mt-1 block">Default: password123</span>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalResetPass')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Terapkan Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalTambahUser() {
    document.getElementById('modalTambahUser').classList.remove('hidden');
}

function openModalEditUser(user) {
    document.getElementById('edit-id').value = user.id;
    document.getElementById('edit-email').value = user.email;
    document.getElementById('edit-role').value = user.role;
    document.getElementById('edit-cabang').value = user.id_cabang || '';
    document.getElementById('edit-status').value = user.is_active;
    document.getElementById('edit-user-title').innerText = user.username + ' (' + (user.display_name || '') + ')';
    document.getElementById('modalEditUser').classList.remove('hidden');
}

function openModalResetPass(userId, username) {
    document.getElementById('reset-id').value = userId;
    document.getElementById('reset-username').innerText = username;
    document.getElementById('modalResetPass').classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';

