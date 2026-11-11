<?php
/**
 * SIM BISOLPIN ACADEMY - Validasi & Penilaian Portofolio Karya Siswa (Mentor)
 */

declare(strict_types=1);

$pageTitle = 'Validasi Karya Siswa';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_MENTOR);

$db = get_db();

// Ambil profil data mentor
$stmtPegawai = $db->prepare("SELECT id, nama_lengkap, id_cabang FROM tabel_pegawai WHERE user_id = ?");
$stmtPegawai->execute([auth_id()]);
$mentor = $stmtPegawai->fetch();
$mentorId = $mentor ? (int)$mentor['id'] : 5;
$branchId = $mentor ? (int)$mentor['id_cabang'] : 1;

// Handle Form POST: Validasi & Penilaian Produk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $produkId     = (int)($_POST['produk_id'] ?? 0);
    $nilaiKarya   = (float)($_POST['nilai_karya'] ?? 0);
    $reviewMentor = trim($_POST['review_mentor'] ?? '');
    $statusTampil = in_array($_POST['status_tampil'] ?? '', ['dipublikasikan', 'revisi', 'draft', 'menunggu_validasi'], true) ? $_POST['status_tampil'] : 'dipublikasikan';

    // Batasi nilai antara 0 s/d 100
    $nilaiKarya = max(0.0, min(100.0, $nilaiKarya));

    try {
        $stmtUp = $db->prepare("
            UPDATE tabel_produk 
            SET nilai_karya = ?, review_mentor = ?, id_mentor_validator = ?, status_tampil = ?
            WHERE id = ? AND id_cabang = ?
        ");
        $stmtUp->execute([$nilaiKarya, $reviewMentor, $mentorId, $statusTampil, $produkId, $branchId]);

        set_flash('success', 'Karya siswa berhasil dinilai dan status publikasi diperbarui!');
    } catch (\Throwable $e) {
        set_flash('error', 'Gagal memvalidasi produk: ' . $e->getMessage());
    }
    redirect('modules/mentor/validasi_produk.php');
}

// Filter Tab Status
$filterStatus = $_GET['status'] ?? 'all';

$sql = "
    SELECT p.*, 
           s.nama_lengkap as nama_siswa, s.nis_bisolpin,
           l.nama_layanan, l.kode_layanan,
           m.nama_lengkap as nama_validator
    FROM tabel_produk p
    JOIN tabel_siswa s ON s.id = p.id_siswa
    LEFT JOIN tabel_layanan l ON l.id = p.id_layanan
    LEFT JOIN tabel_pegawai m ON m.id = p.id_mentor_validator
    WHERE p.id_cabang = ?
";
$params = [$branchId];

if ($filterStatus === 'pending') {
    $sql .= " AND p.status_tampil = 'menunggu_validasi'";
} elseif ($filterStatus === 'published') {
    $sql .= " AND p.status_tampil = 'dipublikasikan'";
} elseif ($filterStatus === 'revisi') {
    $sql .= " AND p.status_tampil = 'revisi'";
}

$sql .= " ORDER BY CASE WHEN p.status_tampil = 'menunggu_validasi' THEN 1 ELSE 2 END, p.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$produkList = $stmt->fetchAll();

// Hitung total counter
$stmtCount = $db->prepare("SELECT COUNT(*) FROM tabel_produk WHERE id_cabang = ? AND status_tampil = 'menunggu_validasi'");
$stmtCount->execute([$branchId]);
$countPending = (int)$stmtCount->fetchColumn();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-100 text-purple-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-check-to-slot"></i>
            <span>Penilaian & Validasi Portofolio</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Validasi Karya & Produk Siswa</h1>
        <p class="text-xs text-slate-500 mt-1">
            Berikan nilai dan ulasan kualitatif terhadap karya siswa tatap muka sebelum diterbitkan ke galeri publik.
        </p>
    </div>

    <!-- Tab Filter -->
    <div class="inline-flex rounded-xl bg-white p-1 border border-slate-200 shadow-sm text-xs">
        <a href="?status=all" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'all' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Semua Karya
        </a>
        <a href="?status=pending" class="px-3.5 py-1.5 rounded-lg font-bold transition-all flex items-center gap-1.5 <?= $filterStatus === 'pending' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            <span>Menunggu Validasi</span>
            <?php if ($countPending > 0): ?>
                <span class="w-5 h-5 rounded-full bg-amber-500 text-white text-[10px] flex items-center justify-center font-black">
                    <?= $countPending ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="?status=published" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'published' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Dipublikasikan
        </a>
        <a href="?status=revisi" class="px-3.5 py-1.5 rounded-lg font-bold transition-all <?= $filterStatus === 'revisi' ? 'bg-brand-500 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
            Perlu Revisi
        </a>
    </div>
</div>

<!-- Tabel / Card Karya Siswa -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>Kode & Nama Produk</th>
                    <th>Siswa Pembuat</th>
                    <th>Program Kursus</th>
                    <th>Skor Nilai</th>
                    <th>Catatan Evaluasi Mentor</th>
                    <th>Tautan Karya</th>
                    <th>Status</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produkList)): ?>
                    <?= render_empty_state(
                        'fa-award',
                        'Belum Ada Karya Siswa untuk Divalidasi',
                        'Siswa yang telah menyelesaikan proyek praktikum tatap muka akan mengunggah portofolio karya mereka di sini untuk Anda berikan nilai dan catatan evaluasi.',
                        null,
                        8
                    ) ?>
                <?php else: ?>
                    <?php foreach ($produkList as $row): ?>
                    <tr class="<?= $row['status_tampil'] === 'menunggu_validasi' ? 'bg-amber-50/50 font-medium' : '' ?>">
                        <td>
                            <strong class="text-slate-800 text-sm block"><?= e($row['nama_produk']) ?></strong>
                            <span class="text-xs font-mono text-slate-400">Kode: <?= e($row['kode_produk']) ?></span>
                            <div class="text-[11px] text-slate-500 mt-0.5 max-w-xs line-clamp-2" title="<?= e($row['deskripsi_produk']) ?>">
                                <?= e($row['deskripsi_produk']) ?>
                            </div>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs"><?= e($row['nama_siswa']) ?></div>
                            <div class="text-[10px] text-slate-400 font-mono">NIS: <?= e($row['nis_bisolpin']) ?></div>
                        </td>

                        <td>
                            <strong class="text-brand-700 text-xs block"><?= e($row['nama_layanan']) ?></strong>
                            <span class="text-[10px] text-slate-400 font-mono"><?= e($row['kode_layanan']) ?></span>
                        </td>

                        <td>
                            <?php if ($row['nilai_karya']): ?>
                                <span class="inline-block px-2.5 py-0.5 rounded-md bg-emerald-50 text-emerald-700 font-black text-xs border border-emerald-200">
                                    <?= (float)$row['nilai_karya'] ?> / 100
                                </span>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Belum dinilai</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($row['review_mentor'])): ?>
                                <div class="text-xs text-slate-600 italic bg-slate-50 p-2 rounded-xl border border-slate-100 max-w-xs">
                                    "<?= e($row['review_mentor']) ?>"
                                </div>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Belum ada catatan</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($row['file_atau_url_karya'])): ?>
                                <a href="<?= e($row['file_atau_url_karya']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-800 font-bold text-xs transition-colors">
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                    <span>Lihat Demo</span>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Tidak ada URL</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= render_status_badge($row['status_tampil']) ?>
                        </td>

                        <td class="text-right">
                            <button type="button" onclick='openModalValidasi(<?= json_encode($row) ?>)'
                                    class="btn-brand text-xs py-1.5 px-3 shadow-sm shadow-brand-500/20">
                                <i class="fa-solid fa-stamp text-[10px]"></i>
                                <span>Nilai & Validasi</span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL FORM: PENILAIAN & VALIDASI KARYA -->
<!-- ========================================================================= -->
<div id="modal-validasi" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Form Validasi Karya Siswa</h3>
                <p class="text-xs text-slate-400" id="modal-produk-nama">-</p>
            </div>
            <button onclick="closeModalValidasi()" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" id="edit-produk-id" name="produk_id" value="">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Skor Nilai (0 - 100) *</label>
                    <input type="number" id="edit-nilai" name="nilai_karya" min="0" max="100" step="1" required
                           placeholder="Contoh: 90" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-black text-brand-600">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Keputusan Validasi *</label>
                    <select id="edit-status-tampil" name="status_tampil" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-bold">
                        <option value="dipublikasikan">✅ Setujui & Publikasikan</option>
                        <option value="revisi">⚠️ Perlu Revisi / Perbaikan</option>
                        <option value="menunggu_validasi">⏳ Menunggu Validasi</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">
                    <i class="fa-solid fa-comment-dots text-brand-600 mr-1"></i>
                    Catatan Review & Ulasan Kualitatif Mentor *
                </label>
                <textarea id="edit-review" name="review_mentor" rows="3" required
                          placeholder="Uraikan kelebihan karya, implementasi konsep KBM tatap muka yang sudah baik, serta saran pengembangan..."
                          class="w-full px-3.5 py-2 text-xs rounded-xl border border-slate-200"></textarea>
                <p class="text-[10px] text-slate-400 mt-0.5">Ulasan ini akan tampil di samping karya pada Galeri Siswa.</p>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalValidasi()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Validasi Karya</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalValidasi(data) {
    document.getElementById('edit-produk-id').value = data.id;
    document.getElementById('modal-produk-nama').innerText = data.nama_produk + ' • Pembuat: ' + data.nama_siswa;
    document.getElementById('edit-nilai').value = data.nilai_karya ? parseInt(data.nilai_karya) : 85;
    document.getElementById('edit-review').value = data.review_mentor || '';
    document.getElementById('edit-status-tampil').value = data.status_tampil;

    document.getElementById('modal-validasi').classList.remove('hidden');
}

function closeModalValidasi() {
    document.getElementById('modal-validasi').classList.add('hidden');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

