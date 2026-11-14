<?php
/**
 * SIM BISOLPIN ACADEMY - AJAX Notification Handler
 * Menangani aksi tandai sudah dibaca & refresh count via Fetch/AJAX
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/csrf.php';
require_once dirname(__DIR__) . '/core/helpers.php';
require_once dirname(__DIR__) . '/core/notifications.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = auth_user();
$userId = (int)($user['id'] ?? 0);
$role = $user['role'] ?? '';
$branchId = !empty($user['id_cabang']) ? (int)$user['id_cabang'] : null;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'mark_read') {
    $notifId = (int)($_POST['id'] ?? 0);
    if ($notifId > 0) {
        mark_notification_as_read($notifId, $userId);
    }
    echo json_encode([
        'success' => true,
        'unread_count' => get_unread_notification_count($userId, $role, $branchId)
    ]);
    exit;
}

if ($action === 'mark_all_read') {
    mark_all_notifications_as_read($userId, $role, $branchId);
    echo json_encode([
        'success' => true,
        'unread_count' => 0
    ]);
    exit;
}

if ($action === 'get_count') {
    echo json_encode([
        'success' => true,
        'unread_count' => get_unread_notification_count($userId, $role, $branchId)
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);

