<?php
/**
 * SIM BISOLPIN ACADEMY - Persetujuan Pembuatan & Aktivasi Akun Mitra Cabang (Admin Sistem)
 * Tahap 2 dari Alur Registrasi Mitra:
 * Mitra Mendaftar -> Ditinjau & Disetujui Pimpinan Pusat -> Disetujui Pembuatan & Aktivasi Akun oleh Admin Sistem.
 */

declare(strict_types=1);

$pageTitle = 'Persetujuan Akun Mitra Cabang';

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

// Penanganan Aksi via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action   = $_POST['action'] ?? '';
    $cabangId = (int)($_POST['cabang_id'] ?? 0);

    // Ambil data cabang dan user direktur
    $stmtC = $db->prepare("
        SELECT c.*, u.username, u.email as email_direktur, u.id as user_id,
               p.nama_lengkap as nama_direktur, p.nip_pegawai
        FROM tabel_cabang c
        LEFT JOIN users u ON u.id = c.id_direktur_cabang
        LEFT JOIN tabel_pegawai p ON p.user_id = u.id
        WHERE c.id = ?
    ");
    $stmtC->execute([$cabangId]);
    $cabang = $stmtC->fetch();

    if (!$cabang) {
        redirect_with_message('modules/admin_sistem/verifikasi_mitra.php', 'error', 'Data cabang tidak ditemukan.');
    }

    $direkturUserId = (int)($cabang['user_id'] ?? 0);

    // 1. APPROVE PEMBUATAN AKUN & AKTIVASI LOGIN (ADMIN SISTEM)
    if ($action === 'approve_akun') {
        $kodeCabangOfficial = trim($_POST['kode_cabang'] ?? $cabang['kode_cabang']);
        $usernameOfficial   = trim($_POST['username'] ?? $cabang['username'] ?? '');
        $emailOfficial      = trim($_POST['email'] ?? $cabang['email_direktur'] ?? $cabang['email_cabang']);
        $catatanAdmin       = trim($_POST['catatan_admin_sistem'] ?? '');

        if (empty($kodeCabangOfficial) || empty($usernameOfficial) || empty($emailOfficial)) {
            redirect_with_message('modules/admin_sistem/verifikasi_mitra.php', 'error', 'Kode Cabang, Username, dan Email resmi wajib diisi.');
        }

        // Cek keunikan kode cabang jika diubah
        if ($kodeCabangOfficial !== $cabang['kode_cabang']) {
            $stmtCekKode = $db->prepare("SELECT COUNT(*) FROM tabel_cabang WHERE kode_cabang = ? AND id != ?");
            $stmtCekKode->execute([$kodeCabangOfficial, $cabangId]);
            if ((int)$stmtCekKode->fetchColumn() > 0) {
                redirect_with_message('modules/admin_sistem/verifikasi_mitra.php', 'error', "Kode Cabang {$kodeCabangOfficial} sudah digunakan cabang lain.");
            }
        }

        // Cek keunikan username/email user direktur
        if ($direkturUserId > 0) {
            $stmtCekUser = $db->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmtCekUser->execute([$usernameOfficial, $emailOfficial, $direkturUserId]);
            if ((int)$stmtCekUser->fetchColumn() > 0) {
                redirect_with_message('modules/admin_sistem/verifikasi_mitra.php', 'error', "Username atau Email tersebut sudah digunakan oleh akun lain di sistem.");
            }
        }

        try {
            $db->beginTransaction();

            if (empty($catatanAdmin)) {
                $catatanAdmin = 'Akun Direktur Cabang dan parameter teknis cabang telah diverifikasi dan diaktifkan oleh Admin Sistem.';
            }

            // 1. Update tabel_cabang menjadi 'aktif'
            $stmtUpCabang = $db->prepare("
                UPDATE tabel_cabang 
                SET status_mitra = 'aktif',
                    kode_cabang = ?,
                    tanggal_bergabung = COALESCE(tanggal_bergabung, CURDATE()),
                    catatan_admin_sistem = ?,
                    tanggal_aktivasi_admin = NOW()
                WHERE id = ?
            ");
            $stmtUpCabang->execute([$kodeCabangOfficial, $catatanAdmin, $cabangId]);

            // 2. Aktifkan akun pengguna login (users.is_active = 1)
            if ($direkturUserId > 0) {
                $stmtUpUser = $db->prepare("
                    UPDATE users 
                    SET username = ?,
                        email = ?,
                        is_active = 1 
                    WHERE id = ?
                ");
                $stmtUpUser->execute([$usernameOfficial, $emailOfficial, $direkturUserId]);

                // 3. Aktifkan status kepegawaian direktur
                $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'aktif' WHERE user_id = ?")->execute([$direkturUserId]);
            }

            // Catat audit trail
            log_activity(
                'AKTIVASI_AKUN_MITRA',
                "Admin Sistem menyetujui pembuatan akun & mengaktifkan cabang: {$cabang['nama_cabang']} (Kode: {$kodeCabangOfficial}, User: {$usernameOfficial})"
            );

            // Notifikasi ke Pimpinan Pusat
            push_notification(
                'Cabang Mitra Resmi Beroperasi 🎉',
                "Cabang {$cabang['nama_cabang']} ({$kodeCabangOfficial}) telah resmi diaktifkan oleh Admin Sistem dan siap beroperasi.",
                base_url('modules/pimpinan/monitoring_cabang.php?switch_branch=' . $cabangId),
                null,
                'pimpinan',
                null,
                'success'
            );

            $db->commit();
            set_flash(
                'success',
                'Pembuatan akun kemitraan cabang <strong>' . e($cabang['nama_cabang']) . '</strong> berhasil DISETUJUI & DIAKTIFKAN! Mitra kini sudah dapat login menggunakan akun <strong>' . e($usernameOfficial) . '</strong>.',
                'Akun Mitra Resmi Aktif!'
            );
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal memproses aktivasi akun: ' . $e->getMessage());
        }
        redirect('modules/admin_sistem/verifikasi_mitra.php');

    // 2. TOLAK / NONAKTIFKAN AKUN OLEH ADMIN SISTEM
    } elseif ($action === 'suspend_akun') {
        try {
            $db->beginTransaction();

            $stmtUpCabang = $db->prepare("UPDATE tabel_cabang SET status_mitra = 'nonaktif' WHERE id = ?");
            $stmtUpCabang->execute([$cabangId]);

            if ($direkturUserId > 0) {
                $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$direkturUserId]);
                $db->prepare("UPDATE tabel_pegawai SET status_aktif = 'nonaktif' WHERE user_id = ?")->execute([$direkturUserId]);
            }

            log_activity(
                'SUSPEND_AKUN_MITRA',
                "Admin Sistem menonaktifkan akun login & akses cabang {$cabang['nama_cabang']}"
            );

            $db->commit();
            set_flash('warning', 'Akun login cabang <strong>' . e($cabang['nama_cabang']) . '</strong> telah dinonaktifkan.', 'Akun Dinonaktifkan');
        } catch (\Throwable $e) {
            $db->rollBack();
            set_flash('error', 'Gagal menonaktifkan akun: ' . $e->getMessage());
        }
        redirect('modules/admin_sistem/verifikasi_mitra.php');
    }
}

// Filter Tab
$filterStatus = $_GET['status'] ?? 'menunggu_admin';
$query = "
    SELECT c.*, u.username, u.email as email_direktur, u.is_active as user_active,
           p.nama_lengkap as nama_direktur, p.no_hp as no_hp_direktur, p.nip_pegawai
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
";

if ($filterStatus === 'menunggu_admin') {
    $query .= " WHERE c.status_mitra = 'menunggu_aktivasi_admin'";
} elseif ($filterStatus === 'aktif') {
    $query .= " WHERE c.status_mitra = 'aktif'";
} elseif ($filterStatus === 'pending') {
    $query .= " WHERE c.status_mitra = 'pending_review'";
} elseif ($filterStatus === 'lainnya') {
    $query .= " WHERE c.status_mitra IN ('ditolak', 'nonaktif')";
}

$query .= " ORDER BY 
    CASE 
        WHEN c.status_mitra = 'menunggu_aktivasi_admin' THEN 1
        WHEN c.status_mitra = 'pending_review' THEN 2
        WHEN c.status_mitra = 'aktif' THEN 3
        ELSE 4
    END, c.created_at DESC";

$cabangList = $db->query($query)->fetchAll();

// Hitung total untuk badge counter tab
$countMenungguAdmin = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'menunggu_aktivasi_admin'")->fetchColumn();
$countPending       = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'pending_review'")->fetchColumn();
$countAktif         = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang WHERE status_mitra = 'aktif'")->fetchColumn();
$countTotal         = (int)$db->query("SELECT COUNT(*) FROM tabel_cabang")->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-user-gear"></i>
            <span>Tahap 2: Persetujuan Pembuatan & Aktivasi Akun Mitra</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Persetujuan Akun Mitra Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Permohonan mitra yang telah disetujui Pimpinan Pusat masuk ke antrean ini. Verifikasi parameter akun dan setujui pembuatan akun login resmi cabang.
        </p>
    </div>

    <!-- Tab Filter -->
    <div class="inline-flex flex-wrap rounded-xl bg-white p-1 border border-slate-200 shadow-sm text-xs">
        <a href="?status=menunggu_admin" class="px-3.5 py-1.5 rounded-lg font-bold transition-all flex items-center gap-1.5 <?= $filterStatus === 'menunggu_admin' ? 'bg-cyan-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            <span>Menunggu Persetujuan Akun</span>
            <?php if ($countMenungguAdmin > 0): ?>
                <span class="w-5 h-5 rounded-full <?= $filterStatus === 'menunggu_admin' ? 'bg-white text-cyan-700' : 'bg-cyan-600 text-white' ?> text-[10px] flex items-center justify-center font-black animate-pulse">
                    <?= $countMenungguAdmin ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="?status=aktif" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'aktif' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Akun Aktif (<?= $countAktif ?>)
        </a>
        <a href="?status=pending" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'pending' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Menunggu Pimpinan (<?= $countPending ?>)
        </a>
        <a href="?status=all" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'all' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Semua (<?= $countTotal ?>)
        </a>
    </div>
</div>

<!-- Antrean Approval Banner -->
<?php if ($countMenungguAdmin > 0): ?>
<div class="mb-6 p-4 rounded-2xl bg-cyan-50 border border-cyan-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-cyan-600 text-white flex items-center justify-center text-lg shadow-sm">
            <i class="fa-solid fa-bell animate-bounce"></i>
        </div>
        <div>
            <h4 class="text-xs font-black text-cyan-950 uppercase tracking-wider">Antrean Persetujuan Akun Baru (<?= $countMenungguAdmin ?> Cabang)</h4>
            <p class="text-[11px] text-cyan-800">
                Pimpinan Pusat telah menyetujui berkas legalitas cabang berikut. Silakan konfirmasi kode cabang resmi & klik tombol <strong>"Setujui & Buat Akun"</strong> untuk mengaktifkan portal.
            </p>
        </div>
    </div>
    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-black bg-cyan-600 text-white shadow-sm">
        Siap Diaktivasi
    </span>
</div>
<?php endif; ?>

<!-- Tabel Daftar Persetujuan Akun -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Cabang</th>
                    <th>Calon Direktur Cabang</th>
                    <th>Wilayah & Kontak</th>
                    <th>Review Pimpinan Pusat</th>
                    <th>Status Akun</th>
                    <th class="text-right">Aksi Admin Sistem</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($cabangList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-10 text-slate-400 text-xs">
                            Tidak ada data mitra cabang pada filter ini.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($cabangList as $item): ?>
                    <tr class="hover:bg-slate-50/70 transition-colors">
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($item['nama_cabang']) ?></div>
                            <div class="text-xs font-mono text-cyan-700 font-bold">Kode: <?= e($item['kode_cabang']) ?></div>
                            <?php if (!empty($item['nomor_izin_mitra'])): ?>
                                <div class="text-[11px] text-slate-400">Izin: <?= e($item['nomor_izin_mitra']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['dokumen_kemitraan'])): ?>
                                <div class="mt-1">
                                    <a href="<?= URL_UPLOAD_MITRA . e($item['dokumen_kemitraan']) ?>" target="_blank" 
                                       class="text-[10px] text-teal-700 hover:underline font-bold inline-flex items-center gap-1">
                                        <i class="fa-solid fa-file-pdf"></i> Lihat Berkas MoU
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs"><?= e($item['nama_direktur'] ?? $item['username'] ?? '-') ?></div>
                            <div class="text-xs text-slate-500 font-mono">User: <strong><?= e($item['username'] ?? '-') ?></strong></div>
                            <div class="text-xs text-slate-500"><?= e($item['email_direktur'] ?? $item['email_cabang']) ?></div>
                            <div class="text-[11px] text-slate-400 font-mono"><?= e($item['no_hp_direktur'] ?? $item['telepon']) ?></div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($item['kota']) ?>, <?= e($item['provinsi']) ?></div>
                            <div class="text-[11px] text-slate-400 max-w-xs truncate" title="<?= e($item['alamat']) ?>">
                                <?= e($item['alamat']) ?>
                            </div>
                        </td>

                        <td>
                            <?php if (!empty($item['tanggal_review_pimpinan'])): ?>
                                <div class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                    <i class="fa-solid fa-stamp"></i> Disetujui Pimpinan
                                </div>
                                <div class="text-[10px] text-slate-400 mt-1">
                                    <?= date('d/m/Y H:i', strtotime($item['tanggal_review_pimpinan'])) ?>
                                </div>
                                <?php if (!empty($item['catatan_review_pimpinan'])): ?>
                                    <div class="mt-1 text-[10px] text-slate-600 bg-slate-50 p-1.5 rounded border border-slate-200/80 max-w-xs">
                                        <?= e($item['catatan_review_pimpinan']) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Belum ada review Pimpinan</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= render_status_badge($item['status_mitra']) ?>
                            
                            <?php if ($item['status_mitra'] === 'menunggu_aktivasi_admin'): ?>
                                <div class="text-[10px] text-cyan-800 font-bold mt-1">
                                    <i class="fa-solid fa-lock"></i> Akun Belum Aktif
                                </div>
                            <?php elseif ($item['status_mitra'] === 'aktif'): ?>
                                <div class="text-[10px] text-emerald-700 font-bold mt-1">
                                    <i class="fa-solid fa-lock-open"></i> Login Aktif
                                </div>
                                <?php if (!empty($item['tanggal_aktivasi_admin'])): ?>
                                    <div class="text-[9px] text-slate-400">Diaktivasi: <?= date('d/m/Y H:i', strtotime($item['tanggal_aktivasi_admin'])) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <?php if ($item['status_mitra'] === 'menunggu_aktivasi_admin'): ?>
                                    <!-- Tombol Buka Modal Setujui Pembuatan Akun -->
                                    <button type="button" 
                                            onclick="openApproveAkunModal(
                                                <?= (int)$item['id'] ?>, 
                                                '<?= e(addslashes($item['nama_cabang'])) ?>',
                                                '<?= e(addslashes($item['kode_cabang'])) ?>',
                                                '<?= e(addslashes($item['username'] ?? '')) ?>',
                                                '<?= e(addslashes($item['email_direktur'] ?? $item['email_cabang'])) ?>',
                                                '<?= e(addslashes($item['catatan_review_pimpinan'] ?? '')) ?>'
                                            )"
                                            class="px-3.5 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white font-bold text-xs shadow-sm shadow-cyan-600/20 transition-all flex items-center gap-1.5">
                                        <i class="fa-solid fa-user-check"></i>
                                        <span>Setujui & Buat Akun</span>
                                    </button>

                                <?php elseif ($item['status_mitra'] === 'aktif'): ?>
                                    <form method="POST" action="" onsubmit="return confirm('Apakah Anda yakin ingin MENONAKTIFKAN akun login cabang ini?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="suspend_akun">
                                        <input type="hidden" name="cabang_id" value="<?= (int)$item['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold text-xs border border-amber-200 transition-all">
                                            <i class="fa-solid fa-user-lock"></i>
                                            <span>Nonaktifkan Akun</span>
                                        </button>
                                    </form>

                                <?php elseif ($item['status_mitra'] === 'pending_review'): ?>
                                    <span class="text-xs text-slate-400 italic">
                                        Menunggu Pimpinan
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Dialog Persetujuan Akun & Aktivasi (Admin Sistem) -->
<div id="modalApproveAkun" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 animate-in fade-in zoom-in-95 duration-150">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center font-bold">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <h3 class="font-black text-slate-800 text-base">Persetujuan Pembuatan Akun Mitra</h3>
            </div>
            <button type="button" onclick="closeApproveAkunModal()" class="text-slate-400 hover:text-slate-600 text-sm">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_akun">
            <input type="hidden" name="cabang_id" id="modal_cabang_id" value="">

            <div class="p-3 bg-cyan-50 rounded-xl border border-cyan-100 mb-4">
                <p class="text-xs text-cyan-950 font-medium">
                    Cabang: <strong id="modal_cabang_nama">-</strong>
                </p>
                <div id="modal_catatan_pimpinan_wrap" class="mt-2 text-[11px] text-cyan-900 bg-white/70 p-2 rounded border border-cyan-200">
                    <span class="font-bold">Catatan Pimpinan:</span> <span id="modal_catatan_pimpinan">-</span>
                </div>
            </div>

            <div class="space-y-3 mb-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kode Cabang Resmi <span class="text-rose-500">*</span></label>
                    <input type="text" name="kode_cabang" id="modal_kode_cabang" required class="form-input text-xs font-mono font-bold w-full uppercase" placeholder="Contoh: CBG-SBY-03">
                    <span class="text-[10px] text-slate-400">Kode unik cabang yang digunakan sebagai identifier resmi di seluruh sistem.</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Username Login Direktur <span class="text-rose-500">*</span></label>
                        <input type="text" name="username" id="modal_username" required class="form-input text-xs font-mono w-full" placeholder="username_cabang">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Email Resmi Login <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" id="modal_email" required class="form-input text-xs w-full" placeholder="cabang@bisolpin.com">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Konfigurasi Admin Sistem (Opsional)</label>
                    <textarea name="catatan_admin_sistem" rows="2" class="form-input text-xs w-full" placeholder="Contoh: Akun telah diverifikasi dan alokasi tenant cabang siap digunakan."></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeApproveAkunModal()" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-bold transition-all">
                    Batal
                </button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-xs font-bold shadow-md shadow-cyan-600/20 transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Setujui & Aktifkan Akun Sekarang</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveAkunModal(id, nama, kode, username, email, catatanPimpinan) {
    document.getElementById('modal_cabang_id').value = id;
    document.getElementById('modal_cabang_nama').textContent = nama;
    document.getElementById('modal_kode_cabang').value = kode;
    document.getElementById('modal_username').value = username;
    document.getElementById('modal_email').value = email;
    
    var catatanWrap = document.getElementById('modal_catatan_pimpinan_wrap');
    var catatanText = document.getElementById('modal_catatan_pimpinan');
    if (catatanPimpinan && catatanPimpinan.trim() !== '') {
        catatanText.textContent = catatanPimpinan;
        catatanWrap.classList.remove('hidden');
    } else {
        catatanWrap.classList.add('hidden');
    }

    document.getElementById('modalApproveAkun').classList.remove('hidden');
}

function closeApproveAkunModal() {
    document.getElementById('modalApproveAkun').classList.add('hidden');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

