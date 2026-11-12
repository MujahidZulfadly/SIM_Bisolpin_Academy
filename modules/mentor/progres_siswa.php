<?php
/**
 * SIM BISOLPIN ACADEMY - Update Progres Belajar & Evaluasi Siswa (Mentor)
 */

declare(strict_types=1);

$pageTitle = 'Update Progres Belajar Siswa';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

check_role(ROLE_MENTOR);

$db = get_db();

// Auto-migrate: pastikan kolom skor_tka dan detail_tka ada pada tabel_siswa
try {
    $db->query("SELECT skor_tka, detail_tka FROM tabel_siswa LIMIT 1");
} catch (\Throwable $e) {
    try {
        $db->exec("ALTER TABLE tabel_siswa ADD COLUMN `skor_tka` DECIMAL(5,2) NULL DEFAULT NULL AFTER `catatan_progres_mentor`, ADD COLUMN `detail_tka` VARCHAR(255) NULL DEFAULT NULL AFTER `skor_tka`");
    } catch (\Throwable $ex) {}
}

// Ambil profil data mentor
$stmtPegawai = $db->prepare("SELECT id, nama_lengkap, id_cabang FROM tabel_pegawai WHERE user_id = ?");
$stmtPegawai->execute([auth_id()]);
$mentor = $stmtPegawai->fetch();
$mentorId = $mentor ? (int)$mentor['id'] : 5;
$branchId = $mentor ? (int)$mentor['id_cabang'] : 1;

// Keamanan isolasi cabang
verify_branch_access($branchId);

// Handle Form POST: Update Progres Belajar Siswa
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $action              = $_POST['action'] ?? 'update_progres';
    $siswaId             = (int)($_POST['siswa_id'] ?? 0);
    $catatanProgres      = trim($_POST['catatan_progres_mentor'] ?? '');
    $statusBelajar       = in_array($_POST['status_belajar'] ?? '', ['aktif', 'lulus', 'cuti', 'keluar'], true) ? $_POST['status_belajar'] : 'aktif';

    // Perhitungan Skor Simulasi TKA & Try Out Bisolpin
    $isInputTka = isset($_POST['tka_benar']) && $_POST['tka_benar'] !== '';
    $skorTka = null;
    $detailTka = null;
    if ($isInputTka) {
        $benar = max(0, (int)($_POST['tka_benar'] ?? 0));
        $salah = max(0, (int)($_POST['tka_salah'] ?? 0));
        $kosong = max(0, (int)($_POST['tka_kosong'] ?? 0));
        $total = $benar + $salah + $kosong;
        if ($total > 0) {
            $skorTka = round(($benar / $total) * 100, 2);
            $detailTka = "Benar: {$benar}, Salah: {$salah}, Kosong: {$kosong} (Skor: {$skorTka})";
        }
    }

    // Verifikasi kepemilikan siswa di cabang mentor
    $stmtCek = $db->prepare("SELECT id_cabang, nama_lengkap FROM tabel_siswa WHERE id = ?");
    $stmtCek->execute([$siswaId]);
    $sRow = $stmtCek->fetch();

    if (!$sRow || (int)$sRow['id_cabang'] !== $branchId) {
        verify_branch_access($sRow ? (int)$sRow['id_cabang'] : null);
    }

    try {
        // Otomatis sinkronkan persentase & pertemuan_terakhir_diikuti berdasarkan riwayat absensi hadir riil
        $persentaseAuto = sync_student_progress($db, $siswaId);

        // Update catatan evaluasi kualitatif, status belajar, dan skor TKA siswa
        if ($isInputTka) {
            $stmtUp = $db->prepare("
                UPDATE tabel_siswa 
                SET catatan_progres_mentor = ?, status_belajar = ?, skor_tka = ?, detail_tka = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUp->execute([$catatanProgres, $statusBelajar, $skorTka, $detailTka, $siswaId, $branchId]);
        } else {
            $stmtUp = $db->prepare("
                UPDATE tabel_siswa 
                SET catatan_progres_mentor = ?, status_belajar = ?
                WHERE id = ? AND id_cabang = ?
            ");
            $stmtUp->execute([$catatanProgres, $statusBelajar, $siswaId, $branchId]);
        }

        $msgTka = $skorTka !== null ? " Nilai Simulasi TKA berhasil dicatat: <strong>{$skorTka}</strong>." : "";
        set_flash('success', "Catatan evaluasi untuk <strong>" . e($sRow['nama_lengkap']) . "</strong> berhasil disimpan! Progres otomatis terkalkulasi: <strong>{$persentaseAuto}%</strong>.{$msgTka}");
    } catch (\Throwable $e) {
        set_flash('error', 'Gagal memperbarui progres siswa: ' . $e->getMessage());
    }
    redirect('modules/mentor/progres_siswa.php');
}

// Ambil seluruh siswa yang dibimbing oleh mentor ini di cabangnya
$stmtSiswa = $db->prepare("
    SELECT s.*, 
           l.nama_layanan, l.kode_layanan, l.jumlah_pertemuan,
           (SELECT COUNT(*) FROM tabel_absensi a WHERE a.id_siswa = s.id AND a.status_kehadiran = 'hadir') as total_hadir,
           (SELECT COUNT(*) FROM tabel_produk p WHERE p.id_siswa = s.id) as total_karya
    FROM tabel_siswa s
    JOIN tabel_layanan l ON l.id = s.id_layanan
    WHERE s.id_cabang = ? AND s.is_archived = 0 AND (s.id_mentor_pembimbing = ? OR s.id_mentor_pembimbing IS NULL)
    ORDER BY s.persentase_progres DESC, s.nama_lengkap ASC
");
$stmtSiswa->execute([$branchId, $mentorId]);
$siswaList = $stmtSiswa->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/header.php';
require_once dirname(__DIR__, 2) . '/includes/sidebar.php';
require_once dirname(__DIR__, 2) . '/includes/topbar.php';
?>

<!-- Header -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-100 text-teal-800 text-xs font-bold mb-2">
            <i class="fa-solid fa-sliders"></i>
            <span>Learning Progress Tracking</span>
        </div>
        <h1 class="text-2xl font-black text-slate-800 tracking-tight">Evaluasi & Progres Belajar Siswa</h1>
        <p class="text-xs text-slate-500 mt-1">
            Pantau persentase penuntasan materi dan berikan umpan balik (*feedback*) kualitatif untuk siswa dan orang tua.
        </p>
    </div>
</div>

<!-- Tabel Siswa & Progres -->
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="datatable w-full text-left">
            <thead>
                <tr>
                    <th>NIS & Nama Siswa</th>
                    <th>Program Kursus</th>
                    <th>Kehadiran & Sesi</th>
                    <th>Persentase Capaian</th>
                    <th>Catatan Evaluasi Personal Mentor</th>
                    <th>Status</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($siswaList)): ?>
                    <?= render_empty_state(
                        'fa-user-graduate',
                        'Belum Ada Siswa Bimbingan',
                        'Belum ada siswa aktif yang ditugaskan kepada Anda pada cabang ini. Siswa akan muncul setelah didaftarkan oleh Staff Front Office.',
                        null,
                        7
                    ) ?>
                <?php else: ?>
                    <?php foreach ($siswaList as $row): ?>
                    <tr>
                        <td>
                            <div class="font-bold text-slate-800 text-sm"><?= e($row['nama_lengkap']) ?></div>
                            <div class="text-xs font-mono text-slate-400">NIS: <?= e($row['nis_bisolpin']) ?></div>
                            <div class="text-[11px] text-slate-500"><?= e($row['asal_sekolah_instansi'] ?? '-') ?></div>
                        </td>

                        <td>
                            <strong class="text-brand-700 text-xs block"><?= e($row['nama_layanan']) ?></strong>
                            <span class="text-[10px] text-slate-400 font-mono"><?= e($row['kode_layanan']) ?></span>
                            <?php if (!empty($row['skor_tka'])): ?>
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200" title="<?= e($row['detail_tka'] ?? '') ?>">
                                        <i class="fa-solid fa-trophy mr-1 text-amber-500"></i> Skor TKA: <?= number_format((float)$row['skor_tka'], 1) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="font-bold text-slate-800 text-xs">
                                Sesi ke-<?= (int)$row['pertemuan_terakhir_diikuti'] ?> / <?= (int)$row['jumlah_pertemuan'] ?>
                            </div>
                            <div class="text-[11px] text-emerald-600 font-medium">
                                <?= (int)$row['total_hadir'] ?> Kali Hadir • <?= (int)$row['total_karya'] ?> Karya
                            </div>
                        </td>

                        <td class="w-48">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-black text-slate-800"><?= (float)$row['persentase_progres'] ?>%</span>
                                <span class="text-[10px] text-slate-400 font-semibold">Tuntas</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                                <div class="bg-gradient-brand h-2.5 rounded-full" style="width: <?= (float)$row['persentase_progres'] ?>%"></div>
                            </div>
                            <span class="text-[10px] text-teal-800 font-bold block mt-1">Hadir <?= (int)$row['total_hadir'] ?> dari <?= (int)$row['jumlah_pertemuan'] ?> sesi</span>
                        </td>

                        <td>
                            <?php if (!empty($row['catatan_progres_mentor'])): ?>
                                <div class="text-xs text-slate-600 italic bg-slate-50 p-2.5 rounded-xl border border-slate-100 max-w-xs">
                                    "<?= e($row['catatan_progres_mentor']) ?>"
                                </div>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Belum ada evaluasi</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= render_status_badge($row['status_belajar']) ?>
                        </td>

                        <td class="text-right">
                            <button type="button" onclick='openModalProgres(<?= json_encode($row) ?>)'
                                    class="btn-brand text-xs py-1.5 px-3 shadow-sm shadow-brand-500/20">
                                <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                                <span>Update Progres</span>
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
<!-- MODAL FORM: UPDATE PROGRES & EVALUASI SISWA -->
<!-- ========================================================================= -->
<div id="modal-progres" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Evaluasi Belajar Siswa</h3>
                <p class="text-xs text-slate-400" id="modal-siswa-nama">-</p>
            </div>
            <button onclick="closeModalProgres()" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" id="edit-siswa-id" name="siswa_id" value="">

            <div class="p-3.5 rounded-xl bg-teal-50 border border-teal-200">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs font-bold text-teal-900 flex items-center gap-1.5">
                        <i class="fa-solid fa-calculator text-teal-600"></i>
                        <span>Progres Belajar Otomatis (Presensi Hadir)</span>
                    </span>
                    <span id="label-progres-val" class="text-sm font-black text-teal-800">0%</span>
                </div>
                <p class="text-[11px] text-teal-700 leading-relaxed">
                    Sistem otomatis menghitung persentase progres dan sesi terakhir saat form ini disimpan atau saat presensi kelas diinput.
                </p>
                <input type="hidden" id="edit-progres" name="persentase_progres" value="0">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Sesi Terakhir Diikuti</label>
                    <input type="number" id="edit-pertemuan" name="pertemuan_terakhir_diikuti" min="0" max="100" readonly class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-bold bg-slate-50 text-slate-600 cursor-not-allowed">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-600 mb-1">Status Belajar</label>
                    <select id="edit-status" name="status_belajar" class="w-full px-3.5 py-2 text-sm rounded-xl border border-slate-200 font-semibold">
                        <option value="aktif">Aktif Belajar</option>
                        <option value="lulus">Lulus Program</option>
                        <option value="cuti">Cuti</option>
                        <option value="keluar">Keluar</option>
                    </select>
                </div>
            </div>

            <!-- Card Simulasi TKA & Try Out -->
            <div class="p-4 rounded-2xl bg-amber-50/70 border border-amber-200 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-950 flex items-center gap-1.5">
                        <i class="fa-solid fa-graduation-cap text-amber-600 text-sm"></i>
                        <span>Simulasi TKA & Try Out Bisolpin</span>
                    </span>
                    <span id="badge-skor-tka" class="text-xs font-black px-2.5 py-0.5 rounded-full bg-amber-200 text-amber-950 border border-amber-300">
                        Skor: -
                    </span>
                </div>
                <p class="text-[11px] text-amber-900 leading-relaxed">
                    Opsional (khusus program TKA / Try Out / Ujian Potensi Akademik). Masukkan rincian soal untuk menghitung skor akhir otomatis: <code class="bg-amber-100 px-1 py-0.5 rounded font-mono text-[10px]">(Benar / Total Soal) &times; 100</code>.
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-700 mb-1">Soal Benar</label>
                        <input type="number" id="tka_benar" name="tka_benar" min="0" placeholder="0" class="w-full px-2.5 py-1.5 text-xs rounded-xl border border-slate-200 bg-white font-mono font-bold text-emerald-700">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-700 mb-1">Soal Salah</label>
                        <input type="number" id="tka_salah" name="tka_salah" min="0" placeholder="0" class="w-full px-2.5 py-1.5 text-xs rounded-xl border border-slate-200 bg-white font-mono font-bold text-rose-700">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-700 mb-1">Soal Kosong</label>
                        <input type="number" id="tka_kosong" name="tka_kosong" min="0" placeholder="0" class="w-full px-2.5 py-1.5 text-xs rounded-xl border border-slate-200 bg-white font-mono font-bold text-slate-600">
                    </div>
                </div>
                <div id="tka-summary-text" class="text-[11px] font-medium text-slate-600 italic">
                    Belum ada nilai simulasi TKA.
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase text-slate-600 mb-1">
                    <i class="fa-solid fa-comment-dots text-brand-600 mr-1"></i>
                    Catatan Evaluasi Kualitatif Personal Mentor
                </label>
                <textarea id="edit-catatan" name="catatan_progres_mentor" rows="3" required
                          placeholder="Uraikan perkembangan pemahaman konsep siswa di kelas, sikap belajar, dan materi yang perlu dilatih mandiri di rumah..."
                          class="w-full px-3.5 py-2 text-xs rounded-xl border border-slate-200"></textarea>
                <p class="text-[10px] text-slate-400 mt-0.5">Catatan ini akan tampil di Portal Siswa dan Laporan Orang Tua.</p>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-3">
                <button type="button" onclick="closeModalProgres()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100">Batal</button>
                <button type="submit" class="btn-brand text-xs py-2 px-5">Simpan Evaluasi</button>
            </div>
        </form>
    </div>
</div>

<script>
function syncProgres(val) {
    document.getElementById('label-progres-val').innerText = val + '%';
    document.getElementById('edit-progres').value = val;
}

function calculateTkaLive() {
    const benarInput = document.getElementById('tka_benar').value;
    const salahInput = document.getElementById('tka_salah').value;
    const kosongInput = document.getElementById('tka_kosong').value;

    const badge = document.getElementById('badge-skor-tka');
    const summary = document.getElementById('tka-summary-text');

    if (benarInput === '' && salahInput === '' && kosongInput === '') {
        badge.innerText = 'Skor: -';
        summary.innerText = 'Belum ada nilai simulasi TKA.';
        return;
    }

    const benar = parseInt(benarInput) || 0;
    const salah = parseInt(salahInput) || 0;
    const kosong = parseInt(kosongInput) || 0;
    const total = benar + salah + kosong;

    if (total === 0) {
        badge.innerText = 'Skor: 0';
        summary.innerText = 'Total soal: 0';
        return;
    }

    const skor = ((benar / total) * 100).toFixed(2);
    badge.innerText = 'Skor: ' + skor;
    summary.innerHTML = `<span class="text-emerald-700 font-bold">${benar} Benar</span>, <span class="text-rose-700 font-bold">${salah} Salah</span>, <span class="text-slate-600">${kosong} Kosong</span> &bull; Total: <strong>${total} Soal</strong> &bull; Akurasi: <strong>${skor}%</strong>`;
}

document.getElementById('tka_benar').addEventListener('input', calculateTkaLive);
document.getElementById('tka_salah').addEventListener('input', calculateTkaLive);
document.getElementById('tka_kosong').addEventListener('input', calculateTkaLive);

function openModalProgres(data) {
    document.getElementById('edit-siswa-id').value = data.id;
    document.getElementById('modal-siswa-nama').innerText = data.nama_lengkap + ' (' + data.nis_bisolpin + ') • ' + data.nama_layanan;
    syncProgres(parseFloat(data.persentase_progres));
    document.getElementById('edit-pertemuan').value = data.pertemuan_terakhir_diikuti;
    document.getElementById('edit-catatan').value = data.catatan_progres_mentor || '';
    document.getElementById('edit-status').value = data.status_belajar;

    // Parse TKA info if exists
    if (data.detail_tka) {
        const match = data.detail_tka.match(/Benar:\s*(\d+),\s*Salah:\s*(\d+),\s*Kosong:\s*(\d+)/i);
        if (match) {
            document.getElementById('tka_benar').value = match[1];
            document.getElementById('tka_salah').value = match[2];
            document.getElementById('tka_kosong').value = match[3];
        } else {
            document.getElementById('tka_benar').value = '';
            document.getElementById('tka_salah').value = '';
            document.getElementById('tka_kosong').value = '';
        }
    } else {
        document.getElementById('tka_benar').value = '';
        document.getElementById('tka_salah').value = '';
        document.getElementById('tka_kosong').value = '';
    }
    calculateTkaLive();

    document.getElementById('modal-progres').classList.remove('hidden');
}

function closeModalProgres() {
    document.getElementById('modal-progres').classList.add('hidden');
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>

