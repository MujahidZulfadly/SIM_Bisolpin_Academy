<?php
/**
 * SIM BISOLPIN ACADEMY - Jadwal Mengajar Tatap Muka (Mentor)
 */

declare(strict_types=1);

$pageTitle = 'Jadwal Mengajar Tatap Muka';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_MENTOR);

$db = get_db();

// Ambil profil data mentor
$stmtPegawai = $db->prepare("SELECT id, nama_lengkap, id_cabang FROM tabel_pegawai WHERE user_id = ?");
$stmtPegawai->execute([auth_id()]);
$mentor = $stmtPegawai->fetch();
$mentorId = $mentor ? (int)$mentor['id'] : 5;

// Ambil seluruh jadwal yang diplot untuk mentor ini
$stmt = $db->prepare("
    SELECT j.*, 
           l.nama_layanan, l.kode_layanan, l.kategori_jenjang, l.jumlah_pertemuan,
           a.nama_aset as nama_ruangan, a.lokasi_penempatan,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_layanan = j.id_layanan AND s.id_cabang = j.id_cabang AND s.status_belajar = 'aktif') as total_siswa_kelas
    FROM jadwal_kelas j
    JOIN tabel_layanan l ON l.id = j.id_layanan
    LEFT JOIN tabel_aset a ON a.id = j.id_aset_ruangan
    WHERE j.id_mentor = ?
    ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'), j.jam_mulai ASC
");
$stmt->execute([$mentorId]);
$jadwalList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Agenda Mengajar Mingguan</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Jadwal Kelas Tatap Muka</h1>
        <p class="text-xs text-slate-500 mt-1">
            Daftar sesi belajar offline, ruangan fisik, dan waktu pelaksanaan kelas bimbingan.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <a href="<?= base_url('modules/mentor/absensi.php') ?>" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-clipboard-check text-xs"></i>
            <span>Buka Presensi & BAP</span>
        </a>
    </div>
</div>

<!-- Tabel Jadwal -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Hari & Waktu</th>
                    <th>Program Kursus</th>
                    <th>Jenjang</th>
                    <th>Ruang Kelas Fisik</th>
                    <th>Siswa Terdaftar</th>
                    <th>Status</th>
                    <th class="text-right">Aksi Kelas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jadwalList as $row): ?>
                    <tr>
                        <td>
                            <span class="inline-block px-2.5 py-0.5 rounded-md bg-teal-50 text-teal-800 font-extrabold text-xs mb-1 border border-teal-200">
                                <?= e($row['hari']) ?>
                            </span>
                            <div class="text-xs font-mono font-bold text-slate-700">
                                <?= substr($row['jam_mulai'], 0, 5) ?> - <?= substr($row['jam_selesai'], 0, 5) ?> WITA
                            </div>
                        </td>

                        <td>
                            <strong class="text-slate-800 text-sm block"><?= e($row['nama_layanan']) ?></strong>
                            <span class="text-xs font-mono text-slate-400">Kode: <?= e($row['kode_layanan']) ?></span>
                        </td>

                        <td>
                            <span class="inline-block px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-semibold text-xs border border-slate-200">
                                <?= e($row['kategori_jenjang']) ?>
                            </span>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs flex items-center gap-1.5">
                                <i class="fa-solid fa-door-open text-brand-600"></i>
                                <span><?= e($row['nama_ruangan'] ?? 'Ruang Kelas Standar') ?></span>
                            </div>
                            <div class="text-[11px] text-slate-400"><?= e($row['lokasi_penempatan'] ?? 'Gedung Cabang') ?></div>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs"><?= (int)$row['total_siswa_kelas'] ?> / <?= (int)$row['kuota_siswa'] ?> Siswa</div>
                            <div class="text-[10px] text-slate-400">Kapasitas Maksimal</div>
                        </td>

                        <td>
                            <?= render_status_badge($row['status']) ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <a href="<?= base_url('modules/mentor/materi_bak.php?layanan_id=' . $row['id_layanan']) ?>" 
                                   class="px-3 py-1.5 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-800 font-bold text-xs border border-teal-200 transition-all flex items-center gap-1">
                                    <i class="fa-solid fa-book-open text-[10px]"></i>
                                    <span>Modul BAK</span>
                                </a>

                                <a href="<?= base_url('modules/mentor/absensi.php?jadwal_id=' . $row['id']) ?>" 
                                   class="px-3 py-1.5 rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-bold text-xs shadow-sm shadow-brand-500/20 transition-all flex items-center gap-1">
                                    <i class="fa-solid fa-clipboard-check text-[10px]"></i>
                                    <span>Presensi</span>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

