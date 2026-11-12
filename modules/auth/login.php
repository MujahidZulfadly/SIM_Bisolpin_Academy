<?php
/**
 * SIM BISOLPIN ACADEMY - Multi-Role Unified Login Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/csrf.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Jika sudah dalam kondisi login, langsung arahkan ke dashboard sesuai role
if (is_logged_in()) {
    redirect('index.php');
}

$errorMessage = '';
$inputIdentifier = '';

// Proses Autentikasi Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_abort();

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $inputIdentifier = $identifier;

    if (empty($identifier) || empty($password)) {
        $errorMessage = 'Silakan masukkan username/email dan kata sandi Anda.';
    } else {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ((int)$user['is_active'] !== 1) {
                if ($user['role'] === ROLE_DIREKTUR_CABANG) {
                    $errorMessage = 'Pengajuan kemitraan cabang Anda saat ini masih dalam tahap peninjauan (Pending Review) oleh Pimpinan Pusat. Silakan tunggu verifikasi resmi.';
                } else {
                    $errorMessage = 'Akun Anda dinonaktifkan oleh administrator. Silakan hubungi bagian administrasi.';
                }
            } else {
                login_user($user);
                set_flash('success', 'Selamat datang kembali, <strong>' . e($_SESSION['user']['nama_lengkap']) . '</strong> di SIM Bisolpin Academy!', 'Login Berhasil');
                redirect('index.php');
            }
        } else {
            $errorMessage = 'Username/email atau kata sandi yang Anda masukkan tidak sesuai.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Portal SIM-BISOLPIN | <?= APP_NAME ?></title>

    <!-- Favicon Resmi Bisolpin Academy -->
    <link rel="icon" type="image/png" href="<?= asset_url('img/logo.png') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('img/logo.png') ?>">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

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

    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= asset_url('css/variables.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/main.css') ?>">
</head>
<body class="h-full text-slate-800 antialiased flex items-center justify-center p-4 sm:p-6 lg:p-8 bg-slate-100/70">

<div class="w-full max-w-5xl bg-white rounded-3xl shadow-2xl border border-slate-200/80 overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[660px]">

    <!-- Kolom Kiri: Branding & Informasi Kemitraan Bisolpin -->
    <div class="lg:col-span-5 bg-gradient-brand text-white p-8 sm:p-10 flex flex-col justify-between relative overflow-hidden">
        <!-- Background Blur Spheres -->
        <div class="absolute -right-16 -top-16 w-56 h-56 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 w-48 h-48 bg-teal-300/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10">
            <!-- Brand Logo & Badge -->
            <div class="flex items-center gap-3 mb-6">
                <img src="<?= asset_url('img/logo.png') ?>" alt="Logo Bisolpin Academy" class="w-16 h-16 object-contain shrink-0 drop-shadow-sm">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/15 backdrop-blur-md border border-white/20 text-xs font-semibold">
                    <i class="fa-solid fa-graduation-cap text-teal-200"></i>
                    <span>SIAKAD & Kemitraan V2</span>
                </div>
            </div>

            <h1 class="text-3xl font-extrabold tracking-tight mb-3">BISOLPIN ACADEMY</h1>
            <p class="text-teal-50 text-sm leading-relaxed mb-8">
                Sistem Informasi Manajemen Terpadu untuk kursus tatap muka, standardisasi kurikulum BAK Pusat, dan ekosistem kemitraan multi-cabang.
            </p>

            <!-- Feature Bullet List -->
            <div class="space-y-4 text-xs">
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-building-circle-check"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-semibold">Multi-Branch Tenant Isolation</strong>
                        <span class="text-teal-100">Pimpinan Pusat mengawasi nasional, cabang terisolasi aman.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-book-bookmark"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-semibold">Standardisasi Modul BAK</strong>
                        <span class="text-teal-100">Modul resmi PDF/PPT disiapkan oleh BAK, mentor fokus mengajar.</span>
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-lg bg-white/15 flex items-center justify-center shrink-0 text-teal-200">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div>
                        <strong class="block text-white font-semibold">Showcase & Validasi Karya Siswa</strong>
                        <span class="text-teal-100">Galeri portofolio karya praktikum hasil belajar tatap muka.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bawah: Call to Action Registrasi Kemitraan -->
        <div class="relative z-10 pt-8 border-t border-white/20 mt-6">
            <p class="text-xs text-teal-100 mb-2">Ingin membuka cabang kursus di kota Anda?</p>
            <a href="<?= base_url('modules/auth/register_mitra.php') ?>" class="inline-flex items-center gap-2 text-xs font-bold text-white bg-white/20 hover:bg-white/30 px-4 py-2 rounded-xl transition-all border border-white/30">
                <span>Daftar Kemitraan Cabang Baru</span>
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- Kolom Kanan: Formulir Login & Quick Demo Panel -->
    <div class="lg:col-span-7 p-8 sm:p-10 flex flex-col justify-between bg-white">
        <div>
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-800">Masuk ke Portal</h2>
                    <p class="text-xs text-slate-400 mt-1">Gunakan akun kredensial Anda untuk melanjutkan</p>
                </div>
                <img src="<?= asset_url('img/logo.png') ?>" alt="Bisolpin" class="w-14 h-14 object-contain">
            </div>

            <!-- Pesan Error jika Ada -->
            <?php if (!empty($errorMessage)): ?>
                <div class="mb-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs flex items-start gap-3">
                    <i class="fa-solid fa-circle-exclamation text-base shrink-0 text-rose-500 mt-0.5"></i>
                    <div class="flex-1 font-medium"><?= e($errorMessage) ?></div>
                </div>
            <?php endif; ?>

            <!-- Form Login -->
            <form id="loginForm" method="POST" action="" class="space-y-4">
                <?= csrf_field() ?>

                <div>
                    <label for="identifier" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Username atau Email</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-regular fa-user text-sm"></i>
                        </span>
                        <input type="text" id="identifier" name="identifier" value="<?= e($inputIdentifier) ?>" required autocomplete="username"
                               placeholder="pimpinan / direktur_mks / mentor_ahmad..."
                               class="w-full pl-10 pr-4 py-3 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all bg-slate-50/50">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Kata Sandi</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-key text-sm"></i>
                        </span>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                               placeholder="••••••••"
                               class="w-full pl-10 pr-10 py-3 text-sm rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none transition-all bg-slate-50/50">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600">
                            <i id="toggle-eye" class="fa-regular fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" id="btnSubmitLogin" class="w-full btn-brand py-3 text-sm rounded-xl shadow-lg shadow-brand-500/30 flex items-center justify-center gap-2">
                        <span>Masuk ke Dashboard</span>
                        <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
                    </button>
                </div>
            </form>

            <!-- ============================================================= -->
            <!-- PANEL QUICK LOGIN DEMO (7 ROLE BISOLPIN)                      -->
            <!-- ============================================================= -->
            <div class="mt-6 pt-5 border-t border-slate-200">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-amber-100 text-amber-800 border border-amber-200">
                            <i class="fa-solid fa-bolt mr-1"></i> Quick Login Demo (7 Role)
                        </span>
                    </div>
                    <span class="text-[11px] font-mono text-slate-400">Default Pass: <strong>password123</strong></span>
                </div>

                <p class="text-[11px] text-slate-500 mb-3">
                    Klik salah satu peran di bawah untuk mengisi kredensial secara instan saat simulasi & presentasi pimpinan:
                </p>

                <!-- Grid 7 Tombol Role Cepat -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    <!-- 1. PIMPINAN -->
                    <button type="button" onclick="fillAndHighlight('pimpinan', 'Pusat (Super Admin)', this)" class="role-demo-btn p-2 rounded-xl border border-purple-200 bg-purple-50/70 hover:bg-purple-100 text-purple-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-purple-600">1. Pusat</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>👑</span>
                            <span class="truncate">Pimpinan</span>
                        </div>
                    </button>

                    <!-- 2. DIREKTUR CABANG -->
                    <button type="button" onclick="fillAndHighlight('direktur_mks', 'Cabang Makassar (Mitra)', this)" class="role-demo-btn p-2 rounded-xl border border-indigo-200 bg-indigo-50/70 hover:bg-indigo-100 text-indigo-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-indigo-600">2. Cabang</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>🏢</span>
                            <span class="truncate">Direktur</span>
                        </div>
                    </button>

                    <!-- 3. BAK PUSAT -->
                    <button type="button" onclick="fillAndHighlight('bak_pusat', 'Pusat (Kurikulum)', this)" class="role-demo-btn p-2 rounded-xl border border-sky-200 bg-sky-50/70 hover:bg-sky-100 text-sky-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-sky-600">3. Akademik</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>📚</span>
                            <span class="truncate">BAK Pusat</span>
                        </div>
                    </button>

                    <!-- 4. MENTOR -->
                    <button type="button" onclick="fillAndHighlight('mentor_ahmad', 'Cabang Makassar (Tutor)', this)" class="role-demo-btn p-2 rounded-xl border border-teal-200 bg-teal-50/70 hover:bg-teal-100 text-teal-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-teal-600">4. Pengajar</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>👨‍🏫</span>
                            <span class="truncate">Mentor</span>
                        </div>
                    </button>

                    <!-- 5. STAFF -->
                    <button type="button" onclick="fillAndHighlight('staff_siti', 'Cabang Makassar (Front Office)', this)" class="role-demo-btn p-2 rounded-xl border border-amber-200 bg-amber-50/70 hover:bg-amber-100 text-amber-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-amber-600">5. Admin</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>📋</span>
                            <span class="truncate">Staff Kasir</span>
                        </div>
                    </button>

                    <!-- 6. SARPRAS -->
                    <button type="button" onclick="fillAndHighlight('sarpras_budi', 'Cabang Makassar (Inventaris)', this)" class="role-demo-btn p-2 rounded-xl border border-rose-200 bg-rose-50/70 hover:bg-rose-100 text-rose-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-rose-600">6. Fasilitas</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>🛠️</span>
                            <span class="truncate">Sarpras</span>
                        </div>
                    </button>

                    <!-- 7. SISWA -->
                    <button type="button" onclick="fillAndHighlight('siswa_rizky', 'Cabang Makassar (Pelajar)', this)" class="role-demo-btn p-2 rounded-xl border border-emerald-200 bg-emerald-50/70 hover:bg-emerald-100 text-emerald-900 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-emerald-600">7. Pelajar</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>🎒</span>
                            <span class="truncate">Siswa Rizky</span>
                        </div>
                    </button>

                    <!-- 8. MITRA PENDING REVIEW -->
                    <button type="button" onclick="fillAndHighlight('mitra_sby', 'Surabaya (Simulasi Pending)', this)" class="role-demo-btn p-2 rounded-xl border border-slate-300 bg-slate-100/70 hover:bg-slate-200 text-slate-700 font-bold text-left transition-all hover:scale-[1.02]">
                        <span class="block text-[9px] font-semibold uppercase tracking-wider text-slate-500">Demo Pending</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span>⏳</span>
                            <span class="truncate">Mitra Review</span>
                        </div>
                    </button>
                </div>

                <!-- Indikator Role Terpilih -->
                <div id="demoSelectedIndicator" class="mt-2.5 px-3 py-1.5 rounded-lg bg-teal-50 border border-teal-200 text-teal-800 text-[11px] font-medium hidden flex items-center justify-between">
                    <span id="demoSelectedText">Kredensial siap digunakan. Klik <strong>Masuk ke Dashboard</strong> di atas.</span>
                    <button type="button" onclick="document.getElementById('loginForm').submit()" class="text-teal-900 font-bold underline hover:no-underline ml-2">
                        Masuk Sekarang &rarr;
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer Bawah -->
        <div class="mt-6 text-center text-xs text-slate-400">
            &copy; <?= date('Y') ?> SIM Bisolpin Academy. All rights reserved.
        </div>
    </div>

</div>

<!-- SweetAlert2 & Script -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?= render_flash() ?>

<script>
function fillAndHighlight(username, label, btnElement) {
    const inputIdentifier = document.getElementById('identifier');
    const inputPassword = document.getElementById('password');
    const indicator = document.getElementById('demoSelectedIndicator');
    const indicatorText = document.getElementById('demoSelectedText');

    inputIdentifier.value = username;
    inputPassword.value = 'password123';

    // Efek highlight pada input
    inputIdentifier.classList.add('ring-2', 'ring-brand-500', 'bg-teal-50/50');
    inputPassword.classList.add('ring-2', 'ring-brand-500', 'bg-teal-50/50');

    setTimeout(() => {
        inputIdentifier.classList.remove('ring-2', 'ring-brand-500', 'bg-teal-50/50');
        inputPassword.classList.remove('ring-2', 'ring-brand-500', 'bg-teal-50/50');
    }, 1200);

    // Tampilkan notifikasi indikator
    if (indicator && indicatorText) {
        indicatorText.innerHTML = `Akun <strong>${username}</strong> (${label}) terisi! Silakan klik tombol masuk.`;
        indicator.classList.remove('hidden');
    }
}

function togglePasswordVisibility() {
    const pwd = document.getElementById('password');
    const eye = document.getElementById('toggle-eye');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        eye.classList.remove('fa-eye');
        eye.classList.add('fa-eye-slash');
    } else {
        pwd.type = 'password';
        eye.classList.remove('fa-eye-slash');
        eye.classList.add('fa-eye');
    }
}
</script>

</body>
</html>
