<?php
/**
 * SIM BISOLPIN ACADEMY - Verifikasi & Approval Kemitraan Cabang Baru (Pimpinan Pusat)
 * Tahap 1 dari Alur Registrasi Mitra:
 * Mitra Mendaftar -> Ditinjau & Disetujui Pimpinan Pusat -> Masuk ke Panel Admin Sistem untuk Aktivasi Akun.
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
ensure_database_schema_integrity($db);

// Penanganan Aksi Approval / Reject / Suspend via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action   = $_POST['action'] ?? '';
    $cabangId = (int)($_POST['cabang_id'] ?? 0);
    $catatan  = trim($_POST['catatan_review_pimpinan'] ?? '');

    // Ambil data cabang dan user direktur
    $stmtC = $db->prepare("SELECT * FROM tabel_cabang WHERE id = ?");
    $stmtC->execute([$cabangId]);
    $cabang = $stmtC->fetch();

    if (!$cabang) {
        redirect_with_message('modules/pimpinan/verifikasi_mitra.php', 'error', 'Data cabang tidak ditemukan.');
    }

    $direkturUserId = $cabang['id_direktur_cabang'];

    // 1. APPROVE TAHAP 1 (PIMPINAN PUSAT)
    // Berkas disetujui, diteruskan ke Admin Sistem untuk persetujuan teknis dan aktivasi akun login
    if ($action === 'approve') {
        try {
            $db->beginTransaction();

            if (empty($catatan)) {
                $catatan = 'Proposal MoU dan berkas legalitas kemitraan telah diverifikasi dan disetujui oleh Pimpinan Pusat. Diteruskan ke Admin Sistem untuk aktivasi akun login cabang.';
            }

            // Ubah status cabang menjadi 'menunggu_aktivasi_admin'
            $stmtUpCabang = $db->prepare("
                UPDATE tabel_cabang 
                SET status_mitra = 'menunggu_aktivasi_admin',
                    catatan_review_pimpinan = ?,
                    tanggal_review_pimpinan = NOW()
                WHERE id = ?
            ");
            $stmtUpCabang->execute([$catatan, $cabangId]);

            // Catatan: Akun login direktur TETAP belum aktif (is_active = 0) sampai Admin Sistem menyetujuinya
            if ($direkturUserId) {
                $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$direkturUserId]);
                $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'pending_review' WHERE user_id = ?")->execute([$direkturUserId]);
            }

            log_activity(
                'APPROVAL_BERKAS_MITRA_PIMPINAN',
                "Pimpinan menyetujui berkas kemitraan cabang {$cabang['nama_cabang']} ({$cabang['kode_cabang']}). Diteruskan ke Admin Sistem untuk pembuatan & aktivasi akun."
            );

            // Notifikasi ke Admin Sistem (IT) untuk aktivasi akun
            push_notification(
                'Aktivasi Akun Mitra Menunggu',
                "Mitra cabang {$cabang['nama_cabang']} telah disetujui Pimpinan. Silakan atur kode cabang dan buat akun resmi.",
                base_url('modules/admin_sistem/verifikasi_mitra.php'),
                null,
                'admin_sistem',
                null,
                'info'
            );

            $db->commit();
            set_flash(
                'success', 
                'Berkas kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> berhasil DISETUJUI oleh Pimpinan Pusat! Status dialihkan ke <strong>Admin Sistem</strong> untuk persetujuan teknis & aktivasi akun login cabang.', 
                'Tahap 1 Disetujui!'
            );
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal memproses persetujuan berkas: ' . $e->getMessage());
        }
        redirect('modules/pimpinan/verifikasi_mitra.php');

    // 2. TOLAK KEMITRAAN (REJECT)
    } elseif ($action === 'reject') {
        try {
            $db->beginTransaction();

            $alasan = !empty($catatan) ? $catatan : 'Berkas permohonan kemitraan belum memenuhi kualifikasi atau kelengkapan yang dipersyaratkan oleh Pimpinan Pusat.';

            $stmtUpCabang = $db->prepare("
                UPDATE tabel_cabang 
                SET status_mitra = 'ditolak',
                    catatan_review_pimpinan = ?,
                    tanggal_review_pimpinan = NOW()
                WHERE id = ?
            ");
            $stmtUpCabang->execute([$alasan, $cabangId]);

            if ($direkturUserId) {
                $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$direkturUserId]);
                $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE user_id = ?")->execute([$direkturUserId]);
            }

            log_activity(
                'PENOLAKAN_MITRA_PIMPINAN',
                "Pimpinan menolak permohonan kemitraan cabang {$cabang['nama_cabang']}. Alasan: {$alasan}"
            );

            $db->commit();
            set_flash('info', 'Permohonan kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> telah DITOLAK.', 'Kemitraan Ditolak');
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal memproses penolakan: ' . $e->getMessage());
        }
        redirect('modules/pimpinan/verifikasi_mitra.php');

    // 3. SUSPEND CABANG AKTIF
    } elseif ($action === 'suspend') {
        try {
            $db->beginTransaction();

            $alasanSuspend = !empty($catatan) ? $catatan : 'Cabang dinonaktifkan sementara oleh Pimpinan Pusat untuk evaluasi operasional.';

            $stmtUpCabang = $db->prepare("
                UPDATE tabel_cabang 
                SET status_mitra = 'nonaktif',
                    catatan_review_pimpinan = ?
                WHERE id = ?
            ");
            $stmtUpCabang->execute([$alasanSuspend, $cabangId]);

            if ($direkturUserId) {
                $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$direkturUserId]);
                $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE user_id = ?")->execute([$direkturUserId]);
            }

            log_activity(
                'SUSPEND_CABANG_PIMPINAN',
                "Pimpinan menonaktifkan operasional cabang {$cabang['nama_cabang']}. Alasan: {$alasanSuspend}"
            );

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

if ($filterStatus === 'pending') {
    $query .= " WHERE c.status_mitra = 'pending_review'";
} elseif ($filterStatus === 'menunggu_admin') {
    $query .= " WHERE c.status_mitra = 'menunggu_aktivasi_admin'";
} elseif ($filterStatus === 'aktif') {
    $query .= " WHERE c.status_mitra = 'aktif'";
} elseif ($filterStatus === 'lainnya') {
    $query .= " WHERE c.status_mitra IN ('ditolak', 'nonaktif')";
}

$query .= " ORDER BY 
    CASE 
        WHEN c.status_mitra = 'pending_review' THEN 1
        WHEN c.status_mitra = 'menunggu_aktivasi_admin' THEN 2
        WHEN c.status_mitra = 'aktif' THEN 3
        ELSE 4
    END, c.created_at DESC";

$stmt = $db->query($query);
$cabangList = $stmt->fetchAll();

// Hitung total untuk badge counter tab
$countPending       = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'pending_review'")->fetchColumn();
$countMenungguAdmin = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'menunggu_aktivasi_admin'")->fetchColumn();
$countAktif         = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'aktif'")->fetchColumn();
$countTotal         = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-100 text-indigo-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-handshake"></i>
            <span>Tahap 1: Verifikasi Proposal & Berkas Legalitas</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Verifikasi Kemitraan Mitra Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Tinjau proposal & kelayakan mitra. Setelah disetujui Pimpinan Pusat, pengajuan akan diteruskan ke <strong>Admin Sistem</strong> untuk pembuatan dan aktivasi akun login.
        </p>
    </div>

    <!-- Tab Filter -->
    <div class="inline-flex flex-wrap rounded-xl bg-white p-1 border border-slate-200 shadow-sm text-xs">
        <a href="?status=all" class="px-3 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'all' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Semua (<?= $countTotal ?>)
        </a>
        <a href="?status=pending" class="px-3 py-1.5 rounded-lg font-bold transition-all flex items-center gap-1.5 <?= $filterStatus === 'pending' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            <span>Menunggu Review</span>
            <?php if ($countPending > 0): ?>
                <span class="w-5 h-5 rounded-full <?= $filterStatus === 'pending' ? 'bg-white text-amber-600' : 'bg-amber-500 text-white' ?> text-[10px] flex items-center justify-center font-black">
                    <?= $countPending ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="?status=menunggu_admin" class="px-3 py-1.5 rounded-lg font-bold transition-all flex items-center gap-1.5 <?= $filterStatus === 'menunggu_admin' ? 'bg-cyan-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            <span>Diteruskan ke Admin IT</span>
            <?php if ($countMenungguAdmin > 0): ?>
                <span class="w-5 h-5 rounded-full <?= $filterStatus === 'menunggu_admin' ? 'bg-white text-cyan-700' : 'bg-cyan-600 text-white' ?> text-[10px] flex items-center justify-center font-black">
                    <?= $countMenungguAdmin ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="?status=aktif" class="px-3 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'aktif' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Aktif (<?= $countAktif ?>)
        </a>
        <a href="?status=lainnya" class="px-3 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'lainnya' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Ditolak / Nonaktif
        </a>
    </div>
</div>

<!-- Informasi Alur 2 Tahap (Workflow Banner) -->
<div class="mb-6 p-4 rounded-2xl bg-gradient-to-r from-indigo-50 via-teal-50 to-emerald-50 border border-indigo-100 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-lg shadow-sm">
            <i class="fa-solid fa-network-wired"></i>
        </div>
        <div>
            <h4 class="text-xs font-black text-slate-800 uppercase tracking-wider">Alur Pendaftaran Mitra 2 Tahap (Two-Factor Approval)</h4>
            <p class="text-[11px] text-slate-600">
                1. <strong>Mitra Mendaftar</strong> &rarr; 2. <strong>Pimpinan Pusat</strong> (Verifikasi & Approve Berkas) &rarr; 3. <strong>Admin Sistem</strong> (Persetujuan Akun & Aktivasi Login).
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2 text-xs font-bold text-slate-500 bg-white/80 px-3 py-1.5 rounded-xl border border-slate-200">
        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
        <span><?= $countPending ?> Mitra Membutuhkan Review Anda</span>
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
                    <th>Status Alur Kemitraan</th>
                    <th>Dokumen</th>
                    <th class="text-right">Aksi Pimpinan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cabangList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-10 text-slate-400 text-xs">
                            Tidak ada permohonan kemitraan pada kategori ini.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($cabangList as $item): ?>
                    <tr class="hover:bg-slate-50/70 transition-colors">
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
                            
                            <?php if ($item['status_mitra'] === 'pending_review'): ?>
                                <div class="text-[10px] text-amber-700 font-medium mt-1">
                                    <i class="fa-solid fa-clock"></i> Menunggu tinjauan Pimpinan
                                </div>
                            <?php elseif ($item['status_mitra'] === 'menunggu_aktivasi_admin'): ?>
                                <div class="text-[10px] text-cyan-700 font-semibold mt-1">
                                    <i class="fa-solid fa-arrow-right"></i> Diteruskan ke Admin IT
                                </div>
                                <?php if (!empty($item['tanggal_review_pimpinan'])): ?>
                                    <div class="text-[9px] text-slate-400">Disetujui: <?= date('d/m/Y H:i', strtotime($item['tanggal_review_pimpinan'])) ?></div>
                                <?php endif; ?>
                            <?php elseif ($item['status_mitra'] === 'aktif'): ?>
                                <div class="text-[10px] text-emerald-600 font-medium mt-1">
                                    <i class="fa-solid fa-circle-check"></i> Akun aktif & operasional
                                </div>
                                <?php if ($item['tanggal_bergabung']): ?>
                                    <div class="text-[9px] text-slate-400">Gabung: <?= format_tanggal_indonesia($item['tanggal_bergabung']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($item['catatan_review_pimpinan'])): ?>
                                <div class="mt-1 text-[10px] text-slate-500 bg-slate-50 p-1.5 rounded border border-slate-200/80 max-w-xs">
                                    <span class="font-bold text-slate-700">Catatan:</span> <?= e($item['catatan_review_pimpinan']) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($item['dokumen_kemitraan'])): ?>
                                <a href="<?= URL_UPLOAD_MITRA . e($item['dokumen_kemitraan']) ?>" target="_blank" 
                                   class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-700 font-bold text-xs border border-teal-200 transition-all">
                                    <i class="fa-solid fa-file-arrow-down"></i>
                                    <span>Unduh Berkas</span>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Tidak ada berkas</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <?php if ($item['status_mitra'] === 'pending_review'): ?>
                                    <!-- Tombol Buka Modal Setujui (Approve) -->
                                    <button type="button" 
                                            onclick="openApproveModal(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['nama_cabang'])) ?>')"
                                            class="px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs shadow-sm shadow-emerald-500/20 transition-all flex items-center gap-1">
                                        <i class="fa-solid fa-check"></i>
                                        <span>Setujui Berkas</span>
                                    </button>

                                    <!-- Tombol Buka Modal Tolak (Reject) -->
                                    <button type="button" 
                                            onclick="openRejectModal(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['nama_cabang'])) ?>')"
                                            class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs border border-rose-200 transition-all">
                                        <i class="fa-solid fa-xmark"></i>
                                        <span>Tolak</span>
                                    </button>

                                <?php elseif ($item['status_mitra'] === 'menunggu_aktivasi_admin'): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-cyan-50 text-cyan-800 text-xs font-bold border border-cyan-200">
                                        <i class="fa-solid fa-hourglass-half text-cyan-600"></i>
                                        <span>Menunggu Admin IT</span>
                                    </span>

                                <?php elseif ($item['status_mitra'] === 'aktif'): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENONAKTIFKAN operasional cabang ini sementara waktu?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold text-xs border border-amber-200 transition-all">
                                            <i class="fa-solid fa-pause"></i>
                                            <span>Nonaktifkan</span>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <!-- Jika Ditolak/Nonaktif, bisa diajukan review kembali -->
                                    <button type="button" 
                                            onclick="openApproveModal(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['nama_cabang'])) ?>')"
                                            class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-emerald-50 text-slate-700 hover:text-emerald-700 font-bold text-xs border border-slate-200 transition-all">
                                        <i class="fa-solid fa-rotate-left"></i>
                                        <span>Review Ulang</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Dialog Persetujuan Berkas (Approve Tahap 1) -->
<div id="modalApprove" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h3 class="font-black text-slate-800 text-base">Persetujuan Berkas Kemitraan (Tahap 1)</h3>
            </div>
            <button type="button" onclick="closeApproveModal()" class="text-slate-400 hover:text-slate-600 text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="cabang_id" id="approve_cabang_id" value="">

            <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-100 mb-4">
                <p class="text-xs text-emerald-900 font-medium">
                    Anda akan menyetujui dokumen & proposal kemitraan cabang <strong id="approve_cabang_nama">-</strong>.
                </p>
                <p class="text-[11px] text-emerald-700 mt-1">
                    <i class="fa-solid fa-circle-info mr-1"></i> Setelah disetujui, pengajuan akan masuk ke <strong>Panel Admin Sistem</strong> untuk verifikasi teknis dan pembuatan/aktivasi akun login Direktur Cabang.
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold text-slate-700 mb-1">Catatan / Rekomendasi Pimpinan Pusat</label>
                <textarea name="catatan_review_pimpinan" rows="3" class="form-input text-xs w-full" placeholder="Contoh: Proposal MoU dan kelayakan lokasi telah diverifikasi. Rekomendasi disetujui untuk aktivasi sistem."></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeApproveModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-bold transition-all">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-right"></i>
                    <span>Setujui & Teruskan ke Admin Sistem</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Dialog Penolakan Berkas (Reject) -->
<div id="modalReject" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-700 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-xmark"></i>
                </div>
                <h3 class="font-black text-slate-800 text-base">Tolak Permohonan Kemitraan</h3>
            </div>
            <button type="button" onclick="closeRejectModal()" class="text-slate-400 hover:text-slate-600 text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="cabang_id" id="reject_cabang_id" value="">

            <div class="p-3 bg-rose-50 rounded-xl border border-rose-100 mb-4">
                <p class="text-xs text-rose-900 font-medium">
                    Anda akan menolak permohonan kemitraan cabang <strong id="reject_cabang_nama">-</strong>.
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold text-slate-700 mb-1">Alasan Penolakan <span class="text-rose-500">*</span></label>
                <textarea name="catatan_review_pimpinan" required rows="3" class="form-input text-xs w-full" placeholder="Jelaskan alasan penolakan (misal: dokumen legalitas belum lengkap, zonasi cabang terlalu dekat dengan cabang lain, dsb.)"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeRejectModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-bold transition-all">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-md shadow-rose-600/20 transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-ban"></i>
                    <span>Tolak Kemitraan</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(id, nama) {
    document.getElementById('approve_cabang_id').value = id;
    document.getElementById('approve_cabang_nama').textContent = nama;
    document.getElementById('modalApprove').classList.remove('hidden');
}

function closeApproveModal() {
    document.getElementById('modalApprove').classList.add('hidden');
}

function openRejectModal(id, nama) {
    document.getElementById('reject_cabang_id').value = id;
    document.getElementById('reject_cabang_nama').textContent = nama;
    document.getElementById('modalReject').classList.remove('hidden');
}

function closeRejectModal() {
    document.getElementById('modalReject').classList.add('hidden');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
