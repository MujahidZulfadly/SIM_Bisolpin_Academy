<?php
/**
 * SIM BISOLPIN ACADEMY - Form Pembuatan Kata Sandi Baru (Reset Password)
 * UI/UX Modern 2-Column Split Screen dengan Visual Step Indicator, Live Password Strength Meter,
 * Match Validator, dan Show/Hide Toggle.
 */

declare(strict_types=1);

$pageTitle = 'Buat Kata Sandi Baru';

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Jika sudah login, redirect langsung
if (is_logged_in()) {
    redirect('index.php');
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errorMessage = '';
$tokenData = null;

if (empty($token)) {
    $errorMessage = 'Token pemulihan kata sandi tidak ditemukan pada tautan ini.';
} else {
    $tokenData = verify_password_reset_token($token);
    if (!$tokenData) {
        $errorMessage = 'Tautan pemulihan ini tidak valid atau sudah kedaluwarsa (melebihi batas waktu 30 menit). Silakan ajukan permohonan baru.';
    }
}

// Proses POST Form Ganti Kata Sandi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenData) {
    validate_csrf_or_abort();

    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $errorMessage = 'Kata sandi baru minimal harus terdiri dari 6 karakter.';
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = 'Konfirmasi kata sandi tidak sesuai dengan kata sandi baru yang dimasukkan.';
    } else {
        $success = complete_password_reset($token, $newPassword);

        if ($success) {
            set_flash(
                'success',
                'Kata sandi untuk akun <strong>' . e($tokenData['username']) . '</strong> berhasil diperbarui! Silakan masuk menggunakan kata sandi baru Anda.',
                'Kata Sandi Berhasil Diperbarui'
            );
            redirect('modules/auth/login.php');
        } else {
            $errorMessage = 'Gagal memperbarui kata sandi. Tautan ini mungkin sudah pernah digunakan.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Kata Sandi Baru | <?= APP_NAME ?></title>

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
    <!-- KOLOM KIRI: BRANDING & PANDUAN KATA SANDI KUAT                    -->
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
                    <i class="fa-solid fa-lock text-teal-200"></i>
                    <span>Pengaturan Sandi Baru</span>
                </div>
            </div>

            <h1 class="text-2xl sm:text-3xl font-black tracking-tight mb-3">KEAMANAN AKUN</h1>
            <p class="text-teal-50 text-xs sm:text-sm leading-relaxed mb-8">
                Buat kata sandi baru yang kuat dan unik untuk melindungi data akademik, presensi, dan riwayat kemitraan Anda.
            </p>

            <!-- Panduan Kata Sandi -->
            <div class="space-y-4 text-xs">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Minimal 6 Karakter</strong>
                        <span class="text-teal-100">Gunakan kombinasi huruf besar, kecil, angka, atau simbol.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Enkripsi BCRYPT Standar Industri</strong>
                        <span class="text-teal-100">Sandi dienkripsi satu arah dengan salt acak yang tidak dapat dibalik.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-bold">Pencegahan Akses Ilegal</strong>
                        <span class="text-teal-100">Setelah disimpan, seluruh sesi lama akan diperbarui secara otomatis.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bagian Bawah: Navigasi Batal -->
        <div class="relative z-10 pt-6 border-t border-white/20 mt-6 flex items-center justify-between text-xs">
            <span class="text-teal-100">Batal melakukan reset?</span>
            <a href="<?= base_url('modules/auth/login.php') ?>" class="font-bold text-white hover:underline inline-flex items-center gap-1.5">
                <span>Kembali ke Login</span>
                <i class="fa-solid fa-arrow-right text-[10px]"></i>
            </a>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- KOLOM KANAN: FORM GANTI SANDI BARU                               -->
    <!-- ================================================================= -->
    <div class="lg:col-span-7 bg-white p-8 sm:p-12 flex flex-col justify-between">
        
        <div>
            <!-- Step Progress Indicator -->
            <div class="mb-8">
                <div class="flex items-center justify-between relative">
                    <div class="w-full absolute top-1/2 left-0 -translate-y-1/2 h-0.5 bg-slate-100 -z-0"></div>
                    <div class="w-full absolute top-1/2 left-0 -translate-y-1/2 h-0.5 bg-brand-500 -z-0"></div>

                    <!-- Step 1 Selesai -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full bg-brand-500 text-white text-xs font-bold flex items-center justify-center shadow-md shadow-brand-500/30">
                            <i class="fa-solid fa-check text-[10px]"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-700 mt-1">Identifikasi</span>
                    </div>

                    <!-- Step 2 Selesai -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full bg-brand-500 text-white text-xs font-bold flex items-center justify-center shadow-md shadow-brand-500/30">
                            <i class="fa-solid fa-check text-[10px]"></i>
                        </div>
                        <span class="text-[10px] font-bold text-slate-700 mt-1">Verifikasi</span>
                    </div>

                    <!-- Step 3 Aktif -->
                    <div class="flex flex-col items-center relative z-10">
                        <div class="w-8 h-8 rounded-full bg-brand-500 text-white text-xs font-bold flex items-center justify-center shadow-md shadow-brand-500/30 animate-pulse">
                            3
                        </div>
                        <span class="text-[10px] font-bold text-brand-700 mt-1">Sandi Baru</span>
                    </div>
                </div>
            </div>

            <?php if (!$tokenData): ?>
                <!-- TAMPILAN TOKEN KEDALUWARSA ATAU INVALID -->
                <div class="text-center py-6 animate-in fade-in duration-300">
                    <div class="w-16 h-16 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center text-2xl mx-auto mb-4 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <h2 class="text-xl font-black text-slate-800 mb-2">Tautan Tidak Valid atau Kedaluwarsa</h2>
                    <p class="text-xs text-slate-500 leading-relaxed max-w-md mx-auto mb-6">
                        <?= e($errorMessage) ?>
                    </p>

                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                        <a href="<?= base_url('modules/auth/forgot_password.php') ?>" 
                           class="btn-brand text-xs py-3 px-5 rounded-xl font-bold shadow-md shadow-brand-500/20 flex items-center gap-2">
                            <i class="fa-solid fa-rotate-right text-xs"></i>
                            <span>Minta Tautan Pemulihan Baru</span>
                        </a>
                        <a href="<?= base_url('modules/auth/login.php') ?>" 
                           class="px-5 py-3 rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 text-xs font-bold transition-all">
                            <span>Kembali ke Login</span>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- FORM PEMBUATAN SANDI BARU -->
                <div class="animate-in fade-in duration-300">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-teal-50 border border-teal-200 text-teal-800 text-xs font-bold mb-3">
                        <i class="fa-solid fa-key text-teal-600"></i>
                        <span>Langkah 3 dari 3</span>
                    </div>

                    <h2 class="text-2xl font-black text-slate-800 tracking-tight mb-2">Buat Kata Sandi Baru</h2>
                    <p class="text-xs text-slate-500 leading-relaxed mb-5">
                        Silakan tentukan kata sandi baru untuk akun Anda. Pastikan kata sandi mudah Anda ingat namun sulit ditebak orang lain.
                    </p>

                    <!-- Info Ringkas Akun yang Direset -->
                    <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200 flex items-center justify-between mb-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-brand-500 text-white font-black text-sm flex items-center justify-center shadow-sm uppercase shrink-0">
                                <?= substr($tokenData['display_name'], 0, 2) ?>
                            </div>
                            <div class="min-w-0">
                                <h4 class="font-extrabold text-slate-800 text-xs truncate"><?= e($tokenData['display_name']) ?></h4>
                                <div class="text-[11px] text-slate-400 font-mono">@<?= e($tokenData['username']) ?> &bull; <?= e($tokenData['user_email']) ?></div>
                            </div>
                        </div>
                        <?= render_status_badge($tokenData['role']) ?>
                    </div>

                    <?php if (!empty($errorMessage)): ?>
                        <div class="mb-5 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-3">
                            <i class="fa-solid fa-circle-exclamation text-base shrink-0 text-rose-500 mt-0.5"></i>
                            <div class="flex-1 font-medium leading-relaxed"><?= e($errorMessage) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" class="space-y-4" onsubmit="return validateForm()">
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">

                        <!-- Input Kata Sandi Baru -->
                        <div>
                            <label for="new_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                                Kata Sandi Baru <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                                    <i class="fa-solid fa-key text-sm"></i>
                                </span>
                                <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password"
                                       placeholder="Minimal 6 karakter..." oninput="checkStrength(this.value)"
                                       class="w-full pl-10 pr-10 py-3.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all bg-slate-50/50">
                                <button type="button" onclick="togglePass('new_password', 'eye1')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
                                    <i id="eye1" class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                            
                            <!-- Strength Indicator Bar -->
                            <div class="mt-2">
                                <div class="flex items-center justify-between text-[10px] font-bold text-slate-400 mb-1">
                                    <span>Kekuatan Kata Sandi:</span>
                                    <span id="strengthText" class="text-slate-400">Belum Diisi</span>
                                </div>
                                <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden flex gap-1">
                                    <div id="bar1" class="h-full w-1/3 bg-slate-200 transition-all duration-300 rounded-full"></div>
                                    <div id="bar2" class="h-full w-1/3 bg-slate-200 transition-all duration-300 rounded-full"></div>
                                    <div id="bar3" class="h-full w-1/3 bg-slate-200 transition-all duration-300 rounded-full"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Input Konfirmasi Kata Sandi -->
                        <div>
                            <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">
                                Konfirmasi Kata Sandi Baru <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                                    <i class="fa-solid fa-shield-halved text-sm"></i>
                                </span>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password"
                                       placeholder="Ulangi kata sandi baru..." oninput="checkMatch()"
                                       class="w-full pl-10 pr-10 py-3.5 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 outline-none transition-all bg-slate-50/50">
                                <button type="button" onclick="togglePass('confirm_password', 'eye2')" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
                                    <i id="eye2" class="fa-regular fa-eye text-sm"></i>
                                </button>
                            </div>
                            <span id="matchFeedback" class="text-[11px] font-semibold mt-1 block hidden"></span>
                        </div>

                        <div class="pt-2">
                            <button type="submit" id="btnSubmit" class="w-full btn-brand py-3.5 text-sm rounded-xl shadow-lg shadow-brand-500/30 flex items-center justify-center gap-2 font-bold">
                                <i class="fa-solid fa-floppy-disk text-xs"></i>
                                <span>Simpan Kata Sandi Baru & Masuk</span>
                            </button>
                        </div>
                    </form>

                    <div class="mt-8 pt-6 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span class="text-slate-400">Batal melakukan perubahan?</span>
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
            <span>Keamanan Kriptografi BCRYPT &bull; v2.0.0</span>
        </div>

    </div>
</div>

<script>
function togglePass(inputId, eyeId) {
    const input = document.getElementById(inputId);
    const eye = document.getElementById(eyeId);
    if (!input || !eye) return;

    if (input.type === 'password') {
        input.type = 'text';
        eye.classList.remove('fa-eye');
        eye.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        eye.classList.remove('fa-eye-slash');
        eye.classList.add('fa-eye');
    }
}

function checkStrength(val) {
    const bar1 = document.getElementById('bar1');
    const bar2 = document.getElementById('bar2');
    const bar3 = document.getElementById('bar3');
    const label = document.getElementById('strengthText');
    if (!bar1 || !bar2 || !bar3 || !label) return;

    bar1.className = 'h-full w-1/3 transition-all duration-300 rounded-full bg-slate-200';
    bar2.className = 'h-full w-1/3 transition-all duration-300 rounded-full bg-slate-200';
    bar3.className = 'h-full w-1/3 transition-all duration-300 rounded-full bg-slate-200';

    if (!val || val.length === 0) {
        label.textContent = 'Belum Diisi';
        label.className = 'text-slate-400';
        return;
    }

    let score = 0;
    if (val.length >= 6) score++;
    if (val.length >= 8 && /[0-9]/.test(val)) score++;
    if (val.length >= 10 && /[A-Z]/.test(val) && /[^A-Za-z0-9]/.test(val)) score++;

    if (score === 1) {
        bar1.classList.remove('bg-slate-200');
        bar1.classList.add('bg-rose-500');
        label.textContent = 'Cukup / Standar (Min. 6 Karakter)';
        label.className = 'text-rose-500 font-bold';
    } else if (score === 2) {
        bar1.classList.remove('bg-slate-200');
        bar1.classList.add('bg-amber-500');
        bar2.classList.remove('bg-slate-200');
        bar2.classList.add('bg-amber-500');
        label.textContent = 'Sedang (Kombinasi Angka)';
        label.className = 'text-amber-600 font-bold';
    } else if (score >= 3) {
        bar1.classList.remove('bg-slate-200');
        bar1.classList.add('bg-emerald-500');
        bar2.classList.remove('bg-slate-200');
        bar2.classList.add('bg-emerald-500');
        bar3.classList.remove('bg-slate-200');
        bar3.classList.add('bg-emerald-500');
        label.textContent = 'Sangat Kuat (Kombinasi Sempurna)';
        label.className = 'text-emerald-600 font-bold';
    }

    checkMatch();
}

function checkMatch() {
    const p1 = document.getElementById('new_password');
    const p2 = document.getElementById('confirm_password');
    const feedback = document.getElementById('matchFeedback');
    if (!p1 || !p2 || !feedback) return;

    if (!p2.value) {
        feedback.classList.add('hidden');
        return;
    }

    feedback.classList.remove('hidden');
    if (p1.value === p2.value) {
        feedback.textContent = '✓ Kata sandi cocok!';
        feedback.className = 'text-[11px] font-bold text-emerald-600 mt-1 block';
    } else {
        feedback.textContent = '✗ Konfirmasi kata sandi belum cocok.';
        feedback.className = 'text-[11px] font-bold text-rose-500 mt-1 block';
    }
}

function validateForm() {
    const p1 = document.getElementById('new_password');
    const p2 = document.getElementById('confirm_password');
    if (p1 && p2 && p1.value !== p2.value) {
        alert('Konfirmasi kata sandi tidak cocok. Harap periksa kembali.');
        return false;
    }
    return true;
}
</script>

</body>
</html>
