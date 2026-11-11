<?php
/**
 * SIM BISOLPIN ACADEMY - CSRF Protection Helper
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';

/**
 * Generate atau ambil CSRF token yang aktif dalam session
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render elemen input hidden HTML untuk form POST
 */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verifikasi kecocokan CSRF token dari request POST
 */
function verify_csrf_token(?string $token = null): bool
{
    $submittedToken = $token ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if (empty($submittedToken) || empty($sessionToken)) {
        return false;
    }

    return hash_equals($sessionToken, $submittedToken);
}

/**
 * Validasi CSRF secara ketat, hentikan request jika token tidak valid
 */
function validate_csrf_or_abort(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(419);
            die("<div style='font-family:sans-serif;padding:30px;max-width:550px;margin:60px auto;border:1px solid #fed7aa;border-radius:12px;background:#fffbeb;color:#9a3412;'>"
                . "<h2 style='margin-top:0;'>⚠️ 419 - Sesi Kedaluwarsa (CSRF Mismatch)</h2>"
                . "<p>Token keamanan formulir Anda telah kedaluwarsa atau tidak valid. Silakan kembali, muat ulang halaman, dan coba kirimkan kembali.</p>"
                . "<p><a href='javascript:history.back()' style='display:inline-block;padding:10px 18px;background:#1BA89C;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>← Kembali & Refresh</a></p>"
                . "</div>");
        }
    }
}

