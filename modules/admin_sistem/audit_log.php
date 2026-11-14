<?php
/**
 * SIM BISOLPIN ACADEMY - Audit Trail & Log Keamanan Sistem (Admin Sistem)
 */

declare(strict_types=1);

$pageTitle = 'Audit Trail & Log Sistem';

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

// PROSES POST FORM: BERSIHKAN LOG LAMA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    if ($action === 'bersihkan_log_lama') {
        $hari = (int)($_POST['hari'] ?? 90);
        if ($hari < 30) $hari = 30; // Proteksi minimal 30 hari

        try {
            $stmtDel = $db->prepare("DELETE FROM tabel_audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmtDel->execute([$hari]);
            $deletedCount = $stmtDel->rowCount();

            log_activity('PURGE_LOG', "Membersihkan {$deletedCount} catatan audit log yang lebih lama dari {$hari} hari.");

            redirect_with_message('modules/admin_sistem/audit_log.php', 'success', "Berhasil membersihkan {$deletedCount} catatan log lama (> {$hari} hari).", 'Log Dibersihkan');
        } catch (\Throwable $e) {
            redirect_with_message('modules/admin_sistem/audit_log.php', 'error', 'Gagal membersihkan log: ' . $e->getMessage());
        }
    }
}

// FILTER & QUERY LOG
$filterTanggal = trim($_GET['tanggal'] ?? '');
$filterRole    = trim($_GET['role'] ?? '');
$filterAksi    = trim($_GET['aksi'] ?? '');
$search        = trim($_GET['q'] ?? '');

$sql = "
    SELECT a.*, c.nama_cabang, c.kode_cabang
    FROM tabel_audit_log a
    LEFT JOIN tabel_cabang c ON c.id = a.id_cabang
    WHERE 1=1
";
$params = [];

if (!empty($filterTanggal)) {
    $sql .= " AND DATE(a.created_at) = ?";
    $params[] = $filterTanggal;
}
if (!empty($filterRole)) {
    $sql .= " AND a.role = ?";
    $params[] = $filterRole;
}
if (!empty($filterAksi)) {
    $sql .= " AND a.aksi = ?";
    $params[] = $filterAksi;
}
if (!empty($search)) {
    $sql .= " AND (a.username LIKE ? OR a.deskripsi LIKE ? OR a.ip_address LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY a.id DESC LIMIT 300";
$stmtLogs = $db->prepare($sql);
$stmtLogs->execute($params);
$logList = $stmtLogs->fetchAll();

// Daftar Aksi Unik untuk Filter Dropdown
$distinctAksi = $db->query("SELECT DISTINCT aksi FROM tabel_audit_log ORDER BY aksi ASC")->fetchAll(PDO::FETCH_COLUMN);

// EKSPOR EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $columns = [
        'no'         => ['label' => 'No', 'type' => 'center', 'width' => 45],
        'waktu'      => ['label' => 'Waktu (WITA)', 'type' => 'datetime', 'width' => 140],
        'username'   => ['label' => 'Username', 'type' => 'text', 'width' => 130],
        'role'       => ['label' => 'Role Akses', 'type' => 'center', 'width' => 110],
        'cabang'     => ['label' => 'Cabang', 'type' => 'text', 'width' => 160],
        'aksi'       => ['label' => 'Jenis Aksi', 'type' => 'center', 'width' => 130],
        'deskripsi'  => ['label' => 'Deskripsi Aktivitas', 'type' => 'text', 'width' => 300],
        'ip_address' => ['label' => 'Alamat IP', 'type' => 'center', 'width' => 120]
    ];

    $rows = [];
    $no = 1;
    foreach ($logList as $l) {
        $rows[] = [
            'no'         => $no++,
            'waktu'      => $l['created_at'],
            'username'   => $l['username'],
            'role'       => strtoupper($l['role']),
            'cabang'     => !empty($l['nama_cabang']) ? ($l['nama_cabang'] . ' (' . $l['kode_cabang'] . ')') : 'Pusat / Global',
            'aksi'       => $l['aksi'],
            'deskripsi'  => $l['deskripsi'],
            'ip_address' => $l['ip_address'] ?: '127.0.0.1'
        ];
    }

    $metadata = [
        'Cakupan'       => 'Audit Trail & Rekam Jejak SIM Bisolpin',
        'Filter Tanggal'=> !empty($filterTanggal) ? format_tanggal_indonesia($filterTanggal) : 'Semua Tanggal',
        'Total Log'     => count($rows) . ' Catatan',
        'Waktu Ekspor'  => date('d-m-Y H:i') . ' WITA'
    ];

    export_to_excel('audit_log_bisolpin_' . date('Ymd_His'), 'REKAM JEJAK AUDIT TRAIL & KEAMANAN SISTEM BISOLPIN', $columns, $rows, $metadata);
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Security Audit Trail & Compliance</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Audit Trail & Log Sistem
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Rekam jejak seluruh operasi pengguna, autentikasi login, perubahan data, dan transaksi krusial.
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2.5">
        <a href="?tanggal=<?= urlencode($filterTanggal) ?>&role=<?= urlencode($filterRole) ?>&aksi=<?= urlencode($filterAksi) ?>&q=<?= urlencode($search) ?>&export=excel" class="px-3.5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm shadow-emerald-600/20 flex items-center gap-1.5 transition-all">
            <i class="fa-solid fa-file-excel text-xs"></i>
            <span>Ekspor Excel (.xls)</span>
        </a>
        <button type="button" onclick="openModalPurgeLog()" class="px-3.5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all flex items-center gap-1.5">
            <i class="fa-solid fa-broom text-xs text-slate-500"></i>
            <span>Bersihkan Log Lama</span>
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
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari username, deskripsi, atau IP..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-36">
                <input type="date" name="tanggal" value="<?= e($filterTanggal) ?>" class="form-input text-xs py-2 w-full">
            </div>

            <div class="w-40">
                <select name="role" class="form-input text-xs py-2">
                    <option value="">Semua Role</option>
                    <?php foreach (APP_ROLES as $rk => $rm): ?>
                        <option value="<?= $rk ?>" <?= $filterRole === $rk ? 'selected' : '' ?>><?= $rm['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-40">
                <select name="aksi" class="form-input text-xs py-2">
                    <option value="">Semua Aksi</option>
                    <?php foreach ($distinctAksi as $ak): ?>
                        <option value="<?= $ak ?>" <?= $filterAksi === $ak ? 'selected' : '' ?>><?= e($ak) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterTanggal) || !empty($filterRole) || !empty($filterAksi)): ?>
                <a href="<?= base_url('modules/admin_sistem/audit_log.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset Filter
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Ditampilkan: <span class="font-bold text-slate-800"><?= count($logList) ?> Baris Log</span>
        </div>
    </form>
</div>

<!-- Tabel Log -->
<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-600">
                    <th class="py-3 px-4 font-bold">Waktu (WITA)</th>
                    <th class="py-3 px-4 font-bold">Pengguna & Role</th>
                    <th class="py-3 px-4 font-bold">Cabang</th>
                    <th class="py-3 px-4 font-bold">Jenis Aksi</th>
                    <th class="py-3 px-4 font-bold">Deskripsi Aktivitas</th>
                    <th class="py-3 px-4 font-bold">Alamat IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($logList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-12 text-slate-400">
                            <i class="fa-solid fa-shield-cat text-3xl mb-2 text-slate-300 block"></i>
                            Tidak ada rekaman log aktivitas yang sesuai dengan filter pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logList as $l): ?>
                    <tr class="hover:bg-slate-50/70 transition-colors">
                        <td class="py-3 px-4 font-mono text-slate-500 whitespace-nowrap">
                            <?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?>
                        </td>
                        <td class="py-3 px-4">
                            <span class="font-bold text-slate-800 block"><?= e($l['username']) ?></span>
                            <span class="text-[10px] font-bold px-1.5 py-0.2 rounded uppercase bg-slate-100 text-slate-600">
                                <?= e($l['role']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="text-slate-600 font-medium block truncate max-w-[130px]">
                                <?= e($l['nama_cabang'] ?? 'Pusat / Global') ?>
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-teal-100 text-teal-800 font-mono">
                                <?= e($l['aksi']) ?>
                            </span>
                        </td>
                        <td class="py-3 px-4 text-slate-700 max-w-md">
                            <?= e($l['deskripsi']) ?>
                        </td>
                        <td class="py-3 px-4 font-mono text-[11px] text-slate-400 whitespace-nowrap">
                            <?= e($l['ip_address'] ?: '127.0.0.1') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: BERSIHKAN LOG LAMA -->
<!-- ========================================================================= -->
<div id="modalPurgeLog" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 sm:p-8 shadow-2xl border border-slate-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <h3 class="text-base font-bold text-slate-800">Bersihkan Log Audit</h3>
            <button onclick="closeModal('modalPurgeLog')" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bersihkan_log_lama">

            <p class="text-xs text-slate-500 leading-relaxed">
                Hapus catatan riwayat audit yang sudah kedaluwarsa untuk menghemat kapasitas database.
            </p>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Hapus log yang lebih lama dari:</label>
                <select name="hari" class="form-input text-xs py-2 w-full">
                    <option value="90">90 Hari yang lalu (3 Bulan)</option>
                    <option value="180">180 Hari yang lalu (6 Bulan)</option>
                    <option value="365">365 Hari yang lalu (1 Tahun)</option>
                    <option value="30">30 Hari yang lalu (1 Bulan)</option>
                </select>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('modalPurgeLog')" class="btn-brand-outline text-xs py-2 px-4">Batal</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-sm">Hapus Log</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalPurgeLog() {
    document.getElementById('modalPurgeLog').classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';

