<?php
/**
 * SIM BISOLPIN ACADEMY - Katalog Modul Pembelajaran Siswa ala Ruangguru (Resmi dari BAK)
 */

declare(strict_types=1);

$pageTitle = 'Modul & Bahan Ajar Resmi';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Proteksi hak akses role SISWA
check_role(ROLE_SISWA);

$db = get_db();
$userId = auth_id();

// Ambil Profil Siswa
$stmtSiswa = $db->prepare("
    SELECT s.*, l.nama_layanan, l.kode_layanan, l.kategori_jenjang, l.jumlah_pertemuan, l.target_output_produk,
           c.nama_cabang, p.nama_lengkap as nama_mentor
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

$layananId = (int)$siswa['id_layanan'];
$pertemuanTerakhir = (int)$siswa['pertemuan_terakhir_diikuti'];

// Ambil Seluruh Modul Resmi dari BAK untuk Layanan Tersebut
$stmtMateri = $db->prepare("
    SELECT m.*, p.nama_lengkap as nama_penyusun_bak
    FROM tabel_materi m
    LEFT JOIN tabel_pegawai p ON p.id = m.id_pembuat_bak
    WHERE m.id_layanan = ? AND m.status_publikasi = 'published'
    ORDER BY m.pertemuan_ke ASC
");
$stmtMateri->execute([$layananId]);
$materiList = $stmtMateri->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman ala Ruangguru EdTech Catalog -->
<div class="mb-8 p-6 lg:p-8 rounded-2xl bg-gradient-to-r from-teal-900 via-brand-900 to-teal-800 text-white shadow-xl relative overflow-hidden">
    <div class="absolute -right-10 -bottom-10 w-64 h-64 rounded-full bg-teal-500/10 blur-2xl pointer-events-none"></div>

    <div class="relative z-10 max-w-3xl">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur text-teal-200 text-xs font-bold mb-3 border border-white/10">
            <i class="fa-solid fa-book-bookmark"></i>
            <span>Repositori Materi Terstandar Nasional (BAK Pusat)</span>
        </div>
        <h1 class="text-2xl lg:text-3xl font-black tracking-tight text-white">
            <?= e($siswa['nama_layanan']) ?>
        </h1>
        <p class="text-xs lg:text-sm text-teal-100/90 mt-2 leading-relaxed">
            Modul pembelajaran tatap muka resmi yang disusun oleh Bagian Akademik (BAK) Bisolpin Pusat. Setiap modul dilengkapi panduan praktikum dan standar tugas karya mandiri.
        </p>

        <!-- Milestone Progres -->
        <div class="mt-6 p-4 rounded-xl bg-white/10 backdrop-blur border border-white/15 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-teal-500 text-white font-black text-lg flex items-center justify-center shadow-lg">
                    <?= number_format((float)$siswa['persentase_progres'], 0) ?>%
                </div>
                <div>
                    <span class="block text-xs font-bold uppercase tracking-wider text-teal-200">Tahapan Sesi Saat Ini:</span>
                    <span class="text-sm font-extrabold text-white">Pertemuan Ke-<?= $pertemuanTerakhir ?> dari <?= $siswa['jumlah_pertemuan'] ?> Sesi</span>
                </div>
            </div>
            <div>
                <a href="<?= base_url('modules/siswa/produk_saya.php') ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-amber-400 hover:bg-amber-300 text-slate-900 text-xs font-black shadow-md transition-all">
                    <i class="fa-solid fa-palette"></i>
                    <span>Unggah Karya Proyek</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- List Modul Pembelajaran per Pertemuan ala Card Ruangguru -->
<div class="space-y-4">
    <?php if (empty($materiList)): ?>
        <div class="card p-12 text-center">
            <i class="fa-regular fa-folder-open text-4xl mb-3 text-slate-300 block"></i>
            <h3 class="text-sm font-bold text-slate-700">Modul Sedang Dipersiapkan</h3>
            <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                Bagian Akademik (BAK) sedang memfinalisasi silabus dan modul PDF untuk program kursus ini.
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($materiList as $m): ?>
            <?php
            $isCompleted = ($m['pertemuan_ke'] <= $pertemuanTerakhir);
            $isCurrent = ($m['pertemuan_ke'] === $pertemuanTerakhir + 1);
            ?>
            <div class="card p-6 border-2 transition-all duration-200 <?= $isCurrent ? 'border-teal-500 bg-teal-50/20 shadow-md ring-2 ring-teal-500/10' : ($isCompleted ? 'border-slate-200 hover:border-teal-200' : 'border-slate-100 opacity-90') ?>">
                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                    <!-- Left: Detail Modul -->
                    <div class="flex items-start gap-4">
                        <!-- Icon Pertemuan -->
                        <div class="w-12 h-12 rounded-2xl flex flex-col items-center justify-center flex-shrink-0 font-black text-sm <?= $isCompleted ? 'bg-emerald-100 text-emerald-800' : ($isCurrent ? 'bg-teal-600 text-white shadow-md shadow-teal-600/30' : 'bg-slate-100 text-slate-500') ?>">
                            <span class="text-[9px] uppercase font-bold tracking-wider leading-none">SESI</span>
                            <span class="text-base leading-none mt-0.5"><?= $m['pertemuan_ke'] ?></span>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[11px] font-bold text-teal-700 bg-teal-50 px-2 py-0.5 rounded border border-teal-200">
                                    <?= e($m['kode_materi']) ?>
                                </span>
                                <span class="text-xs font-semibold text-slate-500">
                                    <?= e($m['nama_pelajaran']) ?>
                                </span>
                                <?php if ($isCompleted): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                        <i class="fa-solid fa-circle-check text-[10px]"></i> Selesai Dipelajari
                                    </span>
                                <?php elseif ($isCurrent): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 animate-pulse">
                                        <i class="fa-solid fa-play text-[10px]"></i> Sesi Sedang Berjalan
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-500">
                                        <i class="fa-solid fa-lock text-[10px]"></i> Sesi Mendatang
                                    </span>
                                <?php endif; ?>
                            </div>

                            <h3 class="text-base font-black text-slate-800">
                                <?= e($m['judul_modul']) ?>
                            </h3>

                            <p class="text-xs text-slate-600 leading-relaxed max-w-3xl pt-1">
                                <?= e($m['ringkasan_materi']) ?>
                            </p>

                            <!-- Target Capaian Tugas Produk -->
                            <?php if (!empty($m['standar_tugas_produk'])): ?>
                                <div class="mt-3 p-3 rounded-xl bg-amber-50/70 border border-amber-200/80 text-xs text-amber-900 flex items-start gap-2">
                                    <i class="fa-solid fa-trophy text-amber-600 text-xs mt-0.5 flex-shrink-0"></i>
                                    <div>
                                        <strong class="font-bold">Target Karya Praktikum Pertemuan:</strong>
                                        <p class="text-[11px] text-amber-800 mt-0.5"><?= e($m['standar_tugas_produk']) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: Action Buttons -->
                    <div class="flex flex-row lg:flex-col items-center lg:items-end justify-between lg:justify-start gap-2 flex-shrink-0 border-t lg:border-t-0 pt-3 lg:pt-0 border-slate-100">
                        <?php 
                        $isLink = ($m['tipe_lampiran'] ?? '') === 'link' || (!empty($m['external_url']) && str_starts_with($m['external_url'], 'http'));
                        $urlTarget = $m['external_url'] ?? $m['file_modul'];
                        ?>
                        <?php if ($isLink && !empty($urlTarget)): ?>
                            <a href="<?= e($urlTarget) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-sky-50 text-sky-700 font-bold text-xs border border-sky-200 hover:bg-sky-100 shadow-sm transition-all whitespace-nowrap">
                                <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                <span>Buka Link Modul</span>
                            </a>
                        <?php elseif (!empty($m['file_modul'])): ?>
                            <a href="<?= base_url('assets/uploads/modul_bak/' . rawurlencode($m['file_modul'])) ?>" target="_blank" class="btn-brand text-xs py-2 px-4 shadow-sm inline-flex items-center gap-1.5 whitespace-nowrap">
                                <i class="fa-solid fa-file-pdf text-xs"></i>
                                <span>Unduh Modul PDF</span>
                            </a>
                        <?php else: ?>
                            <span class="text-xs text-slate-400 italic bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
                                Modul di Ruang Kelas
                            </span>
                        <?php endif; ?>

                        <button onclick="bacaRingkasan(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)" class="btn-brand-outline text-xs py-1.5 px-3">
                            <i class="fa-solid fa-eye text-xs mr-1"></i> Rincian Silabus
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODAL DETAIL SILABUS & RINGKASAN MODUL -->
<!-- ========================================================================= -->
<div id="modalDetailSilabus" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 my-8 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div>
                <span class="text-[10px] font-mono text-teal-700 font-bold" id="silabus_kode">-</span>
                <h3 class="text-base font-black text-slate-800" id="silabus_judul">-</h3>
            </div>
            <button onclick="closeModal('modalDetailSilabus')" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-slate-100 flex items-center justify-center">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="mt-4 space-y-4 text-xs">
            <div>
                <h4 class="font-bold text-slate-700 mb-1">Mata Pelajaran & Sesi:</h4>
                <p class="text-slate-600" id="silabus_pelajaran">-</p>
            </div>

            <div>
                <h4 class="font-bold text-slate-700 mb-1">Ringkasan Materi Sesi Tatap Muka:</h4>
                <p class="text-slate-600 leading-relaxed p-3 bg-slate-50 rounded-xl border border-slate-200" id="silabus_ringkasan">-</p>
            </div>

            <div>
                <h4 class="font-bold text-amber-900 mb-1">Standar Tugas & Output Produk Siswa:</h4>
                <p class="text-amber-800 leading-relaxed p-3 bg-amber-50 rounded-xl border border-amber-200" id="silabus_tugas">-</p>
            </div>

            <div class="pt-2 flex items-center justify-between text-slate-400 text-[11px]">
                <span>Penyusun Standar: <strong class="text-slate-600" id="silabus_bak">BAK Pusat</strong></span>
                <span class="text-teal-600 font-semibold">Bisolpin Curriculum Standard</span>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
            <button onclick="closeModal('modalDetailSilabus')" class="btn-brand-outline text-xs py-2 px-4">
                Tutup
            </button>
        </div>
    </div>
</div>

<script>
function bacaRingkasan(m) {
    document.getElementById('silabus_kode').textContent = m.kode_materi + ' • Pertemuan Ke-' + m.pertemuan_ke;
    document.getElementById('silabus_judul').textContent = m.judul_modul;
    document.getElementById('silabus_pelajaran').textContent = m.nama_pelajaran;
    document.getElementById('silabus_ringkasan').textContent = m.ringkasan_materi;
    document.getElementById('silabus_tugas').textContent = m.standar_tugas_produk || 'Menyelesaikan modul praktikum di kelas tatap muka.';
    document.getElementById('silabus_bak').textContent = m.nama_penyusun_bak || 'Bagian Akademik (BAK) Pusat';
    openModal('modalDetailSilabus');
}
</script>

<?php
require_once dirname(__DIR__, 2) . '/includes/footer.php';
?>

