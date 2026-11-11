<?php
/**
 * SIM BISOLPIN ACADEMY - Monitoring Siswa Cabang (Direktur Cabang)
 */

declare(strict_types=1);

$pageTitle = 'Data Siswa Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id();
verify_branch_access($branchId);
$db = get_db();

// Ambil seluruh siswa di cabang ini
$stmt = $db->prepare("
    SELECT s.*, 
           l.nama_layanan, l.kode_layanan, l.jumlah_pertemuan,
           m.nama_lengkap as nama_mentor, m.no_hp as no_hp_mentor,
           (SELECT COUNT(*) FROM tabel_absensi a WHERE a.id_siswa = s.id AND a.status_kehadiran = 'hadir') as total_hadir
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    LEFT JOIN tabel_pegawai m ON m.id = s.id_mentor_pembimbing
    WHERE s.id_cabang = ?
    ORDER BY s.created_at DESC
");
$stmt->execute([$branchId]);
$siswaList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-user-graduate"></i>
            <span>Monitoring Akademik Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Data Siswa Kursus Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">
            Daftar seluruh siswa tatap muka, capaian modul KBM, dan evaluasi berkala mentor.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?= base_url('modules/direktur_cabang/laporan.php?jenis=siswa') ?>" class="btn-brand-outline text-xs py-2 px-4">
            <i class="fa-solid fa-print text-xs"></i>
            <span>Cetak Rekap Siswa</span>
        </a>
    </div>
</div>

<!-- Tabel Siswa -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>NIS & Siswa</th>
                    <th>Asal Sekolah</th>
                    <th>Program Kursus</th>
                    <th>Mentor Pembimbing</th>
                    <th>Kehadiran & Sesi</th>
                    <th>Progres Belajar</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($siswaList)): ?>
                    <?= render_empty_state(
                        'fa-user-graduate',
                        'Belum Ada Siswa Terdaftar di Cabang',
                        'Belum ada siswa aktif yang terdaftar di cabang Anda. Staff Front Office dapat mendaftarkan siswa melalui menu Pendaftaran & Data Siswa.',
                        null,
                        7
                    ) ?>
                <?php else: ?>
                    <?php foreach ($siswaList as $s): ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($s['nama_lengkap']) ?></div>
                            <div class="text-xs font-mono text-slate-400">NIS: <?= e($s['nis_bisolpin']) ?></div>
                            <div class="text-[11px] text-slate-500">WA: <?= e($s['no_hp_siswa']) ?></div>
                        </td>

                        <td>
                            <div class="font-medium text-slate-700 text-xs"><?= e($s['asal_sekolah_instansi'] ?? '-') ?></div>
                            <?php if (!empty($s['nama_orang_tua'])): ?>
                                <div class="text-[10px] text-slate-400">Wali: <?= e($s['nama_orang_tua']) ?> (<?= e($s['no_hp_orang_tua'] ?? '-') ?>)</div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong class="text-brand-700 text-xs block"><?= e($s['nama_layanan']) ?></strong>
                            <span class="text-[10px] text-slate-400 font-mono"><?= e($s['kode_layanan']) ?></span>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700"><?= e($s['nama_mentor'] ?? 'Belum Ditugaskan') ?></div>
                            <?php if (!empty($s['catatan_progres_mentor'])): ?>
                                <div class="text-[11px] text-slate-400 max-w-xs truncate italic" title="<?= e($s['catatan_progres_mentor']) ?>">
                                    "<?= e($s['catatan_progres_mentor']) ?>"
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs">
                                Sesi ke-<?= (int)$s['pertemuan_terakhir_diikuti'] ?> / <?= (int)$s['jumlah_pertemuan'] ?>
                            </div>
                            <div class="text-[11px] text-emerald-600 font-medium">
                                <?= (int)$s['total_hadir'] ?> Kali Hadir
                            </div>
                        </td>

                        <td class="w-48">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-black text-slate-700"><?= (float)$s['persentase_progres'] ?>%</span>
                                <span class="text-[10px] text-slate-400 font-semibold">Tuntas</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                                <div class="bg-brand-500 h-2 rounded-full" style="width: <?= (float)$s['persentase_progres'] ?>%"></div>
                            </div>
                            <span class="text-[10px] text-teal-800 font-bold block mt-1">Hadir <?= (int)$s['total_hadir'] ?> dari <?= (int)$s['jumlah_pertemuan'] ?> sesi</span>
                        </td>

                        <td>
                            <?= render_status_badge($s['status_belajar']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

