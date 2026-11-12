<?php
/**
 * SIM BISOLPIN ACADEMY - General Utility & Helper Functions
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

/**
 * Escape string untuk mencegah serangan XSS
 */
function e(?string $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Format angka ke mata uang Rupiah Indonesia (Rp 1.500.000)
 */
function format_rupiah(float|int|string|null $amount): string
{
    $val = (float)($amount ?? 0);
    return 'Rp ' . number_format($val, 0, ',', '.');
}

/**
 * Format tanggal standar MySQL (YYYY-MM-DD) ke format bahasa Indonesia
 * Contoh: "12 Januari 2026" atau "12 Jan 2026, 15:30 WITA"
 */
function format_tanggal_indonesia(?string $datetime, bool $with_time = false): string
{
    if (empty($datetime)) {
        return '-';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '-';
    }

    $bulanIndo = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $hari  = date('j', $timestamp);
    $bulan = $bulanIndo[(int)date('n', $timestamp)];
    $tahun = date('Y', $timestamp);

    $hasil = "{$hari} {$bulan} {$tahun}";
    if ($with_time) {
        $jam = date('H:i', $timestamp);
        $hasil .= " pukul {$jam} WITA";
    }

    return $hasil;
}

/**
 * Ambil nama hari dalam Bahasa Indonesia dari tanggal tertentu
 */
function format_hari_indonesia(?string $datetime): string
{
    if (empty($datetime)) {
        return '-';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '-';
    }
    $hariMap = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    return $hariMap[date('l', $timestamp)] ?? '-';
}

/**
 * Bersihkan input secara rekursif dari whitespace berlebih
 */
function sanitize_input(mixed $data): mixed
{
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    if (is_string($data)) {
        return trim($data);
    }
    return $data;
}

/**
 * Generate full URL berdasarkan relative path
 */
function base_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return empty($path) ? BASE_URL : BASE_URL . '/' . $path;
}

/**
 * Generate URL untuk aset statis
 */
function asset_url(string $path = ''): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

/**
 * Redirect ke URL tujuan dan hentikan script
 */
function redirect(string $path): void
{
    $target = str_starts_with($path, 'http') ? $path : base_url($path);
    header("Location: {$target}");
    exit;
}

/**
 * Redirect dengan menyertakan pesan flash
 */
function redirect_with_message(string $path, string $type, string $message, string $title = ''): void
{
    set_flash($type, $message, $title);
    redirect($path);
}

/**
 * Fungsi aman untuk menangani upload file
 *
 * @param string $inputName Nama atribut input type="file"
 * @param string $targetDir Direktori absolut tujuan simpan
 * @param array $allowedExt Ekstensi yang diizinkan (default: pdf, doc, ppt, jpg, png, zip)
 * @param int $maxSizeKB Batas ukuran file dalam KB (default: 10MB)
 * @return array ['success' => bool, 'filename' => string|null, 'message' => string]
 */
function handle_file_upload(
    string $inputName,
    string $targetDir,
    array $allowedExt = ['pdf', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip'],
    int $maxSizeKB = 10240
): array {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'filename' => null, 'message' => 'Tidak ada file yang dipilih untuk diunggah.'];
    }

    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'filename' => null, 'message' => 'Gagal mengunggah file. Kode error PHP: ' . $file['error']];
    }

    // Validasi Ukuran File
    $fileSizeKB = $file['size'] / 1024;
    if ($fileSizeKB > $maxSizeKB) {
        return ['success' => false, 'filename' => null, 'message' => "Ukuran file melebihi batas maksimal " . round($maxSizeKB / 1024, 1) . " MB."];
    }

    // Validasi Ekstensi File
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, array_map('strtolower', $allowedExt), true)) {
        return ['success' => false, 'filename' => null, 'message' => "Format file .{$extension} tidak diizinkan. Gunakan format: " . implode(', ', $allowedExt)];
    }

    // Buat direktori jika belum ada
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        return ['success' => false, 'filename' => null, 'message' => 'Gagal membuat direktori penyimpanan pada server.'];
    }

    // Generate nama file unik aman
    $newFileName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $destination = rtrim($targetDir, '/') . '/' . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'filename' => null, 'message' => 'Gagal memindahkan file ke direktori tujuan.'];
    }

    return [
        'success'  => true,
        'filename' => $newFileName,
        'message'  => 'File berhasil diunggah.'
    ];
}

/**
 * Render badge HTML untuk status umum dengan icon dan label ramah pengguna
 */
function render_status_badge(string $status): string
{
    $statusLower = strtolower(trim($status));
    $map = [
        'aktif'             => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-circle-check', 'label' => 'Aktif'],
        'dipublikasikan'    => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-globe', 'label' => 'Tampil di Portofolio'],
        'lulus'             => ['bg' => 'bg-teal-50 text-teal-700 border-teal-200',       'icon' => 'fa-graduation-cap', 'label' => 'Lulus'],
        'baik'              => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-check', 'label' => 'Kondisi Baik'],
        'hadir'             => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'icon' => 'fa-user-check', 'label' => 'Hadir'],
        'pending_review'    => ['bg' => 'bg-amber-50 text-amber-700 border-amber-200',       'icon' => 'fa-clock', 'label' => 'Menunggu Verifikasi'],
        'menunggu_validasi' => ['bg' => 'bg-amber-50 text-amber-700 border-amber-200',       'icon' => 'fa-hourglass-half', 'label' => 'Menunggu Review Mentor'],
        'perlu_perbaikan'   => ['bg' => 'bg-rose-50 text-rose-700 border-rose-200',          'icon' => 'fa-pen-to-square', 'label' => 'Perlu Revisi'],
        'draft'             => ['bg' => 'bg-slate-100 text-slate-700 border-slate-200',     'icon' => 'fa-file-lines', 'label' => 'Draft'],
        'izin'              => ['bg' => 'bg-sky-50 text-sky-700 border-sky-200',             'icon' => 'fa-envelope-open-text', 'label' => 'Izin'],
        'sakit'             => ['bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200',     'icon' => 'fa-heart-pulse', 'label' => 'Sakit'],
        'alfa'              => ['bg' => 'bg-rose-50 text-rose-700 border-rose-200',         'icon' => 'fa-user-xmark', 'label' => 'Alfa'],
        'nonaktif'          => ['bg' => 'bg-slate-100 text-slate-600 border-slate-200',     'icon' => 'fa-ban', 'label' => 'Nonaktif'],
        'ditolak'           => ['bg' => 'bg-rose-50 text-rose-700 border-rose-200',         'icon' => 'fa-xmark', 'label' => 'Ditolak'],
        'rusak_berat'       => ['bg' => 'bg-rose-50 text-rose-700 border-rose-200',         'icon' => 'fa-triangle-exclamation', 'label' => 'Rusak Berat'],
    ];

    $info = $map[$statusLower] ?? ['bg' => 'bg-gray-100 text-gray-800 border-gray-200', 'icon' => 'fa-circle-info', 'label' => ucfirst($status)];
    return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold border ' . $info['bg'] . '"><i class="fa-solid ' . $info['icon'] . ' text-[10px]"></i><span>' . e($info['label']) . '</span></span>';
}

/**
 * Render Empty State visual component (IMK / UX Best Practice)
 *
 * @param string $icon FontAwesome icon class (e.g. 'fa-boxes-stacked')
 * @param string $title Judul ramah keadaan kosong
 * @param string $message Penjelasan kontekstual mengapa data kosong dan apa yang harus dilakukan
 * @param string|null $ctaHtml Tombol aksi langsung (HTML button atau a)
 * @param int $colSpan Jumlah colspan jika dirender di dalam tr td
 * @return string
 */
function render_empty_state(string $icon, string $title, string $message, ?string $ctaHtml = null, int $colSpan = 0): string
{
    $body = '
    <div class="max-w-md mx-auto py-12 px-4 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-3xl bg-teal-50 text-teal-600 flex items-center justify-center text-2xl mb-3 shadow-inner border border-teal-100">
            <i class="fa-solid ' . e($icon) . '"></i>
        </div>
        <h4 class="text-sm font-bold text-slate-800 mb-1">' . e($title) . '</h4>
        <p class="text-xs text-slate-500 mb-4 leading-relaxed max-w-sm">' . e($message) . '</p>
        ' . ($ctaHtml ? '<div class="pt-1">' . $ctaHtml . '</div>' : '') . '
    </div>';

    if ($colSpan > 0) {
        return '<tr><td colspan="' . $colSpan . '" class="p-0 border-0">' . $body . '</td></tr>';
    }

    return '<div class="bg-white rounded-2xl border border-slate-200 p-6">' . $body . '</div>';
}

/**
 * Sinkronisasi Progres Belajar Siswa Berdasarkan Kehadiran Tatap Muka
 *
 * Rumus Otomatis:
 * (Jumlah Presensi 'hadir' pada tabel_absensi / jumlah_pertemuan pada tabel_layanan) * 100
 * Batas persentase maksimal adalah 100%.
 * Otomatis memperbarui kolom `persentase_progres` dan `pertemuan_terakhir_diikuti` di tabel_siswa
 * serta menyinkronkan data di tabel `riwayat_layanan_siswa`.
 *
 * @param PDO $pdo Objek koneksi database PDO
 * @param int $id_siswa ID siswa yang akan dihitung ulang progresnya
 * @return float Persentase progres belajar yang telah diperbarui (0.00 - 100.00)
 */
function sync_student_progress(PDO $pdo, int $id_siswa): float
{
    if ($id_siswa <= 0) {
        return 0.0;
    }

    // 1. Ambil data layanan & jumlah pertemuan standar dari tabel_siswa dan tabel_layanan
    $stmtS = $pdo->prepare("
        SELECT s.id, s.id_cabang, s.id_layanan, l.jumlah_pertemuan 
        FROM tabel_siswa s
        LEFT JOIN tabel_layanan l ON s.id_layanan = l.id
        WHERE s.id = ?
    ");
    $stmtS->execute([$id_siswa]);
    $student = $stmtS->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return 0.0;
    }

    $totalPertemuan = (!empty($student['jumlah_pertemuan']) && (int)$student['jumlah_pertemuan'] > 0)
        ? (int)$student['jumlah_pertemuan']
        : 12;

    // 2. Hitung jumlah kehadiran siswa dengan status 'hadir' dan pertemuan materi tertinggi
    $stmtA = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_hadir,
            COALESCE(MAX(m.pertemuan_ke), 0) AS max_materi_pertemuan
        FROM tabel_absensi a
        LEFT JOIN tabel_materi m ON a.id_materi_pertemuan = m.id
        WHERE a.tipe_absensi = 'siswa'
          AND a.id_siswa = ?
          AND a.status_kehadiran = 'hadir'
    ");
    $stmtA->execute([$id_siswa]);
    $presensi = $stmtA->fetch(PDO::FETCH_ASSOC);

    $totalHadir = (int)($presensi['total_hadir'] ?? 0);
    $maxMateriPertemuan = (int)($presensi['max_materi_pertemuan'] ?? 0);
    $pertemuanTerakhir = max($totalHadir, $maxMateriPertemuan);

    // 3. Hitung persentase progres: (total_hadir / total_pertemuan) * 100
    $persentase = round(($totalHadir / $totalPertemuan) * 100, 2);
    if ($persentase > 100.0) {
        $persentase = 100.0;
    }

    // 4. Update data progres di tabel_siswa
    $stmtU = $pdo->prepare("
        UPDATE tabel_siswa
        SET persentase_progres = ?,
            pertemuan_terakhir_diikuti = ?
        WHERE id = ?
    ");
    $stmtU->execute([$persentase, $pertemuanTerakhir, $id_siswa]);

    // 5. Sinkronkan juga pada tabel jembatan riwayat_layanan_siswa jika ada
    if (!empty($student['id_layanan'])) {
        $stmtRLS = $pdo->prepare("
            UPDATE riwayat_layanan_siswa
            SET persentase_progres = ?
            WHERE id_siswa = ? AND id_layanan = ?
        ");
        $stmtRLS->execute([$persentase, $id_siswa, $student['id_layanan']]);
    }

    return $persentase;
}

/**
 * Menangani Unggahan Hibrida (Hybrid Storage)
 * Mendukung Unggah File Fisik Lokal (PDF, PPT, DOC, ZIP, Gambar maks 3MB)
 * ATAU Tautan URL Eksternal (Google Drive, GitHub, Figma, YouTube, Canva, dsb).
 *
 * @param string|array $file_input Nama key $_FILES atau array file $_FILES
 * @param string|null $url_input Nilai string URL eksternal atau nama field $_POST
 * @param string $target_dir Direktori absolut tempat penyimpanan file di server
 * @param int $max_size_mb Batas ukuran file dalam satuan Megabytes (default: 3MB)
 * @return array [
 *   'success'       => bool,
 *   'tipe_lampiran' => 'file'|'link'|'none',
 *   'file_name'     => string|null,
 *   'external_url'  => string|null,
 *   'value'         => string|null, // Nilai siap simpan ke kolom file_modul / file_atau_url_karya
 *   'message'       => string
 * ]
 */
function handle_hybrid_upload(
    string|array $file_input,
    ?string $url_input,
    string $target_dir,
    int $max_size_mb = 3
): array {
    // 1. Ekstrak nilai URL jika diberikan berupa key POST atau string URL langsung
    $urlValue = '';
    if (!empty($url_input)) {
        if (isset($_POST[$url_input])) {
            $urlValue = trim((string)$_POST[$url_input]);
        } else {
            $urlValue = trim($url_input);
        }
    }

    // 2. Cek apakah ada file fisik yang diunggah
    $fileData = null;
    if (is_array($file_input) && isset($file_input['error'])) {
        $fileData = $file_input;
    } elseif (is_string($file_input) && isset($_FILES[$file_input])) {
        $fileData = $_FILES[$file_input];
    }

    $hasFile = ($fileData !== null && $fileData['error'] !== UPLOAD_ERR_NO_FILE);

    // KASUS A: Pengguna Mengunggah File Fisik
    if ($hasFile) {
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'file',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => 'Gagal mengunggah file. Kode error sistem: ' . $fileData['error']
            ];
        }

        // Validasi Ukuran (Maksimal $max_size_mb, default 3MB)
        $maxSizeBytes = $max_size_mb * 1024 * 1024;
        if ($fileData['size'] > $maxSizeBytes) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'file',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => "Ukuran file melebihi batas maksimal {$max_size_mb} MB (Ukuran terdeteksi: " . round($fileData['size'] / (1024 * 1024), 2) . " MB)."
            ];
        }

        // Validasi Ekstensi Aman
        $allowedExtensions = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'webp'];
        $originalName = $fileData['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'file',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => "Ekstensi file .{$extension} tidak diizinkan. Gunakan format aman: " . implode(', ', $allowedExtensions)
            ];
        }

        // Buat folder tujuan jika belum ada
        if (!is_dir($target_dir) && !mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'file',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => 'Gagal membuat direktori penyimpanan pada server.'
            ];
        }

        // Generate nama unik yang aman
        $safeFileName = time() . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
        $destination = rtrim($target_dir, '/') . '/' . $safeFileName;

        if (!move_uploaded_file($fileData['tmp_name'], $destination)) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'file',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => 'Gagal memindahkan file yang diunggah ke direktori penyimpanan server.'
            ];
        }

        return [
            'success'       => true,
            'tipe_lampiran' => 'file',
            'file_name'     => $safeFileName,
            'external_url'  => null,
            'value'         => $safeFileName,
            'message'       => 'File fisik berhasil diunggah dan disimpan.'
        ];
    }

    // KASUS B: Pengguna Memasukkan Tautan Eksternal (URL)
    if (!empty($urlValue)) {
        // Validasi format URL standar
        if (!filter_var($urlValue, FILTER_VALIDATE_URL)) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'link',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => 'Format URL tautan eksternal tidak valid. Pastikan menyertakan http:// atau https://.'
            ];
        }

        $parsedScheme = parse_url($urlValue, PHP_URL_SCHEME);
        if (!in_array(strtolower((string)$parsedScheme), ['http', 'https'], true)) {
            return [
                'success'       => false,
                'tipe_lampiran' => 'link',
                'file_name'     => null,
                'external_url'  => null,
                'value'         => null,
                'message'       => 'Skema URL harus menggunakan protokol http:// atau https://.'
            ];
        }

        return [
            'success'       => true,
            'tipe_lampiran' => 'link',
            'file_name'     => null,
            'external_url'  => $urlValue,
            'value'         => $urlValue,
            'message'       => 'Tautan URL eksternal berhasil divalidasi.'
        ];
    }

    // KASUS C: Tidak Ada Lampiran File Maupun URL
    return [
        'success'       => true,
        'tipe_lampiran' => 'none',
        'file_name'     => null,
        'external_url'  => null,
        'value'         => null,
        'message'       => 'Tidak ada file fisik maupun tautan eksternal yang disertakan.'
    ];
}

/**
 * Format nomor WhatsApp ke link wa.me resmi
 * Membersihkan spasi, strip, tanda kurung, dan mengubah awalan 08... atau +628... menjadi 628...
 */
function format_wa_url(?string $phone, string $text = ''): string
{
    if (empty($phone)) {
        return '#';
    }
    // Bersihkan karakter selain angka
    $clean = preg_replace('/[^0-9]/', '', (string)$phone);
    if (empty($clean)) {
        return '#';
    }
    // Jika berawalan 08... ubah jadi 628...
    if (str_starts_with($clean, '0')) {
        $clean = '62' . substr($clean, 1);
    }
    // Jika belum ada awalan 62 dan langsung 8...
    if (str_starts_with($clean, '8')) {
        $clean = '62' . $clean;
    }

    $url = "https://wa.me/{$clean}";
    if (!empty($text)) {
        $url .= '?text=' . urlencode($text);
    }
    return $url;
}

/**
 * Render perbandingan harga normal dan harga promo Bisolpin
 */
function render_promo_price(float|int|string|null $normalPrice, float|int|string|null $promoPrice): string
{
    $normal = (float)($normalPrice ?? 0);
    $promo  = $promoPrice !== null && $promoPrice !== '' ? (float)$promoPrice : null;

    if ($promo !== null && $promo > 0 && $promo < $normal) {
        $diskon = round((($normal - $promo) / $normal) * 100);
        return '<div class="flex flex-col">
            <span class="text-xs text-slate-400 line-through">' . format_rupiah($normal) . '</span>
            <div class="flex items-center gap-1.5">
                <span class="font-bold text-emerald-600">' . format_rupiah($promo) . '</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-50 text-rose-600 border border-rose-200">-' . $diskon . '%</span>
            </div>
        </div>';
    }

    return '<span class="font-bold text-slate-800">' . format_rupiah($normal) . '</span>';
}

/**
 * Render bintang visual untuk rating (skala 1 - 5)
 */
function render_star_rating(float|int|string|null $rating, int $maxStars = 5): string
{
    $r = (float)($rating ?? 0);
    $html = '<div class="inline-flex items-center gap-0.5 text-amber-400" title="' . number_format($r, 2) . ' / ' . $maxStars . '">';
    for ($i = 1; $i <= $maxStars; $i++) {
        if ($r >= $i) {
            $html .= '<i class="fa-solid fa-star text-xs"></i>';
        } elseif ($r >= ($i - 0.5)) {
            $html .= '<i class="fa-solid fa-star-half-stroke text-xs"></i>';
        } else {
            $html .= '<i class="fa-regular fa-star text-xs text-slate-300"></i>';
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Predikat Mutu & Badge Kinerja Mentor / IKM
 * Hijau (>= 4.5 - Sangat Baik), Kuning (3.5 - 4.4 - Cukup), Merah (< 3.5 - Perlu Pembinaan)
 */
function get_ikm_badge(float|int|string|null $score): array
{
    $s = (float)($score ?? 0);
    if ($s <= 0) {
        return [
            'label' => 'Belum Ada Penilaian',
            'badge' => 'bg-slate-100 text-slate-600 border-slate-200',
            'color' => 'slate',
            'icon'  => 'fa-circle-question'
        ];
    }
    if ($s >= 4.5) {
        return [
            'label' => 'Sangat Baik (Unggul)',
            'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'color' => 'emerald',
            'icon'  => 'fa-circle-check'
        ];
    }
    if ($s >= 3.5) {
        return [
            'label' => 'Cukup (Baik)',
            'badge' => 'bg-amber-50 text-amber-700 border-amber-200',
            'color' => 'amber',
            'icon'  => 'fa-circle-exclamation'
        ];
    }
    return [
        'label' => 'Perlu Pembinaan',
        'badge' => 'bg-rose-50 text-rose-700 border-rose-200',
        'color' => 'rose',
        'icon'  => 'fa-triangle-exclamation',
        'desc'  => 'Perlu supervisi dan pembinaan pedagogis'
    ];
}

/**
 * Memastikan tabel_evaluasi_mentor ada di database (Auto-Migrate On-the-Fly)
 * Mencegah fatal error SQLSTATE[42S02] jika pengguna belum import patch SQL manual
 */
function ensure_table_evaluasi_mentor_exists(?PDO $db = null): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        $db = $db ?? get_db();
        // Cek cepat apakah tabel sudah ada
        $check = $db->query("SHOW TABLES LIKE 'tabel_evaluasi_mentor'")->fetch();
        if ($check) {
            $checked = true;
            return true;
        }

        // Buat tabel jika belum ada
        $db->exec("
            CREATE TABLE IF NOT EXISTS `tabel_evaluasi_mentor` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_cabang` INT NOT NULL,
              `id_siswa` INT NOT NULL,
              `id_mentor` INT NOT NULL,
              `id_layanan` INT NOT NULL,
              `skor_materi` TINYINT NOT NULL CHECK (`skor_materi` BETWEEN 1 AND 5),
              `skor_penyampaian` TINYINT NOT NULL CHECK (`skor_penyampaian` BETWEEN 1 AND 5),
              `skor_kedisiplinan` TINYINT NOT NULL CHECK (`skor_kedisiplinan` BETWEEN 1 AND 5),
              `skor_kesabaran` TINYINT NOT NULL CHECK (`skor_kesabaran` BETWEEN 1 AND 5),
              `nilai_rata_rata` DECIMAL(3,2) NOT NULL,
              `ulasan_saran` TEXT NULL,
              `is_anonymous` TINYINT(1) DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_eval_cabang` (`id_cabang`),
              INDEX `idx_eval_mentor` (`id_mentor`),
              INDEX `idx_eval_siswa` (`id_siswa`),
              UNIQUE KEY `unique_evaluasi` (`id_siswa`, `id_layanan`, `id_mentor`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Seed data evaluasi awal contoh jika data mentor & siswa tersedia
        try {
            $mentor = $db->query("SELECT id, id_cabang FROM tabel_pegawai WHERE jabatan_role = 'mentor' LIMIT 1")->fetch();
            $siswa  = $db->query("SELECT id, id_cabang, id_layanan FROM tabel_siswa LIMIT 1")->fetch();
            if ($mentor && $siswa) {
                $mentorId  = (int)$mentor['id'];
                $branchId  = (int)($mentor['id_cabang'] ?? 1);
                $siswaId   = (int)$siswa['id'];
                $layananId = (int)($siswa['id_layanan'] ?? 5);

                $stmtIns = $db->prepare("
                    INSERT IGNORE INTO `tabel_evaluasi_mentor` (
                        `id_cabang`, `id_siswa`, `id_mentor`, `id_layanan`,
                        `skor_materi`, `skor_penyampaian`, `skor_kedisiplinan`, `skor_kesabaran`,
                        `nilai_rata_rata`, `ulasan_saran`, `is_anonymous`, `created_at`
                    ) VALUES (?, ?, ?, ?, 5, 5, 5, 5, 5.00, ?, 1, NOW() - INTERVAL 2 DAY)
                ");
                $stmtIns->execute([
                    $branchId, $siswaId, $mentorId, $layananId,
                    'Kak mentor membimbing dengan sangat sabar dan jelas. Penjelasan modul praktik langsung hands-on sangat membantu pemahaman saya!'
                ]);
            }
        } catch (\Throwable $seedErr) {
            // Abaikan jika seed gagal, tabel utama sudah dibuat
        }

        // Pastikan juga tabel_maintenance_log dan tabel_pembayaran ada
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `tabel_maintenance_log` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `id_cabang` INT NOT NULL,
                  `id_aset` INT NOT NULL,
                  `tanggal_maintenance` DATE NOT NULL,
                  `jenis_perbaikan` VARCHAR(150) NOT NULL,
                  `biaya_perbaikan` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                  `teknisi_pelaksana` VARCHAR(150) NOT NULL,
                  `kondisi_sebelum` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
                  `kondisi_setelah` ENUM('baik','perlu_perbaikan','rusak_berat') NOT NULL,
                  `keterangan_tindakan` TEXT NOT NULL,
                  `created_by` INT NOT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX `idx_maint_cabang` (`id_cabang`),
                  INDEX `idx_maint_aset` (`id_aset`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `tabel_pembayaran` (
                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                  `id_cabang` INT NOT NULL,
                  `id_siswa` INT NOT NULL,
                  `id_layanan` INT NOT NULL,
                  `kode_transaksi` VARCHAR(50) NOT NULL UNIQUE,
                  `tanggal_bayar` DATE NOT NULL,
                  `jumlah_bayar` DECIMAL(12,2) NOT NULL,
                  `metode_pembayaran` ENUM('tunai','transfer_bank','qris') NOT NULL DEFAULT 'tunai',
                  `status_pembayaran` ENUM('lunas','cicilan','menunggu_konfirmasi') NOT NULL DEFAULT 'lunas',
                  `bukti_transfer` VARCHAR(255) NULL,
                  `catatan` TEXT NULL,
                  `diterima_oleh` INT NOT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  INDEX `idx_bayar_cabang` (`id_cabang`),
                  INDEX `idx_bayar_siswa` (`id_siswa`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Throwable $suppErr) {
            // Abaikan
        }

        $checked = true;
        return true;
    } catch (\Throwable $e) {
        error_log("Gagal auto-migrate tabel_evaluasi_mentor: " . $e->getMessage());
        return false;
    }
}

/**
 * SIM BISOLPIN ACADEMY - Schema Auto-Migration & Column Integrity Helper
 * Memastikan semua tabel dan kolom versi terbaru otomatis tersedia di MySQL tanpa perlu import manual.
 */
function ensure_database_schema_integrity(?PDO $db = null): bool
{
    static $checked = false;
    if ($checked) {
        return true;
    }

    try {
        $db = $db ?? get_db();

        // 1. Pastikan kolom-kolom baru di tabel_siswa
        $siswaCols = $db->query("SHOW COLUMNS FROM `tabel_siswa`")->fetchAll(PDO::FETCH_COLUMN);
        $siswaColMap = array_flip($siswaCols);

        $newSiswaCols = [
            'umur' => "INT NULL DEFAULT NULL AFTER `nama_lengkap`",
            'jenjang' => "ENUM('TK','SD','SMP','SMA','SMK','Mahasiswa','Umum') NULL DEFAULT 'SD' AFTER `umur`",
            'kelas' => "VARCHAR(50) NULL DEFAULT NULL AFTER `jenjang`",
            'nama_sekolah' => "VARCHAR(150) NULL DEFAULT NULL AFTER `kelas`",
            'asal_sekolah_instansi' => "VARCHAR(150) NULL DEFAULT NULL AFTER `nama_sekolah`",
            'provinsi' => "VARCHAR(100) NULL DEFAULT 'Sulawesi Selatan' AFTER `alamat`",
            'kabupaten_kota' => "VARCHAR(100) NULL DEFAULT 'Kota Makassar' AFTER `provinsi`",
            'kecamatan' => "VARCHAR(100) NULL DEFAULT 'Panakkukang' AFTER `kabupaten_kota`",
            'no_wa_ortu' => "VARCHAR(30) NULL DEFAULT NULL AFTER `no_hp_orang_tua`",
            'skor_tka' => "DECIMAL(5,2) NULL DEFAULT NULL AFTER `catatan_progres_mentor`",
            'detail_tka' => "VARCHAR(255) NULL DEFAULT NULL AFTER `skor_tka`",
        ];

        foreach ($newSiswaCols as $col => $def) {
            if (!isset($siswaColMap[$col])) {
                try {
                    $db->exec("ALTER TABLE `tabel_siswa` ADD COLUMN `{$col}` {$def}");
                    $siswaColMap[$col] = true;
                } catch (\Throwable $e) {
                    error_log("Gagal add column {$col} on tabel_siswa: " . $e->getMessage());
                }
            }
        }

        // 2. Pastikan kolom di tabel_materi
        $materiCols = $db->query("SHOW COLUMNS FROM `tabel_materi`")->fetchAll(PDO::FETCH_COLUMN);
        $materiColMap = array_flip($materiCols);

        $newMateriCols = [
            'ringkasan_materi' => "TEXT NULL AFTER `judul_modul`",
            'tipe_lampiran' => "ENUM('file','link') NOT NULL DEFAULT 'file' AFTER `file_modul`",
            'external_url' => "VARCHAR(500) NULL AFTER `tipe_lampiran`",
            'panduan_mentor' => "TEXT NULL AFTER `external_url`",
            'standar_tugas_produk' => "TEXT NULL AFTER `panduan_mentor`",
        ];

        foreach ($newMateriCols as $col => $def) {
            if (!isset($materiColMap[$col])) {
                try {
                    $db->exec("ALTER TABLE `tabel_materi` ADD COLUMN `{$col}` {$def}");
                    $materiColMap[$col] = true;
                } catch (\Throwable $e) {
                    error_log("Gagal add column {$col} on tabel_materi: " . $e->getMessage());
                }
            }
        }

        // 3. Pastikan kolom di tabel_pegawai
        $pegawaiCols = $db->query("SHOW COLUMNS FROM `tabel_pegawai`")->fetchAll(PDO::FETCH_COLUMN);
        $pegawaiColMap = array_flip($pegawaiCols);

        $newPegawaiCols = [
            'spesialisasi_mapel' => "ENUM('Matematika','Bahasa Inggris','Calistung','Coding','Pemrograman Web') NULL DEFAULT 'Coding' AFTER `spesialisasi_mentor`",
            'pengalaman_mengajar' => "TEXT NULL DEFAULT NULL AFTER `alamat`",
            'berkas_cv' => "VARCHAR(255) NULL DEFAULT NULL AFTER `pengalaman_mengajar`",
        ];

        foreach ($newPegawaiCols as $col => $def) {
            if (!isset($pegawaiColMap[$col])) {
                try {
                    $db->exec("ALTER TABLE `tabel_pegawai` ADD COLUMN `{$col}` {$def}");
                    $pegawaiColMap[$col] = true;
                } catch (\Throwable $e) {
                    error_log("Gagal add column {$col} on tabel_pegawai: " . $e->getMessage());
                }
            }
        }

        // 4. Pastikan kolom di tabel_produk
        $produkCols = $db->query("SHOW COLUMNS FROM `tabel_produk`")->fetchAll(PDO::FETCH_COLUMN);
        $produkColMap = array_flip($produkCols);

        $newProdukCols = [
            'tipe_lampiran' => "ENUM('file','link') NOT NULL DEFAULT 'file' AFTER `file_atau_url_karya`",
            'external_url' => "VARCHAR(500) NULL AFTER `tipe_lampiran`",
            'nilai_karya' => "DECIMAL(5,2) NULL DEFAULT NULL AFTER `deskripsi_karya`",
            'review_mentor' => "TEXT NULL AFTER `nilai_karya`",
            'id_mentor_validator' => "INT NULL AFTER `review_mentor`",
        ];

        foreach ($newProdukCols as $col => $def) {
            if (!isset($produkColMap[$col])) {
                try {
                    $db->exec("ALTER TABLE `tabel_produk` ADD COLUMN `{$col}` {$def}");
                    $produkColMap[$col] = true;
                } catch (\Throwable $e) {
                    error_log("Gagal add column {$col} on tabel_produk: " . $e->getMessage());
                }
            }
        }

        // 5. Pastikan kolom di tabel_layanan
        $layananCols = $db->query("SHOW COLUMNS FROM `tabel_layanan`")->fetchAll(PDO::FETCH_COLUMN);
        $layananColMap = array_flip($layananCols);

        if (!isset($layananColMap['deskripsi'])) {
            try {
                $db->exec("ALTER TABLE `tabel_layanan` ADD COLUMN `deskripsi` TEXT NULL AFTER `kategori_jenjang`");
            } catch (\Throwable $e) {}
        }

        // 6. Pastikan tabel-tabel pendukung ada
        ensure_table_evaluasi_mentor_exists($db);

        $checked = true;
        return true;
    } catch (\Throwable $e) {
        error_log("Schema integrity check failed: " . $e->getMessage());
        return false;
    }
}




