<?php
/**
 * SIM BISOLPIN ACADEMY - Redirect Layanan Kursus Pusat ke Modul BAK
 * Fitur pengelolaan layanan dan kurikulum resmi kini dikelola secara terpusat oleh Tim Akademik (BAK).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/auth.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__, 2) . '/core/flash.php';

// Jika pengguna adalah BAK, langsung arahkan ke modul layanan BAK
if (is_logged_in() && auth_role() === ROLE_BAK) {
    redirect('modules/bak/layanan.php');
}

// Jika pengguna adalah Pimpinan atau role lain, alihkan ke dashboard dengan informasi alur kerja
redirect_with_message(
    'modules/pimpinan/index.php',
    'info',
    'Menu Layanan Kursus Pusat telah dialihkan secara terpusat ke Bagian Administrasi Kurikulum (BAK).',
    'Informasi Alur Kerja'
);
