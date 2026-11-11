<?php
/**
 * SIM BISOLPIN ACADEMY - Manajemen Portofolio & Upload Karya Siswa
 * Mendukung Hybrid Storage (Upload Berkas Fisik Maks 3MB & Link Eksternal GDrive/Figma/GitHub/YouTube)
 */

declare(strict_types=1);

$pageTitle = 'Karya & Produk Saya';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SISWA
check_role(ROLE_SISWA);

$db = get_db();
$userId = auth_id();

// Ambil Profil Siswa
$stmtSiswa = $db->prepare("
    SELECT s.*, l.nama_layanan, l.kode_layanan, c.nama_cabang, c.kode_cabang,
           p.nama_lengkap as nama_mentor
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    JOIN tabel_cabang c ON c.id = s.id_cabang
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    WHERE s.user_id = ?
");
$stmtSiswa->execute([$userId]);
$siswa = $stmtSiswa->fetch();

if (!$siswa) {
    echo "<div class='p-8 text-center text-rose-600 font-bold'>Data siswa tidak ditemukan.</div>";
    exit;
}

$siswaId = (int)$siswa['id'];
$branchId = (int)$siswa['id_cabang'];
$layananId = (int)$siswa['id_layanan'];
$mentorId = !empty($siswa['id_mentor_pembimbing']) ? (int)$siswa['id_mentor_pembimbing'] : null;

// Keamanan isolasi cabang
verify_branch_access($branchId);

// PROSES POST FORM
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action = $_POST['action'] ?? '';

    // 1. TAMBAH / UPLOAD KARYA BARU
    if ($action === 'tambah_produk') {
        $namaProduk        = trim($_POST['nama_produk'] ?? '');
        $deskripsi         = trim($_POST['deskripsi_produk'] ?? '');
        $idMateri          = !empty($_POST['id_materi']) ? (int)$_POST['id_materi'] : null;
        $progres           = (float)($_POST['progres_pembuatan'] ?? 100);
        $tipeLampiranInput = $_POST['tipe_lampiran'] ?? 'link';
        $externalUrlInput  = trim($_POST['external_url'] ?? '');

        if (empty($namaProduk) || empty($deskripsi)) {
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Judul karya dan deskripsi wajib diisi.', 'Validasi Gagal');
        }

        // Proses Hybrid Upload Karya (File Lokal Maks 3MB atau Link Eksternal)
        $uploadTarget = dirname(__DIR__, 2) . '/assets/uploads/produk_siswa';
        $uploadKarya = handle_hybrid_upload('file_karya', $externalUrlInput, $uploadTarget, 3);
        if (!$uploadKarya['success']) {
            redirect_with_message('modules/siswa/produk_saya.php', 'error', $uploadKarya['message'], 'Upload Gagal');
        }

        $finalTipe = $uploadKarya['tipe_lampiran'];
        $finalFileOrUrl = $uploadKarya['value'];
        $finalUrl = $uploadKarya['external_url'];

        if ($tipeLampiranInput === 'link' && empty($finalUrl)) {
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Masukkan tautan URL eksternal yang valid.', 'Validasi Gagal');
        }

        // Upload Thumbnail Foto Produk (Maks 5MB)
        $fotoFilename = null;
        if (isset($_FILES['foto_produk']) && $_FILES['foto_produk']['error'] === UPLOAD_ERR_OK) {
            $resUpload = handle_file_upload('foto_produk', $uploadTarget, ['jpg', 'jpeg', 'png', 'webp'], 5120);
            if (!$resUpload['success']) {
                redirect_with_message('modules/siswa/produk_saya.php', 'error', $resUpload['message'], 'Upload Gagal');
            }
            $fotoFilename = $resUpload['filename'];
        }

        // Generate Kode Produk Unik: PRD-CBG-XXXXX
        $kodeProduk = sprintf("PRD-%s-%04d", substr($siswa['kode_cabang'], -3), rand(100, 9999));

        try {
            $stmtInsert = $db->prepare("
                INSERT INTO tabel_produk (
                    id_cabang, kategori_kepemilikan, id_siswa, id_layanan, id_materi,
                    kode_produk, nama_produk, deskripsi_produk, foto_produk,
                    file_atau_url_karya, tipe_lampiran, external_url,
                    progres_pembuatan, nilai_karya, review_mentor,
                    id_mentor_validator, status_tampil, created_at
                ) VALUES (
                    ?, 'karya_siswa', ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, NULL, NULL,
                    ?, 'menunggu_validasi', NOW()
                )
            ");
            $stmtInsert->execute([
                $branchId, $siswaId, $layananId, $idMateri,
                $kodeProduk, $namaProduk, $deskripsi, $fotoFilename,
                $finalFileOrUrl, $finalTipe, $finalUrl,
                $progres, $mentorId
            ]);

            redirect_with_message('modules/siswa/produk_saya.php', 'success', "Karya '{$namaProduk}' berhasil diunggah dan diajukan ke Mentor Pembimbing untuk direview.", 'Pengajuan Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal tambah produk: " . $e->getMessage());
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Terjadi kesalahan sistem saat mengunggah karya: ' . $e->getMessage(), 'Gagal Simpan');
        }
    }

    // 2. EDIT KARYA
    if ($action === 'edit_produk') {
        $id                = (int)($_POST['id'] ?? 0);
        $namaProduk        = trim($_POST['nama_produk'] ?? '');
        $deskripsi         = trim($_POST['deskripsi_produk'] ?? '');
        $idMateri          = !empty($_POST['id_materi']) ? (int)$_POST['id_materi'] : null;
        $progres           = (float)($_POST['progres_pembuatan'] ?? 100);
        $tipeLampiranInput = $_POST['tipe_lampiran'] ?? 'link';
        $externalUrlInput  = trim($_POST['external_url'] ?? '');

        // Verifikasi kepemilikan karya oleh siswa yang bersangkutan & cabang
        $stmtCek = $db->prepare("SELECT * FROM tabel_produk WHERE id = ? AND id_siswa = ?");
        $stmtCek->execute([$id, $siswaId]);
        $existing = $stmtCek->fetch();

        if (!$existing) {
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Karya tidak ditemukan atau bukan milik Anda.', 'Akses Ditolak');
        }

        $uploadTarget = dirname(__DIR__, 2) . '/assets/uploads/produk_siswa';
        $finalTipe = $tipeLampiranInput;
        $finalFileOrUrl = $existing['file_atau_url_karya'];
        $finalUrl = $existing['external_url'];

        if ($tipeLampiranInput === 'link') {
            if (!empty($externalUrlInput)) {
                $resLink = handle_hybrid_upload([], $externalUrlInput, $uploadTarget, 3);
                if (!$resLink['success']) {
                    redirect_with_message('modules/siswa/produk_saya.php', 'error', $resLink['message'], 'Validasi URL Gagal');
                }
                $finalUrl = $resLink['external_url'];
                $finalFileOrUrl = $resLink['value'];
            }
        } else {
            if (isset($_FILES['file_karya']) && $_FILES['file_karya']['error'] !== UPLOAD_ERR_NO_FILE) {
                $resFile = handle_hybrid_upload('file_karya', null, $uploadTarget, 3);
                if (!$resFile['success']) {
                    redirect_with_message('modules/siswa/produk_saya.php', 'error', $resFile['message'], 'Upload File Gagal');
                }
                $finalFileOrUrl = $resFile['file_name'];
                $finalUrl = null;
            }
        }

        $fotoFilename = $existing['foto_produk'];
        if (isset($_FILES['foto_produk']) && $_FILES['foto_produk']['error'] === UPLOAD_ERR_OK) {
            $resUpload = handle_file_upload('foto_produk', $uploadTarget, ['jpg', 'jpeg', 'png', 'webp'], 5120);
            if ($resUpload['success']) {
                $fotoFilename = $resUpload['filename'];
            }
        }

        $newStatus = ($existing['status_tampil'] === 'revisi') ? 'menunggu_validasi' : $existing['status_tampil'];

        try {
            $stmtUpdate = $db->prepare("
                UPDATE tabel_produk SET
                    nama_produk = ?,
                    deskripsi_produk = ?,
                    id_materi = ?,
                    file_atau_url_karya = ?,
                    tipe_lampiran = ?,
                    external_url = ?,
                    foto_produk = ?,
                    progres_pembuatan = ?,
                    status_tampil = ?
                WHERE id = ? AND id_siswa = ?
            ");
            $stmtUpdate->execute([
                $namaProduk, $deskripsi, $idMateri,
                $finalFileOrUrl, $finalTipe, $finalUrl,
                $fotoFilename, $progres,
                $newStatus, $id, $siswaId
            ]);

            redirect_with_message('modules/siswa/produk_saya.php', 'success', 'Perubahan karya berhasil disimpan.', 'Update Berhasil');
        } catch (\Throwable $e) {
            error_log("Gagal edit produk: " . $e->getMessage());
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Gagal memperbarui karya: ' . $e->getMessage(), 'Gagal Update');
        }
    }

    // 3. HAPUS KARYA
    if ($action === 'hapus_produk') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmtDel = $db->prepare("DELETE FROM tabel_produk WHERE id = ? AND id_siswa = ? AND status_tampil != 'dipublikasikan'");
            $stmtDel->execute([$id, $siswaId]);

            redirect_with_message('modules/siswa/produk_saya.php', 'success', 'Karya berhasil dihapus.', 'Hapus Berhasil');
        } catch (\Throwable $e) {
            redirect_with_message('modules/siswa/produk_saya.php', 'error', 'Karya yang sudah dipublikasikan di galeri tidak dapat dihapus sembarangan.', 'Gagal Hapus');
        }
    }
}

// AMBIL SELURUH KARYA SISWA INI
$stmtProduk = $db->prepare("
    SELECT p.*, m.pertemuan_ke, m.judul_modul, m.nama_pelajaran,
           v.nama_lengkap as nama_validator
    FROM tabel_produk p
    LEFT JOIN tabel_materi m ON m.id = p.id_materi
    LEFT JOIN tabel_pegawai v ON v.id = p.id_mentor_validator
    WHERE p.id_siswa = ?
    ORDER BY p.created_at DESC
");
$stmtProduk->execute([$siswaId]);
$produkList = $stmtProduk->fetchAll();

// AMBIL MATERI UNTUK DROPDOWN
$stmtMateri = $db->prepare("
    SELECT id, pertemuan_ke, judul_modul FROM tabel_materi
    WHERE id_layanan = ? AND status_publikasi = 'published'
    ORDER BY pertemuan_ke ASC
");
$stmtMateri->execute([$layananId]);
$materiPilihan = $stmtMateri->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-shapes text-teal-600"></i>
            <span>Etalase Portofolio & Tugas Tatap Muka</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Karya & Produk Saya</h1>
        <p class="text-xs text-slate-500 mt-1">
            Unggah hasil tugas praktek offline, prototipe coding, atau proyek tugas akhir untuk dinilai oleh Mentor Pembimbing.
        </p>
    </div>

    <div class="flex items-center gap-3">
        <a href="<?= base_url('modules/siswa/galeri_karya.php') ?>" class="btn-brand-outline text-xs py-2.5 px-4">
            <i class="fa-solid fa-store text-xs mr-1"></i> Galeri Publik
        </a>
        <button onclick="openModal('modalUploadKarya')" class="btn-brand text-xs py-2.5 px-4 shadow-lg shadow-brand-500/20">
            <i class="fa-solid fa-plus text-xs mr-1"></i> Unggah Karya Baru
        </button>
    </div>
</div>

<!-- Daftar Karya -->
<?php if (empty($produkList)): ?>
    <?= render_empty_state(
        'fa-palette',
        'Portofolio Karya Belum Ada',
        'Anda belum mengunggah karya. Klik tombol \'+ Unggah Karya Pertama Saya\' untuk membangun portofolio Anda.',
        '<button type="button" onclick="openModal(\'modalUploadKarya\')" class="btn-brand text-xs py-2.5 px-5 shadow-md shadow-brand-500/20"><i class="fa-solid fa-cloud-arrow-up mr-1"></i> + Unggah Karya Pertama Saya</button>',
        0
    ) ?>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        <?php foreach ($produkList as $p): ?>
            <div class="card overflow-hidden flex flex-col justify-between hover:shadow-lg transition-all border border-slate-200">
                <div>
                    <!-- Thumbnail Header -->
                    <div class="relative h-44 bg-slate-100 border-b border-slate-100 overflow-hidden group">
                        <?php if (!empty($p['foto_produk'])): ?>
                            <img src="<?= base_url('assets/uploads/produk_siswa/' . rawurlencode($p['foto_produk'])) ?>" alt="<?= e($p['nama_produk']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-slate-300 bg-gradient-to-br from-slate-100 to-slate-200">
                                <i class="fa-solid fa-laptop-code text-4xl mb-2 text-slate-400"></i>
                                <span class="text-[11px] font-semibold text-slate-500">Thumbnail Belum Tersedia</span>
                            </div>
                        <?php endif; ?>

                        <!-- Status Badge -->
                        <div class="absolute top-3 left-3">
                            <?= render_status_badge($p['status_tampil']) ?>
                        </div>

                        <!-- Nilai Badge jika ada -->
                        <?php if ($p['nilai_karya'] !== null): ?>
                            <div class="absolute top-3 right-3 bg-white/95 backdrop-blur-sm px-2.5 py-1 rounded-xl shadow-md border border-slate-200 flex items-center gap-1.5">
                                <i class="fa-solid fa-star text-amber-500 text-xs"></i>
                                <span class="text-xs font-black text-slate-800"><?= number_format((float)$p['nilai_karya'], 0) ?></span>
                                <span class="text-[10px] text-slate-400">/ 100</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Detail Body -->
                    <div class="p-5">
                        <div class="flex items-center gap-2 text-[11px] text-slate-400 mb-1 font-mono">
                            <span><?= e($p['kode_produk']) ?></span>
                            <?php if (!empty($p['pertemuan_ke'])): ?>
                                <span>•</span>
                                <span class="text-brand-600 font-bold">Sesi Pertemuan Ke-<?= $p['pertemuan_ke'] ?></span>
                            <?php endif; ?>
                        </div>

                        <h3 class="font-bold text-slate-800 text-sm mb-2 line-clamp-1" title="<?= e($p['nama_produk']) ?>">
                            <?= e($p['nama_produk']) ?>
                        </h3>

                        <p class="text-xs text-slate-500 line-clamp-2 mb-4 leading-relaxed">
                            <?= e($p['deskripsi_produk']) ?>
                        </p>

                        <!-- Review Mentor jika ada -->
                        <?php if (!empty($p['review_mentor'])): ?>
                            <div class="p-3 rounded-xl bg-amber-50/80 border border-amber-200 text-xs text-amber-900 mb-3">
                                <span class="font-bold block text-[10px] uppercase text-amber-800 mb-0.5">
                                    <i class="fa-solid fa-comment-dots mr-1"></i> Feedback Mentor:
                                </span>
                                <p class="text-slate-700 italic text-[11px]">"<?= e($p['review_mentor']) ?>"</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Footer Aksi -->
                <div class="p-5 pt-0">
                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                        <!-- TOMBOL HYBRID: LINK EKSTERNAL vs UNDUH FILE -->
                        <?php 
                        $isLink = ($p['tipe_lampiran'] === 'link') || (!empty($p['external_url']) && str_starts_with($p['external_url'], 'http')) || (!empty($p['file_atau_url_karya']) && str_starts_with($p['file_atau_url_karya'], 'http'));
                        $linkTarget = $p['external_url'] ?: $p['file_atau_url_karya'];
                        ?>

                        <?php if ($isLink && !empty($linkTarget)): ?>
                            <a href="<?= e($linkTarget) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 font-bold text-xs border border-sky-200 hover:bg-sky-100 transition-all">
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                <span>Buka Link Karya</span>
                            </a>
                        <?php elseif (!empty($p['file_atau_url_karya'])): ?>
                            <a href="<?= base_url('assets/uploads/produk_siswa/' . rawurlencode($p['file_atau_url_karya'])) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 font-bold text-xs border border-indigo-200 hover:bg-indigo-100 transition-all">
                                <i class="fa-solid fa-download text-[10px]"></i>
                                <span>Unduh/Lihat File</span>
                            </a>
                        <?php else: ?>
                            <span class="text-[11px] text-slate-400 italic">Tanpa Lampiran</span>
                        <?php endif; ?>

                        <div class="inline-flex items-center gap-1.5">
                            <button onclick="editProduk(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-200 inline-flex items-center justify-center transition-colors" title="Edit Karya">
                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                            </button>

                            <?php if ($p['status_tampil'] !== 'dipublikasikan'): ?>
                                <form method="POST" action="" onsubmit="return confirm('Hapus karya ini?');" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="hapus_produk">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-500 hover:bg-rose-100 inline-flex items-center justify-center transition-colors" title="Hapus Karya">
                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL 1: FORM UNGGAH KARYA BARU (HYBRID STORAGE) -->
<!-- ========================================================================= -->
<div id="modalUploadKarya" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Unggah Karya / Produk Belajar</h3>
                <p class="text-xs text-slate-400 mt-0.5">Karya akan diajukan ke mentor pembimbing untuk dinilai.</p>
            </div>
            <button onclick="closeModal('modalUploadKarya')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_produk">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Nama Karya <span class="text-rose-500">*</span></label>
                <input type="text" name="nama_produk" placeholder="Contoh: Website Portofolio Sains atau Smart Home Arduino" required class="form-input text-xs">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Terkait Sesi Modul Pertemuan (Opsional)</label>
                <select name="id_materi" class="form-input text-xs">
                    <option value="">-- Tugas Akhir / Proyek Mandiri --</option>
                    <?php foreach ($materiPilihan as $mp): ?>
                        <option value="<?= $mp['id'] ?>">
                            Sesi <?= $mp['pertemuan_ke'] ?>: <?= e($mp['judul_modul']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Deskripsi Hasil Karya & Fitur Utama <span class="text-rose-500">*</span></label>
                <textarea name="deskripsi_produk" rows="3" placeholder="Ceritakan fitur aplikasi, teknologi yang digunakan, atau fungsi kit robotik yang kamu buat..." required class="form-input text-xs"></textarea>
            </div>

            <!-- PILIHAN MEDIA HYBRID: UPLOAD FILE vs LINK EKSTERNAL -->
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                <label class="block text-xs font-bold text-slate-700 mb-2">Lampiran Berkas / Tautan Karya</label>
                <div class="flex items-center gap-4 mb-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                        <input type="radio" name="tipe_lampiran" value="link" checked onchange="toggleKaryaSource('link', 'create')" class="text-brand-600">
                        <span><i class="fa-solid fa-link text-sky-600 mr-1"></i> Link Eksternal (GitHub / Figma / GDrive)</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                        <input type="radio" name="tipe_lampiran" value="file" onchange="toggleKaryaSource('file', 'create')" class="text-brand-600">
                        <span><i class="fa-solid fa-file-arrow-up text-brand-600 mr-1"></i> Upload File (Maks 3MB)</span>
                    </label>
                </div>

                <div id="container-karya-link-create">
                    <input type="url" name="external_url" placeholder="https://github.com/username/project atau https://drive.google.com/..." class="form-input text-xs bg-white">
                    <p class="text-[10px] text-slate-400 mt-1">Gunakan link publik GitHub, Figma prototype, Canva slide, atau Google Drive.</p>
                </div>

                <div id="container-karya-file-create" class="hidden">
                    <input type="file" name="file_karya" accept=".zip,.rar,.pdf,.png,.jpg,.jpeg,.docx" class="form-input text-xs bg-white py-1.5">
                    <p class="text-[10px] text-slate-400 mt-1">Format arsip ZIP, PDF, atau gambar dokumen (Maks 3MB).</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Progres Pengerjaan (%)</label>
                    <input type="number" name="progres_pembuatan" value="100" min="1" max="100" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Foto / Screenshot Thumbnail</label>
                    <input type="file" name="foto_produk" accept="image/png, image/jpeg, image/webp" class="form-input text-xs py-1.5">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalUploadKarya')" class="btn-brand-outline text-xs py-2 px-4">
                    Batal
                </button>
                <button type="submit" class="btn-brand text-xs py-2.5 px-6">
                    <i class="fa-solid fa-paper-plane text-xs mr-1"></i> Submit untuk Direview
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL 2: FORM EDIT KARYA -->
<!-- ========================================================================= -->
<div id="modalEditKarya" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-800">Edit Data Karya Siswa</h3>
                <p class="text-xs text-slate-400 mt-0.5" id="editKaryaKode">-</p>
            </div>
            <button onclick="closeModal('modalEditKarya')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="mt-4 space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_produk">
            <input type="hidden" name="id" id="edit_karya_id" value="">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Judul / Nama Karya <span class="text-rose-500">*</span></label>
                <input type="text" name="nama_produk" id="edit_karya_nama" required class="form-input text-xs">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Terkait Modul / Pertemuan</label>
                <select name="id_materi" id="edit_karya_materi" class="form-input text-xs">
                    <option value="">-- Tugas Akhir / Mandiri --</option>
                    <?php foreach ($materiPilihan as $mp): ?>
                        <option value="<?= $mp['id'] ?>">
                            Sesi <?= $mp['pertemuan_ke'] ?>: <?= e($mp['judul_modul']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Deskripsi Karya <span class="text-rose-500">*</span></label>
                <textarea name="deskripsi_produk" id="edit_karya_deskripsi" rows="3" required class="form-input text-xs"></textarea>
            </div>

            <!-- EDIT PILIHAN MEDIA HYBRID -->
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                <label class="block text-xs font-bold text-slate-700 mb-2">Lampiran Berkas / Tautan Karya</label>
                <div class="flex items-center gap-4 mb-3">
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                        <input type="radio" id="edit-radio-link" name="tipe_lampiran" value="link" onchange="toggleKaryaSource('link', 'edit')" class="text-brand-600">
                        <span><i class="fa-solid fa-link text-sky-600 mr-1"></i> Link Eksternal</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-700 bg-white px-3 py-2 rounded-xl border border-slate-300 hover:bg-slate-100">
                        <input type="radio" id="edit-radio-file" name="tipe_lampiran" value="file" onchange="toggleKaryaSource('file', 'edit')" class="text-brand-600">
                        <span><i class="fa-solid fa-file-arrow-up text-brand-600 mr-1"></i> Upload File (Maks 3MB)</span>
                    </label>
                </div>

                <div id="container-karya-link-edit">
                    <input type="url" name="external_url" id="edit_karya_url" placeholder="https://github.com/..." class="form-input text-xs bg-white">
                    <p class="text-[10px] text-slate-400 mt-1">Tautan URL publik proyek atau repo.</p>
                </div>

                <div id="container-karya-file-edit" class="hidden">
                    <input type="file" name="file_karya" accept=".zip,.rar,.pdf,.png,.jpg,.jpeg,.docx" class="form-input text-xs bg-white py-1.5">
                    <p id="edit-file-karya-info" class="text-[10px] text-slate-400 mt-1"></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Progres (%)</label>
                    <input type="number" name="progres_pembuatan" id="edit_karya_progres" min="1" max="100" required class="form-input text-xs">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Ganti Screenshot / Foto (Opsional)</label>
                    <input type="file" name="foto_produk" accept="image/png, image/jpeg, image/webp" class="form-input text-xs py-1.5">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('modalEditKarya')" class="btn-brand-outline text-xs py-2 px-4">
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
function toggleKaryaSource(type, mode) {
    const linkContainer = document.getElementById('container-karya-link-' + mode);
    const fileContainer = document.getElementById('container-karya-file-' + mode);

    if (type === 'file') {
        fileContainer.classList.remove('hidden');
        linkContainer.classList.add('hidden');
    } else {
        linkContainer.classList.remove('hidden');
        fileContainer.classList.add('hidden');
    }
}

function editProduk(p) {
    document.getElementById('edit_karya_id').value = p.id;
    document.getElementById('editKaryaKode').textContent = 'Kode: ' + p.kode_produk + ' • Status: ' + p.status_tampil.toUpperCase();
    document.getElementById('edit_karya_nama').value = p.nama_produk;
    document.getElementById('edit_karya_materi').value = p.id_materi || '';
    document.getElementById('edit_karya_deskripsi').value = p.deskripsi_produk;
    document.getElementById('edit_karya_progres').value = Math.round(p.progres_pembuatan);

    const isLink = (p.tipe_lampiran === 'link') || (p.external_url && p.external_url.startsWith('http')) || (p.file_atau_url_karya && p.file_atau_url_karya.startsWith('http'));
    if (isLink) {
        document.getElementById('edit-radio-link').checked = true;
        toggleKaryaSource('link', 'edit');
        document.getElementById('edit_karya_url').value = p.external_url || p.file_atau_url_karya || '';
    } else {
        document.getElementById('edit-radio-file').checked = true;
        toggleKaryaSource('file', 'edit');
        document.getElementById('edit-file-karya-info').textContent = p.file_atau_url_karya ? ('File saat ini: ' + p.file_atau_url_karya) : 'Belum ada file diunggah.';
    }

    openModal('modalEditKarya');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>
