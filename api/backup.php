<?php
/**
 * Download backup data HRIS (khusus ADMIN).
 * Dipanggil dari tombol "Download Backup" di menu Pengaturan admin.
 * POST JSON { "token": "...", "includeFiles": true/false }  ->  file ZIP
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') throw new ApiError('Gunakan tombol Backup di menu admin.', 405);
    $data = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $token = (string)($data['token'] ?? '');
    $me = $token === '' ? null : q_one('SELECT u.* FROM sessions s JOIN users u ON u.emp_id = s.emp_id
                   WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.is_active = 1', [hash('sha256', $token)]);
    if (!$me) throw new ApiError('Sesi login sudah berakhir. Silakan login ulang.', 401);
    if ($me['role'] !== 'ADMIN') throw new ApiError('Akses ditolak: backup khusus Admin.', 403);

    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'hris');
    build_backup_zip($tmp, !empty($data['includeFiles']));
    audit_log($me, 'BACKUP', 'Download backup', !empty($data['includeFiles']) ? 'Termasuk foto & lampiran.' : 'Data saja.');

    $name = 'backup-hris-' . date('Y-m-d-His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
} catch (ApiError $e) {
    http_response_code($e->httpCode >= 400 ? $e->httpCode : 400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[HRIS backup] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Gagal membuat backup: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
