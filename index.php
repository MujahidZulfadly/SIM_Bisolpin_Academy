<?php
/**
 * SIM BISOLPIN ACADEMY - Main Application Entrypoint & Smart Router
 */

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/helpers.php';

// Jika belum login, arahkan langsung ke halaman login
if (!is_logged_in()) {
    redirect('modules/auth/login.php');
}

// Routing otomatis sesuai role yang sedang aktif
$role = auth_role();
switch ($role) {
    case ROLE_PIMPINAN:
        redirect('modules/pimpinan/index.php');
        break;

    case ROLE_DIREKTUR_CABANG:
        redirect('modules/direktur_cabang/index.php');
        break;

    case ROLE_BAK:
        redirect('modules/bak/index.php');
        break;

    case ROLE_MENTOR:
        redirect('modules/mentor/index.php');
        break;

    case ROLE_STAFF:
        redirect('modules/staff/index.php');
        break;

    case ROLE_SARPRAS:
        redirect('modules/sarpras/index.php');
        break;

    case ROLE_SISWA:
        redirect('modules/siswa/index.php');
        break;

    default:
        logout_user();
        redirect_with_message('modules/auth/login.php', 'error', 'Role pengguna tidak valid atau tidak dikenali.');
        break;
}

