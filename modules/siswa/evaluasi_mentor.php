<?php
/**
 * SIM BISOLPIN ACADEMY - Evaluasi & Rating Kinerja Mentor oleh Siswa (EDOM)
 * Pengisian Ulasan & Rating 4 Aspek Pedagogis secara Anonim
 */

declare(strict_types=1);

$pageTitle = 'Evaluasi & Rating Mentor';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_SISWA);

$db = get_db();
$userId = auth_id();

// Ambil profil siswa beserta data mentor & layanannya
$stmtSiswa = $db->prepare("
    SELECT s.*, 
           l.nama_layanan, l.kode_layanan,
           c.nama_cabang, c.kota as kota_cabang,
           p.nama_lengkap as nama_mentor, p.nip_pegawai as nip_mentor, p.spesialisasi_mentor
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    JOIN tabel_cabang c ON c.id = s.id_cabang
    LEFT JOIN tabel_pegawai p ON p.id = s.id_mentor_pembimbing
    WHERE s.user_id = ?
");
$stmtSiswa->execute([$userId]);
$siswa = $stmtSiswa->fetch();

if (!$siswa) {
    redirect_with_message('modules/siswa/index.php', 'error', 'Data profil siswa tidak ditemukan.');
}

$siswaId   = (int)$siswa['id'];
$branchId  = (int)$siswa['id_cabang'];
$layananId = (int)$siswa['id_layanan'];
$mentorId  = (int)($siswa['id_mentor_pembimbing'] ?? 0);

// Auto-migrate: Pastikan tabel_evaluasi_mentor sudah ada di database
ensure_table_evaluasi_mentor_exists($db);

$evaluasiExisting = null;
try {
    // Cek apakah siswa sudah pernah memberikan evaluasi untuk mentor & layanan ini
    $stmtEval = $db->prepare("
        SELECT * FROM tabel_evaluasi_mentor 
        WHERE id_siswa = ? AND id_mentor = ? AND id_layanan = ?
    ");
    $stmtEval->execute([$siswaId, $mentorId, $layananId]);
    $evaluasiExisting = $stmtEval->fetch();
} catch (\Throwable $e) {
    error_log("Error cek evaluasi existing: " . $e->getMessage());
}

// ------------------------------------------------------------------------------
// PENANGANAN FORM SUBMIT EVALUASI
// ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    if ($mentorId <= 0) {
        redirect_with_message('modules/siswa/evaluasi_mentor.php', 'error', 'Anda belum memiliki mentor pembimbing yang ditugaskan.', 'Gagal');
    }

    if ($evaluasiExisting) {
        redirect_with_message('modules/siswa/evaluasi_mentor.php', 'info', 'Anda sudah pernah mengirimkan evaluasi untuk mentor ini.', 'Sudah Terisi');
    }

    $skorMateri       = max(1, min(5, (int)($_POST['skor_materi'] ?? 0)));
    $skorPenyampaian  = max(1, min(5, (int)($_POST['skor_penyampaian'] ?? 0)));
    $skorKedisiplinan = max(1, min(5, (int)($_POST['skor_kedisiplinan'] ?? 0)));
    $skorKesabaran    = max(1, min(5, (int)($_POST['skor_kesabaran'] ?? 0)));
    $ulasanSaran      = trim($_POST['ulasan_saran'] ?? '');
    $isAnonymous      = isset($_POST['is_anonymous']) ? 1 : 1; // Default selalu 1 untuk proteksi siswa

    if ($skorMateri < 1 || $skorPenyampaian < 1 || $skorKedisiplinan < 1 || $skorKesabaran < 1) {
        redirect_with_message('modules/siswa/evaluasi_mentor.php', 'error', 'Mohon berikan rating bintang (1 s/d 5) untuk seluruh 4 indikator penilaian.', 'Validasi Belum Lengkap');
    }

    // Hitung nilai rata-rata
    $nilaiRataRata = round(($skorMateri + $skorPenyampaian + $skorKedisiplinan + $skorKesabaran) / 4, 2);

    try {
        $stmtIns = $db->prepare("
            INSERT INTO tabel_evaluasi_mentor (
                id_cabang, id_siswa, id_mentor, id_layanan,
                skor_materi, skor_penyampaian, skor_kedisiplinan, skor_kesabaran,
                nilai_rata_rata, ulasan_saran, is_anonymous, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmtIns->execute([
            $branchId, $siswaId, $mentorId, $layananId,
            $skorMateri, $skorPenyampaian, $skorKedisiplinan, $skorKesabaran,
            $nilaiRataRata, $ulasanSaran, $isAnonymous
        ]);

        redirect_with_message('modules/siswa/evaluasi_mentor.php', 'success', 'Terima kasih! Evaluasi kinerja mentor Anda berhasil disimpan secara anonim.', 'Penilaian Terkirim');
    } catch (\Throwable $e) {
        error_log("Gagal simpan evaluasi mentor: " . $e->getMessage());
        redirect_with_message('modules/siswa/evaluasi_mentor.php', 'error', 'Terjadi kesalahan sistem saat menyimpan evaluasi: ' . $e->getMessage(), 'Gagal Simpan');
    }
}

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-star"></i>
            <span>Evaluasi Dosen & Mentor (EDOM)</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Evaluasi Kinerja & Mutu Mentor</h1>
        <p class="text-xs text-slate-500 mt-1">
            Bantu BISOLPIN ACADEMY menjaga standar mutu pengajaran tatap muka melalui masukan yang objektif dan konstruktif.
        </p>
    </div>

    <div>
        <a href="<?= base_url('modules/siswa/index.php') ?>" class="btn-brand-outline text-xs py-2 px-4 shadow-sm flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-left text-xs"></i>
            <span>Kembali ke Dashboard</span>
        </a>
    </div>
</div>

<?php if ($mentorId <= 0 || empty($siswa['nama_mentor'])): ?>
    <!-- Fallback Jika Belum Ada Mentor Ditugaskan -->
    <div class="bg-white rounded-3xl border border-slate-200 p-8 text-center max-w-lg mx-auto shadow-sm">
        <div class="w-16 h-16 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center text-3xl mx-auto mb-4">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <h3 class="text-base font-bold text-slate-800 mb-1">Mentor Pembimbing Belum Ditugaskan</h3>
        <p class="text-xs text-slate-500 leading-relaxed mb-4">
            Akun Anda belum memiliki mentor pembimbing aktif untuk program <strong><?= e($siswa['nama_layanan']) ?></strong>. Fitur penilaian akan aktif setelah Staff cabang menetapkan mentor untuk kelas Anda.
        </p>
        <a href="<?= base_url('modules/siswa/index.php') ?>" class="btn-brand text-xs py-2 px-4">
            Kembali ke Beranda Siswa
        </a>
    </div>

<?php elseif ($evaluasiExisting): ?>
    <!-- ========================================================================= -->
    <!-- KONDISI SUDAH MENILAI (READ-ONLY SUMMARY) -->
    <!-- ========================================================================= -->
    <div class="max-w-2xl mx-auto space-y-6">
        <div class="bg-gradient-to-br from-emerald-50 via-teal-50 to-white rounded-3xl border border-emerald-200 p-6 sm:p-8 shadow-sm text-center relative overflow-hidden">
            <div class="w-16 h-16 rounded-3xl bg-emerald-500 text-white flex items-center justify-center text-3xl mx-auto mb-3 shadow-lg shadow-emerald-500/20">
                <i class="fa-solid fa-check-double"></i>
            </div>
            <h2 class="text-xl font-black text-slate-800">Terima Kasih atas Penilaian Anda!</h2>
            <p class="text-xs text-slate-600 mt-1 max-w-md mx-auto leading-relaxed">
                Anda telah mengirimkan evaluasi untuk mentor <strong><?= e($siswa['nama_mentor']) ?></strong> pada program <strong><?= e($siswa['nama_layanan']) ?></strong>.
            </p>

            <div class="mt-6 inline-flex flex-col items-center bg-white px-6 py-4 rounded-2xl border border-emerald-200/80 shadow-sm">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Skor Rata-Rata yang Anda Berikan</span>
                <div class="flex items-baseline gap-2 mt-1">
                    <span class="text-4xl font-black text-emerald-700"><?= number_format((float)$evaluasiExisting['nilai_rata_rata'], 2) ?></span>
                    <span class="text-sm font-bold text-slate-400">/ 5.00</span>
                </div>
                <div class="mt-2">
                    <?= render_star_rating($evaluasiExisting['nilai_rata_rata']) ?>
                </div>
            </div>

            <div class="mt-6 text-[11px] text-slate-400">
                Dikirimkan pada: <strong class="text-slate-600"><?= format_tanggal_indonesia($evaluasiExisting['created_at']) ?></strong>
            </div>
        </div>

        <!-- Rincian Skor per Aspek -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-list-check text-teal-600"></i>
                <span>Rincian Indikator Penilaian Anda</span>
            </h3>

            <div class="space-y-3 text-xs">
                <div class="p-3 rounded-2xl bg-slate-50 flex items-center justify-between">
                    <div>
                        <strong class="text-slate-800 block">1. Penguasaan Materi</strong>
                        <span class="text-[11px] text-slate-500">Kedalaman materi silabus & pemecahan error</span>
                    </div>
                    <div class="text-right">
                        <span class="font-black text-slate-800 text-sm"><?= (int)$evaluasiExisting['skor_materi'] ?> / 5</span>
                        <div><?= render_star_rating($evaluasiExisting['skor_materi']) ?></div>
                    </div>
                </div>

                <div class="p-3 rounded-2xl bg-slate-50 flex items-center justify-between">
                    <div>
                        <strong class="text-slate-800 block">2. Kejelasan Penyampaian</strong>
                        <span class="text-[11px] text-slate-500">Struktur penyampaian & artikulasi komunikasi</span>
                    </div>
                    <div class="text-right">
                        <span class="font-black text-slate-800 text-sm"><?= (int)$evaluasiExisting['skor_penyampaian'] ?> / 5</span>
                        <div><?= render_star_rating($evaluasiExisting['skor_penyampaian']) ?></div>
                    </div>
                </div>

                <div class="p-3 rounded-2xl bg-slate-50 flex items-center justify-between">
                    <div>
                        <strong class="text-slate-800 block">3. Kedisiplinan Waktu</strong>
                        <span class="text-[11px] text-slate-500">Ketepatan jam mulai & pemanfaatan sesi KBM</span>
                    </div>
                    <div class="text-right">
                        <span class="font-black text-slate-800 text-sm"><?= (int)$evaluasiExisting['skor_kedisiplinan'] ?> / 5</span>
                        <div><?= render_star_rating($evaluasiExisting['skor_kedisiplinan']) ?></div>
                    </div>
                </div>

                <div class="p-3 rounded-2xl bg-slate-50 flex items-center justify-between">
                    <div>
                        <strong class="text-slate-800 block">4. Kesabaran & Pendampingan</strong>
                        <span class="text-[11px] text-slate-500">Keramahan, motivasi & kesabaran membimbing</span>
                    </div>
                    <div class="text-right">
                        <span class="font-black text-slate-800 text-sm"><?= (int)$evaluasiExisting['skor_kesabaran'] ?> / 5</span>
                        <div><?= render_star_rating($evaluasiExisting['skor_kesabaran']) ?></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($evaluasiExisting['ulasan_saran'])): ?>
                <div class="mt-5 pt-4 border-t border-slate-100">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Catatan / Ulasan yang Anda Kirimkan:</span>
                    <p class="text-xs text-slate-700 italic bg-amber-50/60 border border-amber-200/80 p-3.5 rounded-2xl leading-relaxed">
                        "<?= e($evaluasiExisting['ulasan_saran']) ?>"
                    </p>
                </div>
            <?php endif; ?>

            <div class="mt-4 p-3 rounded-2xl bg-teal-50/70 border border-teal-200/60 text-[11px] text-teal-900 flex items-center gap-2">
                <i class="fa-solid fa-shield-halved text-teal-600 text-sm shrink-0"></i>
                <span>Identitas Anda 100% terlindungi. Ulasan ini ditampilkan ke mentor tanpa memuat nama maupun nomor induk Anda.</span>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ========================================================================= -->
    <!-- FORMULIR EVALUASI MENTOR (BELUM MENILAI) -->
    <!-- ========================================================================= -->
    <div class="max-w-2xl mx-auto">
        <!-- Kartu Identitas Mentor yang Dinilai -->
        <div class="mb-6 p-5 rounded-3xl bg-white border border-slate-200 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-700 flex items-center justify-center font-black text-xl border border-teal-200 shrink-0">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Mentor yang Anda Nilai:</span>
                    <h3 class="text-base font-black text-slate-800 leading-tight"><?= e($siswa['nama_mentor']) ?></h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Program: <strong class="text-teal-700"><?= e($siswa['nama_layanan']) ?></strong> &bull; <?= e($siswa['nama_cabang']) ?>
                    </p>
                </div>
            </div>

            <div class="px-3.5 py-1.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs font-bold text-center">
                <span>⭐ Wajib Isi 4 Bintang</span>
            </div>
        </div>

        <form method="POST" action="" id="formEvaluasi" class="space-y-6">
            <?= csrf_field() ?>

            <!-- PEMBERITAHUAN PRIVASI & ANONIMITAS 100% -->
            <div class="p-4 rounded-2xl bg-gradient-to-r from-teal-50 to-emerald-50 border border-teal-200 text-xs text-teal-950 flex items-start gap-3 shadow-sm">
                <div class="w-8 h-8 rounded-xl bg-teal-600 text-white flex items-center justify-center text-sm shrink-0 shadow-sm">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <h4 class="font-bold text-teal-950 text-xs">Jaminan Kerahasiaan & 100% Anonim</h4>
                    <p class="text-[11px] text-teal-800 mt-0.5 leading-relaxed">
                        Nama, NIS, dan profil Anda <strong>tidak akan pernah ditampilkan</strong> kepada mentor maupun publik. Mentor hanya dapat melihat rata-rata nilai dan rangkuman saran tanpa mengetahui identitas siswa yang menilai.
                    </p>
                </div>
            </div>

            <!-- CONTAINER 4 INDIKATOR PENILAIAN DENGAN INTERACTIVE STAR RATING -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-6">
                <h3 class="text-sm font-bold text-slate-800 pb-3 border-b border-slate-100 flex items-center justify-between">
                    <span>Indikator Penilaian Kinerja Pedagogis</span>
                    <span id="label-live-average" class="text-xs font-black px-2.5 py-1 rounded-full bg-slate-100 text-slate-600">
                        Pilih Bintang di Bawah
                    </span>
                </h3>

                <!-- INDIKATOR 1: PENGUASAAN MATERI -->
                <div class="p-4 rounded-2xl bg-slate-50/70 border border-slate-100 space-y-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                        <div>
                            <strong class="text-xs font-bold text-slate-800 block">1. Penguasaan Materi Silabus & Modul</strong>
                            <p class="text-[11px] text-slate-500">Sejauh mana mentor menguasai kurikulum dan tanggap menyelesaikan kendala teknis/soal?</p>
                        </div>
                        <span id="text-desc-materi" class="text-xs font-bold text-amber-600 sm:text-right">Pilih Rating</span>
                    </div>

                    <div class="flex items-center gap-1 pt-1" data-aspect="materi">
                        <input type="hidden" name="skor_materi" id="input-materi" value="5" required>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" onclick="setRating('materi', <?= $i ?>)" class="star-btn w-9 h-9 rounded-xl flex items-center justify-center text-xl transition-all text-amber-400 hover:scale-110 active:scale-95" data-star="<?= $i ?>">
                                <i class="fa-solid fa-star"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- INDIKATOR 2: KEJELASAN PENYAMPAIAN -->
                <div class="p-4 rounded-2xl bg-slate-50/70 border border-slate-100 space-y-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                        <div>
                            <strong class="text-xs font-bold text-slate-800 block">2. Kejelasan Cara Penyampaian</strong>
                            <p class="text-[11px] text-slate-500">Apakah penjelasan mentor mudah dicerna, runtut, artikulatif, dan menyenangkan?</p>
                        </div>
                        <span id="text-desc-penyampaian" class="text-xs font-bold text-amber-600 sm:text-right">Pilih Rating</span>
                    </div>

                    <div class="flex items-center gap-1 pt-1" data-aspect="penyampaian">
                        <input type="hidden" name="skor_penyampaian" id="input-penyampaian" value="5" required>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" onclick="setRating('penyampaian', <?= $i ?>)" class="star-btn w-9 h-9 rounded-xl flex items-center justify-center text-xl transition-all text-amber-400 hover:scale-110 active:scale-95" data-star="<?= $i ?>">
                                <i class="fa-solid fa-star"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- INDIKATOR 3: KEDISIPLINAN WAKTU -->
                <div class="p-4 rounded-2xl bg-slate-50/70 border border-slate-100 space-y-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                        <div>
                            <strong class="text-xs font-bold text-slate-800 block">3. Kedisiplinan & Ketepatan Waktu</strong>
                            <p class="text-[11px] text-slate-500">Apakah mentor selalu hadir tepat waktu dan mengoptimalkan durasi tatap muka kelas?</p>
                        </div>
                        <span id="text-desc-kedisiplinan" class="text-xs font-bold text-amber-600 sm:text-right">Pilih Rating</span>
                    </div>

                    <div class="flex items-center gap-1 pt-1" data-aspect="kedisiplinan">
                        <input type="hidden" name="skor_kedisiplinan" id="input-kedisiplinan" value="5" required>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" onclick="setRating('kedisiplinan', <?= $i ?>)" class="star-btn w-9 h-9 rounded-xl flex items-center justify-center text-xl transition-all text-amber-400 hover:scale-110 active:scale-95" data-star="<?= $i ?>">
                                <i class="fa-solid fa-star"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- INDIKATOR 4: KESABARAN & PENDAMPINGAN -->
                <div class="p-4 rounded-2xl bg-slate-50/70 border border-slate-100 space-y-2">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                        <div>
                            <strong class="text-xs font-bold text-slate-800 block">4. Kesabaran & Sikap Pendampingan</strong>
                            <p class="text-[11px] text-slate-500">Apakah mentor bersikap ramah, sabar saat mengajari, dan menyemangati belajar?</p>
                        </div>
                        <span id="text-desc-kesabaran" class="text-xs font-bold text-amber-600 sm:text-right">Pilih Rating</span>
                    </div>

                    <div class="flex items-center gap-1 pt-1" data-aspect="kesabaran">
                        <input type="hidden" name="skor_kesabaran" id="input-kesabaran" value="5" required>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" onclick="setRating('kesabaran', <?= $i ?>)" class="star-btn w-9 h-9 rounded-xl flex items-center justify-center text-xl transition-all text-amber-400 hover:scale-110 active:scale-95" data-star="<?= $i ?>">
                                <i class="fa-solid fa-star"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- TEXTAREA ULASAN & SARAN -->
                <div class="pt-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                        <span>Kritik, Saran & Ulasan Membangun (Opsional)</span>
                        <span class="text-[10px] text-slate-400 font-normal">Maksimal 1000 karakter</span>
                    </label>
                    <textarea name="ulasan_saran" rows="3" maxlength="1000" placeholder="Tuliskan apresiasi, saran metode mengajar, atau masukan untuk mentor Anda di sini..." class="w-full px-4 py-3 text-xs rounded-2xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all resize-y"></textarea>
                </div>
            </div>

            <!-- TOMBOL SUBMIT -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <label class="inline-flex items-center gap-2 text-xs text-slate-600 font-semibold cursor-pointer">
                    <input type="checkbox" name="is_anonymous" value="1" checked disabled class="rounded text-brand-600 focus:ring-brand-500">
                    <span>Tetap Anonim (Identitas saya tidak akan pernah dibagikan)</span>
                </label>

                <button type="submit" class="btn-brand py-3 px-6 text-xs font-black rounded-2xl shadow-lg shadow-brand-500/20 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-paper-plane text-xs"></i>
                    <span>Kirim Evaluasi Mentor</span>
                </button>
            </div>
        </form>
    </div>

    <!-- JAVASCRIPT UNTUK INTERACTIVE STAR RATING & LIVE AVERAGE -->
    <script>
    const ratingLabels = {
        1: '1/5 - Sangat Kurang',
        2: '2/5 - Kurang',
        3: '3/5 - Cukup',
        4: '4/5 - Baik',
        5: '5/5 - Sangat Baik / Luar Biasa'
    };

    const currentRatings = {
        materi: 5,
        penyampaian: 5,
        kedisiplinan: 5,
        kesabaran: 5
    };

    function setRating(aspect, val) {
        val = parseInt(val, 10);
        currentRatings[aspect] = val;

        const input = document.getElementById('input-' + aspect);
        if (input) input.value = val;

        const desc = document.getElementById('text-desc-' + aspect);
        if (desc) {
            desc.textContent = ratingLabels[val] || (val + ' / 5');
        }

        // Update bintang visual
        const container = document.querySelector(`[data-aspect="${aspect}"]`);
        if (container) {
            const buttons = container.querySelectorAll('.star-btn');
            buttons.forEach(btn => {
                const s = parseInt(btn.getAttribute('data-star'), 10);
                if (s <= val) {
                    btn.classList.remove('text-slate-200');
                    btn.classList.add('text-amber-400');
                } else {
                    btn.classList.remove('text-amber-400');
                    btn.classList.add('text-slate-200');
                }
            });
        }

        updateLiveAverage();
    }

    function updateLiveAverage() {
        const avg = (currentRatings.materi + currentRatings.penyampaian + currentRatings.kedisiplinan + currentRatings.kesabaran) / 4;
        const badge = document.getElementById('label-live-average');
        if (badge) {
            badge.innerHTML = `<i class="fa-solid fa-star text-amber-500 mr-1"></i> Rata-rata Skor: <strong>${avg.toFixed(2)} / 5.00</strong>`;
            badge.className = "text-xs font-black px-3 py-1 rounded-full bg-amber-50 text-amber-800 border border-amber-200";
        }
    }

    // Inisialisasi awal saat halaman dimuat
    document.addEventListener('DOMContentLoaded', function() {
        setRating('materi', 5);
        setRating('penyampaian', 5);
        setRating('kedisiplinan', 5);
        setRating('kesabaran', 5);
    });
    </script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

