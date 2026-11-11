<?php
/**
 * SIM BISOLPIN ACADEMY - Application Configuration
 * Framework: Native Modular PHP 8.2+
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');

    // Fallback save path jika session path bawaan server tidak memiliki izin tulis
    $defaultSavePath = session_save_path();
    if (empty($defaultSavePath) || !@is_writable($defaultSavePath)) {
        $tempPath = sys_get_temp_dir();
        if (@is_writable($tempPath)) {
            @session_save_path($tempPath);
        } else {
            $localSessionDir = __DIR__ . '/../assets/sessions';
            if (!is_dir($localSessionDir)) {
                @mkdir($localSessionDir, 0777, true);
            }
            if (@is_writable($localSessionDir)) {
                @session_save_path($localSessionDir);
            }
        }
    }

    @session_start();
}

// Zona Waktu Default Operasional Bisolpin (WITA / Makassar)
date_default_timezone_set('Asia/Makassar');

// Konstanta Identitas Aplikasi
define('APP_NAME', 'SIM BISOLPIN ACADEMY');
define('APP_TAGLINE', 'Sistem Informasi Manajemen Kursus & Kemitraan Multi-Cabang');
define('APP_VERSION', '2.0.0');
define('BRAND_COLOR_PRIMARY', '#1BA89C');
define('BRAND_COLOR_DARK', '#0d7a71');

// Deteksi Otomatis Base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/(modules/.*|includes/.*|core/.*)$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');

define('BASE_URL', 'http://localhost:8000');
define('ROOT_PATH', dirname(__DIR__));

// Path Direktori Unggahan
define('DIR_UPLOAD_MODUL', ROOT_PATH . '/assets/uploads/modul_bak/');
define('DIR_UPLOAD_PRODUK', ROOT_PATH . '/assets/uploads/produk_siswa/');
define('DIR_UPLOAD_MITRA', ROOT_PATH . '/assets/uploads/dokumen_mitra/');

// URL Direktori Unggahan
define('URL_UPLOAD_MODUL', BASE_URL . '/assets/uploads/modul_bak/');
define('URL_UPLOAD_PRODUK', BASE_URL . '/assets/uploads/produk_siswa/');
define('URL_UPLOAD_MITRA', BASE_URL . '/assets/uploads/dokumen_mitra/');

// Role Mapping & Label Ramah Pengguna
define('ROLE_PIMPINAN', 'pimpinan');
define('ROLE_DIREKTUR_CABANG', 'direktur_cabang');
define('ROLE_BAK', 'bak');
define('ROLE_MENTOR', 'mentor');
define('ROLE_STAFF', 'staff');
define('ROLE_SARPRAS', 'sarpras');
define('ROLE_SISWA', 'siswa');

const APP_ROLES = [
    ROLE_PIMPINAN        => ['label' => 'Pimpinan Pusat (Super Admin)', 'badge' => 'bg-purple-100 text-purple-800 border-purple-200', 'color' => '#8B5CF6'],
    ROLE_DIREKTUR_CABANG => ['label' => 'Direktur Cabang (Mitra)',     'badge' => 'bg-indigo-100 text-indigo-800 border-indigo-200', 'color' => '#6366F1'],
    ROLE_BAK             => ['label' => 'Bagian Akademik (BAK)',       'badge' => 'bg-blue-100 text-blue-800 border-blue-200',     'color' => '#3B82F6'],
    ROLE_MENTOR          => ['label' => 'Mentor / Pengajar',           'badge' => 'bg-teal-100 text-teal-800 border-teal-200',     'color' => '#14B8A6'],
    ROLE_STAFF           => ['label' => 'Staff Administrasi',          'badge' => 'bg-amber-100 text-amber-800 border-amber-200',   'color' => '#F59E0B'],
    ROLE_SARPRAS         => ['label' => 'Sarana & Prasarana',          'badge' => 'bg-rose-100 text-rose-800 border-rose-200',     'color' => '#F43F5E'],
    ROLE_SISWA           => ['label' => 'Siswa / Pelajar',             'badge' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'color' => '#10B981'],
];
