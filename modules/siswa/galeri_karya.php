<?php
/**
 * SIM BISOLPIN ACADEMY - Galeri Portofolio & Showcase Karya Siswa Bisolpin
 */

declare(strict_types=1);

$pageTitle = 'Galeri Karya & Portofolio Siswa';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SISWA
check_role(ROLE_SISWA);

$db = get_db();

// Filter Pencarian & Program Layanan
$filterLayanan = !empty($_GET['layanan']) ? (int)$_GET['layanan'] : 0;
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, s.nama_lengkap as nama_siswa, s.nis_bisolpin, s.asal_sekolah_instansi,
           l.nama_layanan, l.kategori_jenjang, c.nama_cabang, c.kota as kota_cabang,
           v.nama_lengkap as nama_mentor_validator, m.pertemuan_ke, m.judul_modul
    FROM tabel_produk p
    LEFT JOIN tabel_siswa s ON s.id = p.id_siswa
    LEFT JOIN tabel_layanan l ON l.id = p.id_layanan
    LEFT JOIN tabel_cabang c ON c.id = p.id_cabang
    LEFT JOIN tabel_pegawai v ON v.id = p.id_mentor_validator
    LEFT JOIN tabel_materi m ON m.id = p.id_materi
    WHERE p.status_tampil = 'dipublikasikan'
";
$params = [];

if (!empty($filterLayanan)) {
    $sql .= " AND p.id_layanan = ?";
    $params[] = $filterLayanan;
}
if (!empty($search)) {
    $sql .= " AND (p.nama_produk LIKE ? OR p.deskripsi_produk LIKE ? OR s.nama_lengkap LIKE ? OR c.nama_cabang LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY p.nilai_karya DESC, p.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$galeriList = $stmt->fetchAll();

// Daftar Layanan untuk Dropdown Filter
$layananList = $db->query("SELECT id, nama_layanan FROM tabel_layanan WHERE status_aktif = 'aktif' ORDER BY nama_layanan ASC")->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Galeri Showcase ala Dribbble / Product Hunt EdTech -->
<div class="mb-8 p-6 lg:p-8 rounded-2xl bg-gradient-to-r from-teal-900 via-brand-900 to-teal-800 text-white shadow-xl relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-64 h-64 rounded-full bg-teal-500/10 blur-2xl pointer-events-none"></div>

    <div class="relative z-10 max-w-3xl">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur text-teal-200 text-xs font-bold mb-3 border border-white/10">
            <i class="fa-solid fa-award"></i>
            <span>Hall of Fame & Portofolio Siswa BISOLPIN</span>
        </div>
        <h1 class="text-2xl lg:text-3xl font-black tracking-tight text-white">
            Galeri Karya & Produk Praktikum
        </h1>
        <p class="text-xs lg:text-sm text-teal-100/90 mt-2 leading-relaxed">
            Kumpulan hasil karya terbaik siswa kursus tatap muka di seluruh cabang Bisolpin yang telah divalidasi dan diuji langsung oleh mentor pengajar.
        </p>

        <div class="mt-5 flex items-center gap-3">
            <a href="<?= base_url('modules/siswa/produk_saya.php') ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-teal-500 hover:bg-teal-400 text-white text-xs font-bold shadow-md transition-all">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <span>Unggah Karyamu Sekarang</span>
            </a>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-4 mb-8">
    <form method="GET" action="" class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-3 flex-1 min-w-[280px]">
            <div class="relative flex-1 min-w-[200px]">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                </span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari judul karya, pembuat, teknologi..." class="form-input pl-9 text-xs py-2 w-full">
            </div>

            <div class="w-56">
                <select name="layanan" class="form-input text-xs py-2">
                    <option value="">Semua Kategori Program</option>
                    <?php foreach ($layananList as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= $filterLayanan === $l['id'] ? 'selected' : '' ?>>
                            <?= e($l['nama_layanan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn-brand-outline text-xs py-2 px-3">
                <i class="fa-solid fa-filter text-xs"></i> Filter
            </button>

            <?php if (!empty($search) || !empty($filterLayanan)): ?>
                <a href="<?= base_url('modules/siswa/galeri_karya.php') ?>" class="text-xs text-rose-500 hover:underline">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <div class="text-xs text-slate-500 font-medium">
            Karya Terpilih: <span class="font-bold text-slate-800"><?= count($galeriList) ?> Proyek Unggulan</span>
        </div>
    </form>
</div>

<!-- Grid Showcase Karya Siswa -->
<?php if (empty($galeriList)): ?>
    <div class="card p-12 text-center">
        <i class="fa-regular fa-folder-open text-4xl mb-3 text-slate-300 block"></i>
        <h3 class="text-base font-bold text-slate-700">Tidak Ada Karya yang Sesuai</h3>
        <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
            Belum ada karya dengan kriteria pencarian tersebut yang telah dipublikasikan di galeri.
        </p>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($galeriList as $g): ?>
            <div class="card overflow-hidden hover:border-brand-300 hover:shadow-xl transition-all duration-300 flex flex-col justify-between group">
                <div>
                    <!-- Thumbnail Foto -->
                    <div class="w-full h-48 bg-slate-100 relative overflow-hidden">
                        <?php if (!empty($g['foto_produk']) && file_exists(dirname(__DIR__, 2) . '/assets/uploads/produk_siswa/' . $g['foto_produk'])): ?>
                            <img src="<?= base_url('assets/uploads/produk_siswa/' . rawurlencode($g['foto_produk'])) ?>" alt="<?= e($g['nama_produk']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-slate-400 bg-gradient-to-br from-slate-100 to-slate-200">
                                <i class="fa-solid fa-code text-4xl mb-2 text-slate-300"></i>
                                <span class="text-[10px] font-semibold uppercase tracking-wider">Portofolio Siswa</span>
                            </div>
                        <?php endif; ?>

                        <!-- Nilai Badge Overlay -->
                        <?php if ($g['nilai_karya'] !== null): ?>
                            <div class="absolute top-3 left-3 px-2.5 py-1 rounded-lg bg-slate-900/85 backdrop-blur text-amber-300 font-black text-xs border border-white/20 flex items-center gap-1 shadow-lg">
                                <i class="fa-solid fa-star text-[10px] text-amber-400"></i>
                                <span><?= number_format((float)$g['nilai_karya'], 0) ?> / 100</span>
                            </div>
                        <?php endif; ?>

                        <!-- Lencana Cabang -->
                        <div class="absolute bottom-3 right-3 px-2.5 py-0.5 rounded-full bg-teal-900/80 backdrop-blur text-white text-[10px] font-semibold">
                            <?= e($g['kota_cabang'] ?? $g['nama_cabang']) ?>
                        </div>
                    </div>

                    <!-- Detail Konten -->
                    <div class="p-5">
                        <div class="flex items-center justify-between text-[11px] text-slate-400 font-medium mb-1">
                            <span class="text-teal-700 font-bold"><?= e($g['nama_layanan']) ?></span>
                            <span><?= e($g['kategori_jenjang']) ?></span>
                        </div>

                        <h3 class="text-base font-black text-slate-800 hover:text-brand-600 transition-colors line-clamp-1">
                            <?= e($g['nama_produk']) ?>
                        </h3>

                        <p class="text-xs text-slate-600 line-clamp-2 mt-2 leading-relaxed">
                            <?= e($g['deskripsi_produk']) ?>
                        </p>

                        <!-- Profil Pembuat Karya -->
                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-800 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                <?= strtoupper(substr($g['nama_siswa'] ?? 'S', 0, 1)) ?>
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-bold text-slate-800 truncate"><?= e($g['nama_siswa'] ?? 'Siswa Bisolpin') ?></h4>
                                <p class="text-[10px] text-slate-400 truncate"><?= e($g['asal_sekolah_instansi'] ?? 'Pelajar Bisolpin') ?></p>
                            </div>
                        </div>

                        <!-- Review Mentor jika ada -->
                        <?php if (!empty($g['review_mentor'])): ?>
                            <div class="mt-3 p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600">
                                <div class="flex items-center gap-1 text-[10px] font-bold text-teal-800 uppercase tracking-wider mb-0.5">
                                    <i class="fa-solid fa-badge-check text-teal-600"></i>
                                    <span>Ulasan Validator:</span>
                                </div>
                                <p class="italic text-[11px] line-clamp-2">"<?= e($g['review_mentor']) ?>"</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Footer Aksi -->
                <div class="p-5 pt-0">
                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                        <button onclick="lihatDetail(<?= htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8') ?>)" class="btn-brand-outline text-xs py-1.5 px-3">
                            <i class="fa-solid fa-eye text-xs mr-1"></i> Detail Karya
                        </button>

                        <?php 
                        $isLink = ($g['tipe_lampiran'] ?? '') === 'link' || (!empty($g['external_url']) && str_starts_with($g['external_url'], 'http')) || (!empty($g['file_atau_url_karya']) && str_starts_with($g['file_atau_url_karya'], 'http'));
                        $urlTarget = $g['external_url'] ?? $g['file_atau_url_karya'];
                        ?>
                        <?php if ($isLink && !empty($urlTarget)): ?>
                            <a href="<?= e($urlTarget) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-sky-50 text-sky-700 font-bold text-xs border border-sky-200 hover:bg-sky-100 transition-all">
                                <span>Buka Link Karya</span>
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                            </a>
                        <?php elseif (!empty($g['file_atau_url_karya'])): ?>
                            <a href="<?= base_url('assets/uploads/produk_siswa/' . rawurlencode($g['file_atau_url_karya'])) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 font-bold text-xs border border-indigo-200 hover:bg-indigo-100 transition-all">
                                <span>Unduh/Lihat File</span>
                                <i class="fa-solid fa-download text-[10px]"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL DETAIL KARYA SISWA -->
<!-- ========================================================================= -->
<div id="modalDetailKarya" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <span class="text-[10px] font-mono text-teal-700 font-bold" id="detail_kode">-</span>
                <h3 class="text-base font-black text-slate-800" id="detail_nama">-</h3>
            </div>
            <button onclick="closeModal('modalDetailKarya')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="mt-4 space-y-4 text-xs">
            <!-- Info Siswa & Cabang -->
            <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-200 flex items-center justify-between">
                <div>
                    <span class="block text-[10px] text-slate-400 font-bold uppercase">Pencipta Karya:</span>
                    <h4 class="font-bold text-slate-800 text-sm" id="detail_siswa">-</h4>
                    <p class="text-[11px] text-slate-500" id="detail_sekolah">-</p>
                </div>
                <div class="text-right">
                    <span class="block text-[10px] text-slate-400 font-bold uppercase">Cabang Belajar:</span>
                    <span class="font-semibold text-teal-800" id="detail_cabang">-</span>
                    <div id="detail_nilai_box" class="mt-1 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 font-black text-xs">
                        <i class="fa-solid fa-star text-[10px]"></i>
                        <span id="detail_nilai">-</span>
                    </div>
                </div>
            </div>

            <div>
                <h4 class="font-bold text-slate-700 mb-1">Deskripsi & Rincian Karya:</h4>
                <p class="text-slate-600 leading-relaxed p-3.5 bg-white rounded-xl border border-slate-200 whitespace-pre-line" id="detail_deskripsi">-</p>
            </div>

            <div>
                <h4 class="font-bold text-teal-900 mb-1">Ulasan Evaluasi Mentor Validator:</h4>
                <p class="text-teal-900 italic leading-relaxed p-3.5 bg-teal-50 rounded-xl border border-teal-200" id="detail_review">-</p>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-between gap-3 pt-3 border-t border-slate-100">
            <span class="text-[11px] text-slate-400">Verifikasi Resmi Kurikulum BISOLPIN</span>
            <div class="flex items-center gap-2">
                <button onclick="closeModal('modalDetailKarya')" class="btn-brand-outline text-xs py-2 px-4">
                    Tutup
                </button>
                <a id="detail_demo_link" href="#" target="_blank" class="btn-brand text-xs py-2 px-4 inline-flex items-center gap-1.5">
                    <span>Kunjungi Demo Karya</span>
                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function lihatDetail(g) {
    document.getElementById('detail_kode').textContent = g.kode_produk + ' • ' + g.nama_layanan;
    document.getElementById('detail_nama').textContent = g.nama_produk;
    document.getElementById('detail_siswa').textContent = g.nama_siswa || 'Siswa Bisolpin';
    document.getElementById('detail_sekolah').textContent = (g.asal_sekolah_instansi || 'Pelajar') + ' (' + g.nis_bisolpin + ')';
    document.getElementById('detail_cabang').textContent = g.nama_cabang;
    document.getElementById('detail_nilai').textContent = (g.nilai_karya ? Number(g.nilai_karya) : '-') + ' / 100';
    document.getElementById('detail_deskripsi').textContent = g.deskripsi_produk;
    document.getElementById('detail_review').textContent = g.review_mentor || 'Karya memenuhi standar kualitas capaian kurikulum tatap muka Bisolpin.';

    const demoLink = document.getElementById('detail_demo_link');
    if (g.file_atau_url_karya) {
        demoLink.href = g.file_atau_url_karya;
        demoLink.classList.remove('hidden');
    } else {
        demoLink.classList.add('hidden');
    }

    openModal('modalDetailKarya');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

