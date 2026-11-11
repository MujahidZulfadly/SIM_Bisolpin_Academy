<?php
/**
 * SIM BISOLPIN ACADEMY - Plotting Jadwal Kelas Tatap Muka & Deteksi Anti-Bentrok
 */

declare(strict_types=1);

$pageTitle = 'Plotting Jadwal Kelas Offline';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role STAFF
check_role(ROLE_STAFF);

$db = get_db();
$branchId = get_active_branch_id() ?? 1;

// PROSES POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH JADWAL BARU
    if ($action === 'tambah_jadwal') {
        $idLayanan = (int)($_POST['id_layanan'] ?? 0);
        $idMentor = (int)($_POST['id_mentor'] ?? 0);
        $idRuangan = !empty($_POST['id_aset_ruangan']) ? (int)$_POST['id_aset_ruangan'] : null;
        $hari = trim($_POST['hari'] ?? '');
        $jamMulai = trim($_POST['jam_mulai'] ?? '');
        $jamSelesai = trim($_POST['jam_selesai'] ?? '');
        $kuotaSiswa = (int)($_POST['kuota_siswa'] ?? 15);

        if (empty($idLayanan) || empty($idMentor) || empty($hari) || empty($jamMulai) || empty($jamSelesai)) {
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Mohon lengkapi seluruh field wajib (Layanan, Mentor, Hari, Jam Mulai & Selesai).', 'Validasi Gagal');
        }

        if (strtotime($jamSelesai) <= strtotime($jamMulai)) {
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Jam selesai kelas harus lebih akhir daripada jam mulai.', 'Waktu Tidak Valid');
        }

        // =========================================================================
        // FITUR VALIDASI ANTI-BENTROK (CONFLICT DETECTION)
        // =========================================================================

        // 1. Cek Bentrok Mentor: Apakah mentor yang sama sudah mengajar di jam beririsan pada hari tersebut?
        $stmtCekMentor = $db->prepare("
            SELECT j.*, l.nama_layanan, p.nama_lengkap as nama_mentor
            FROM jadwal_kelas j
            JOIN tabel_layanan l ON l.id = j.id_layanan
            JOIN tabel_pegawai p ON p.id = j.id_mentor
            WHERE j.id_cabang = ? AND j.id_mentor = ? AND j.hari = ? AND j.status = 'aktif'
              AND (j.jam_mulai < ? AND j.jam_selesai > ?)
        ");
        $stmtCekMentor->execute([$branchId, $idMentor, $hari, $jamSelesai, $jamMulai]);
        $bentrokMentor = $stmtCekMentor->fetch();

        if ($bentrokMentor) {
            $jamBentrok = date('H:i', strtotime($bentrokMentor['jam_mulai'])) . ' - ' . date('H:i', strtotime($bentrokMentor['jam_selesai']));
            redirect_with_message(
                'modules/staff/jadwal_plotting.php',
                'error',
                "BENTROK MENTOR: {$bentrokMentor['nama_mentor']} sudah terjadwal mengajar kelas '{$bentrokMentor['nama_layanan']}' pada hari {$hari} pukul {$jamBentrok}.",
                'Jadwal Bentrok!'
            );
        }

        // 2. Cek Bentrok Ruangan Fisik: Apakah ruang kelas yang sama sudah dipakai di jam beririsan pada hari tersebut?
        if (!empty($idRuangan)) {
            $stmtCekRuang = $db->prepare("
                SELECT j.*, l.nama_layanan, a.nama_aset as nama_ruangan
                FROM jadwal_kelas j
                JOIN tabel_layanan l ON l.id = j.id_layanan
                JOIN tabel_aset a ON a.id = j.id_aset_ruangan
                WHERE j.id_cabang = ? AND j.id_aset_ruangan = ? AND j.hari = ? AND j.status = 'aktif'
                  AND (j.jam_mulai < ? AND j.jam_selesai > ?)
            ");
            $stmtCekRuang->execute([$branchId, $idRuangan, $hari, $jamSelesai, $jamMulai]);
            $bentrokRuang = $stmtCekRuang->fetch();

            if ($bentrokRuang) {
                $jamBentrok = date('H:i', strtotime($bentrokRuang['jam_mulai'])) . ' - ' . date('H:i', strtotime($bentrokRuang['jam_selesai']));
                redirect_with_message(
                    'modules/staff/jadwal_plotting.php',
                    'error',
                    "BENTROK RUANGAN: Ruangan '{$bentrokRuang['nama_ruangan']}' sudah digunakan untuk kelas '{$bentrokRuang['nama_layanan']}' pada hari {$hari} pukul {$jamBentrok}.",
                    'Ruangan Terpakai!'
                );
            }
        }

        // Simpan Jadwal Baru
        try {
            $stmtInsert = $db->prepare("
                INSERT INTO jadwal_kelas (
                    id_cabang, id_layanan, id_mentor, id_aset_ruangan,
                    hari, jam_mulai, jam_selesai, kuota_siswa, status, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, 'aktif', NOW()
                )
            ");
            $stmtInsert->execute([
                $branchId, $idLayanan, $idMentor, $idRuangan,
                $hari, $jamMulai, $jamSelesai, $kuotaSiswa
            ]);

            redirect_with_message('modules/staff/jadwal_plotting.php', 'success', "Jadwal tatap muka hari {$hari} ({$jamMulai} - {$jamSelesai}) berhasil ditambahkan tanpa konflik.", 'Jadwal Berhasil Dibuat');
        } catch (\Throwable $e) {
            error_log("Gagal buat jadwal: " . $e->getMessage());
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Terjadi kesalahan sistem saat menyimpan jadwal: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. EDIT JADWAL
    if ($action === 'edit_jadwal') {
        $id = (int)($_POST['id'] ?? 0);
        $idLayanan = (int)($_POST['id_layanan'] ?? 0);
        $idMentor = (int)($_POST['id_mentor'] ?? 0);
        $idRuangan = !empty($_POST['id_aset_ruangan']) ? (int)$_POST['id_aset_ruangan'] : null;
        $hari = trim($_POST['hari'] ?? '');
        $jamMulai = trim($_POST['jam_mulai'] ?? '');
        $jamSelesai = trim($_POST['jam_selesai'] ?? '');
        $kuotaSiswa = (int)($_POST['kuota_siswa'] ?? 15);
        $status = trim($_POST['status'] ?? 'aktif');

        if (empty($idLayanan) || empty($idMentor) || empty($hari) || empty($jamMulai) || empty($jamSelesai)) {
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Data perubahan jadwal tidak lengkap.', 'Validasi Gagal');
        }

        // Cek Bentrok Mentor (Exclude current ID)
        $stmtCekMentor = $db->prepare("
            SELECT j.*, l.nama_layanan, p.nama_lengkap as nama_mentor
            FROM jadwal_kelas j
            JOIN tabel_layanan l ON l.id = j.id_layanan
            JOIN tabel_pegawai p ON p.id = j.id_mentor
            WHERE j.id_cabang = ? AND j.id_mentor = ? AND j.hari = ? AND j.status = 'aktif' AND j.id != ?
              AND (j.jam_mulai < ? AND j.jam_selesai > ?)
        ");
        $stmtCekMentor->execute([$branchId, $idMentor, $hari, $id, $jamSelesai, $jamMulai]);
        $bentrokMentor = $stmtCekMentor->fetch();

        if ($bentrokMentor && $status === 'aktif') {
            $jamBentrok = date('H:i', strtotime($bentrokMentor['jam_mulai'])) . ' - ' . date('H:i', strtotime($bentrokMentor['jam_selesai']));
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', "BENTROK MENTOR: {$bentrokMentor['nama_mentor']} sudah ada jadwal lain pada {$hari} pukul {$jamBentrok}.", 'Bentrok Jadwal');
        }

        // Cek Bentrok Ruang (Exclude current ID)
        if (!empty($idRuangan) && $status === 'aktif') {
            $stmtCekRuang = $db->prepare("
                SELECT j.*, l.nama_layanan, a.nama_aset as nama_ruangan
                FROM jadwal_kelas j
                JOIN tabel_layanan l ON l.id = j.id_layanan
                JOIN tabel_aset a ON a.id = j.id_aset_ruangan
                WHERE j.id_cabang = ? AND j.id_aset_ruangan = ? AND j.hari = ? AND j.status = 'aktif' AND j.id != ?
                  AND (j.jam_mulai < ? AND j.jam_selesai > ?)
            ");
            $stmtCekRuang->execute([$branchId, $idRuangan, $hari, $id, $jamSelesai, $jamMulai]);
            $bentrokRuang = $stmtCekRuang->fetch();

            if ($bentrokRuang) {
                $jamBentrok = date('H:i', strtotime($bentrokRuang['jam_mulai'])) . ' - ' . date('H:i', strtotime($bentrokRuang['jam_selesai']));
                redirect_with_message('modules/staff/jadwal_plotting.php', 'error', "BENTROK RUANGAN: Ruangan {$bentrokRuang['nama_ruangan']} terpakai pada {$hari} pukul {$jamBentrok}.", 'Bentrok Ruangan');
            }
        }

        try {
            $stmtUpdate = $db->prepare("
                UPDATE jadwal_kelas SET
                    id_layanan = ?,
                    id_mentor = ?,
                    id_aset_ruangan = ?,
                    hari = ?,
                    jam_mulai = ?,
                    jam_selesai = ?,
                    kuota_siswa = ?,
                    status = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUpdate->execute([
                $idLayanan, $idMentor, $idRuangan,
                $hari, $jamMulai, $jamSelesai, $kuotaSiswa, $status,
                $id, $branchId
            ]);

            redirect_with_message('modules/staff/jadwal_plotting.php', 'success', 'Jadwal tatap muka berhasil diperbarui.', 'Update Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal update jadwal: " . $e->getMessage());
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Gagal memperbarui jadwal: ' . $e->getMessage(), 'Error Update');
        }
    }

    // 3. HAPUS JADWAL
    if ($action === 'hapus_jadwal') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmtDel = $db->prepare("DELETE FROM jadwal_kelas WHERE id = ? AND id_cabang = ?");
            $stmtDel->execute([$id, $branchId]);

            redirect_with_message('modules/staff/jadwal_plotting.php', 'success', 'Jadwal kelas tatap muka berhasil dihapus.', 'Hapus Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal hapus jadwal: " . $e->getMessage());
            redirect_with_message('modules/staff/jadwal_plotting.php', 'error', 'Gagal menghapus jadwal.', 'Error Hapus');
        }
    }
}

// AMBIL SELURUH JADWAL DI CABANG
$filterHari = trim($_GET['hari'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$sql = "
    SELECT j.*, l.nama_layanan, l.kode_layanan, l.kategori_jenjang, p.nama_lengkap as nama_mentor, a.nama_aset as nama_ruangan
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    JOIN tabel_pegawai p ON p.id = j.id_mentor
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_cabang = ?
";
$params = [$branchId];

if (!empty($filterHari)) {
    $sql .= " AND j.hari = ?";
    $params[] = $filterHari;
}
if (!empty($filterStatus)) {
    $sql .= " AND j.status = ?";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$jadwalList = $stmt->fetchAll();

// Grouping Jadwal Berdasarkan Hari
$jadwalByHari = [
    'Senin' => [], 'Selasa' => [], 'Rabu' => [], 'Kamis' => [],
    'Jumat' => [], 'Sabtu' => [], 'Minggu' => []
];
foreach ($jadwalList as $j) {
    if (isset($jadwalByHari[$j['hari']])) {
        $jadwalByHari[$j['hari']][] = $j;
    }
}

// Master Layanan Aktif
$layananList = $db->query("SELECT id, kode_layanan, nama_layanan, kategori_jenjang FROM tabel_layanan WHERE status_aktif = 'aktif' ORDER BY nama_layanan ASC")->fetchAll();

// Master Mentor Cabang
$stmtMentor = $db->prepare("SELECT id, nip_pegawai, nama_lengkap, spesialisasi_mentor FROM tabel_pegawai WHERE id_cabang = ? AND jabatan_role = 'mentor' AND status_aktif = 'aktif' ORDER BY nama_lengkap ASC");
$stmtMentor->execute([$branchId]);
$mentorList = $stmtMentor->fetchAll();

// Master Ruang Kelas Fisik di Cabang (tabel_aset kategori ruang_kelas)
$stmtRuang = $db->prepare("SELECT id, kode_aset, nama_aset, lokasi_penempatan FROM tabel_aset WHERE id_cabang = ? AND kategori_aset = 'ruang_kelas' AND kondisi = 'baik' ORDER BY nama_aset ASC");
$stmtRuang->execute([$branchId]);
$ruanganList = $stmtRuang->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-clock"></i>
            <span>SIAKAD & Penjadwalan Tatap Muka Cabang</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">
            Plotting Jadwal Kelas & Manajemen Ruangan
        </h1>
        <p class="text-xs text-slate-500 mt-1">
            Susun jadwal kelas offline mingguan dengan sistem validasi anti-bentrok mentor dan ruangan fisik secara otomatis.
        </p>
    </div>

    <div>
        <button onclick="openModal('modalTambahJadwal')" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-calendar-plus text-xs"></i>
            <span>Buat Jadwal Baru</span>
        </button>
    </div>
</div>

<!-- Banner Indikator Deteksi Anti-Bentrok -->
<div class="card p-4 mb-6 bg-gradient-to-r from-teal-50/80 to-sky-50/80 border-teal-200 flex items-start gap-3.5">
    <div class="w-10 h-10 rounded-xl bg-teal-600 text-white flex items-center justify-center flex-shrink-0 text-base shadow-sm">
        <i class="fa-solid fa-shield-halved"></i>
    </div>
    <div class="flex-1">
        <h4 class="text-xs font-black text-teal-900 uppercase tracking-wider">Fitur Validasi Anti-Bentrok Aktif</h4>
        <p class="text-xs text-teal-800 mt-0.5">
            Sistem secara ketat memblokir plotting jadwal apabila terdapat <strong>Mentor</strong> atau <strong>Ruang Kelas Fisik</strong> yang sama telah terisi pada hari dan rentang jam yang beririsan.
        </p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-4 mb-6">
    <form method="GET" action="" class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <div class="w-40">
                <select name="hari" class="form-input text-xs py-2">
                    <option value="">Semua Hari</option>
                    <?php foreach (['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'] as $h): ?>
                        <option value="<?= $h ?>" <?= $filterHari === $h ? 'selected' : '' ?>><?= $h ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-36">
                <select name="status" class="form-input text-xs py-2">
                    <option value="">Semua Status</option>
                    <option value="aktif" <?= $filterStatus === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                    <option value="dibatalkan" <?= $filterStatus === 'dibatalkan' ? 'selected' : '' ?>>Dibatalkan</option>
                    <option value="selesai" <?= $filterStatus === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($filterHari) || !empty($filterStatus)): ?>
                <a href="<?= base_url('modules/staff/jadwal_plotting.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Total Jadwal: <span class="font-bold text-slate-800"><?= count($jadwalList) ?> Sesi Kelas</span>
        </div>
    </form>
</div>

<!-- ========================================================================= -->
<!-- GRID TAMPILAN JADWAL MINGGUAN PER HARI -->
<!-- ========================================================================= -->
<?php if (empty($jadwalList)): ?>
    <?= render_empty_state(
        'fa-calendar-xmark',
        'Belum Ada Jadwal Kelas Tatap Muka',
        'Plotting jadwal kelas tatap muka untuk menentukan hari, jam, mentor pengajar, dan ruang kelas fisik di cabang ini.',
        '<button type="button" onclick="openModal(\'modalTambahJadwal\')" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20"><i class="fa-solid fa-plus mr-1"></i> Plotting Jadwal Baru</button>',
        0
    ) ?>
<?php endif; ?>

<div class="space-y-6">
    <?php foreach ($jadwalByHari as $hariName => $listHari): ?>
        <?php if (!empty($filterHari) && $filterHari !== $hariName) continue; ?>
        <div class="card p-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-teal-100 text-teal-800 flex items-center justify-center font-black text-xs">
                        <?= substr($hariName, 0, 2) ?>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-slate-800">Hari <?= $hariName ?></h3>
                        <p class="text-[11px] text-slate-400"><?= count($listHari) ?> Sesi Kelas Tatap Muka Terjadwal</p>
                    </div>
                </div>

                <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full <?= count($listHari) > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                    <?= count($listHari) > 0 ? count($listHari) . ' Kelas' : 'Kosong' ?>
                </span>
            </div>

            <?php if (empty($listHari)): ?>
                <p class="text-xs text-slate-400 py-4 text-center italic">
                    Belum ada kelas tatap muka yang dijadwalkan pada hari <?= $hariName ?>.
                </p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($listHari as $j): ?>
                        <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-white hover:border-brand-300 hover:shadow-md transition-all flex flex-col justify-between">
                            <div>
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span class="font-mono text-xs font-black text-teal-700 bg-teal-50 px-2 py-0.5 rounded-md border border-teal-200">
                                        <?= date('H:i', strtotime($j['jam_mulai'])) ?> - <?= date('H:i', strtotime($j['jam_selesai'])) ?>
                                    </span>
                                    <?= render_status_badge($j['status']) ?>
                                </div>

                                <h4 class="text-xs font-bold text-slate-800 hover:text-brand-600 transition-colors">
                                    <?= e($j['nama_layanan']) ?>
                                </h4>
                                <p class="text-[11px] text-slate-500 font-medium mb-3"><?= e($j['kategori_jenjang']) ?></p>

                                <div class="space-y-1.5 text-xs text-slate-600 pt-2 border-t border-slate-100">
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-chalkboard-user text-slate-400 w-4 text-center"></i>
                                        <span class="font-semibold text-slate-700 truncate"><?= e($j['nama_mentor']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-door-open text-slate-400 w-4 text-center"></i>
                                        <span class="text-slate-600 truncate"><?= e($j['nama_ruangan'] ?? 'Ruang Kelas Standar') ?></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-users text-slate-400 w-4 text-center"></i>
                                        <span class="text-slate-500">Kapasitas Maks: <strong><?= $j['kuota_siswa'] ?> Siswa</strong></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-end gap-1.5">
                                <button onclick="editJadwal(<?= htmlspecialchars(json_encode($j), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Jadwal" class="px-2.5 py-1 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 text-xs font-semibold inline-flex items-center gap-1 transition-colors">
                                    <i class="fa-solid fa-pen-to-square text-[10px]"></i> Edit
                                </button>
                                <form method="POST" action="" onsubmit="return confirm('Hapus jadwal kelas ini?');" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="hapus_jadwal">
                                    <input type="hidden" name="id" value="<?= $j['id'] ?>">
                                    <button type="submit" title="Hapus Jadwal" class="px-2.5 py-1 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 text-xs font-semibold inline-flex items-center gap-1 transition-colors">
                                        <i class="fa-solid fa-trash-can text-[10px]"></i> Hapus
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM TAMBAH JADWAL BARU -->
<!-- ========================================================================= -->
<div id="modalTambahJadwal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Plotting Jadwal Kelas Baru</h3>
                <p class="text-xs text-slate-400 mt-0.5">Sistem akan otomatis mengecek bentrok ruangan dan mentor.</p>
            </div>
            <button onclick="closeModal('modalTambahJadwal')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_jadwal">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Pilihan Layanan / Program Kursus <span class="text-rose-500">*</span></label>
                <?php if (empty($layananList)): ?>
                    <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-start gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600 mt-0.5"></i>
                        <div>
                            <strong class="font-bold">Belum Ada Program Kursus Aktif</strong>
                            <p class="text-[11px] text-amber-700 mt-0.5">Master kurikulum dibuat oleh tim <strong>Bagian Akademik (BAK)</strong> atau <strong>Pimpinan Pusat</strong>.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <select name="id_layanan" required class="form-input text-xs">
                        <option value="">-- Pilih Layanan --</option>
                        <?php foreach ($layananList as $l): ?>
                            <option value="<?= $l['id'] ?>"><?= e($l['nama_layanan']) ?> (<?= e($l['kategori_jenjang']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mentor Pengajar <span class="text-rose-500">*</span></label>
                    <?php if (empty($mentorList)): ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 flex items-start gap-2">
                            <i class="fa-solid fa-info-circle text-slate-400 mt-0.5"></i>
                            <div>
                                <p class="text-[11px] text-slate-500">Belum ada mentor aktif. Daftarkan mentor di menu <strong>Data Pegawai Cabang</strong>.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <select name="id_mentor" required class="form-input text-xs">
                            <option value="">-- Pilih Mentor --</option>
                            <?php foreach ($mentorList as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= e($m['nama_lengkap']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ruangan Fisik / Lab</label>
                    <?php if (empty($ruanganList)): ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 flex items-start gap-2">
                            <i class="fa-solid fa-info-circle text-slate-400 mt-0.5"></i>
                            <div>
                                <p class="text-[11px] text-slate-500">Belum ada data ruang kelas. Petugas Sarpras perlu mendaftarkan ruang di menu <strong>Inventaris Ruang & Alat</strong>.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <select name="id_aset_ruangan" class="form-input text-xs">
                            <option value="">-- Tentukan Nanti / Bebas --</option>
                            <?php foreach ($ruanganList as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= e($r['nama_aset']) ?> (<?= e($r['lokasi_penempatan']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Hari Pelaksanaan <span class="text-rose-500">*</span></label>
                    <select name="hari" required class="form-input text-xs">
                        <?php foreach (['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'] as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Mulai <span class="text-rose-500">*</span></label>
                    <input type="time" name="jam_mulai" value="15:30" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Selesai <span class="text-rose-500">*</span></label>
                    <input type="time" name="jam_selesai" value="17:00" required class="form-input text-xs">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Kuota Maksimal Siswa</label>
                <input type="number" name="kuota_siswa" value="15" min="1" max="50" required class="form-input text-xs">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalTambahJadwal')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-check text-xs mr-1"></i> Simpan Jadwal
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM EDIT JADWAL -->
<!-- ========================================================================= -->
<div id="modalEditJadwal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Jadwal Kelas</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="editJadwalTitle">-</p>
            </div>
            <button onclick="closeModal('modalEditJadwal')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_jadwal">
            <input type="hidden" name="id" id="edit_jadwal_id" value="">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Layanan / Program Kursus <span class="text-rose-500">*</span></label>
                <select name="id_layanan" id="edit_id_layanan" required class="form-input text-xs">
                    <?php foreach ($layananList as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= e($l['nama_layanan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Mentor Pengajar <span class="text-rose-500">*</span></label>
                    <select name="id_mentor" id="edit_id_mentor" required class="form-input text-xs">
                        <?php foreach ($mentorList as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['nama_lengkap']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ruangan Fisik</label>
                    <select name="id_aset_ruangan" id="edit_id_aset_ruangan" class="form-input text-xs">
                        <option value="">-- Tanpa Ruangan Khusus --</option>
                        <?php foreach ($ruanganList as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['nama_aset']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Hari <span class="text-rose-500">*</span></label>
                    <select name="hari" id="edit_hari" required class="form-input text-xs">
                        <?php foreach (['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'] as $h): ?>
                            <option value="<?= $h ?>"><?= $h ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Mulai <span class="text-rose-500">*</span></label>
                    <input type="time" name="jam_mulai" id="edit_jam_mulai" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Jam Selesai <span class="text-rose-500">*</span></label>
                    <input type="time" name="jam_selesai" id="edit_jam_selesai" required class="form-input text-xs">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Kuota Maksimal Siswa</label>
                    <input type="number" name="kuota_siswa" id="edit_kuota_siswa" min="1" max="50" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Status Jadwal</label>
                    <select name="status" id="edit_status" class="form-input text-xs">
                        <option value="aktif">Aktif</option>
                        <option value="dibatalkan">Dibatalkan</option>
                        <option value="selesai">Selesai</option>
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditJadwal')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-save text-xs mr-1"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editJadwal(j) {
    document.getElementById('edit_jadwal_id').value = j.id;
    document.getElementById('editJadwalTitle').textContent = j.nama_layanan + ' (' + j.hari + ')';
    document.getElementById('edit_id_layanan').value = j.id_layanan;
    document.getElementById('edit_id_mentor').value = j.id_mentor;
    document.getElementById('edit_id_aset_ruangan').value = j.id_aset_ruangan || '';
    document.getElementById('edit_hari').value = j.hari;
    document.getElementById('edit_jam_mulai').value = j.jam_mulai.substring(0, 5);
    document.getElementById('edit_jam_selesai').value = j.jam_selesai.substring(0, 5);
    document.getElementById('edit_kuota_siswa').value = j.kuota_siswa;
    document.getElementById('edit_status').value = j.status;
    openModal('modalEditJadwal');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

