<?php
/**
 * SIM BISOLPIN ACADEMY - Authentication, RBAC & Multi-Branch Tenant Isolation Engine
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/flash.php';

/**
 * Mendapatkan data pengguna yang sedang login saat ini
 */
function auth_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Mendapatkan ID user yang sedang login
 */
function auth_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}

/**
 * Mendapatkan role pengguna aktif
 */
function auth_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

/**
 * Mendapatkan id_cabang terdaftar dari pengguna
 */
function auth_branch_id(): ?int
{
    return isset($_SESSION['user']['id_cabang']) && $_SESSION['user']['id_cabang'] !== null
        ? (int)$_SESSION['user']['id_cabang']
        : null;
}

/**
 * Cek apakah pengguna sudah dalam kondisi login
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Melakukan proses login dan inisialisasi session user secara lengkap
 */
function login_user(array $userData): void
{
    $db = get_db();

    // Query detail tambahan dari tabel_pegawai atau tabel_siswa & tabel_cabang
    $fullName = $userData['username'];
    $branchName = 'Kantor Pusat / Nasional';
    $employeeOrStudentCode = '-';
    $branchCode = '-';

    if (!empty($userData['id_cabang'])) {
        $stmtC = $db->prepare("SELECT kode_cabang, nama_cabang FROM tabel_cabang WHERE id = ?");
        $stmtC->execute([$userData['id_cabang']]);
        $cabang = $stmtC->fetch();
        if ($cabang) {
            $branchName = $cabang['nama_cabang'];
            $branchCode = $cabang['kode_cabang'];
        }
    }

    if ($userData['role'] === ROLE_SISWA) {
        $stmtS = $db->prepare("SELECT nis_bisolpin, nama_lengkap FROM tabel_siswa WHERE user_id = ?");
        $stmtS->execute([$userData['id']]);
        $siswa = $stmtS->fetch();
        if ($siswa) {
            $fullName = $siswa['nama_lengkap'];
            $employeeOrStudentCode = $siswa['nis_bisolpin'];
        }
    } else {
        $stmtP = $db->prepare("SELECT nip_pegawai, nama_lengkap FROM tabel_pegawai WHERE user_id = ?");
        $stmtP->execute([$userData['id']]);
        $pegawai = $stmtP->fetch();
        if ($pegawai) {
            $fullName = $pegawai['nama_lengkap'];
            $employeeOrStudentCode = $pegawai['nip_pegawai'];
        }
    }

    // Perbarui waktu login terakhir di database
    try {
        $stmtUpdate = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmtUpdate->execute([$userData['id']]);
    } catch (\Throwable $e) {
        error_log("Gagal update last_login: " . $e->getMessage());
    }

    // Regenerate ID sesi untuk mencegah Session Fixation
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'             => (int)$userData['id'],
        'username'       => $userData['username'],
        'email'          => $userData['email'],
        'role'           => $userData['role'],
        'id_cabang'      => !empty($userData['id_cabang']) ? (int)$userData['id_cabang'] : null,
        'nama_lengkap'   => $fullName,
        'nama_cabang'    => $branchName,
        'kode_cabang'    => $branchCode,
        'nomor_induk'    => $employeeOrStudentCode,
        'is_active'      => (int)$userData['is_active'],
        'logged_in_at'   => time(),
    ];

    // Reset filter cabang sesi jika Pimpinan/BAK
    unset($_SESSION['filter_branch_id']);
}

/**
 * Logout pengguna dan bersihkan seluruh sesi
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Middleware RBAC: Cek hak akses role pengguna.
 * Jika tidak berhak, redirect ke halaman login atau dashboard sesuai haknya.
 *
 * @param array|string $allowedRoles Satu role atau array role yang diperbolehkan
 */
function check_role(array|string $allowedRoles): void
{
    if (!is_logged_in()) {
        redirect_with_message('modules/auth/login.php', 'warning', 'Sesi Anda telah berakhir. Silakan masuk terlebih dahulu.', 'Akses Ditolak');
    }

    $roles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    $currentRole = auth_role();

    if (!in_array($currentRole, $roles, true)) {
        // Alihkan ke rute modul masing-masing dengan pesan penolakan
        $redirectMap = [
            ROLE_PIMPINAN        => 'modules/pimpinan/index.php',
            ROLE_DIREKTUR_CABANG => 'modules/direktur_cabang/index.php',
            ROLE_BAK             => 'modules/bak/index.php',
            ROLE_MENTOR          => 'modules/mentor/index.php',
            ROLE_STAFF           => 'modules/staff/index.php',
            ROLE_SARPRAS         => 'modules/sarpras/index.php',
            ROLE_SISWA           => 'modules/siswa/index.php',
        ];

        $target = $redirectMap[$currentRole] ?? 'modules/auth/login.php';
        redirect_with_message($target, 'error', 'Anda tidak memiliki hak akses untuk membuka halaman tersebut.', '403 Forbidden');
    }
}

/**
 * ISOLASI TENANT MULTI-CABANG (CRITICAL BUSINESS RULE)
 *
 * - Jika Pimpinan / BAK: Dapat melihat semua cabang (return null) ATAU memfilter ke cabang spesifik.
 * - Jika Direktur Cabang, Staff, Mentor, Sarpras, Siswa: KETAT terkunci pada id_cabang akunnya sendiri.
 *
 * @return int|null null artinya scope "Semua Cabang / Nasional", integer artinya ID cabang aktif
 */
function get_active_branch_id(): ?int
{
    $role = auth_role();
    $userBranchId = auth_branch_id();

    // Role yang terisolasi mutlak ke cabangnya sendiri
    if (in_array($role, [ROLE_DIREKTUR_CABANG, ROLE_STAFF, ROLE_MENTOR, ROLE_SARPRAS, ROLE_SISWA], true)) {
        return $userBranchId;
    }

    // Role Pimpinan / BAK (Pusat): Cek apakah sedang memilih filter cabang tertentu
    if (isset($_GET['switch_branch'])) {
        $switched = trim($_GET['switch_branch']);
        if ($switched === 'all' || $switched === '') {
            unset($_SESSION['filter_branch_id']);
        } else {
            $_SESSION['filter_branch_id'] = (int)$switched;
        }
    }

    return isset($_SESSION['filter_branch_id']) ? (int)$_SESSION['filter_branch_id'] : null;
}

/**
 * Mendapatkan nama cabang aktif yang sedang dilihat
 */
function get_active_branch_name(): string
{
    $activeBranchId = get_active_branch_id();
    if ($activeBranchId === null) {
        return 'Seluruh Cabang (Nasional)';
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT nama_cabang FROM tabel_cabang WHERE id = ?");
    $stmt->execute([$activeBranchId]);
    $res = $stmt->fetch();
    return $res['nama_cabang'] ?? 'Cabang Terpilih';
}

/**
 * Ambil daftar semua cabang aktif untuk dropdown selector
 */
function get_all_branches(bool $onlyActive = true): array
{
    $db = get_db();
    $sql = "SELECT id, kode_cabang, nama_cabang, kota, provinsi, status_mitra FROM tabel_cabang";
    if ($onlyActive) {
        $sql .= " WHERE status_mitra = 'aktif'";
    }
    $sql .= " ORDER BY nama_cabang ASC";
    return $db->query($sql)->fetchAll();
}

/**
 * Keamanan Multi-Tenant: Verifikasi Hak Akses Data Milik Cabang Tertentu.
 *
 * Mencegah manipulasi URL parameter (IDOR) oleh pengguna cabang:
 * - Role Pimpinan & BAK (Pusat): Diizinkan mengakses/memonitor data seluruh cabang.
 * - Role Cabang (Direktur Cabang, Staff, Mentor, Sarpras, Siswa):
 *   DITOLAK dan dialihkan dengan kode HTTP 403 serta notifikasi flash error
 *   jika mencoba mengakses atau mengedit data milik id_cabang lain.
 *
 * @param int|null $recordBranchId ID cabang dari data yang ingin diakses
 */
function verify_branch_access(?int $recordBranchId): void
{
    if (!is_logged_in()) {
        redirect_with_message('modules/auth/login.php', 'warning', 'Sesi Anda telah berakhir. Silakan masuk terlebih dahulu.', 'Akses Ditolak');
    }

    $role = auth_role();

    // Role Pusat (Pimpinan & BAK) memiliki wewenang lintas cabang
    if (in_array($role, [ROLE_PIMPINAN, ROLE_BAK], true)) {
        return;
    }

    $userBranchId = auth_branch_id();

    // Jika data terikat ke cabang tertentu dan tidak cocok dengan cabang pengguna aktif
    if ($recordBranchId !== null && (int)$recordBranchId !== (int)$userBranchId) {
        http_response_code(403);

        $redirectMap = [
            ROLE_DIREKTUR_CABANG => 'modules/direktur_cabang/index.php',
            ROLE_MENTOR          => 'modules/mentor/index.php',
            ROLE_STAFF           => 'modules/staff/index.php',
            ROLE_SARPRAS         => 'modules/sarpras/index.php',
            ROLE_SISWA           => 'modules/siswa/index.php',
        ];

        $target = $redirectMap[$role] ?? 'modules/auth/login.php';
        redirect_with_message(
            $target,
            'error',
            'Akses Ditolak (403 Forbidden): Anda tidak memiliki wewenang untuk melihat atau memanipulasi data milik cabang lain.',
            'Pelanggaran Hak Akses'
        );
    }
}


