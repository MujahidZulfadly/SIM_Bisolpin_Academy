<?php
/**
 * SIM BISOLPIN ACADEMY - Global Topbar Header & Branch Tenant Selector
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/helpers.php';
require_once dirname(__DIR__) . '/core/attendance_ceklok.php';

$user = auth_user() ?? [];
$role = auth_role() ?? '';
$activeBranchId = get_active_branch_id();
$activeBranchName = get_active_branch_name();
$isNationalRole = in_array($role, [ROLE_PIMPINAN, ROLE_BAK], true);
$allBranches = $isNationalRole ? get_all_branches(false) : [];

// Cek status Ceklok untuk personil pegawai
$topbarPegawai = is_eligible_for_ceklok($role) ? get_logged_in_pegawai() : null;
$topbarAttendance = ($topbarPegawai && !empty($topbarPegawai['id'])) ? get_today_pegawai_attendance((int)$topbarPegawai['id']) : null;
?>
<div class="main-content-wrapper flex-1 flex flex-col min-w-0">
    <!-- Topbar Container -->
    <header class="topbar-container flex items-center justify-between border-b border-slate-200/80 bg-white/95 px-6 py-3 sticky top-0 z-20 backdrop-blur-md">
        <!-- Left: Mobile Toggle & Page Context -->
        <div class="flex items-center gap-4">
            <button id="btn-toggle-sidebar" class="md:hidden w-10 h-10 rounded-xl border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 transition-colors">
                <i class="fa-solid fa-bars text-lg"></i>
            </button>
            <div>
                <h1 class="text-lg font-bold text-slate-800 leading-tight">
                    <?= e($pageTitle ?? 'Dashboard') ?>
                </h1>
                <p class="text-xs text-slate-400 hidden sm:block">
                    <?= format_hari_indonesia(date('Y-m-d')) ?>, <?= format_tanggal_indonesia(date('Y-m-d')) ?>
                </p>
            </div>
        </div>

        <!-- Right: Multi-Branch Selector & User Profile -->
        <div class="flex items-center gap-3">
            <?php if ($isNationalRole): ?>
                <!-- Pimpinan & BAK: Form Switcher Cabang Nasional -->
                <form method="GET" action="" class="flex items-center">
                    <?php
                    // Pertahankan query parameter lain jika ada
                    foreach ($_GET as $key => $val) {
                        if ($key !== 'switch_branch') {
                            echo '<input type="hidden" name="' . e($key) . '" value="' . e((string)$val) . '">';
                        }
                    }
                    ?>
                    <div class="relative flex items-center">
                        <i class="fa-solid fa-location-dot absolute left-3 text-brand-600 text-xs"></i>
                        <select name="switch_branch" onchange="this.form.submit()" class="pl-8 pr-8 py-2 text-xs font-semibold rounded-xl border border-brand-200 bg-brand-50/50 text-brand-900 focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer shadow-sm">
                            <option value="all" <?= $activeBranchId === null ? 'selected' : '' ?>>🌐 Seluruh Cabang (Nasional)</option>
                            <?php foreach ($allBranches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $activeBranchId === (int)$b['id'] ? 'selected' : '' ?>>
                                    📍 <?= e($b['nama_cabang']) ?> (<?= e($b['kota']) ?>) <?= $b['status_mitra'] !== 'aktif' ? '[Pending]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            <?php else: ?>
                <!-- Role Cabang: Badge Cabang Tetap (Strict Isolation) -->
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 border border-slate-200 text-xs font-semibold text-slate-700 shadow-sm">
                    <i class="fa-solid fa-location-dot text-brand-600"></i>
                    <span><?= e($activeBranchName) ?></span>
                </div>
            <?php endif; ?>

            <?php if (is_eligible_for_ceklok($role)): ?>
                <!-- Badge Status Ceklok Pegawai Hari Ini -->
                <?php if (!$topbarAttendance): ?>
                    <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-bold border border-amber-200 transition-all shadow-sm" title="Klik untuk melakukan Presensi Ceklok Masuk">
                        <i class="fa-solid fa-fingerprint text-amber-500"></i>
                        <span>Belum Ceklok</span>
                    </a>
                <?php elseif ($topbarAttendance['status_kehadiran'] === 'hadir' && empty($topbarAttendance['jam_keluar'])): ?>
                    <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-bold border border-teal-200 transition-all shadow-sm" title="Anda aktif Clock-In hari ini">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                        <span>Clock-In: <?= substr($topbarAttendance['jam_masuk'], 0, 5) ?></span>
                    </a>
                <?php elseif ($topbarAttendance['status_kehadiran'] === 'hadir' && !empty($topbarAttendance['jam_keluar'])): ?>
                    <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-bold border border-emerald-200 transition-all shadow-sm" title="Presensi selesai hari ini">
                        <i class="fa-solid fa-circle-check text-emerald-600"></i>
                        <span>Ceklok Selesai</span>
                    </a>
                <?php else: ?>
                    <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="hidden md:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold border border-slate-200 transition-all shadow-sm">
                        <span>Status: <?= strtoupper($topbarAttendance['status_kehadiran']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- User Menu Pill -->
            <div class="flex items-center gap-3 pl-2 border-l border-slate-200">
                <div class="w-9 h-9 rounded-xl bg-gradient-brand text-white flex items-center justify-center font-bold text-sm shadow-sm">
                    <?= strtoupper(substr($user['nama_lengkap'] ?? $user['username'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="hidden lg:block text-right">
                    <div class="text-xs font-bold text-slate-800"><?= e($user['nama_lengkap'] ?? $user['username'] ?? '-') ?></div>
                    <div class="text-[10px] text-slate-400 font-medium"><?= e($user['email'] ?? '-') ?></div>
                </div>
                <a href="<?= base_url('modules/auth/logout.php') ?>" title="Keluar dari akun" class="w-9 h-9 rounded-xl border border-slate-200 hover:border-rose-300 hover:bg-rose-50 text-slate-500 hover:text-rose-600 flex items-center justify-center transition-all ml-1">
                    <i class="fa-solid fa-power-off text-sm"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content Body -->
    <main class="content-body flex-1">

