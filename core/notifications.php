<?php
/**
 * SIM BISOLPIN ACADEMY - Notification Management Engine
 * Layanan Notifikasi Terpusat, Real-time Triggers & Dropdown Topbar Notification Bell
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/helpers.php';

/**
 * Buat notifikasi baru ke basis data
 */
function push_notification(
    string $judul,
    string $pesan,
    ?string $link = null,
    ?int $userId = null,
    ?string $targetRole = null,
    ?int $idCabang = null,
    string $tipe = 'info'
): bool {
    try {
        $db = get_db();
        ensure_database_schema_integrity($db);

        $validTypes = ['info', 'warning', 'success', 'danger'];
        if (!in_array($tipe, $validTypes, true)) {
            $tipe = 'info';
        }

        $stmt = $db->prepare("
            INSERT INTO tabel_notifikasi (user_id, target_role, id_cabang, judul, pesan, link, tipe, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$userId, $targetRole, $idCabang, $judul, $pesan, $link, $tipe]);
    } catch (\Throwable $e) {
        error_log("Gagal push_notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Format waktu relatif dalam Bahasa Indonesia (misal: "Baru saja", "15 mnt lalu", "2 jam lalu")
 */
function format_waktu_relatif(string $datetime): string
{
    $time = strtotime($datetime);
    if (!$time) return $datetime;

    $diff = time() - $time;
    if ($diff < 60) {
        return 'Baru saja';
    } elseif ($diff < 3600) {
        $m = max(1, (int)floor($diff / 60));
        return "{$m} mnt lalu";
    } elseif ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        return "{$h} jam lalu";
    } elseif ($diff < 604800) {
        $d = (int)floor($diff / 86400);
        return "{$d} hari lalu";
    } else {
        return date('d M Y', $time);
    }
}

/**
 * Mengambil daftar notifikasi untuk pengguna yang sedang aktif.
 * Menggabungkan notifikasi dari database dan Smart Trigger real-time dari database.
 */
function get_user_notifications(
    ?int $userId = null,
    ?string $role = null,
    ?int $branchId = null,
    int $limit = 10
): array {
    $db = get_db();
    ensure_database_schema_integrity($db);

    if ($userId === null || $role === null) {
        $u = auth_user();
        if ($u) {
            $userId   = $userId ?? (int)($u['id'] ?? 0);
            $role     = $role ?? ($u['role'] ?? '');
            $branchId = $branchId ?? (!empty($u['id_cabang']) ? (int)$u['id_cabang'] : null);
        }
    }

    $notifications = [];

    // =========================================================================
    // 1. SMART DYNAMIC TRIGGERS (Live Alerts dari Status Terkini Sistem)
    // =========================================================================
    try {
        // Trigger A: Untuk Pimpinan -> Permohonan mitra cabang baru yang pending review
        if ($role === ROLE_PIMPINAN) {
            $stmtPending = $db->query("
                SELECT id, nama_cabang, kota, created_at 
                FROM tabel_cabang 
                WHERE status_mitra = 'pending_review' 
                ORDER BY created_at DESC 
                LIMIT 3
            ");
            foreach ($stmtPending->fetchAll() as $p) {
                $notifications[] = [
                    'id'         => 'live_mitra_' . $p['id'],
                    'judul'      => 'Permohonan Mitra Baru',
                    'pesan'      => "Cabang {$p['nama_cabang']} ({$p['kota']}) menunggu review dan verifikasi pimpinan.",
                    'link'       => base_url('modules/pimpinan/verifikasi_mitra.php'),
                    'tipe'       => 'warning',
                    'is_read'    => 0,
                    'created_at' => $p['created_at'],
                    'is_live'    => true
                ];
            }
        }

        // Trigger B: Untuk Admin Sistem -> Mitra yang disetujui pimpinan & siap aktivasi IT
        if ($role === ROLE_ADMIN_SISTEM) {
            $stmtAktivasi = $db->query("
                SELECT id, nama_cabang, kota, tanggal_review_pimpinan, created_at 
                FROM tabel_cabang 
                WHERE status_mitra = 'menunggu_aktivasi_admin' 
                ORDER BY tanggal_review_pimpinan DESC 
                LIMIT 3
            ");
            foreach ($stmtAktivasi->fetchAll() as $a) {
                $notifications[] = [
                    'id'         => 'live_admin_mitra_' . $a['id'],
                    'judul'      => 'Aktivasi Mitra Siap Diproses',
                    'pesan'      => "Cabang {$a['nama_cabang']} telah disetujui Pimpinan. Atur kode cabang dan buat akun resmi.",
                    'link'       => base_url('modules/admin_sistem/verifikasi_mitra.php'),
                    'tipe'       => 'info',
                    'is_read'    => 0,
                    'created_at' => $a['tanggal_review_pimpinan'] ?? $a['created_at'],
                    'is_live'    => true
                ];
            }
        }

        // Trigger C: Untuk Mentor -> Produk / Tugas Siswa yang menunggu review
        if ($role === ROLE_MENTOR) {
            $sqlMentor = "
                SELECT pr.id, pr.judul_karya, s.nama_lengkap as nama_siswa, pr.created_at
                FROM tabel_produk pr
                JOIN tabel_siswa s ON s.id = pr.id_siswa
                WHERE (pr.nilai_karya IS NULL OR pr.status_tampil = 'draf')
            ";
            $paramsMentor = [];
            if ($branchId) {
                $sqlMentor .= " AND pr.id_cabang = ?";
                $paramsMentor[] = $branchId;
            }
            $sqlMentor .= " ORDER BY pr.created_at DESC LIMIT 3";
            $stmtMentor = $db->prepare($sqlMentor);
            $stmtMentor->execute($paramsMentor);

            foreach ($stmtMentor->fetchAll() as $pr) {
                $notifications[] = [
                    'id'         => 'live_produk_' . $pr['id'],
                    'judul'      => 'Tugas Siswa Butuh Penilaian',
                    'pesan'      => "Siswa {$pr['nama_siswa']} mengumpulkan karya \"{$pr['judul_karya']}\".",
                    'link'       => base_url('modules/mentor/validasi_produk.php'),
                    'tipe'       => 'info',
                    'is_read'    => 0,
                    'created_at' => $pr['created_at'],
                    'is_live'    => true
                ];
            }
        }

        // Trigger D: Untuk Siswa -> Produk yang sudah dinilai mentor
        if ($role === ROLE_SISWA && $userId) {
            $stmtSiswa = $db->prepare("
                SELECT pr.id, pr.judul_karya, pr.nilai_karya, pr.updated_at
                FROM tabel_produk pr
                JOIN tabel_siswa s ON s.id = pr.id_siswa
                WHERE s.user_id = ? AND pr.nilai_karya IS NOT NULL
                ORDER BY pr.updated_at DESC
                LIMIT 2
            ");
            $stmtSiswa->execute([$userId]);
            foreach ($stmtSiswa->fetchAll() as $ps) {
                $notifications[] = [
                    'id'         => 'live_nilai_' . $ps['id'],
                    'judul'      => 'Karya Anda Telah Dinilai! 🎉',
                    'pesan'      => "Karya \"{$ps['judul_karya']}\" mendapatkan nilai {$ps['nilai_karya']} dari mentor.",
                    'link'       => base_url('modules/siswa/produk_saya.php'),
                    'tipe'       => 'success',
                    'is_read'    => 0,
                    'created_at' => $ps['updated_at'],
                    'is_live'    => true
                ];
            }
        }

        // Trigger E: Untuk Staff / Direktur Cabang -> Siswa menunggak cicilan/belum bayar
        if (in_array($role, [ROLE_STAFF, ROLE_DIREKTUR_CABANG], true) && $branchId) {
            $stmtStaffPay = $db->prepare("
                SELECT COUNT(*) FROM tabel_siswa 
                WHERE id_cabang = ? AND status_pembayaran IN ('belum_bayar', 'cicilan') AND is_archived = 0
            ");
            $stmtStaffPay->execute([$branchId]);
            $countUnpaid = (int)$stmtStaffPay->fetchColumn();
            if ($countUnpaid > 0) {
                $notifications[] = [
                    'id'         => 'live_tagihan_spp',
                    'judul'      => 'Pemberitahuan Tagihan SPP',
                    'pesan'      => "Terdapat {$countUnpaid} siswa dengan status pembayaran belum lunas di cabang Anda.",
                    'link'       => base_url('modules/staff/pembayaran.php'),
                    'tipe'       => 'warning',
                    'is_read'    => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'is_live'    => true
                ];
            }
        }
    } catch (\Throwable $e) {
        error_log("Error generating live notifications: " . $e->getMessage());
    }

    // =========================================================================
    // 2. DATABASE PERSISTED NOTIFICATIONS (tabel_notifikasi)
    // =========================================================================
    try {
        $sql = "
            SELECT * FROM tabel_notifikasi
            WHERE (
                (user_id IS NOT NULL AND user_id = ?)
                OR
                (user_id IS NULL AND (target_role IS NULL OR target_role = 'semua' OR target_role = ?) AND (id_cabang IS NULL OR id_cabang = ?))
            )
            ORDER BY is_read ASC, created_at DESC
            LIMIT ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $role, PDO::PARAM_STR);
        $stmt->bindValue(3, $branchId, PDO::PARAM_INT);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $dbNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dbNotifs as $row) {
            $row['is_live'] = false;
            $notifications[] = $row;
        }
    } catch (\Throwable $e) {
        error_log("Error fetching DB notifications: " . $e->getMessage());
    }

    // Urutkan berdasarkan waktu terbaru
    usort($notifications, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    return array_slice($notifications, 0, $limit);
}

/**
 * Hitung jumlah notifikasi yang belum dibaca (Unread Count)
 */
function get_unread_notification_count(?int $userId = null, ?string $role = null, ?int $branchId = null): int
{
    $notifs = get_user_notifications($userId, $role, $branchId, 25);
    $unread = 0;
    foreach ($notifs as $n) {
        if ((int)$n['is_read'] === 0) {
            $unread++;
        }
    }
    return $unread;
}

/**
 * Tandai satu notifikasi sebagai sudah dibaca
 */
function mark_notification_as_read(int $notifId, ?int $userId = null): bool
{
    try {
        $db = get_db();
        $stmt = $db->prepare("UPDATE tabel_notifikasi SET is_read = 1 WHERE id = ?");
        return $stmt->execute([$notifId]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Tandai semua notifikasi pengguna sebagai sudah dibaca
 */
function mark_all_notifications_as_read(?int $userId = null, ?string $role = null, ?int $branchId = null): bool
{
    try {
        $db = get_db();
        if ($userId === null || $role === null) {
            $u = auth_user();
            if ($u) {
                $userId   = $userId ?? (int)($u['id'] ?? 0);
                $role     = $role ?? ($u['role'] ?? '');
                $branchId = $branchId ?? (!empty($u['id_cabang']) ? (int)$u['id_cabang'] : null);
            }
        }

        $stmt = $db->prepare("
            UPDATE tabel_notifikasi 
            SET is_read = 1 
            WHERE (
                (user_id IS NOT NULL AND user_id = ?)
                OR
                (user_id IS NULL AND (target_role IS NULL OR target_role = 'semua' OR target_role = ?) AND (id_cabang IS NULL OR id_cabang = ?))
            )
        ");
        return $stmt->execute([$userId, $role, $branchId]);
    } catch (\Throwable $e) {
        return false;
    }
}

