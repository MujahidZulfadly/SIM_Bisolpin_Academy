<?php
/**
 * SIM BISOLPIN ACADEMY - Session Flash Message System (SweetAlert2 Integrated)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

/**
 * Menyimpan pesan flash sementara ke dalam sesi
 *
 * @param string $type success | error | warning | info
 * @param string $message Pesan detail
 * @param string $title Judul alert (opsional)
 */
function set_flash(string $type, string $message, string $title = ''): void
{
    $_SESSION['flash_message'] = [
        'type'    => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
        'message' => $message,
        'title'   => $title,
    ];
}

/**
 * Mengecek apakah terdapat pesan flash di sesi
 */
function has_flash(): bool
{
    return !empty($_SESSION['flash_message']);
}

/**
 * Mengambil dan langsung menghapus pesan flash dari sesi (one-time read)
 */
function get_flash(): ?array
{
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $flash;
    }
    return null;
}

/**
 * Render script SweetAlert2 secara otomatis jika ada pesan flash
 */
function render_flash(): string
{
    $flash = get_flash();
    if (!$flash) {
        return '';
    }

    $type    = in_array($flash['type'] ?? '', ['success', 'error', 'warning', 'info'], true) ? $flash['type'] : 'info';
    $title   = !empty($flash['title']) ? strip_tags((string)$flash['title']) : ($type === 'success' ? 'Berhasil!' : ($type === 'error' ? 'Perhatian!' : 'Informasi'));
    
    // Izinkan tag format teks aman (strong, b, em, i, br, code, p, span) dan buang tag berbahaya
    $allowedTags = '<strong><b><em><i><br><small><code><p><span>';
    $message = strip_tags((string)($flash['message'] ?? ''), $allowedTags);

    $swalConfig = json_encode([
        'icon'               => $type,
        'title'              => $title,
        'html'               => $message,
        'confirmButtonColor' => '#1BA89C',
        'confirmButtonText'  => 'Tutup',
        'customClass'        => [
            'popup' => 'rounded-2xl shadow-xl'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({$swalConfig});
    }
});
</script>
HTML;
}

