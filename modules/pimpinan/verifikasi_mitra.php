<?php
/**
 * SIM BISOLPIN ACADEMY - Verifikasi & Approval Kemitraan Cabang Baru (Pimpinan Pusat)
 */

declare(strict_types=1);

$pageTitle = 'Verifikasi Kemitraan Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Pimpinan Pusat
check_role(ROLE_PIMPINAN);

$db = get_db();

// Penanganan Aksi Approval / Reject / Suspend via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action   = $_POST['action'] ?? '';
    $cabangId = (int)($_POST['cabang_id'] ?? 0);

    // Ambil data cabang dan user direktur
    $stmtC = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
    $stmtC->execute([$cabangId]);
    $cabang = $stmtC->fetch();

    if (!$cabang) {
        redirect_with_message('modules/pimpinan/verifikasi_mitra.php', 'error', 'Data cabang tidak ditemukan.');
    }

    $direkturUserId = $cabang['id_direktur_cabang'];

    if ($action === 'approve') {
        try {
            $db->beginTransaction();

            // 1. Ubah status cabang menjadi aktif
            $stmtUpCabang = $db->prepare("UPDATE tabel_cabang SET status_mitra = 'aktif', tanggal_bergabung = COALESCE(tanggal_bergabung, CURDATE()) WHERE id = ?");
            $stmtUpCabang->execute([$cabangId]);

            // 2. Aktifkan akun login pengguna direktur cabang
            if ($direkturUserId) {
                $stmtUpUser = $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
                $stmtUpUser->execute([$direkturUserId]);

                // 3. Aktifkan status pegawai
                $stmtUpPegawai = $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'aktif' WHERE user_id = ?");
                $stmtUpPegawai->execute([$direkturUserId]);
            }

            $db->commit();
            set_flash('success', 'Permohonan kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> berhasil DISETUJUI! Akun Direktur Cabang kini aktif dan dapat mengelola sistem cabangnya.', 'Kemitraan Aktif!');
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal menyetujui kemitraan: ' . $e->getMessage());
        }
        redirect('modules/pimpinan/verifikasi_mitra.php');

    } elseif ($action === 'reject') {
        try {
            $db->beginTransaction();

            $stmtUpCabang = $db->prepare("UPDATE tabel_cabang SET status_mitra = 'ditolak' WHERE id = ?");
            $stmtUpCabang->execute([$cabangId]);

            if ($direkturUserId) {
                $stmtUpUser = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                $stmtUpUser->execute([$direkturUserId]);

                $stmtUpPegawai = $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE user_id = ?");
                $stmtUpPegawai->execute([$direkturUserId]);
            }

            $db->commit();
            set_flash('info', 'Permohonan kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> telah ditolak.', 'Status Diperbarui');
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal memproses penolakan: ' . $e->getMessage());
        }
        redirect('modules/pimpinan/verifikasi_mitra.php');

    } elseif ($action === 'suspend') {
        try {
            $db->beginTransaction();

            $stmtUpCabang = $db->prepare("UPDATE tabel_cabang SET status_mitra = 'nonaktif' WHERE id = ?");
            $stmtUpCabang->execute([$cabangId]);

            if ($direkturUserId) {
                $stmtUpUser = $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                $stmtUpUser->execute([$direkturUserId]);

                $stmtUpPegawai = $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE user_id = ?");
                $stmtUpPegawai->execute([$direkturUserId]);
            }

            $db->commit();
            set_flash('warning', 'Kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> telah dinonaktifkan sementara.', 'Cabang Dinonaktifkan');
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal menonaktifkan cabang: ' . $e->getMessage());
        }
        redirect('modules/pimpinan/verifikasi_mitra.php');
    }
}

// Filter Tab
$filterStatus = $_GET['status'] ?? 'all';
$query = "
    SELECT c.*, u.username, u.email as email_direktur, u.is_active as user_active,
           p.nama_lengkap as nama_direktur, p.no_hp as no_hp_direktur, p.nip_pegawai
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
";
$params = [];

if ($filterStatus === 'pending') {
    $query .= " WHERE c.status_mitra = 'pending_review'";
} elseif ($filterStatus === 'aktif') {
    $query .= " WHERE c.status_mitra = 'aktif'";
} elseif ($filterStatus === 'lainnya') {
    $query .= " WHERE c.status_mitra IN ('ditolak', 'nonaktif')";
}

$query .= " ORDER BY CASE WHEN c.status_mitra = 'pending_review' THEN 1 ELSE 2 END, c.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$cabangList = $stmt->fetchAll();

// Hitung total untuk badge counter tab
$countPending = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'pending_review'")->fetchColumn();
$countAktif   = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'aktif'")->fetchColumn();
$countTotal   = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 text-indigo-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-handshake"></i>
            <span>Verifikasi Lisensi & Kemitraan</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Verifikasi Pendaftaran Mitra Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Tinjau proposal, berkas legalitas, dan berikan persetujuan aktivasi akun Direktur Cabang baru.
        </p>
    </div>

    <!-- Tab Filter -->
    <div class="inline-flex rounded-xl bg-white p-1 border border-slate-200 shadow-sm text-xs">
        <a href="?status=all" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'all' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Semua (<?= $countTotal ?>)
        </a>
        <a href="?status=pending" class="px-3.5 py-1.5 rounded-lg font-bold transition-all flex items-center gap-1.5 <?= $filterStatus === 'pending' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            <span>Pending</span>
            <?php if ($countPending > 0): ?>
                <span class="w-5 h-5 rounded-full bg-amber-500 text-white text-[10px] flex items-center justify-center font-black">
                    <?= $countPending ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="?status=aktif" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'aktif' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Aktif (<?= $countAktif ?>)
        </a>
        <a href="?status=lainnya" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'lainnya' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Ditolak / Nonaktif
        </a>
    </div>
</div>

<!-- Tabel Daftar Mitra -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Cabang</th>
                    <th>Calon Direktur Cabang</th>
                    <th>Wilayah & Kontak</th>
                    <th>Status Lisensi</th>
                    <th>Dokumen</th>
                    <th class="text-right">Aksi Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cabangList as $item): ?>
                    <tr class="<?= isset($_GET['highlight']) && (int)$_GET['highlight'] === (int)$item['id'] ? 'bg-amber-50/70 font-semibold' : '' ?>">
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($item['nama_cabang']) ?></div>
                            <div class="text-xs text-slate-400 font-mono">Kode: <?= e($item['kode_cabang']) ?></div>
                            <?php if (!empty($item['nomor_izin_mitra'])): ?>
                                <div class="text-[11px] text-teal-700 font-medium">Izin: <?= e($item['nomor_izin_mitra']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs"><?= e($item['nama_direktur'] ?? $item['username'] ?? '-') ?></div>
                            <div class="text-xs text-slate-500"><?= e($item['email_direktur'] ?? $item['email_cabang']) ?></div>
                            <div class="text-xs text-slate-400 font-mono"><?= e($item['no_hp_direktur'] ?? $item['telepon']) ?></div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($item['kota']) ?>, <?= e($item['provinsi']) ?></div>
                            <div class="text-[11px] text-slate-400 max-w-xs truncate" title="<?= e($item['alamat']) ?>">
                                <?= e($item['alamat']) ?>
                            </div>
                        </td>

                        <td>
                            <?= render_status_badge($item['status_mitra']) ?>
                            <?php if ($item['tanggal_bergabung']): ?>
                                <div class="text-[10px] text-slate-400 mt-1">Gabung: <?= format_tanggal_indonesia($item['tanggal_bergabung']) ?></div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($item['dokumen_kemitraan'])): ?>
                                <a href="<?= URL_UPLOAD_MITRA . e($item['dokumen_kemitraan']) ?>" target="_blank" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-700 font-bold text-xs border border-teal-200 transition-all">
                                    <i class="fa-solid fa-file-arrow-down"></i>
                                    <span>Unduh Dokumen</span>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Tidak ada berkas</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <?php if ($item['status_mitra'] === 'pending_review'): ?>
                                    <!-- Form Setujui (Approve) -->
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENYETUJUI kemitraan cabang ini? Akun Direktur Cabang akan langsung diaktifkan.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-sm shadow-emerald-500/20 transition-all flex items-center gap-1">
                                            <i class="fa-solid fa-check"></i>
                                            <span>Setujui (Aktifkan)</span>
                                        </button>
                                    </form>

                                    <!-- Form Tolak (Reject) -->
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENOLAK permohonan kemitraan ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs border border-rose-200 transition-all">
                                            <i class="fa-solid fa-xmark"></i>
                                            <span>Tolak</span>
                                        </button>
                                    </form>

                                <?php elseif ($item['status_mitra'] === 'aktif'): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENONAKTIFKAN cabang ini sementara waktu?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold text-xs border border-amber-200 transition-all">
                                            <i class="fa-solid fa-pause"></i>
                                            <span>Nonaktifkan</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Jika Ditolak/Nonaktif, bisa diaktifkan kembali -->
                                    <form method="POST" action="" onsubmit="return confirm('Aktifkan kembali kemitraan cabang ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-emerald-50 text-slate-700 hover:text-emerald-700 font-bold text-xs border border-slate-200 transition-all">
                                            <i class="fa-solid fa-rotate-left"></i>
                                            <span>Aktifkan Kembali</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

