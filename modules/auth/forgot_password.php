<?php
/**
 * SIM BISOLPIN ACADEMY - Permohonan Pemulihan Kata Sandi (Forgot Password)
 * UI/UX Modern 2-Column Split Screen dengan Visual Step Indicator, Demo Quick-Fill,
 * Kartu Tiket Pemulihan Interaktif, dan Dukungan Pengujian Lokal.
 */

declare(strict_types=1);

$pageTitle = 'Lupa Kata Sandi';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Jika sudah login, redirect langsung ke dashboard
if (is_logged_in()) {
    redirect('index.php');
}

$errorMessage = '';
$successData  = null;
$inputIdentifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $inputIdentifier = trim($_POST['identifier'] ?? '');

    if (empty($inputIdentifier)) {
        $errorMessage = 'Silakan masukkan username atau alamat email akun Anda.';
    } else {
        $db = get_db();
        ensure_database_schema_integrity($db);

        $stmt = $db->prepare("
            SELECT u.*, 
                   COALESCE(p.nama_lengkap, s.nama_lengkap, u.username) as display_name,
                   c.nama_cabang
            FROM users u
            LEFT JOIN tabel_pegawai p ON p.user_id = u.id
            LEFT JOIN tabel_siswa s ON s.user_id = u.id
            LEFT JOIN tabel_cabang c ON c.id = u.id_cabang
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$inputIdentifier, $inputIdentifier]);
        $user = $stmt->fetch();

        if ($user) {
            // Cek apakah akun aktif
            if ((int)$user['is_active'] !== 1) {
                $errorMessage = 'Akun Anda saat ini berstatus nonaktif atau sedang dalam tahap verifikasi kemitraan. Silakan hubungi Kantor Pusat atau Admin Sistem.';
            } else {
                $tokenInfo = generate_password_reset_token((int)$user['id'], (string)$user['email']);
                if ($tokenInfo) {
                    $successData = [
                        'username'     => $user['username'],
                        'display_name' => $user['display_name'],
                        'email'        => $user['email'],
                        'role'         => $user['role'],
                        'nama_cabang'  => $user['nama_cabang'] ?? 'Pusat / Seluruh Cabang',
                        'reset_url'    => $tokenInfo['reset_url'],
                        'raw_token'    => $tokenInfo['raw_token'],
                        'expires_at'   => $tokenInfo['expires_at'],
                    ];
                } else {
                    $errorMessage = 'Gagal membuat tautan pemulihan. Silakan coba kembali beberapa saat lagi.';
                }
            }
        } else {
            $errorMessage = 'Akun dengan username atau email tersebut tidak ditemukan. Mohon periksa kembali ejaan Anda.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi | <?= APP_NAME ?></title>

    <link rel="icon" type="image/png" href="<?= asset_url('img/logo.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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
                            800: '#0b5f58',
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
<body class="h-full text-slate-800 antialiased flex items-center justify-center p-4 sm:p-6 lg:p-8 bg-slate-100/70">

<div class="w-full max-w-5xl bg-white rounded-3xl shadow-2xl border border-slate-200/80 overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[620px]">

    <!-- ================================================================= -->
    <!-- KOLOM KIRI: BRANDING & PANDUAN KEAMANAN                           -->
    <!-- ================================================================= -->
    <div class="lg:col-span-5 bg-gradient-brand text-white p-8 sm:p-10 flex flex-col justify-between relative overflow-hidden">
        <!-- Ambient Blur Glows -->
        <div class="absolute -right-16 -top-16 w-56 h-56 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 w-48 h-48 bg-teal-300/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10">
            <!-- Header Brand & Badge -->
            <div class="flex items-center gap-3 mb-6">
                <a href="<?= base_url('modules/auth/login.php') ?>" class="shrink-0 transition-transform hover:scale-105">
                    <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="w-14 h-14 object-contain drop-shadow-md">
                </a>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/15 backdrop-blur-md border border-white/20 text-xs font-semibold">
                    <i class="fa-solid fa-shield-halved text-teal-200"></i>
                    <span>Pusat Pemulihan Akun</span>
                </div>
            </div>

            <h1 class="text-2xl sm:text-3xl font-black tracking-tight mb-3">BISOLPIN ACADEMY</h1>
            <p class="text-teal-50 text-xs sm:text-sm leading-relaxed mb-8">
                Layanan mandiri pengaturan ulang kata sandi dengan enkripsi berstandar industri dan proteksi token berbasis waktu.
            </p>

            <!-- Fitur Keamanan Pemulihan -->
            <div class="space-y-4 text-xs">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Token Kriptografis Berbatas Waktu</strong>
                        <span class="text-teal-100">Tautan reset hanya berlaku 30 menit & langsung hangus setelah sekali pakai.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Enkripsi BCRYPT Berlapis</strong>
                        <span class="text-teal-100">Kata sandi baru langsung di-hash aman tanpa dapat dibaca oleh siapa pun.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Audit Keamanan Otomatis</strong>
                        <span class="text-teal-100">Setiap perubahan terekam di Log Audit Sistem demi keamanan data cabang.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bagian Bawah: Navigasi Cepat -->
        <div class="relative z-10 pt-6 border-t border-white/20 mt-6 flex items-center justify-between text-xs">
            <span class="text-teal-100">Sudah ingat kata sandi?</span>
            <a href="<?= base_url('modules/auth/login.php') ?>" class="font-bold text-white hover:underline inline-flex items-center gap-1.5">
                <span>Masuk Sekarang</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- KOLOM KANAN: FORM INTERAKTIF / STATUS TIKET                      -->
    <!-- ================================================================= -->
    <div class="lg:col-span-7 bg-white p-8 sm:p-12 flex flex-col justify-between">
        
        <div>
            <!-- Step Progress Indicator -->
            <div class="mb-8">
                <div class="flex items-center justify-between relative">
                    <div class="w-full absolute top-1/2 left-0 -translate-y-1/2 h-0.5 bg-slate-100 -z-0"></div>
                    <div class="w-1/2 absolute top-1/2 left-0 -translate-y-1/2 h-0.5 bg-brand-500 -z-0 transition-all duration-500 <?= $successData ? 'w-full' : 'w-1/4' ?>"></div>

                    <!-- Step 1 -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full bg-brand-500 text-white text-xs font-bold flex items-center justify-center shadow-md shadow-brand-500/30">
                            <?= $successData ? '<i class="fa-solid fa-check text-[10px]"></i>' : '1' ?>
                        </div>
                        <span class="text-[10px] font-bold text-slate-700 mt-1">Identifikasi</span>
                    </div>

                    <!-- Step 2 -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full <?= $successData ? 'bg-brand-500 text-white shadow-md shadow-brand-500/30 animate-pulse' : 'bg-slate-100 text-slate-400' ?> text-xs font-bold flex items-center justify-center">
                            2
                        </div>
                        <span class="text-[10px] font-bold <?= $successData ? 'text-brand-700' : 'text-slate-400' ?> mt-1">Verifikasi Token</span>
                    </div>

                    <!-- Step 3 -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-400 text-xs font-bold flex items-center justify-center">
                            3
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 mt-1">Sandi Baru</span>
                    </div>
                </div>
            </div>

            <?php if ($successData): ?>
                <!-- ========================================================= -->
                <!-- STATE 2: KARTU TIKET PEMULIHAN BERHASIL DIBUAT            -->
                <!-- ========================================================= -->
                <div class="animate-in fade-in duration-300">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold mb-3">
                        <i class="fa-solid fa-circle-check text-emerald-600"></i>
                        <span>Identitas Terverifikasi</span>
                    </div>

                    <h2 class="text-2xl font-black text-slate-800 tracking-tight mb-2">Tautan Pemulihan Siap!</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">
                        Kami telah menyiapkan tautan aman untuk mengatur ulang kata sandi akun Anda. Tautan ini dilindungi token unik dan berlaku selama <strong>30 menit</strong>.
                    </p>

                    <!-- Kartu Identitas Akun (Security Clearance Ticket) -->
                    <div class="p-5 rounded-2xl bg-gradient-to-br from-slate-50 to-teal-50/40 border border-slate-200 shadow-sm mb-6 relative overflow-hidden">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 rounded-2xl bg-brand-500 text-white font-black text-xl flex items-center justify-center shadow-lg shadow-brand-500/20 shrink-0 uppercase">
                                <?= substr($successData['display_name'], 0, 2) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                    <h4 class="font-extrabold text-slate-800 text-sm truncate"><?= e($successData['display_name']) ?></h4>
                                    <?= render_status_badge($successData['role']) ?>
                                </div>
                                <div class="text-xs text-slate-500 font-mono">Username: <strong><?= e($successData['username']) ?></strong></div>
                                <div class="text-xs text-slate-400 truncate"><?= e($successData['email']) ?> &bull; <?= e($successData['nama_cabang']) ?></div>
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-200/80 flex items-center justify-between text-[11px] text-slate-500">
                            <span class="flex items-center gap-1.5">
                                <i class="fa-solid fa-hourglass-half text-amber-500"></i>
                                <span>Kedaluwarsa Pukul: <strong><?= date('H:i', strtotime($successData['expires_at'])) ?> WITA</strong></span>
                            </span>
                            <span class="font-bold text-emerald-600 flex items-center gap-1">
                                <i class="fa-solid fa-shield-check"></i> One-Time Token
                            </span>
                        </div>
                    </div>

                    <!-- Call To Action Utama: Buka Halaman Reset -->
                    <div class="space-y-3">
                        <a href="<?= e($successData['reset_url']) ?>" 
                           class="w-full btn-brand py-3.5 px-4 rounded-xl shadow-lg shadow-brand-500/30 flex items-center justify-center gap-2.5 font-bold text-sm text-center transition-all hover:scale-[1.01]">
                            <i class="fa-solid fa-key text-xs"></i>
                            <span>Buka Halaman Reset Kata Sandi</span>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>

                        <div class="flex items-center gap-2">
                            <input type="text" readonly id="copyTargetUrl" value="<?= e($successData['reset_url']) ?>" 
                                   class="text-[11px] font-mono text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5 flex-1 outline-none">
                            <button type="button" onclick="copyResetUrl()" id="btnCopyUrl" 
                                    class="px-4 py-2.5 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-bold transition-all flex items-center gap-1.5 shrink-0">
                                <i class="fa-regular fa-copy"></i>
                                <span id="copyBtnText">Salin Tautan</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-between text-xs">
                        <a href="<?= base_url('modules/auth/forgot_password.php') ?>" class="text-slate-500 hover:text-slate-800 font-semibold flex items-center gap-1.5">
                            <i class="fa-solid fa-rotate-left text-[10px]"></i>
                            <span>Cari Akun Lain</span>
                        </a>
                        <a href="<?= base_url('modules/auth/login.php') ?>" class="text-brand-600 hover:text-brand-700 font-bold flex items-center gap-1.5">
                            <i class="fa-solid fa-arrow-left text-[10px]"></i>
                            <span>Kembali ke Halaman Masuk</span>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- ========================================================= -->
                <!-- STATE 1: FORM PENCARIAN & IDENTIFIKASI AKUN              -->
                <!-- ========================================================= -->
                <div class="animate-in fade-in duration-300">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-teal-50 border border-teal-200 text-teal-800 text-xs font-bold mb-3">
                        <i class="fa-solid fa-unlock-keyhole text-teal-600"></i>
                        <span>Langkah 1 dari 3</span>
                    </div>

                    <h2 class="text-2xl font-black text-slate-800 tracking-tight mb-2">Lupa Kata Sandi?</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-6">
                        Masukkan username atau alamat email terdaftar Anda. Sistem akan memverifikasi identitas Anda dan menyiapkan tautan pemulihan kata sandi baru.
                    </p>

                    <!-- Alert Eror jika Akun Tidak Ditemukan -->
                    <?php if (!empty($errorMessage)): ?>
                        <div class="mb-5 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-3">
                            <i class="fa-solid fa-circle-exclamation text-base shrink-0 text-rose-500 mt-0.5"></i>
                            <div class="flex-1 font-medium leading-relaxed"><?= e($errorMessage) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="space-y-4">
                        <?= csrf_field() ?>

                        <div>
                            <label for="identifier" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                                Username atau Alamat Email
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                                    <i class="fa-regular fa-envelope text-sm"></i>
                                </span>
                                <input type="text" id="identifier" name="identifier" value="<?= e($inputIdentifier) ?>" required autofocus
                                       placeholder="Contoh: mentor_ahmad / siti@bisolpin.com"
                                       class="w-full pl-10 pr-4 py-3.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all bg-slate-50/50">
                            </div>
                        </div>

                        <!-- Quick-Fill Chips untuk Kemudahan Pengujian -->
                        <div>
                            <span class="text-[11px] font-bold text-slate-400 block mb-1.5">Contoh Akun Pengujian:</span>
                            <div class="flex flex-wrap gap-1.5">
                                <button type="button" onclick="fillIdentifier('mentor_ahmad')" 
                                        class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-700 text-slate-600 text-[11px] font-mono transition-all border border-slate-200">
                                    mentor_ahmad
                                </button>
                                <button type="button" onclick="fillIdentifier('staff.siti')" 
                                        class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-700 text-slate-600 text-[11px] font-mono transition-all border border-slate-200">
                                    staff.siti
                                </button>
                                <button type="button" onclick="fillIdentifier('direktur_mks')" 
                                        class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-700 text-slate-600 text-[11px] font-mono transition-all border border-slate-200">
                                    direktur_mks
                                </button>
                                <button type="button" onclick="fillIdentifier('admin.sistem')" 
                                        class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-brand-50 hover:text-brand-700 text-slate-600 text-[11px] font-mono transition-all border border-slate-200">
                                    admin.sistem
                                </button>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button type="submit" class="w-full btn-brand py-3.5 text-sm rounded-xl shadow-lg shadow-brand-500/30 flex items-center justify-center gap-2 font-bold">
                                <span>Verifikasi & Lanjutkan</span>
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </button>
                        </div>
                    </form>

                    <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400">Punya kendala login lainnya?</span>
                        <a href="<?= base_url('modules/auth/login.php') ?>" class="text-brand-600 hover:text-brand-700 font-bold flex items-center gap-1.5">
                            <i class="fa-solid fa-arrow-left text-[10px]"></i>
                            <span>Kembali ke Halaman Masuk</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer Copyright & Portal Info -->
        <div class="pt-6 mt-6 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between text-[11px] text-slate-400 gap-2">
            <span>&copy; <?= date('Y') ?> SIM BISOLPIN ACADEMY. All rights reserved.</span>
            <span>Versi 2.0.0 &bull; Secure Auth</span>
        </div>

    </div>
</div>

<script>
function fillIdentifier(val) {
    var input = document.getElementById('identifier');
    if (input) {
        input.value = val;
        input.focus();
    }
}

function copyResetUrl() {
    var copyText = document.getElementById('copyTargetUrl');
    if (!copyText) return;

    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        var btnText = document.getElementById('copyBtnText');
        var btn = document.getElementById('btnCopyUrl');
        if (btnText && btn) {
            btnText.textContent = 'Tersalin!';
            btn.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
            setTimeout(function() {
                btnText.textContent = 'Salin Tautan';
                btn.classList.remove('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');
            }, 2500);
        }
    });
}
</script>

</body>
</html>
