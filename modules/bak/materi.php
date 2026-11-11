<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Modul & Bahan Ajar Resmi (BAK)
 * Mendukung Hybrid Storage (File Lokal Maks 3MB & Link Eksternal GDrive/Figma/GitHub/YouTube)
 */

declare(strict_types=1);

$pageTitle = 'Modul & Bahan Ajar Resmi (BAK)';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_BAK);

$db = get_db();

// Ambil ID pegawai BAK yang sedang login
$stmtBak = $db->prepare("SELECT id, nama_lengkap FROM tabel_pegawai WHERE user_id = ?");
$stmtBak->execute([auth_id()]);
$bakUser = $stmtBak->fetch();
$pembuatBakId = $bakUser ? (int)$bakUser['id'] : 4;

// Handle Form POST: Tambah Modul, Edit Modul, Hapus Modul
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $idLayanan         = (int)($_POST['id_layanan'] ?? 0);
        $kodeMateri        = strtoupper(trim($_POST['kode_materi'] ?? ''));
        $namaPelajaran     = trim($_POST['nama_pelajaran'] ?? '');
        $pertemuanKe       = (int)($_POST['pertemuan_ke'] ?? 1);
        $judulModul        = trim($_POST['judul_modul'] ?? '');
        $ringkasanMateri   = trim($_POST['ringkasan_materi'] ?? '');
        $panduanMentor     = trim($_POST['panduan_mentor'] ?? '');
        $standarTugas      = trim($_POST['standar_tugas_produk'] ?? '');
        $statusPublikasi   = in_array($_POST['status_publikasi'] ?? '', ['draft', 'published', 'archived'], true) ? $_POST['status_publikasi'] : 'draft';
        $tipeLampiranInput = $_POST['tipe_lampiran'] ?? 'file';
        $externalUrlInput  = trim($_POST['external_url'] ?? '');

        if (empty($kodeMateri) || empty($judulModul) || $idLayanan <= 0) {
            set_flash('error', 'Layanan kursus, kode materi, dan judul modul wajib diisi.');
            redirect('modules/bak/materi.php');
        }

        // Proses Hybrid Storage Upload (Maks 3MB untuk file fisik atau URL eksternal valid)
        $uploadResult = handle_hybrid_upload('file_modul', $externalUrlInput, DIR_UPLOAD_MODUL, 3);
        if (!$uploadResult['success']) {
            set_flash('error', $uploadResult['message']);
            redirect('modules/bak/materi.php');
        }

        $finalTipeLampiran = $uploadResult['tipe_lampiran'];
        $uploadedFile      = $uploadResult['file_name'];
        $finalExternalUrl  = $uploadResult['external_url'];

        // Jika user memilih tipe link tetapi URL kosong
        if ($tipeLampiranInput === 'link' && empty($finalExternalUrl)) {
            set_flash('error', 'Masukkan tautan URL eksternal yang valid untuk modul.');
            redirect('modules/bak/materi.php');
        }

        try {
            $stmtIns = $db->prepare("
                INSERT INTO tabel_materi 
                (id_layanan, id_pembuat_bak, kode_materi, nama_pelajaran, pertemuan_ke, judul_modul, ringkasan_materi, file_modul, tipe_lampiran, external_url, panduan_mentor, standar_tugas_produk, status_publikasi, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtIns->execute([
                $idLayanan, $pembuatBakId, $kodeMateri, $namaPelajaran,
                $pertemuanKe, $judulModul, $ringkasanMateri, $uploadedFile,
                $finalTipeLampiran, $finalExternalUrl,
                $panduanMentor, $standarTugas, $statusPublikasi
            ]);
            set_flash('success', 'Modul <strong>' . e($judulModul) . '</strong> berhasil diterbitkan untuk pertemuan ke-' . $pertemuanKe . '!');
        } catch (\Throwable $e) {
            set_flash('error', 'Gagal menyimpan modul: ' . $e->getMessage());
        }
        redirect('modules/bak/materi.php');

    } elseif ($action === 'update') {
        $id                = (int)($_POST['id'] ?? 0);
        $idLayanan         = (int)($_POST['id_layanan'] ?? 0);
        $kodeMateri        = strtoupper(trim($_POST['kode_materi'] ?? ''));
        $namaPelajaran     = trim($_POST['nama_pelajaran'] ?? '');
        $pertemuanKe       = (int)($_POST['pertemuan_ke'] ?? 1);
        $judulModul        = trim($_POST['judul_modul'] ?? '');
        $ringkasanMateri   = trim($_POST['ringkasan_materi'] ?? '');
        $panduanMentor     = trim($_POST['panduan_mentor'] ?? '');
        $standarTugas      = trim($_POST['standar_tugas_produk'] ?? '');
        $statusPublikasi   = in_array($_POST['status_publikasi'] ?? '', ['draft', 'published', 'archived'], true) ? $_POST['status_publikasi'] : 'draft';
        $tipeLampiranInput = $_POST['tipe_lampiran'] ?? 'file';
        $externalUrlInput  = trim($_POST['external_url'] ?? '');

        // Cek data existing
        $stmtExisting = $db->prepare("SELECT file_modul, tipe_lampiran, external_url FROM tabel_materi WHERE id = ?");
        $stmtExisting->execute([$id]);
        $existing = $stmtExisting->fetch();

        if (!$existing) {
            set_flash('error', 'Modul tidak ditemukan.');
            redirect('modules/bak/materi.php');
        }

        $finalTipeLampiran = $tipeLampiranInput;
        $finalFileModul    = $existing['file_modul'];
        $finalExternalUrl  = $existing['external_url'];

        if ($tipeLampiranInput === 'link') {
            if (empty($externalUrlInput)) {
                set_flash('error', 'Masukkan alamat URL tautan eksternal.');
                redirect('modules/bak/materi.php');
            }
            $uploadResult = handle_hybrid_upload([], $externalUrlInput, DIR_UPLOAD_MODUL, 3);
            if (!$uploadResult['success']) {
                set_flash('error', $uploadResult['message']);
                redirect('modules/bak/materi.php');
            }
            $finalExternalUrl = $uploadResult['external_url'];
            $finalTipeLampiran = 'link';
        } else {
            // Tipe file
            if (isset($_FILES['file_modul']) && $_FILES['file_modul']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadResult = handle_hybrid_upload('file_modul', null, DIR_UPLOAD_MODUL, 3);
                if (!$uploadResult['success']) {
                    set_flash('error', $uploadResult['message']);
                    redirect('modules/bak/materi.php');
                }
                $finalFileModul = $uploadResult['file_name'];
            }
            $finalTipeLampiran = 'file';
        }

        try {
            $stmtUp = $db->prepare("
                UPDATE tabel_materi 
                SET id_layanan = ?, kode_materi = ?, nama_pelajaran = ?, pertemuan_ke = ?, 
                    judul_modul = ?, ringkasan_materi = ?, file_modul = ?, tipe_lampiran = ?, external_url = ?,
                    panduan_mentor = ?, standar_tugas_produk = ?, status_publikasi = ?
                WHERE id = ?
            ");
            $stmtUp->execute([
                $idLayanan, $kodeMateri, $namaPelajaran, $pertemuanKe,
                $judulModul, $ringkasanMateri, $finalFileModul, $finalTipeLampiran, $finalExternalUrl,
                $panduanMentor, $standarTugas, $statusPublikasi, $id
            ]);
            set_flash('success', 'Modul <strong>' . e($judulModul) . '</strong> berhasil diperbarui!');
        } catch (\Throwable $e) {
            set_flash('error', 'Gagal memperbarui modul: ' . $e->getMessage());
        }
        redirect('modules/bak/materi.php');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmtDel = $db->prepare("DELETE FROM tabel_materi WHERE id = ?");
            $stmtDel->execute([$id]);
            set_flash('success', 'Modul berhasil dihapus dari sistem.');
        } catch (\Throwable $e) {
            set_flash('error', 'Gagal menghapus modul: ' . $e->getMessage());
        }
        redirect('modules/bak/materi.php');
    }
}

// Ambil seluruh program layanan untuk opsi dropdown
$layananOptions = $db->query("SELECT id, kode_layanan, nama_layanan, jumlah_pertemuan FROM tabel_layanan WHERE status_aktif = 'aktif' ORDER BY nama_layanan ASC")->fetchAll();

// Filter per Program Layanan
$filterLayananId = isset($_GET['layanan_id']) && $_GET['layanan_id'] !== '' ? (int)$_GET['layanan_id'] : null;

$sql = "
    SELECT m.*, 
           l.nama_layanan, l.kode_layanan, l.jumlah_pertemuan,
           p.nama_lengkap as nama_pembuat
    FROM tabel_materi m
    JOIN tabel_layanan l ON l.id = m.id_layanan
    LEFT JOIN tabel_pegawai p ON p.id = m.id_pembuat_bak
";
$params = [];

if ($filterLayananId !== null) {
    $sql .= " WHERE m.id_layanan = ?";
    $params[] = $filterLayananId;
}

$sql .= " ORDER BY m.id_layanan ASC, m.pertemuan_ke ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$materiList = $stmt->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-100 text-blue-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-file-pdf"></i>
            <span>Repositori Materi & Modul BAK (Hybrid Storage)</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Modul Pembelajaran Resmi untuk Mentor</h1>
        <p class="text-xs text-slate-500 mt-1">
            Materi terstruktur per pertemuan tatap muka. Mendukung upload berkas lokal (maks 3MB) dan tautan eksternal (GDrive, Figma, YouTube, GitHub).
        </p>
    </div>

    <div class="flex items-center gap-3">
        <!-- Filter Layanan -->
        <form method="GET" action="" class="flex items-center">
            <select name="layanan_id" onchange="this.form.submit()" class="px-3.5 py-2 text-xs font-bold rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <option value="">Semua Program Kursus</option>
                <?php foreach ($layananOptions as $lo): ?>
                    <option value="<?= (int)$lo['id'] ?>" <?= $filterLayananId === (int)$lo['id'] ? 'selected' : '' ?>>
                        <?= e($lo['nama_layanan']) ?> (<?= (int)$lo['jumlah_pertemuan'] ?> Sesi)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <button onclick="openModalCreate()" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20">
            <i class="fa-solid fa-cloud-arrow-up text-xs"></i>
            <span>Upload Modul Baru</span>
        </button>
    </div>
</div>

<!-- Tabel Modul -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Sesi</th>
                    <th>Kode & Judul Modul</th>
                    <th>Program Kursus</th>
                    <th>Panduan Mentor di Kelas</th>
                    <th>Standar Tugas Siswa</th>
                    <th>Lampiran Bahan Ajar</th>
                    <th>Status</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($materiList)): ?>
                    <?= render_empty_state(
                        'fa-file-pdf',
                        'Belum Ada Modul Kurikulum Terdata',
                        'Unggah modul pembelajaran resmi (PDF/PPT/DOCX atau Link GDrive/Figma/GitHub) agar dapat diakses oleh mentor dan siswa di kelas.',
                        '<button type="button" onclick="openModalCreate()" class="btn-brand text-xs py-2 px-4 shadow-md shadow-brand-500/20"><i class="fa-solid fa-cloud-arrow-up mr-1"></i> Upload Modul Pertama</button>',
                        8
                    ) ?>
                <?php else: ?>
                    <?php foreach ($materiList as $row): ?>
                    <tr>
                        <td class="text-center w-12">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-brand-50 text-brand-800 font-black text-xs border border-brand-200 shadow-sm">
                                <?= (int)$row['pertemuan_ke'] ?>
                            </span>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($row['judul_modul']) ?></div>
                            <div class="text-xs font-mono text-slate-400"><?= e($row['kode_materi']) ?> • <?= e($row['nama_pelajaran']) ?></div>
                            <div class="text-[11px] text-slate-500 mt-1 max-w-xs line-clamp-2" title="<?= e($row['ringkasan_materi']) ?>">
                                <?= e($row['ringkasan_materi']) ?>
                            </div>
                        </td>

                        <td>
                            <strong class="text-slate-800 text-xs block"><?= e($row['nama_layanan']) ?></strong>
                            <span class="text-[10px] text-slate-400 font-mono"><?= e($row['kode_layanan']) ?></span>
                        </td>

                        <td>
                            <div class="p-2 rounded-lg bg-teal-50/60 border border-teal-100 text-[11px] text-teal-900 max-w-xs">
                                <span class="font-bold text-teal-800 block text-[10px] uppercase">Instruksi:</span>
                                <span class="line-clamp-2" title="<?= e($row['panduan_mentor'] ?? '-') ?>">
                                    <?= e($row['panduan_mentor'] ?? 'Gunakan instruksi KBM standar') ?>
                                </span>
                            </div>
                        </td>

                        <td>
                            <div class="text-xs text-slate-700 max-w-xs line-clamp-2" title="<?= e($row['standar_tugas_produk'] ?? '-') ?>">
                                <?= e($row['standar_tugas_produk'] ?? 'Tugas latihan modul') ?>
                            </div>
                        </td>

                        <!-- LAMPIRAN HIBRIDA (FILE vs LINK) -->
                        <td>
                            <?php if ($row['tipe_lampiran'] === 'link' && !empty($row['external_url'])): ?>
                                <a href="<?= e($row['external_url']) ?>" target="_blank"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 hover:bg-sky-100 text-sky-700 font-bold text-xs border border-sky-200 shadow-sm transition-all">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    <span>Buka Link Modul</span>
                                </a>
                            <?php elseif (!empty($row['file_modul'])): ?>
                                <a href="<?= URL_UPLOAD_MODUL . e($row['file_modul']) ?>" target="_blank"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs border border-rose-200 shadow-sm transition-all">
                                    <i class="fa-solid fa-file-pdf"></i>
                                    <span>Unduh/Lihat File</span>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Tanpa Lampiran</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= render_status_badge($row['status_publikasi']) ?>
                        </td>

                        <td class="text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <button type="button" onclick='openModalEdit(<?= json_encode($row) ?>)'
                                        class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:border-brand-300 hover:bg-brand-50 text-slate-600 hover:text-brand-700 font-bold text-xs transition-all">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>

                                <form method="POST" action="" onsubmit="return confirm('Hapus modul pembelajaran ini?')">
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
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL FORM: TAMBAH MODUL BARU -->
<!-- ========================================================================= -->
<div id="modal-create" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Upload Modul & Bahan Ajar Baru</h3>
                <p class="text-xs text-slate-400">Standardisasi materi resmi untuk diajarkan mentor di kelas</p>
            </div>
            <button onclick="closeModalCreate()" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Program Kursus *</label>
                    <select name="id_layanan" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                        <?php foreach ($layananOptions as $lo): ?>
                            <option value="<?= (int)$lo['id'] ?>"><?= e($lo['nama_layanan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Pertemuan Sesi Ke- *</label>
                    <input type="number" name="pertemuan_ke" value="1" min="1" max="100" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kode Materi *</label>
                    <input type="text" name="kode_materi" required placeholder="MAT-COD-01" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 uppercase font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Nama Mata Pelajaran *</label>
                    <input type="text" name="nama_pelajaran" required placeholder="Contoh: Dasar Pemrograman Web" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Judul Modul Sesi *</label>
                    <input type="text" name="judul_modul" required placeholder="Contoh: Pengenalan Arsitektur Web & Struktur HTML5" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ringkasan Materi Pelajaran</label>
                    <textarea name="ringkasan_materi" rows="2" placeholder="Poin-poin konsep materi yang dibahas pada sesi ini..." class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">
                        <i class="fa-solid fa-chalkboard-user text-teal-600 mr-1"></i>
                        Panduan Instruksi Khusus Mentor di Kelas Offline
                    </label>
                    <textarea name="panduan_mentor" rows="2" placeholder="Petunjuk urutan praktek di papan tulis, tips mengajar, atau instruksi langkah lab komputer..." class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 bg-teal-50/30"></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Standar Tugas / Target Karya Siswa</label>
                    <textarea name="standar_tugas_produk" rows="2" placeholder="Kriteria produk atau latihan mandiri yang wajib diselesaikan siswa..." class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>

                <!-- PILIHAN MEDIA HYBRID: UPLOAD FILE vs LINK EKSTERNAL -->
                <div class="sm:col-span-2 p-4 rounded-2xl bg-slate-50 border border-slate-200">
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-2">Sumber Lampiran Modul</label>
                    <div class="flex items-center gap-4 mb-3">
                        <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                            <input type="radio" name="tipe_lampiran" value="file" checked onchange="toggleMateriSource('file', 'create')" class="text-brand-600">
                            <span><i class="fa-solid fa-file-arrow-up text-brand-600 mr-1"></i> Upload File (Maks 3MB)</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                            <input type="radio" name="tipe_lampiran" value="link" onchange="toggleMateriSource('link', 'create')" class="text-brand-600">
                            <span><i class="fa-solid fa-link text-sky-600 mr-1"></i> Gunakan Link Eksternal</span>
                        </label>
                    </div>

                    <div id="container-file-create">
                        <input type="file" name="file_modul" accept=".pdf,.ppt,.pptx,.docx,.zip" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 border border-slate-200 rounded-xl bg-white">
                        <p class="text-[10px] text-slate-400 mt-1">Format: PDF, PPT, PPTX, DOCX, ZIP (Maksimal 3MB).</p>
                    </div>

                    <div id="container-link-create" class="hidden">
                        <input type="url" name="external_url" placeholder="https://drive.google.com/... atau https://github.com/..." class="w-full px-3.5 py-2 text-xs rounded-xl border border-slate-200 bg-white">
                        <p class="text-[10px] text-slate-400 mt-1">Tautan eksternal (Google Drive, Canva Slides, YouTube Video, GitHub).</p>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Status Publikasi</label>
                    <select name="status_publikasi" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="published">Published (Siap Diajarkan)</option>
                        <option value="draft">Draft (Tahap Penyusunan)</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalCreate()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan & Terbitkan Modul</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL FORM: EDIT MODUL -->
<!-- ========================================================================= -->
<div id="modal-edit" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <h3 class="text-lg font-bold text-slate-800">Edit Modul & Bahan Ajar</h3>
            <button onclick="closeModalEdit()" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit-id" name="id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Program Kursus *</label>
                    <select id="edit-layanan" name="id_layanan" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                        <?php foreach ($layananOptions as $lo): ?>
                            <option value="<?= (int)$lo['id'] ?>"><?= e($lo['nama_layanan']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Pertemuan Sesi Ke- *</label>
                    <input type="number" id="edit-pertemuan" name="pertemuan_ke" min="1" max="100" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Kode Materi *</label>
                    <input type="text" id="edit-kode" name="kode_materi" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 uppercase font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Nama Mata Pelajaran *</label>
                    <input type="text" id="edit-pelajaran" name="nama_pelajaran" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Judul Modul Sesi *</label>
                    <input type="text" id="edit-judul" name="judul_modul" required class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Ringkasan Materi Pelajaran</label>
                    <textarea id="edit-ringkasan" name="ringkasan_materi" rows="2" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Panduan Instruksi Khusus Mentor di Kelas</label>
                    <textarea id="edit-panduan" name="panduan_mentor" rows="2" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 bg-teal-50/30"></textarea>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Standar Tugas / Target Karya Siswa</label>
                    <textarea id="edit-standar" name="standar_tugas_produk" rows="2" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200"></textarea>
                </div>

                <!-- EDIT PILIHAN MEDIA HYBRID -->
                <div class="sm:col-span-2 p-4 rounded-2xl bg-slate-50 border border-slate-200">
                    <label class="block text-xs font-bold uppercase text-slate-700 mb-2">Sumber Lampiran Modul</label>
                    <div class="flex items-center gap-4 mb-3">
                        <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                            <input type="radio" id="edit-radio-file" name="tipe_lampiran" value="file" onchange="toggleMateriSource('file', 'edit')" class="text-brand-600">
                            <span><i class="fa-solid fa-file-arrow-up text-brand-600 mr-1"></i> Upload File (Maks 3MB)</span>
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                            <input type="radio" id="edit-radio-link" name="tipe_lampiran" value="link" onchange="toggleMateriSource('link', 'edit')" class="text-brand-600">
                            <span><i class="fa-solid fa-link text-sky-600 mr-1"></i> Gunakan Link Eksternal</span>
                        </label>
                    </div>

                    <div id="container-file-edit">
                        <input type="file" name="file_modul" accept=".pdf,.ppt,.pptx,.docx,.zip" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 border border-slate-200 rounded-xl bg-white">
                        <p id="edit-file-info" class="text-[10px] text-slate-400 mt-1"></p>
                    </div>

                    <div id="container-link-edit" class="hidden">
                        <input type="url" id="edit-url" name="external_url" placeholder="https://drive.google.com/... atau https://github.com/..." class="w-full px-3.5 py-2 text-xs rounded-xl border border-slate-200 bg-white">
                        <p class="text-[10px] text-slate-400 mt-1">Tautan eksternal (Google Drive, Canva Slides, YouTube, GitHub).</p>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Status Publikasi</label>
                    <select id="edit-status" name="status_publikasi" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalEdit()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Perbarui Modul</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMateriSource(type, mode) {
    const fileContainer = document.getElementById('container-file-' + mode);
    const linkContainer = document.getElementById('container-link-' + mode);

    if (type === 'file') {
        fileContainer.classList.remove('hidden');
        linkContainer.classList.add('hidden');
    } else {
        fileContainer.classList.add('hidden');
        linkContainer.classList.remove('hidden');
    }
}

function openModalCreate() { 
    toggleMateriSource('file', 'create');
    document.getElementById('modal-create').classList.remove('hidden'); 
}
function closeModalCreate() { document.getElementById('modal-create').classList.add('hidden'); }

function openModalEdit(data) {
    document.getElementById('edit-id').value = data.id;
    document.getElementById('edit-layanan').value = data.id_layanan;
    document.getElementById('edit-pertemuan').value = data.pertemuan_ke;
    document.getElementById('edit-kode').value = data.kode_materi;
    document.getElementById('edit-pelajaran').value = data.nama_pelajaran;
    document.getElementById('edit-judul').value = data.judul_modul;
    document.getElementById('edit-ringkasan').value = data.ringkasan_materi || '';
    document.getElementById('edit-panduan').value = data.panduan_mentor || '';
    document.getElementById('edit-standar').value = data.standar_tugas_produk || '';
    document.getElementById('edit-status').value = data.status_publikasi;

    const tipe = data.tipe_lampiran || (data.file_modul ? 'file' : 'link');
    if (tipe === 'link') {
        document.getElementById('edit-radio-link').checked = true;
        toggleMateriSource('link', 'edit');
        document.getElementById('edit-url').value = data.external_url || '';
    } else {
        document.getElementById('edit-radio-file').checked = true;
        toggleMateriSource('file', 'edit');
        document.getElementById('edit-file-info').innerText = data.file_modul ? ('File saat ini: ' + data.file_modul) : 'Belum ada file diunggah.';
    }

    document.getElementById('modal-edit').classList.remove('hidden');
}
function closeModalEdit() { document.getElementById('modal-edit').classList.add('hidden'); }
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
