<?php
/**
 * SIM BISOLPIN ACADEMY - Branch Partnership Self-Registration Form (Calon Direktur Cabang)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

$errors = [];
$old = [
    'nama_lengkap'     => '',
    'no_hp'            => '',
    'email'            => '',
    'username'         => '',
    'alamat_pribadi'   => '',
    'nama_cabang'      => '',
    'alamat_cabang'    => '',
    'kota'             => '',
    'provinsi'         => '',
    'telepon_cabang'   => '',
    'email_cabang'     => '',
    'nomor_izin_mitra' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    // Sanitasi Seluruh Input
    foreach ($old as $key => $val) {
        $old[$key] = trim($_POST[$key] ?? '');
    }

    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validasi Kolom Wajib
    if (empty($old['nama_lengkap']))   $errors[] = 'Nama lengkap calon direktur cabang wajib diisi.';
    if (empty($old['no_hp']))          $errors[] = 'Nomor WhatsApp aktif wajib diisi.';
    if (empty($old['email']))          $errors[] = 'Alamat email pribadi wajib diisi.';
    if (empty($old['username']))       $errors[] = 'Username akun wajib diisi.';
    if (empty($old['nama_cabang']))    $errors[] = 'Nama usulan cabang wajib diisi.';
    if (empty($old['alamat_cabang']))  $errors[] = 'Alamat lokasi cabang wajib diisi.';
    if (empty($old['kota']))           $errors[] = 'Kota / Kabupaten cabang wajib diisi.';
    if (empty($old['provinsi']))       $errors[] = 'Provinsi cabang wajib diisi.';

    // Validasi Password
    if (strlen($password) < 6) {
        $errors[] = 'Kata sandi minimal terdiri dari 6 karakter.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Konfirmasi kata sandi tidak cocok dengan kata sandi yang dimasukkan.';
    }

    // Cek Keunikan Email & Username di Database
    $db = get_db();
    if (empty($errors)) {
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmtCheck->execute([$old['username'], $old['email']]);
        if ($stmtCheck->fetch()) {
            $errors[] = 'Username atau alamat email pribadi sudah terdaftar di sistem. Gunakan yang lain.';
        }
    }

    // Penanganan Berkas Kemitraan (Proposal / Legalitas / KTP)
    $uploadedFileName = null;
    if (empty($errors)) {
        $uploadResult = handle_file_upload(
            'dokumen_kemitraan',
            DIR_UPLOAD_MITRA,
            ['pdf', 'zip', 'rar', 'docx', 'jpg', 'png'],
            15360 // Maksimal 15 MB
        );

        if ($uploadResult['success']) {
            $uploadedFileName = $uploadResult['filename'];
        } else {
            // Berkas bersifat opsional saat form awal, tetapi jika diunggah dan gagal validasi, tampilkan error
            if (isset($_FILES['dokumen_kemitraan']) && $_FILES['dokumen_kemitraan']['error'] !== UPLOAD_ERR_NO_FILE) {
                $errors[] = $uploadResult['message'];
            }
        }
    }

    // Eksekusi Pendaftaran dalam Transaksi Database
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            // 1. Buat Akun Pengguna (Status Inactive / Menunggu Approval)
            $stmtUser = $db->prepare("INSERT INTO users (username, email, password_hash, role, id_cabang, is_active, created_at) VALUES (?, ?, ?, 'direktur_cabang', NULL, 0, NOW())");
            $stmtUser->execute([
                $old['username'],
                $old['email'],
                $passwordHash
            ]);
            $newUserId = (int)$db->lastInsertId();

            // 2. Generate Kode Cabang Otomatis
            $kodeCabang = 'CBG-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $old['kota']), 0, 3)) . '-' . date('y') . rand(100, 999);

            // 3. Simpan Data Cabang Baru (Status pending_review)
            $stmtCabang = $db->prepare("INSERT INTO tabel_cabang 
                (kode_cabang, nama_cabang, id_direktur_cabang, alamat, kota, provinsi, telepon, email_cabang, nomor_izin_mitra, dokumen_kemitraan, status_mitra, tanggal_bergabung, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', NULL, NOW())");
            $stmtCabang->execute([
                $kodeCabang,
                $old['nama_cabang'],
                $newUserId,
                $old['alamat_cabang'],
                $old['kota'],
                $old['provinsi'],
                !empty($old['telepon_cabang']) ? $old['telepon_cabang'] : $old['no_hp'],
                !empty($old['email_cabang']) ? $old['email_cabang'] : $old['email'],
                $old['nomor_izin_mitra'] ?: null,
                $uploadedFileName
            ]);
            $newCabangId = (int)$db->lastInsertId();

            // 4. Update user dengan id_cabang baru
            $stmtUpdateUser = $db->prepare("UPDATE users SET id_cabang = ? WHERE id = ?");
            $stmtUpdateUser->execute([$newCabangId, $newUserId]);

            // 5. Simpan Data Pegawai sebagai Direktur Cabang
            $nipPegawai = 'DIR-' . date('Y') . '-' . str_pad((string)$newUserId, 4, '0', STR_PAD_LEFT);
            $stmtPegawai = $db->prepare("INSERT INTO tabel_pegawai 
                (user_id, id_cabang, nip_pegawai, nama_lengkap, jabatan_role, spesialisasi_mentor, no_hp, alamat, status_kepegawaian, status_aktif, tanggal_masuk, created_at)
                VALUES (?, ?, ?, ?, 'direktur_cabang', 'Manajemen Kemitraan', ?, ?, 'kontrak', 'nonaktif', CURDATE(), NOW())");
            $stmtPegawai->execute([
                $newUserId,
                $newCabangId,
                $nipPegawai,
                $old['nama_lengkap'],
                $old['no_hp'],
                $old['alamat_pribadi']
            ]);

            // Kirim notifikasi sistem ke Pimpinan Pusat
            push_notification(
                'Permohonan Kemitraan Baru',
                "Pendaftaran mitra baru untuk cabang {$old['nama_cabang']} ({$old['kota']}) menunggu review dan verifikasi Pimpinan Pusat.",
                base_url('modules/pimpinan/verifikasi_mitra.php'),
                null,
                'pimpinan',
                null,
                'warning'
            );

            $db->commit();

            set_flash('success', 'Pengajuan kemitraan cabang <strong>' . e($old['nama_cabang']) . '</strong> berhasil dikirimkan! Dokumen kemitraan Anda akan ditinjau terlebih dahulu oleh Pimpinan Pusat, setelah disetujui maka Admin Sistem akan mengonfigurasi dan menyetujui pembuatan akun login cabang Anda.', 'Registrasi Kemitraan Berhasil!');
            redirect('modules/auth/login.php');

        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Gagal registrasi mitra: " . $e->getMessage());
            $errors[] = 'Terjadi kesalahan sistem saat menyimpan data pendaftaran. Silakan coba beberapa saat lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Kemitraan Cabang | <?= APP_NAME ?></title>

    <!-- Favicon Resmi Bisolpin Academy -->
    <link rel="icon" type="image/png" href="<?= asset_url('img/logo.png') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('img/logo.png') ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            500: '#1BA89C',
                            600: '#159388',
                            700: '#0d7a71',
                        }
                    },
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <link rel="stylesheet" href="<?= asset_url('css/variables.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/main.css') ?>">
</head>
<body class="bg-slate-100/60 py-10 px-4 sm:px-6 lg:px-8">

<div class="max-w-4xl mx-auto">
    <!-- Header Card -->
    <div class="bg-gradient-brand text-white rounded-3xl p-8 sm:p-10 shadow-xl mb-8 relative overflow-hidden">
        <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div>
                <a href="<?= base_url('modules/auth/login.php') ?>" class="inline-flex items-center gap-2 text-xs font-semibold text-teal-100 hover:text-white mb-4 transition-colors">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Kembali ke Halaman Login</span>
                </a>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Formulir Kemitraan Cabang Baru</h1>
                <p class="text-teal-50 text-sm mt-2 max-w-xl">
                    Jadilah bagian dari jaringan bimbingan belajar BISOLPIN di kota Anda. Buka cabang dengan kurikulum terstandardisasi BAK dan sistem manajemen digital lengkap.
                </p>
            </div>
            <div class="shrink-0">
                <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="w-20 h-20 object-contain drop-shadow-sm">
            </div>
        </div>
    </div>

    <!-- Alert Error Jika Ada -->
    <?php if (!empty($errors)): ?>
        <div class="mb-6 p-5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
            <div class="flex items-center gap-2 font-bold mb-2">
                <i class="fa-solid fa-triangle-exclamation text-rose-500"></i>
                <span>Mohon perbaiki kendala berikut:</span>
            </div>
            <ul class="list-disc list-inside space-y-1 text-xs">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Form Body -->
    <form method="POST" action="" enctype="multipart/form-data" class="bg-white rounded-3xl shadow-xl border border-slate-200/80 p-8 sm:p-10 space-y-8">
        <?= csrf_field() ?>

        <!-- BAGIAN 1: DATA CALON DIREKTUR CABANG -->
        <div>
            <div class="flex items-center gap-3 pb-3 border-b border-slate-100 mb-6">
                <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center font-bold text-sm">1</div>
                <div>
                    <h2 class="text-lg font-bold text-slate-800">Profil Calon Direktur Cabang</h2>
                    <p class="text-xs text-slate-400">Identitas penanggung jawab operasional kemitraan</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nama Lengkap & Gelar *</label>
                    <input type="text" name="nama_lengkap" value="<?= e($old['nama_lengkap']) ?>" required placeholder="Contoh: Ir. Bambang Soebroto, M.M."
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nomor WhatsApp Aktif *</label>
                    <input type="tel" name="no_hp" value="<?= e($old['no_hp']) ?>" required placeholder="Contoh: 081234567890"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Alamat Email Pribadi *</label>
                    <input type="email" name="email" value="<?= e($old['email']) ?>" required placeholder="email@gmail.com"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Username Login Diinginkan *</label>
                    <input type="text" name="username" value="<?= e($old['username']) ?>" required placeholder="contoh: direktur_surabaya"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Kata Sandi Akun *</label>
                    <input type="password" name="password" required placeholder="Minimal 6 karakter"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Konfirmasi Kata Sandi *</label>
                    <input type="password" name="password_confirm" required placeholder="Ulangi kata sandi"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Alamat Domisili Calon Direktur</label>
                    <textarea name="alamat_pribadi" rows="2" placeholder="Alamat lengkap tempat tinggal saat ini"
                              class="w-full px-4 py-2 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none"><?= e($old['alamat_pribadi']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- BAGIAN 2: DATA USULAN CABANG BARU -->
        <div>
            <div class="flex items-center gap-3 pb-3 border-b border-slate-100 mb-6">
                <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center font-bold text-sm">2</div>
                <div>
                    <h2 class="text-lg font-bold text-slate-800">Rencana Lokasi & Informasi Cabang</h2>
                    <p class="text-xs text-slate-400">Rincian nama dan wilayah cabang yang akan dibuka</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nama Usulan Cabang *</label>
                    <input type="text" name="nama_cabang" value="<?= e($old['nama_cabang']) ?>" required placeholder="Contoh: Bisolpin Cabang Surabaya Gubeng"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Kota / Kabupaten *</label>
                    <input type="text" name="kota" value="<?= e($old['kota']) ?>" required placeholder="Contoh: Kota Surabaya"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Provinsi *</label>
                    <input type="text" name="provinsi" value="<?= e($old['provinsi']) ?>" required placeholder="Contoh: Jawa Timur"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Telepon Kantor / Cabang</label>
                    <input type="tel" name="telepon_cabang" value="<?= e($old['telepon_cabang']) ?>" placeholder="031-xxxxxx atau nomor admin"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Email Resmi Cabang</label>
                    <input type="email" name="email_cabang" value="<?= e($old['email_cabang']) ?>" placeholder="cabang.surabaya@bisolpin.com"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Alamat Lengkap Rencana Lokasi Cabang *</label>
                    <textarea name="alamat_cabang" rows="2" required placeholder="Jl. Raya ..., Kelurahan ..., Kecamatan ..."
                              class="w-full px-4 py-2 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none"><?= e($old['alamat_cabang']) ?></textarea>
                </div>
            </div>
        </div>

        <!-- BAGIAN 3: LEGALITAS & DOKUMEN PROPOSAL -->
        <div>
            <div class="flex items-center gap-3 pb-3 border-b border-slate-100 mb-6">
                <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center font-bold text-sm">3</div>
                <div>
                    <h2 class="text-lg font-bold text-slate-800">Legalitas & Berkas Kemitraan</h2>
                    <p class="text-xs text-slate-400">Unggah berkas proposal atau company profile mitra (jika ada)</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nomor Izin Usaha / NIB / Yayasan (Opsional)</label>
                    <input type="text" name="nomor_izin_mitra" value="<?= e($old['nomor_izin_mitra']) ?>" placeholder="Contoh: NIB-912000381023"
                           class="w-full px-4 py-2.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Unggah Proposal / Dokumen Kemitraan (Maks 15 MB)</label>
                    <input type="file" name="dokumen_kemitraan" accept=".pdf,.zip,.rar,.docx"
                           class="w-full text-xs text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 transition-all border border-slate-200 rounded-xl">
                    <p class="text-[11px] text-slate-400 mt-1">Format yang didukung: PDF, ZIP, RAR, DOCX.</p>
                </div>
            </div>
        </div>

        <!-- Tombol Aksi Submit -->
        <div class="pt-6 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-xs text-slate-500">
                Dengan menekan tombol kirim, Anda menyetujui syarat & ketentuan kemitraan kursus offline Bisolpin Academy.
            </p>
            <button type="submit" class="w-full sm:w-auto btn-brand px-8 py-3.5 text-sm shadow-xl shadow-brand-500/20">
                <i class="fa-solid fa-paper-plane text-xs"></i>
                <span>Kirim Formulir Pendaftaran Mitra</span>
            </button>
        </div>

    </form>
</div>

</body>
</html>

