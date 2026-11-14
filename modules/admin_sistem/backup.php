<?php
/**
 * SIM BISOLPIN ACADEMY - Pencadangan & Pemeliharaan Basis Data (Admin Sistem)
 */

declare(strict_types=1);

$pageTitle = 'Pencadangan Basis Data';

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

// GENERATE & UNDUH DUMP SQL NATIVE
if (isset($_GET['action']) && $_GET['action'] === 'download_sql') {
    if (ob_get_level()) {
        ob_end_clean();
    }

    $dbName = $db->query("SELECT DATABASE()")->fetchColumn() ?: 'sim_bisolpin_academy';
    $filename = 'backup_' . preg_replace('/[^a-zA-Z0-9_]/', '', $dbName) . '_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate');
    header('Pragma: public');

    // Header komentar
    echo "-- ==========================================================\n";
    echo "-- SIM BISOLPIN ACADEMY - DATABASE BACKUP DUMP\n";
    echo "-- Database: {$dbName}\n";
    echo "-- Waktu Backup: " . date('Y-m-d H:i:s') . " WITA\n";
    echo "-- Versi PHP: " . PHP_VERSION . "\n";
    echo "-- ==========================================================\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+08:00\";\n\n";

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        echo "-- --------------------------------------------------------\n";
        echo "-- Struktur tabel: `{$table}`\n";
        echo "-- --------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createStmt['Create Table'] ?? '';
        echo $createSql . ";\n\n";

        // Dump data
        $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            echo "-- Data tabel: `{$table}` (" . count($rows) . " baris)\n";
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $escapedCols = array_map(fn($c) => "`{$c}`", $cols);
                $escapedVals = array_map(function($v) use ($db) {
                    if ($v === null) return 'NULL';
                    return $db->quote((string)$v);
                }, array_values($row));

                echo "INSERT INTO `{$table}` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $escapedVals) . ");\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- Selesai di-generate pada " . date('Y-m-d H:i:s') . " WITA.\n";

    log_activity('BACKUP_DATABASE', "Mengunduh cadangan basis data lengkap: {$filename}");

    exit;
}

// METRIK KAPASITAS & TABEL DATABASE
$dbName = $db->query("SELECT DATABASE()")->fetchColumn() ?: 'sim_bisolpin_academy';

$stmtTables = $db->prepare("
    SELECT table_name AS `nama_tabel`,
           engine AS `engine`,
           table_rows AS `jumlah_baris`,
           ROUND((data_length + index_length) / 1024, 2) AS `ukuran_kb`,
           ROUND(data_length / 1024, 2) AS `data_kb`,
           ROUND(index_length / 1024, 2) AS `index_kb`,
           update_time AS `terakhir_update`
    FROM information_schema.tables
    WHERE table_schema = ?
    ORDER BY (data_length + index_length) DESC
");
$stmtTables->execute([$dbName]);
$tablesInfo = $stmtTables->fetchAll();

$totalSizeKb = 0;
$totalRows = 0;
foreach ($tablesInfo as $ti) {
    $totalSizeKb += (float)$ti['ukuran_kb'];
    $totalRows += (int)$ti['jumlah_baris'];
}
$totalSizeMb = round($totalSizeKb / 1024, 2);

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-database"></i>
            <span>Disaster Recovery & Database Maintenance</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Pencadangan & Pemeliharaan Basis Data
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Ekspor struktur skema dan seluruh data sistem ke berkas SQL mandiri untuk mitigasi risiko dan arsip berkala.
        </p>
    </div>

    <div>
        <a href="?action=download_sql" class="px-5 py-3 rounded-xl bg-slate-900 hover:bg-black text-white text-xs font-bold shadow-lg shadow-slate-900/20 flex items-center gap-2 transition-all">
            <i class="fa-solid fa-download text-amber-400 text-sm"></i>
            <span>Unduh Cadangan Database (.sql)</span>
        </a>
    </div>
</div>

<!-- Bento KPI Database -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Nama Database MySQL</span>
        <span class="text-2xl font-black text-slate-800 font-mono"><?= e((string)$dbName) ?></span>
        <span class="text-xs text-slate-400 block mt-1">Engine: InnoDB | Charset: utf8mb4</span>
    </div>

    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Total Ukuran Penyimpanan</span>
        <span class="text-2xl font-black text-teal-700"><?= $totalSizeMb > 0 ? $totalSizeMb . ' MB' : $totalSizeKb . ' KB' ?></span>
        <span class="text-xs text-slate-400 block mt-1"><?= count($tablesInfo) ?> Tabel Terdaftar</span>
    </div>

    <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-sm">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Total Record / Data</span>
        <span class="text-2xl font-black text-indigo-700"><?= number_format($totalRows, 0, ',', '.') ?> Baris</span>
        <span class="text-xs text-slate-400 block mt-1">Terdistribusi di Seluruh Cabang</span>
    </div>
</div>

<!-- Tabel Rincian Tabel Database -->
<div class="card overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-slate-800">Daftar Tabel & Kapasitas Database</h3>
            <p class="text-[11px] text-slate-400">Rincian alokasi baris dan ukuran memori penyimpanan per tabel</p>
        </div>
        <span class="text-xs font-mono font-bold text-slate-500 bg-slate-100 px-3 py-1 rounded-lg">
            <?= count($tablesInfo) ?> TABEL
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="bg-slate-50/80 border-b border-slate-200 text-slate-600">
                    <th class="py-3 px-4 font-bold">No</th>
                    <th class="py-3 px-4 font-bold">Nama Tabel</th>
                    <th class="py-3 px-4 font-bold">Engine</th>
                    <th class="py-3 px-4 font-bold text-right">Perkiraan Baris</th>
                    <th class="py-3 px-4 font-bold text-right">Data (KB)</th>
                    <th class="py-3 px-4 font-bold text-right">Index (KB)</th>
                    <th class="py-3 px-4 font-bold text-right">Total Ukuran</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php $no = 1; foreach ($tablesInfo as $t): ?>
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="py-2.5 px-4 text-slate-400 font-mono"><?= $no++ ?></td>
                    <td class="py-2.5 px-4 font-mono font-bold text-slate-800">
                        <i class="fa-solid fa-table text-teal-600 mr-1.5 text-xs"></i>
                        <?= e($t['nama_tabel']) ?>
                    </td>
                    <td class="py-2.5 px-4 font-mono text-slate-500 text-[11px]">
                        <?= e($t['engine'] ?? 'InnoDB') ?>
                    </td>
                    <td class="py-2.5 px-4 text-right font-mono font-semibold text-slate-700">
                        <?= number_format((int)$t['jumlah_baris'], 0, ',', '.') ?>
                    </td>
                    <td class="py-2.5 px-4 text-right font-mono text-slate-500">
                        <?= number_format((float)$t['data_kb'], 2) ?> KB
                    </td>
                    <td class="py-2.5 px-4 text-right font-mono text-slate-500">
                        <?= number_format((float)$t['index_kb'], 2) ?> KB
                    </td>
                    <td class="py-2.5 px-4 text-right font-mono font-bold text-teal-800">
                        <?= number_format((float)$t['ukuran_kb'], 2) ?> KB
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-100 font-bold border-t border-slate-200">
                    <td colspan="3" class="py-3 px-4 text-slate-700">TOTAL KESELURUHAN</td>
                    <td class="py-3 px-4 text-right font-mono text-slate-900"><?= number_format($totalRows, 0, ',', '.') ?></td>
                    <td colspan="2" class="py-3 px-4 text-right text-slate-500">Akumulasi:</td>
                    <td class="py-3 px-4 text-right font-mono text-teal-900"><?= $totalSizeMb > 0 ? $totalSizeMb . ' MB' : $totalSizeKb . ' KB' ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';

