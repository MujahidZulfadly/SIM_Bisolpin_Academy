<?php
/**
 * SIM BISOLPIN ACADEMY - User Logout Handler
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

logout_user();

set_flash('success', 'Anda telah berhasil keluar dari akun. Sampai jumpa kembali!', 'Sesi Selesai');
redirect('modules/auth/login.php');

