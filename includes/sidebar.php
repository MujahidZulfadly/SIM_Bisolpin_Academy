<?php
/**
 * SIM BISOLPIN ACADEMY - Dynamic Multi-Role Sidebar Navigation
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/helpers.php';

$currentRole = auth_role() ?? '';
$user = auth_user() ?? [];
$activeScript = $_SERVER['SCRIPT_NAME'] ?? '';

// Helper untuk menandai menu aktif
$isActive = function(string $keyword) use ($activeScript): string {
    return str_contains($activeScript, $keyword) ? 'active' : '';
};
?>
<!-- Sidebar Overlay untuk Mobile -->
<div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-30 hidden md:hidden"></div>

<!-- Sidebar Container -->
<aside id="sidebar-wrapper" class="sidebar-wrapper fixed md:sticky top-0 left-0 z-40 h-screen transition-transform -translate-x-full md:translate-x-0 shadow-lg md:shadow-none flex flex-col justify-between">
    <!-- Top Branding -->
    <div>
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <a href="<?= base_url('index.php') ?>" class="flex items-center gap-3 text-decoration-none">
                <div class="w-10 h-10 rounded-xl bg-gradient-brand flex items-center justify-center text-white shadow-md shadow-brand-500/20">
                    <i class="fa-solid fa-graduation-cap text-lg"></i>
                </div>
                <div>
                    <span class="font-extrabold text-xl tracking-tight text-slate-800">BISOLPIN</span>
                    <span class="block text-[10px] font-bold tracking-widest uppercase text-brand-600">Academy SIM</span>
                </div>
            </a>
            <button id="btn-close-sidebar" class="md:hidden text-slate-400 hover:text-slate-600 text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Role Badge Header -->
        <div class="px-5 py-3 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between">
            <span class="text-xs font-semibold text-slate-500">Portal Aktif:</span>
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full <?= APP_ROLES[$currentRole]['badge'] ?? 'bg-slate-100 text-slate-700' ?>">
                <?= APP_ROLES[$currentRole]['label'] ?? ucfirst($currentRole) ?>
            </span>
        </div>

        <!-- Navigation Menus based on Role -->
        <nav class="py-4 overflow-y-auto max-h-[calc(100vh-230px)] space-y-1">

            <?php if ($currentRole === ROLE_PIMPINAN): ?>
                <!-- 1. ROLE PIMPINAN (Pusat / Super Admin) -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/pimpinan/index.php') ?>" class="nav-item-link <?= $isActive('/pimpinan/index') ?>">
                    <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                    <span>Executive Dashboard</span>
                </a>
                <a href="<?= base_url('modules/pimpinan/monitoring_cabang.php') ?>" class="nav-item-link <?= $isActive('monitoring_cabang') ?>">
                    <i class="fa-solid fa-building-columns w-5 text-center"></i>
                    <span>Monitoring Cabang</span>
                </a>

                <div class="nav-section-title">KEMITRAAN & LAYANAN</div>
                <a href="<?= base_url('modules/pimpinan/verifikasi_mitra.php') ?>" class="nav-item-link <?= $isActive('verifikasi_mitra') ?>">
                    <i class="fa-solid fa-handshake-angle w-5 text-center"></i>
                    <span>Verifikasi Mitra Cabang</span>
                </a>
                <a href="<?= base_url('modules/pimpinan/layanan_pusat.php') ?>" class="nav-item-link <?= $isActive('layanan_pusat') ?>">
                    <i class="fa-solid fa-layer-group w-5 text-center"></i>
                    <span>Layanan Kursus Pusat</span>
                </a>

                <div class="nav-section-title">AUDIT NASIONAL</div>
                <a href="<?= base_url('modules/pimpinan/siswa_nasional.php') ?>" class="nav-item-link <?= $isActive('siswa_nasional') ?>">
                    <i class="fa-solid fa-user-graduate w-5 text-center"></i>
                    <span>Data Siswa Nasional</span>
                </a>
                <a href="<?= base_url('modules/pimpinan/pegawai_nasional.php') ?>" class="nav-item-link <?= $isActive('pegawai_nasional') ?>">
                    <i class="fa-solid fa-id-card-clip w-5 text-center"></i>
                    <span>Data Pegawai & Mentor</span>
                </a>
                <a href="<?= base_url('modules/pimpinan/aset_nasional.php') ?>" class="nav-item-link <?= $isActive('aset_nasional') ?>">
                    <i class="fa-solid fa-boxes-stacked w-5 text-center"></i>
                    <span>Audit Aset & Sarpras</span>
                </a>

            <?php elseif ($currentRole === ROLE_DIREKTUR_CABANG): ?>
                <!-- 2. ROLE DIREKTUR CABANG (Mitra) -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/direktur_cabang/index.php') ?>" class="nav-item-link <?= $isActive('/direktur_cabang/index') ?>">
                    <i class="fa-solid fa-chart-line w-5 text-center"></i>
                    <span>Dashboard Cabang</span>
                </a>

                <div class="nav-section-title">OPERASIONAL CABANG</div>
                <a href="<?= base_url('modules/direktur_cabang/siswa.php') ?>" class="nav-item-link <?= $isActive('/direktur_cabang/siswa') ?>">
                    <i class="fa-solid fa-user-graduate w-5 text-center"></i>
                    <span>Data Siswa Cabang</span>
                </a>
                <a href="<?= base_url('modules/direktur_cabang/pegawai.php') ?>" class="nav-item-link <?= $isActive('/direktur_cabang/pegawai') ?>">
                    <i class="fa-solid fa-users w-5 text-center"></i>
                    <span>Tim Pegawai & Mentor</span>
                </a>
                <a href="<?= base_url('modules/direktur_cabang/evaluasi_mentor.php') ?>" class="nav-item-link <?= $isActive('evaluasi_mentor') ?>">
                    <i class="fa-solid fa-star w-5 text-center"></i>
                    <span>Evaluasi Mutu Mentor</span>
                </a>
                <a href="<?= base_url('modules/direktur_cabang/aset.php') ?>" class="nav-item-link <?= $isActive('/direktur_cabang/aset') ?>">
                    <i class="fa-solid fa-couch w-5 text-center"></i>
                    <span>Fasilitas & Ruang Kelas</span>
                </a>
                <a href="<?= base_url('modules/direktur_cabang/produk_showcase.php') ?>" class="nav-item-link <?= $isActive('produk_showcase') ?>">
                    <i class="fa-solid fa-gem w-5 text-center"></i>
                    <span>Galeri Karya Siswa</span>
                </a>

                <div class="nav-section-title">REKAP & LAPORAN</div>
                <a href="<?= base_url('modules/direktur_cabang/laporan.php') ?>" class="nav-item-link <?= $isActive('/direktur_cabang/laporan') ?>">
                    <i class="fa-solid fa-print w-5 text-center"></i>
                    <span>Cetak Laporan Cabang</span>
                </a>
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="nav-item-link <?= $isActive('riwayat_saya') ?>">
                    <i class="fa-solid fa-user-clock w-5 text-center"></i>
                    <span>Presensi Ceklok Saya</span>
                </a>

            <?php elseif ($currentRole === ROLE_BAK): ?>
                <!-- 3. ROLE BAGIAN AKADEMIK (BAK) -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/bak/index.php') ?>" class="nav-item-link <?= $isActive('/bak/index') ?>">
                    <i class="fa-solid fa-book-open-reader w-5 text-center"></i>
                    <span>Dashboard Akademik</span>
                </a>

                <div class="nav-section-title">STANDARISASI AKADEMIK</div>
                <a href="<?= base_url('modules/bak/layanan.php') ?>" class="nav-item-link <?= $isActive('/bak/layanan') ?>">
                    <i class="fa-solid fa-layer-group w-5 text-center"></i>
                    <span>Program & Paket Kursus</span>
                </a>
                <a href="<?= base_url('modules/bak/materi.php') ?>" class="nav-item-link <?= $isActive('/bak/materi') ?>">
                    <i class="fa-solid fa-file-pdf w-5 text-center"></i>
                    <span>Kurikulum & Upload Modul</span>
                </a>
                <a href="<?= base_url('modules/bak/standar_produk.php') ?>" class="nav-item-link <?= $isActive('standar_produk') ?>">
                    <i class="fa-solid fa-award w-5 text-center"></i>
                    <span>Standar Capaian Produk</span>
                </a>

                <div class="nav-section-title">KINERJA SAYA</div>
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="nav-item-link <?= $isActive('riwayat_saya') ?>">
                    <i class="fa-solid fa-user-clock w-5 text-center"></i>
                    <span>Presensi Ceklok Saya</span>
                </a>

            <?php elseif ($currentRole === ROLE_MENTOR): ?>
                <!-- 4. ROLE MENTOR / TUTOR -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/mentor/index.php') ?>" class="nav-item-link <?= $isActive('/mentor/index') ?>">
                    <i class="fa-solid fa-chalkboard-user w-5 text-center"></i>
                    <span>Dashboard Mengajar</span>
                </a>

                <div class="nav-section-title">KEGIATAN KELAS OFFLINE</div>
                <a href="<?= base_url('modules/mentor/absensi.php') ?>" class="nav-item-link <?= $isActive('/mentor/absensi') ?>">
                    <i class="fa-solid fa-clipboard-check w-5 text-center"></i>
                    <span>Ruang Mengajar & Absen Siswa</span>
                </a>
                <a href="<?= base_url('modules/mentor/materi_bak.php') ?>" class="nav-item-link <?= $isActive('materi_bak') ?>">
                    <i class="fa-solid fa-folder-open w-5 text-center"></i>
                    <span>Bahan Ajar dari BAK</span>
                </a>
                <a href="<?= base_url('modules/mentor/validasi_produk.php') ?>" class="nav-item-link <?= $isActive('validasi_produk') ?>">
                    <i class="fa-solid fa-check-to-slot w-5 text-center"></i>
                    <span>Validasi Karya Siswa</span>
                </a>
                <a href="<?= base_url('modules/mentor/progres_siswa.php') ?>" class="nav-item-link <?= $isActive('progres_siswa') ?>">
                    <i class="fa-solid fa-sliders w-5 text-center"></i>
                    <span>Evaluasi & Catatan Siswa</span>
                </a>
                <a href="<?= base_url('modules/mentor/jadwal.php') ?>" class="nav-item-link <?= $isActive('/mentor/jadwal') ?>">
                    <i class="fa-solid fa-calendar-days w-5 text-center"></i>
                    <span>Jadwal Kelas Tatap Muka</span>
                </a>

                <div class="nav-section-title">KINERJA SAYA</div>
                <a href="<?= base_url('modules/mentor/evaluasi_saya.php') ?>" class="nav-item-link <?= $isActive('evaluasi_saya') ?>">
                    <i class="fa-solid fa-star-half-stroke w-5 text-center"></i>
                    <span>Rapor Kepuasan Mengajar</span>
                </a>
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="nav-item-link <?= $isActive('riwayat_saya') ?>">
                    <i class="fa-solid fa-user-clock w-5 text-center"></i>
                    <span>Presensi Ceklok Saya</span>
                </a>

            <?php elseif ($currentRole === ROLE_STAFF): ?>
                <!-- 5. ROLE STAFF ADMINISTRASI CABANG -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/staff/index.php') ?>" class="nav-item-link <?= $isActive('/staff/index') ?>">
                    <i class="fa-solid fa-clipboard-list w-5 text-center"></i>
                    <span>Dashboard Staff</span>
                </a>

                <div class="nav-section-title">KESISWAAN & OPERASIONAL</div>
                <a href="<?= base_url('modules/staff/siswa.php') ?>" class="nav-item-link <?= $isActive('/staff/siswa') ?>">
                    <i class="fa-solid fa-user-plus w-5 text-center"></i>
                    <span>Pendaftaran & Data Siswa</span>
                </a>
                <a href="<?= base_url('modules/staff/pegawai.php') ?>" class="nav-item-link <?= $isActive('/staff/pegawai') ?>">
                    <i class="fa-solid fa-address-book w-5 text-center"></i>
                    <span>Data Pegawai Cabang</span>
                </a>
                <a href="<?= base_url('modules/staff/jadwal_plotting.php') ?>" class="nav-item-link <?= $isActive('jadwal_plotting') ?>">
                    <i class="fa-solid fa-clock w-5 text-center"></i>
                    <span>Jadwal Kelas Tatap Muka</span>
                </a>
                <a href="<?= base_url('modules/staff/pembayaran.php') ?>" class="nav-item-link <?= $isActive('pembayaran') ?>">
                    <i class="fa-solid fa-cash-register w-5 text-center"></i>
                    <span>Kasir & SPP Layanan</span>
                </a>

                <div class="nav-section-title">KINERJA SAYA</div>
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="nav-item-link <?= $isActive('riwayat_saya') ?>">
                    <i class="fa-solid fa-user-clock w-5 text-center"></i>
                    <span>Presensi Ceklok Saya</span>
                </a>

            <?php elseif ($currentRole === ROLE_SARPRAS): ?>
                <!-- 6. ROLE SARANA & PRASARANA (SARPRAS) -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/sarpras/index.php') ?>" class="nav-item-link <?= $isActive('/sarpras/index') ?>">
                    <i class="fa-solid fa-toolbox w-5 text-center"></i>
                    <span>Dashboard Sarpras</span>
                </a>

                <div class="nav-section-title">FASILITAS & INVENTARIS</div>
                <a href="<?= base_url('modules/sarpras/inventaris.php') ?>" class="nav-item-link <?= $isActive('inventaris') ?>">
                    <i class="fa-solid fa-boxes-stacked w-5 text-center"></i>
                    <span>Inventaris Ruang & Alat</span>
                </a>
                <a href="<?= base_url('modules/sarpras/maintenance.php') ?>" class="nav-item-link <?= $isActive('maintenance') ?>">
                    <i class="fa-solid fa-screwdriver-wrench w-5 text-center"></i>
                    <span>Laporan Kerusakan / Maintenance</span>
                </a>

                <div class="nav-section-title">KINERJA SAYA</div>
                <a href="<?= base_url('modules/absensi/riwayat_saya.php') ?>" class="nav-item-link <?= $isActive('riwayat_saya') ?>">
                    <i class="fa-solid fa-user-clock w-5 text-center"></i>
                    <span>Presensi Ceklok Saya</span>
                </a>

            <?php elseif ($currentRole === ROLE_SISWA): ?>
                <!-- 7. ROLE SISWA -->
                <div class="nav-section-title">UTAMA</div>
                <a href="<?= base_url('modules/siswa/index.php') ?>" class="nav-item-link <?= $isActive('/siswa/index') ?>">
                    <i class="fa-solid fa-house-user w-5 text-center"></i>
                    <span>Progres & Jadwal Saya</span>
                </a>

                <div class="nav-section-title">PEMBELAJARAN OFFLINE</div>
                <a href="<?= base_url('modules/siswa/materi.php') ?>" class="nav-item-link <?= $isActive('/siswa/materi') ?>">
                    <i class="fa-solid fa-book-bookmark w-5 text-center"></i>
                    <span>Modul Belajar</span>
                </a>
                <a href="<?= base_url('modules/siswa/jadwal.php') ?>" class="nav-item-link <?= $isActive('/siswa/jadwal') ?>">
                    <i class="fa-solid fa-calendar-check w-5 text-center"></i>
                    <span>Jadwal Tatap Muka</span>
                </a>
                <a href="<?= base_url('modules/siswa/evaluasi_mentor.php') ?>" class="nav-item-link <?= $isActive('evaluasi_mentor') ?>">
                    <i class="fa-solid fa-star-half-stroke w-5 text-center"></i>
                    <span>Evaluasi & Rating Mentor</span>
                </a>

                <div class="nav-section-title">PORTOFOLIO SISWA</div>
                <a href="<?= base_url('modules/siswa/produk_saya.php') ?>" class="nav-item-link <?= $isActive('produk_saya') ?>">
                    <i class="fa-solid fa-palette w-5 text-center"></i>
                    <span>Portofolio & Karya Saya</span>
                </a>
                <a href="<?= base_url('modules/siswa/galeri_karya.php') ?>" class="nav-item-link <?= $isActive('galeri_karya') ?>">
                    <i class="fa-solid fa-award w-5 text-center"></i>
                    <span>Galeri Inspirasi Siswa</span>
                </a>
            <?php endif; ?>

        </nav>
    </div>

    <!-- User Profile Footer & Logout -->
    <div class="p-4 border-t border-slate-100 bg-slate-50/50">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-slate-200 border border-slate-300 flex items-center justify-center font-bold text-slate-700">
                <?= strtoupper(substr($user['nama_lengkap'] ?? $user['username'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-bold text-slate-800 truncate"><?= e($user['nama_lengkap'] ?? $user['username'] ?? '-') ?></p>
                <p class="text-xs text-slate-500 truncate"><?= e($user['nama_cabang'] ?? 'Pusat') ?></p>
            </div>
            <a href="<?= base_url('modules/auth/logout.php') ?>" title="Keluar dari Sistem" class="w-8 h-8 rounded-lg flex items-center justify-center text-rose-500 hover:bg-rose-50 transition-colors">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
            </a>
        </div>
    </div>
</aside>

