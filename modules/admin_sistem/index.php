<?php
/**
 * SIM BISOLPIN ACADEMY - Dashboard Administrator Sistem (IT)
 */

declare(strict_types=1);

$pageTitle = 'Dashboard Administrator Sistem';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role ADMIN_SISTEM
check_role(ROLE_ADMIN_SISTEM);

$db = get_db();
ensure_database_schema_integrity($db);

// Statistik Pengguna
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$inactiveUsers = $totalUsers - $activeUsers;

// Breakdown per Role
$stmtRoles = $db->query("SELECT role, COUNT(*) as jml FROM users GROUP BY role");
$roleCounts = [];
foreach ($stmtRoles->fetchAll() as $r) {
    $roleCounts[$r['role']] = (int)$r['jml'];
}

// Statistik Cabang
$totalCabang = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang")->fetchColumn();
$aktifCabang = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'aktif'")->fetchColumn();
$pendingMitraAdmin = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'menunggu_aktivasi_admin'")->fetchColumn();

// Statistik Log Aktivitas Hari Ini
$todayLogs = (int)$db->query("SELECT COUNT(*) FROM tabel_audit_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Pengguna Terdaftar Terbaru
$recentUsers = $db->query("
    SELECT u.*, c.nama_cabang, c.kode_cabang,
           COALESCE(p.nama_lengkap, s.nama_lengkap, u.username) as display_name
    FROM users u
    LEFT JOIN tabel_cabang c ON c.id = u.id_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    LEFT JOIN tabel_siswa s ON s.user_id = u.id
    ORDER BY u.id DESC
    LIMIT 6
")->fetchAll();

// Log Aktivitas Terakhir
$recentLogs = $db->query("
    SELECT a.*, c.kode_cabang
    FROM tabel_audit_log a
    LEFT JOIN tabel_cabang c ON c.id = a.id_cabang
    ORDER BY a.id DESC
    LIMIT 8
")->fetchAll();

// Info Server & Database
$mysqlVersion = $db->query("SELECT VERSION()")->fetchColumn();
$phpVersion = PHP_VERSION;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP CLI Server';
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Dashboard -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-server"></i>
            <span>System Administration & IT Infrastructure</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Dashboard Administrator Sistem
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Pusat kendali tata kelola akun pengguna, master cabang, pemeliharaan basis data, dan audit keamanan sistem SIM Bisolpin.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="<?= base_url('modules/admin_sistem/users.php?action=tambah') ?>" class="btn-brand text-xs py-2.5 px-4 shadow-sm shadow-brand-500/20 flex items-center gap-1.5 font-bold">
            <i class="fa-solid fa-user-plus text-xs"></i>
            <span>Tambah User Baru</span>
        </a>
        <a href="<?= base_url('modules/admin_sistem/backup.php') ?>" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold shadow-sm flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-database text-xs text-amber-400"></i>
            <span>Backup Database</span>
        </a>
    </div>
</div>

<?php if ($pendingMitraAdmin > 0): ?>
<!-- Alert Antrean Persetujuan Akun Mitra Cabang -->
<div class="mb-8 p-4 rounded-2xl bg-cyan-50 border border-cyan-200 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-cyan-600 text-white flex items-center justify-center text-lg shadow-sm">
            <i class="fa-solid fa-user-check animate-pulse"></i>
        </div>
        <div>
            <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-cyan-200 text-cyan-900 text-[10px] font-black uppercase tracking-wider mb-0.5">
                Persetujuan Akun Diperlukan
            </div>
            <h4 class="text-sm font-bold text-slate-800">
                Terdapat <?= $pendingMitraAdmin ?> pengajuan kemitraan cabang baru yang telah disetujui Pimpinan Pusat.
            </h4>
            <p class="text-xs text-slate-600">
                Silakan lakukan peninjauan teknis, verifikasi kode cabang dan username, lalu setujui pembuatan akun resmi login mitra.
            </p>
        </div>
    </div>
    <a href="<?= base_url('modules/admin_sistem/verifikasi_mitra.php') ?>" class="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-bold shadow-md shadow-cyan-600/20 flex items-center gap-1.5 transition-all whitespace-nowrap">
        <span>Buka Persetujuan Akun</span>
        <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
</div>
<?php endif; ?>

<!-- Bento Grid KPI Metrik Sistem -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <!-- KPI 1: Total Pengguna -->
    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Akun Sistem</span>
            <div class="w-10 h-10 rounded-xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <span class="text-3xl font-black text-slate-800 block"><?= $totalUsers ?></span>
        <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Aktif: <strong class="text-emerald-600"><?= $activeUsers ?></strong></span>
            <span>Suspended: <strong class="text-rose-500"><?= $inactiveUsers ?></strong></span>
        </div>
    </div>

    <!-- KPI 2: Jaringan Cabang -->
    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Jaringan Cabang</span>
            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-network-wired"></i>
            </div>
        </div>
        <span class="text-3xl font-black text-indigo-600 block"><?= $totalCabang ?></span>
        <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Operasional: <strong class="text-indigo-700"><?= $aktifCabang ?> Cabang</strong></span>
            <a href="<?= base_url('modules/admin_sistem/cabang.php') ?>" class="text-brand-600 hover:underline font-bold">Kelola →</a>
        </div>
    </div>

    <!-- KPI 3: Aktivitas Hari Ini -->
    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Aktivitas Hari Ini</span>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
        </div>
        <span class="text-3xl font-black text-emerald-600 block"><?= $todayLogs ?></span>
        <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
            <span>Log Keamanan & Operasi</span>
            <a href="<?= base_url('modules/admin_sistem/audit_log.php') ?>" class="text-brand-600 hover:underline font-bold">Audit →</a>
        </div>
    </div>

    <!-- KPI 4: Status Server -->
    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Status Server & DB</span>
            <div class="w-10 h-10 rounded-xl bg-teal-50 text-teal-600 flex items-center justify-center text-lg">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
            <span class="text-base font-black text-slate-800">Online & Stabil</span>
        </div>
        <div class="mt-3 pt-3 border-t border-slate-100 text-[11px] text-slate-500 truncate">
            <span>PHP <?= e($phpVersion) ?> • MySQL <?= e(substr((string)$mysqlVersion, 0, 10)) ?></span>
        </div>
    </div>
</div>

<!-- Distribusi Akun per Role -->
<div class="card p-6 mb-8">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Distribusi Hak Akses (Role-Based Access Control)</h3>
            <p class="text-xs text-slate-400 mt-0.5">Komposisi seluruh pemegang akun di ekosistem SIM Bisolpin Academy.</p>
        </div>
        <a href="<?= base_url('modules/admin_sistem/users.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
            Lihat Semua Akun Pengguna →
        </a>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
        <?php foreach (APP_ROLES as $rKey => $rMeta): 
            $count = $roleCounts[$rKey] ?? 0;
        ?>
        <div class="p-3 rounded-xl border border-slate-200/80 bg-slate-50/50 hover:bg-slate-50 transition-colors">
            <span class="text-xs font-bold text-slate-500 truncate block"><?= $rMeta['label'] ?></span>
            <span class="text-xl font-black text-slate-800 mt-1 block"><?= $count ?></span>
            <span class="text-[10px] font-semibold text-slate-400 block mt-0.5"><?= $totalUsers > 0 ? round(($count / $totalUsers) * 100, 1) : 0 ?>% dari total</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Grid 2 Kolom: Pengguna Terbaru & Audit Trail Terkini -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 mb-8">
    <!-- Kolom Kiri: Akun Terdaftar Terbaru -->
    <div class="lg:col-span-6 card overflow-hidden flex flex-col justify-between">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Akun Pengguna Terbaru</h3>
                    <p class="text-[11px] text-slate-400">Pendaftaran akun terakhir di seluruh cabang</p>
                </div>
            </div>
            <a href="<?= base_url('modules/admin_sistem/users.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Kelola Semua
            </a>
        </div>

        <div class="overflow-x-auto flex-1">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-50/70 border-b border-slate-100 text-slate-500">
                        <th class="py-2.5 px-4 font-bold">Nama & Username</th>
                        <th class="py-2.5 px-4 font-bold">Role</th>
                        <th class="py-2.5 px-4 font-bold">Cabang</th>
                        <th class="py-2.5 px-4 font-bold text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($recentUsers as $ru): ?>
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        <td class="py-3 px-4">
                            <span class="font-bold text-slate-800 block truncate max-w-[150px]"><?= e($ru['display_name']) ?></span>
                            <span class="text-[11px] text-slate-400 font-mono"><?= e($ru['username']) ?></span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold <?= APP_ROLES[$ru['role']]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                                <?= APP_ROLES[$ru['role']]['label'] ?? ucfirst($ru['role']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="text-slate-600 font-medium block truncate max-w-[120px]"><?= e($ru['nama_cabang'] ?? 'Pusat / Nasional') ?></span>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <?php if ((int)$ru['is_active'] === 1): ?>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded-full border border-rose-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Nonaktif
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Kolom Kanan: Audit Trail & Aktivitas Sistem Terkini -->
    <div class="lg:col-span-6 card overflow-hidden flex flex-col justify-between">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-slate-800">Audit Trail Terkini</h3>
                    <p class="text-[11px] text-slate-400">Log transaksi, login, dan aksi pengguna</p>
                </div>
            </div>
            <a href="<?= base_url('modules/admin_sistem/audit_log.php') ?>" class="text-xs font-bold text-brand-600 hover:underline">
                Buka Log Penuh
            </a>
        </div>

        <div class="p-4 flex-1 space-y-3 overflow-y-auto max-h-[380px]">
            <?php if (empty($recentLogs)): ?>
                <div class="text-center py-10 text-slate-400 text-xs">
                    <i class="fa-solid fa-shield-halved text-3xl mb-2 text-slate-300 block"></i>
                    Belum ada log aktivitas tercatat hari ini.
                </div>
            <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                <div class="p-3 rounded-xl border border-slate-100 bg-slate-50/50 flex items-start justify-between gap-3 text-xs">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="font-bold text-slate-800"><?= e($log['username']) ?></span>
                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-200 text-slate-700 uppercase"><?= e($log['role']) ?></span>
                            <span class="text-[10px] text-slate-400"><?= date('H:i', strtotime($log['created_at'])) ?> WITA</span>
                        </div>
                        <p class="text-slate-600 leading-snug"><?= e($log['deskripsi']) ?></p>
                        <span class="text-[10px] text-slate-400 font-mono mt-0.5 block">IP: <?= e($log['ip_address'] ?? '127.0.0.1') ?></span>
                    </div>
                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-teal-100 text-teal-800 shrink-0">
                        <?= e($log['aksi']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';

