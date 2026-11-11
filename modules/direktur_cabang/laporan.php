<?php
/**
 * SIM BISOLPIN ACADEMY - Pusat Cetak Laporan Operasional Cabang (Direktur Cabang)
 */

declare(strict_types=1);

$pageTitle = 'Cetak Laporan Cabang';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_DIREKTUR_CABANG);

$branchId = auth_branch_id();
verify_branch_access($branchId);
$db = get_db();

// Data Cabang & Direktur
$stmtCabang = $db->prepare("
    SELECT c.*, p.nama_lengkap as nama_direktur, p.nip_pegawai
    FROM tabel_cabang c
    LEFT JOIN users u ON u.id = c.id_direktur_cabang
    LEFT JOIN tabel_pegawai p ON p.user_id = u.id
    WHERE c.id = ?
");
$stmtCabang->execute([$branchId]);
$cabang = $stmtCabang->fetch();

$jenisLaporan = $_GET['jenis'] ?? 'siswa';

// 1. Data untuk Laporan Siswa
if ($jenisLaporan === 'siswa') {
    $stmtData = $db->prepare("
        SELECT s.*, l.nama_layanan, m.nama_lengkap as nama_mentor
        FROM tabel_siswa s
        JOIN tabel_layanan l ON l.id = s.id_layanan
        LEFT JOIN tabel_pegawai m ON m.id = s.id_mentor_pembimbing
        WHERE s.id_cabang = ?
        ORDER BY s.created_at ASC
    ");
    $stmtData->execute([$branchId]);
    $dataReport = $stmtData->fetchAll();

// 2. Data untuk Laporan Presensi
} elseif ($jenisLaporan === 'presensi') {
    $stmtData = $db->prepare("
        SELECT a.*, s.nama_lengkap as nama_siswa, s.nis_bisolpin, m.judul_modul, u.username as diinput_oleh_user
        FROM tabel_absensi a
        JOIN tabel_siswa s ON s.id = a.id_siswa
        LEFT JOIN tabel_materi m ON m.id = a.id_materi_pertemuan
        LEFT JOIN users u ON u.id = a.diinput_oleh
        WHERE a.id_cabang = ? AND a.tipe_absensi = 'siswa'
        ORDER BY a.tanggal_absensi DESC, a.jam_masuk DESC
    ");
    $stmtData->execute([$branchId]);
    $dataReport = $stmtData->fetchAll();

// 3. Data untuk Laporan Aset
} elseif ($jenisLaporan === 'aset') {
    $stmtData = $db->prepare("
        SELECT a.*, p.nama_lengkap as nama_penanggung_jawab
        FROM tabel_aset a
        LEFT JOIN tabel_pegawai p ON p.id = a.id_penanggung_jawab
        WHERE a.id_cabang = ?
        ORDER BY a.kategori_aset ASC, a.id ASC
    ");
    $stmtData->execute([$branchId]);
    $dataReport = $stmtData->fetchAll();

// 4. Data untuk Laporan SDM / Pegawai
} else {
    $stmtData = $db->prepare("
        SELECT p.*, u.email
        FROM tabel_pegawai p
        JOIN users u ON u.id = p.user_id
        WHERE p.id_cabang = ?
        ORDER BY p.jabatan_role ASC
    ");
    $stmtData->execute([$branchId]);
    $dataReport = $stmtData->fetchAll();
    $jenisLaporan = 'pegawai';
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Kontrol Pemilihan Jenis Laporan & Tombol Cetak (no-print) -->
<div class="no-print mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Pusat Cetak Dokumen & Laporan Cabang</h1>
        <p class="text-xs text-slate-500 mt-1">Pilih kategori laporan untuk mencetak berkas resmi bertanda tangan.</p>
    </div>

    <div class="flex items-center gap-3">
        <!-- Dropdown Pilih Laporan -->
        <select onchange="window.location.href='?jenis=' + this.value" class="px-4 py-2 text-xs font-bold rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <option value="siswa" <?= $jenisLaporan === 'siswa' ? 'selected' : '' ?>>📑 Laporan Data & Progres Siswa</option>
            <option value="presensi" <?= $jenisLaporan === 'presensi' ? 'selected' : '' ?>>📋 Laporan Rekap Presensi Siswa</option>
            <option value="aset" <?= $jenisLaporan === 'aset' ? 'selected' : '' ?>>🛋️ Laporan Inventaris & Sarpras</option>
            <option value="pegawai" <?= $jenisLaporan === 'pegawai' ? 'selected' : '' ?>>👥 Laporan Data Pegawai & Mentor</option>
        </select>

        <button onclick="window.print()" class="btn-brand text-xs py-2 px-5 shadow-lg shadow-brand-500/20">
            <i class="fa-solid fa-print"></i>
            <span>Cetak Dokumen Sekarang (Print / PDF)</span>
        </button>
    </div>
</div>

<!-- ========================================================================= -->
<!-- LEMBAR DOKUMEN CETAK RESMI (PRINT-READY DOCUMENT) -->
<!-- ========================================================================= -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8 sm:p-12 max-w-5xl mx-auto print:p-0 print:border-none print:shadow-none">

    <!-- KOP SURAT RESMI CABANG -->
    <div class="border-b-4 border-double border-slate-800 pb-5 mb-6 text-center">
        <div class="flex items-center justify-center gap-4 mb-2">
            <div class="w-12 h-12 rounded-xl bg-slate-900 text-white flex items-center justify-center font-black text-2xl print:bg-black">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="text-left">
                <h2 class="text-2xl font-black text-slate-900 tracking-tight uppercase">BISOLPIN ACADEMY</h2>
                <h3 class="text-sm font-bold text-slate-700 tracking-wide uppercase"><?= e($cabang['nama_cabang']) ?></h3>
            </div>
        </div>
        <p class="text-xs text-slate-600 mt-1 max-w-xl mx-auto">
            <?= e($cabang['alamat']) ?>, <?= e($cabang['kota']) ?>, <?= e($cabang['provinsi']) ?> | Telp: <?= e($cabang['telepon']) ?> | Email: <?= e($cabang['email_cabang']) ?>
        </p>
    </div>

    <!-- JUDUL LAPORAN -->
    <div class="text-center mb-6">
        <h3 class="text-base font-extrabold uppercase tracking-wider text-slate-900">
            <?php
            if ($jenisLaporan === 'siswa') echo 'LAPORAN INDUK & PERKEMBANGAN BELAJAR SISWA';
            elseif ($jenisLaporan === 'presensi') echo 'LAPORAN REKAPITULASI PRESENSI KELAS TATAP MUKA';
            elseif ($jenisLaporan === 'aset') echo 'LAPORAN AUDIT INVENTARIS SARANA DAN PRASARANA';
            else echo 'LAPORAN DATA SUMBER DAYA MANUSIA & MENTOR';
            ?>
        </h3>
        <p class="text-xs text-slate-500 mt-0.5">
            Dicetak pada: <?= format_hari_indonesia(date('Y-m-d')) ?>, <?= format_tanggal_indonesia(date('Y-m-d'), true) ?>
        </p>
    </div>

    <!-- TABEL DATA LAPORAN -->
    <div class="overflow-x-auto mb-8">
        <?php if ($jenisLaporan === 'siswa'): ?>
            <table class="w-full text-xs text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 font-bold uppercase text-[11px]">
                        <th class="border border-slate-300 p-2.5 text-center w-8">No</th>
                        <th class="border border-slate-300 p-2.5">NIS & Nama Siswa</th>
                        <th class="border border-slate-300 p-2.5">Program Kursus</th>
                        <th class="border border-slate-300 p-2.5">Mentor</th>
                        <th class="border border-slate-300 p-2.5 text-center">Progres</th>
                        <th class="border border-slate-300 p-2.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($dataReport as $row): ?>
                        <tr class="border-b border-slate-200">
                            <td class="border border-slate-300 p-2 text-center"><?= $no++ ?></td>
                            <td class="border border-slate-300 p-2">
                                <strong class="text-slate-900"><?= e($row['nama_lengkap']) ?></strong>
                                <span class="block text-[10px] text-slate-500 font-mono">NIS: <?= e($row['nis_bisolpin']) ?></span>
                            </td>
                            <td class="border border-slate-300 p-2"><?= e($row['nama_layanan']) ?></td>
                            <td class="border border-slate-300 p-2"><?= e($row['nama_mentor'] ?? '-') ?></td>
                            <td class="border border-slate-300 p-2 text-center font-bold text-brand-700"><?= (float)$row['persentase_progres'] ?>%</td>
                            <td class="border border-slate-300 p-2 text-center capitalize font-semibold"><?= e($row['status_belajar']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($jenisLaporan === 'presensi'): ?>
            <table class="w-full text-xs text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 font-bold uppercase text-[11px]">
                        <th class="border border-slate-300 p-2.5 text-center w-8">No</th>
                        <th class="border border-slate-300 p-2.5">Tanggal & Waktu</th>
                        <th class="border border-slate-300 p-2.5">Nama Siswa</th>
                        <th class="border border-slate-300 p-2.5">Topik Materi Pembahasan</th>
                        <th class="border border-slate-300 p-2.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($dataReport as $row): ?>
                        <tr class="border-b border-slate-200">
                            <td class="border border-slate-300 p-2 text-center"><?= $no++ ?></td>
                            <td class="border border-slate-300 p-2">
                                <?= format_tanggal_indonesia($row['tanggal_absensi']) ?><br>
                                <span class="text-[10px] text-slate-500"><?= substr($row['jam_masuk'], 0, 5) ?> WITA</span>
                            </td>
                            <td class="border border-slate-300 p-2 font-bold"><?= e($row['nama_siswa']) ?></td>
                            <td class="border border-slate-300 p-2"><?= e($row['judul_modul'] ?? 'Sesi Pembahasan Modul') ?></td>
                            <td class="border border-slate-300 p-2 text-center font-bold uppercase text-brand-700"><?= e($row['status_kehadiran']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($jenisLaporan === 'aset'): ?>
            <table class="w-full text-xs text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 font-bold uppercase text-[11px]">
                        <th class="border border-slate-300 p-2.5 text-center w-8">No</th>
                        <th class="border border-slate-300 p-2.5">Kode & Nama Aset</th>
                        <th class="border border-slate-300 p-2.5">Kategori & Lokasi</th>
                        <th class="border border-slate-300 p-2.5 text-center">Jumlah</th>
                        <th class="border border-slate-300 p-2.5 text-center">Kondisi</th>
                        <th class="border border-slate-300 p-2.5 text-right">Nilai Perolehan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; $sum = 0; foreach ($dataReport as $row): $sum += (float)$row['nilai_perolehan']; ?>
                        <tr class="border-b border-slate-200">
                            <td class="border border-slate-300 p-2 text-center"><?= $no++ ?></td>
                            <td class="border border-slate-300 p-2">
                                <strong class="text-slate-900"><?= e($row['nama_aset']) ?></strong><br>
                                <span class="text-[10px] text-slate-500 font-mono"><?= e($row['kode_aset']) ?></span>
                            </td>
                            <td class="border border-slate-300 p-2">
                                <?= ucwords(str_replace('_', ' ', e($row['kategori_aset']))) ?><br>
                                <span class="text-[10px] text-slate-500"><?= e($row['lokasi_penempatan']) ?></span>
                            </td>
                            <td class="border border-slate-300 p-2 text-center font-bold"><?= (int)$row['jumlah_unit'] ?> Unit</td>
                            <td class="border border-slate-300 p-2 text-center capitalize font-semibold"><?= e($row['kondisi']) ?></td>
                            <td class="border border-slate-300 p-2 text-right font-mono font-bold"><?= format_rupiah($row['nilai_perolehan']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-bold">
                        <td colspan="5" class="border border-slate-300 p-2 text-right uppercase">Total Nilai Fasilitas Aset:</td>
                        <td class="border border-slate-300 p-2 text-right text-brand-700 font-mono"><?= format_rupiah($sum) ?></td>
                    </tr>
                </tfoot>
            </table>

        <?php else: ?>
            <table class="w-full text-xs text-left border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 font-bold uppercase text-[11px]">
                        <th class="border border-slate-300 p-2.5 text-center w-8">No</th>
                        <th class="border border-slate-300 p-2.5">NIP & Nama Pegawai</th>
                        <th class="border border-slate-300 p-2.5">Jabatan / Role</th>
                        <th class="border border-slate-300 p-2.5">Keahlian</th>
                        <th class="border border-slate-300 p-2.5">Kontak</th>
                        <th class="border border-slate-300 p-2.5 text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($dataReport as $row): ?>
                        <tr class="border-b border-slate-200">
                            <td class="border border-slate-300 p-2 text-center"><?= $no++ ?></td>
                            <td class="border border-slate-300 p-2">
                                <strong class="text-slate-900"><?= e($row['nama_lengkap']) ?></strong><br>
                                <span class="text-[10px] text-slate-500 font-mono">NIP: <?= e($row['nip_pegawai']) ?></span>
                            </td>
                            <td class="border border-slate-300 p-2 capitalize font-semibold"><?= str_replace('_', ' ', e($row['jabatan_role'])) ?></td>
                            <td class="border border-slate-300 p-2"><?= e($row['spesialisasi_mentor'] ?: '-') ?></td>
                            <td class="border border-slate-300 p-2"><?= e($row['no_hp']) ?></td>
                            <td class="border border-slate-300 p-2 text-center capitalize font-bold"><?= e($row['status_aktif']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- TANDA TANGAN PENGESAHAN LAPORAN -->
    <div class="mt-12 flex justify-between items-end text-xs">
        <div>
            <p class="text-slate-500 text-[11px]">Dokumen Sah Cetak Sistem Informasi Manajemen (SIM)</p>
            <p class="font-mono text-slate-400 text-[10px]">ID Cabang: <?= (int)$branchId ?> | Ref: <?= date('YmdHis') ?></p>
        </div>

        <div class="text-center w-64">
            <p><?= e($cabang['kota']) ?>, <?= format_tanggal_indonesia(date('Y-m-d')) ?></p>
            <p class="font-bold text-slate-800 mt-1">Direktur <?= e($cabang['nama_cabang']) ?></p>
            
            <div class="h-20 flex items-center justify-center text-slate-300 italic text-[11px]">
                (Tanda Tangan & Cap Resmi)
            </div>

            <p class="font-bold underline text-slate-900"><?= e($cabang['nama_direktur'] ?? 'Direktur Cabang') ?></p>
            <p class="text-slate-500 font-mono text-[10px]">NIP: <?= e($cabang['nip_pegawai'] ?? '-') ?></p>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

