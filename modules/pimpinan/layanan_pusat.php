<?php
/**
 * SIM BISOLPIN ACADEMY - CRUD Master Layanan Standar Nasional (Pimpinan Pusat)
 */

declare(strict_types=1);

$pageTitle = 'Master Layanan Kursus Pusat';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses Pimpinan Pusat
check_role(ROLE_PIMPINAN);

$db = get_db();

// Handle Form POST: Create, Update, Delete, Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $kodeLayanan      = strtoupper(trim($_POST['kode_layanan'] ?? ''));
        $namaLayanan      = trim($_POST['nama_layanan'] ?? '');
        $tipeLayanan      = in_array($_POST['tipe_layanan'] ?? '', ['kursus_reguler','les_privat','tka_tryout','event_workshop'], true) ? $_POST['tipe_layanan'] : 'kursus_reguler';
        $mataPelajaran    = in_array($_POST['mata_pelajaran'] ?? '', ['Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web'], true) ? $_POST['mata_pelajaran'] : null;
        $kategoriJenjang  = in_array($_POST['kategori_jenjang'] ?? '', ['TK','SD','SMP','SMA','SMK','Mahasiswa','Umum'], true) ? $_POST['kategori_jenjang'] : 'SMP';
        $deskripsi        = trim($_POST['deskripsi'] ?? '');
        $jumlahPertemuan  = (int)($_POST['jumlah_pertemuan'] ?? 12);
        $durasiMenit      = (int)($_POST['durasi_per_sesi_menit'] ?? 90);
        $hargaLayanan     = (float)str_replace(['.', ','], '', $_POST['harga_layanan'] ?? '0');
        $hargaPromo       = !empty($_POST['harga_promo']) ? (float)str_replace(['.', ','], '', $_POST['harga_promo']) : null;
        $targetOutput     = trim($_POST['target_output_produk'] ?? '');

        if (empty($kodeLayanan) || empty($namaLayanan)) {
            set_flash('error', 'Kode layanan dan nama layanan wajib diisi.');
        } else {
            // Cek keunikan kode
            $stmtC = $db->prepare("SELECT id FROM tabel_layanan WHERE kode_layanan = ?");
            $stmtC->execute([$kodeLayanan]);
            if ($stmtC->fetch()) {
                set_flash('error', 'Kode layanan <strong>' . e($kodeLayanan) . '</strong> sudah digunakan. Gunakan kode lain.');
            } else {
                try {
                    $stmtIns = $db->prepare("
                        INSERT INTO tabel_layanan 
                        (id_cabang, kode_layanan, nama_layanan, tipe_layanan, mata_pelajaran, kategori_jenjang, deskripsi, jumlah_pertemuan, durasi_per_sesi_menit, harga_layanan, harga_promo, target_output_produk, status_aktif, created_at)
                        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif', NOW())
                    ");
                    $stmtIns->execute([
                        $kodeLayanan, $namaLayanan, $tipeLayanan, $mataPelajaran, $kategoriJenjang, $deskripsi,
                        $jumlahPertemuan, $durasiMenit, $hargaLayanan, $hargaPromo, $targetOutput
                    ]);
                    set_flash('success', 'Layanan kursus standar nasional <strong>' . e($namaLayanan) . '</strong> berhasil ditambahkan!');
                } catch (\Throwable $e) {
                    set_flash('error', 'Gagal menambahkan layanan: ' . $e->getMessage());
                }
            }
        }
        redirect('modules/pimpinan/layanan_pusat.php');

    } elseif ($action === 'update') {
        $id               = (int)($_POST['id'] ?? 0);
        $kodeLayanan      = strtoupper(trim($_POST['kode_layanan'] ?? ''));
        $namaLayanan      = trim($_POST['nama_layanan'] ?? '');
        $tipeLayanan      = in_array($_POST['tipe_layanan'] ?? '', ['kursus_reguler','les_privat','tka_tryout','event_workshop'], true) ? $_POST['tipe_layanan'] : 'kursus_reguler';
        $mataPelajaran    = in_array($_POST['mata_pelajaran'] ?? '', ['Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web'], true) ? $_POST['mata_pelajaran'] : null;
        $kategoriJenjang  = in_array($_POST['kategori_jenjang'] ?? '', ['TK','SD','SMP','SMA','SMK','Mahasiswa','Umum'], true) ? $_POST['kategori_jenjang'] : 'SMP';
        $deskripsi        = trim($_POST['deskripsi'] ?? '');
        $jumlahPertemuan  = (int)($_POST['jumlah_pertemuan'] ?? 12);
        $durasiMenit      = (int)($_POST['durasi_per_sesi_menit'] ?? 90);
        $hargaLayanan     = (float)str_replace(['.', ','], '', $_POST['harga_layanan'] ?? '0');
        $hargaPromo       = !empty($_POST['harga_promo']) ? (float)str_replace(['.', ','], '', $_POST['harga_promo']) : null;
        $targetOutput     = trim($_POST['target_output_produk'] ?? '');
        $statusAktif      = $_POST['status_aktif'] === 'aktif' ? 'aktif' : 'nonaktif';

        if (empty($kodeLayanan) || empty($namaLayanan)) {
            set_flash('error', 'Kode layanan dan nama layanan wajib diisi.');
        } else {
            try {
                $stmtUp = $db->prepare("
                    UPDATE tabel_layanan 
                    SET kode_layanan = ?, nama_layanan = ?, tipe_layanan = ?, mata_pelajaran = ?, kategori_jenjang = ?, deskripsi = ?, 
                        jumlah_pertemuan = ?, durasi_per_sesi_menit = ?, harga_layanan = ?, harga_promo = ?,
                        target_output_produk = ?, status_aktif = ?
                    WHERE id = ? AND id_cabang IS NULL
                ");
                $stmtUp->execute([
                    $kodeLayanan, $namaLayanan, $tipeLayanan, $mataPelajaran, $kategoriJenjang, $deskripsi,
                    $jumlahPertemuan, $durasiMenit, $hargaLayanan, $hargaPromo,
                    $targetOutput, $statusAktif, $id
                ]);
                set_flash('success', 'Data layanan <strong>' . e($namaLayanan) . '</strong> berhasil diperbarui!');
            } catch (\Throwable $e) {
                set_flash('error', 'Gagal memperbarui layanan: ' . $e->getMessage());
            }
        }
        redirect('modules/pimpinan/layanan_pusat.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Cek ketergantungan relasi
        $stmtCS = $db->prepare("SELECT COUNT(*) FROM tabel_siswa WHERE id_layanan = ?");
        $stmtCS->execute([$id]);
        $countSiswa = (int)$stmtCS->fetchColumn();

        $stmtCM = $db->prepare("SELECT COUNT(*) FROM tabel_materi WHERE id_layanan = ?");
        $stmtCM->execute([$id]);
        $countMateri = (int)$stmtCM->fetchColumn();

        if ($countSiswa > 0 || $countMateri > 0) {
            set_flash('warning', 'Layanan tidak dapat dihapus karena sudah memiliki data siswa aktif atau modul materi BAK yang terdaftar. Anda dapat menonaktifkan statusnya.');
        } else {
            try {
                $stmtDel = $db->prepare("DELETE FROM tabel_layanan WHERE id = ? AND id_cabang IS NULL");
                $stmtDel->execute([$id]);
                set_flash('success', 'Layanan kursus berhasil dihapus secara permanen.');
            } catch (\Throwable $e) {
                set_flash('error', 'Gagal menghapus layanan: ' . $e->getMessage());
            }
        }
        redirect('modules/pimpinan/layanan_pusat.php');
    }
}

// Ambil seluruh layanan standar pusat (id_cabang IS NULL)
$stmt = $db->query("
    SELECT l.*, 
           (SELECT COUNT(*) FROM tabel_materi m WHERE m.id_layanan = l.id) as total_materi_bak,
           (SELECT COUNT(*) FROM tabel_siswa s WHERE s.id_layanan = l.id) as total_siswa_aktif
    FROM tabel_layanan l
    WHERE l.id_cabang IS NULL
    ORDER BY l.created_at DESC
");
$layananList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header & Tombol Tambah -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-layer-group"></i>
            <span>Standardisasi Kurikulum Nasional</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Katalog Layanan & Program Kursus Pusat</h1>
        <p class="text-xs text-slate-500 mt-1">
            Program resmi yang berlaku secara seragam di seluruh cabang kursus offline Bisolpin Academy.
        </p>
    </div>

    <button onclick="openModalCreate()" class="btn-brand text-xs py-2.5 shadow-md shadow-brand-500/20">
        <i class="fa-solid fa-plus text-xs"></i>
        <span>Tambah Layanan Kursus Baru</span>
    </button>
</div>

<!-- Tabel Layanan -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Program</th>
                    <th>Jenjang & Output Produk</th>
                    <th>Sesi & Durasi</th>
                    <th>Tarif Kursus</th>
                    <th>Kelengkapan BAK</th>
                    <th>Status</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($layananList as $row): ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($row['nama_layanan']) ?></div>
                            <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                                <span class="text-xs font-mono text-slate-400">Kode: <?= e($row['kode_layanan']) ?></span>
                                <?php if (!empty($row['tipe_layanan'])): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-200">
                                        <?= ucwords(str_replace('_', ' ', $row['tipe_layanan'])) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($row['mata_pelajaran'])): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                        <?= e($row['mata_pelajaran']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-[11px] text-slate-500 max-w-xs truncate mt-0.5" title="<?= e($row['deskripsi']) ?>">
                                <?= e($row['deskripsi']) ?>
                            </div>
                        </td>

                        <td>
                            <span class="inline-block px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-semibold text-xs border border-slate-200">
                                <?= e($row['kategori_jenjang']) ?>
                            </span>
                            <div class="text-[11px] text-teal-700 font-medium mt-1">
                                <i class="fa-solid fa-bullseye text-[10px] mr-1"></i>
                                <?= e($row['target_output_produk'] ?: 'Karya Portofolio Siswa') ?>
                            </div>
                        </td>

                        <td>
                            <div class="font-bold text-slate-700 text-xs"><?= (int)$row['jumlah_pertemuan'] ?> Pertemuan</div>
                            <div class="text-[11px] text-slate-400"><?= (int)$row['durasi_per_sesi_menit'] ?> Menit / Sesi</div>
                        </td>

                        <td>
                            <?= render_promo_price($row['harga_layanan'], $row['harga_promo'] ?? null) ?>
                            <div class="text-[10px] text-slate-400 mt-0.5">Per Paket Program</div>
                        </td>

                        <td>
                            <div class="text-xs font-semibold text-slate-700">
                                <i class="fa-solid fa-file-pdf text-rose-500 mr-1"></i>
                                <?= (int)$row['total_materi_bak'] ?> Modul BAK
                            </div>
                            <div class="text-[11px] text-slate-400">
                                <?= (int)$row['total_siswa_aktif'] ?> Siswa Terdaftar
                            </div>
                        </td>

                        <td>
                            <?= render_status_badge($row['status_aktif']) ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <button type="button" onclick='openModalEdit(<?= json_encode($row) ?>)'
                                        class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:border-brand-300 hover:bg-brand-50 text-slate-600 hover:text-brand-700 font-bold text-xs transition-all">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <form method="POST" action="" onsubmit="return confirm('Hapus program layanan ini?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:border-rose-300 hover:bg-rose-50 text-slate-400 hover:text-rose-600 font-bold text-xs transition-all">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL FORM: TAMBAH LAYANAN -->
<!-- ========================================================================= -->
<div id="modal-create" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Tambah Layanan Kursus Pusat</h3>
                <p class="text-xs text-slate-400">Program standar nasional untuk seluruh cabang offline</p>
            </div>
            <button onclick="closeModalCreate()" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kode Layanan *</label>
                    <input type="text" name="kode_layanan" required placeholder="LYN-CODING-01" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 uppercase font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kategori Jenjang *</label>
                    <select name="kategori_jenjang" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="TK">TK (Taman Kanak-Kanak)</option>
                        <option value="SD">SD (Sekolah Dasar)</option>
                        <option value="SMP" selected>SMP (Sekolah Menengah Pertama)</option>
                        <option value="SMA">SMA (Sekolah Menengah Atas)</option>
                        <option value="SMK">SMK (Sekolah Menengah Kejuruan)</option>
                        <option value="Mahasiswa">Mahasiswa / Perguruan Tinggi</option>
                        <option value="Umum">Umum / Profesional</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Tipe Layanan *</label>
                    <select name="tipe_layanan" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold text-brand-700">
                        <option value="kursus_reguler">Kursus Reguler (Tatap Muka)</option>
                        <option value="les_privat">Les Privat (1-on-1)</option>
                        <option value="tka_tryout">Persiapan TKA & Try Out</option>
                        <option value="event_workshop">Event & Workshop</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Mata Pelajaran Inti Bisolpin</label>
                    <select name="mata_pelajaran" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="">-- Pilih Mapel Inti --</option>
                        <option value="Matematika">Matematika</option>
                        <option value="Bahasa Inggris">Bahasa Inggris</option>
                        <option value="Calistung">Calistung</option>
                        <option value="Coding">Coding</option>
                        <option value="Pemrograman Web">Pemrograman Web</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Nama Layanan Kursus *</label>
                    <input type="text" name="nama_layanan" required placeholder="Contoh: Junior Web Developer & Robotik" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Jumlah Pertemuan Tatap Muka</label>
                    <input type="number" name="jumlah_pertemuan" value="12" min="1" max="100" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Durasi per Sesi (Menit)</label>
                    <input type="number" name="durasi_per_sesi_menit" value="90" min="30" max="240" step="15" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Harga Normal Layanan (Rupiah) *</label>
                    <input type="number" name="harga_layanan" value="1500000" min="0" step="10000" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Harga Diskon Promo (Opsional)</label>
                    <input type="number" name="harga_promo" placeholder="Kosongkan jika harga normal" min="0" step="10000" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-mono text-emerald-600">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Target Output Karya/Produk Siswa</label>
                    <input type="text" name="target_output_produk" placeholder="Website Portofolio Interaktif / Buku Ringkasan Rumus" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Deskripsi Singkat Program</label>
                    <textarea name="deskripsi" rows="3" placeholder="Uraian materi, sasaran kompetensi, dan kegiatan belajar..." class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalCreate()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Layanan Baru</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL FORM: EDIT LAYANAN -->
<!-- ========================================================================= -->
<div id="modal-edit" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Edit Layanan Kursus Pusat</h3>
                <p class="text-xs text-slate-400">Perbarui data spesifikasi program kursus</p>
            </div>
            <button onclick="closeModalEdit()" class="text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit-id" name="id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kode Layanan *</label>
                    <input type="text" id="edit-kode" name="kode_layanan" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 uppercase font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kategori Jenjang *</label>
                    <select id="edit-kategori" name="kategori_jenjang" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="TK">TK (Taman Kanak-Kanak)</option>
                        <option value="SD">SD (Sekolah Dasar)</option>
                        <option value="SMP">SMP (Sekolah Menengah Pertama)</option>
                        <option value="SMA">SMA (Sekolah Menengah Atas)</option>
                        <option value="SMK">SMK (Sekolah Menengah Kejuruan)</option>
                        <option value="Mahasiswa">Mahasiswa / Perguruan Tinggi</option>
                        <option value="Umum">Umum / Profesional</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Tipe Layanan *</label>
                    <select id="edit-tipe" name="tipe_layanan" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold text-brand-700">
                        <option value="kursus_reguler">Kursus Reguler (Tatap Muka)</option>
                        <option value="les_privat">Les Privat (1-on-1)</option>
                        <option value="tka_tryout">Persiapan TKA & Try Out</option>
                        <option value="event_workshop">Event & Workshop</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Mata Pelajaran Inti Bisolpin</label>
                    <select id="edit-mapel" name="mata_pelajaran" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="">-- Pilih Mapel Inti --</option>
                        <option value="Matematika">Matematika</option>
                        <option value="Bahasa Inggris">Bahasa Inggris</option>
                        <option value="Calistung">Calistung</option>
                        <option value="Coding">Coding</option>
                        <option value="Pemrograman Web">Pemrograman Web</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Nama Layanan Kursus *</label>
                    <input type="text" id="edit-nama" name="nama_layanan" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Jumlah Pertemuan</label>
                    <input type="number" id="edit-pertemuan" name="jumlah_pertemuan" min="1" max="100" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Durasi per Sesi (Menit)</label>
                    <input type="number" id="edit-durasi" name="durasi_per_sesi_menit" min="30" max="240" step="15" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Harga Normal (Rupiah) *</label>
                    <input type="number" id="edit-harga" name="harga_layanan" min="0" step="10000" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Harga Diskon Promo (Opsional)</label>
                    <input type="number" id="edit-harga-promo" name="harga_promo" min="0" step="10000" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-mono text-emerald-600">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Status Publikasi</label>
                    <select id="edit-status" name="status_aktif" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Target Output Karya/Produk Siswa</label>
                    <input type="text" id="edit-target" name="target_output_produk" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Deskripsi Singkat Program</label>
                    <textarea id="edit-deskripsi" name="deskripsi" rows="3" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalEdit()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Perbarui Layanan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalCreate() {
    document.getElementById('modal-create').classList.remove('hidden');
}
function closeModalCreate() {
    document.getElementById('modal-create').classList.add('hidden');
}

function openModalEdit(data) {
    document.getElementById('edit-id').value = data.id;
    document.getElementById('edit-kode').value = data.kode_layanan;
    document.getElementById('edit-nama').value = data.nama_layanan;
    document.getElementById('edit-kategori').value = data.kategori_jenjang;
    document.getElementById('edit-tipe').value = data.tipe_layanan || 'kursus_reguler';
    document.getElementById('edit-mapel').value = data.mata_pelajaran || '';
    document.getElementById('edit-pertemuan').value = data.jumlah_pertemuan;
    document.getElementById('edit-durasi').value = data.durasi_per_sesi_menit;
    document.getElementById('edit-harga').value = parseInt(data.harga_layanan);
    document.getElementById('edit-harga-promo').value = data.harga_promo ? parseInt(data.harga_promo) : '';
    document.getElementById('edit-status').value = data.status_aktif;
    document.getElementById('edit-target').value = data.target_output_produk || '';
    document.getElementById('edit-deskripsi').value = data.deskripsi || '';

    document.getElementById('modal-edit').classList.remove('hidden');
}
function closeModalEdit() {
    document.getElementById('modal-edit').classList.add('hidden');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

