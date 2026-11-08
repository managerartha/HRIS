<?php
/**
 * BACKUP OTOMATIS HARIAN (Cron Job Hostinger)
 * ----------------------------------------------------------------------
 * hPanel -> Advanced -> Cron Jobs -> Tambah:
 *   Perintah : /usr/bin/php /home/USERNAME/domains/DOMAINANDA/public_html/api/cron_backup.php
 *   Jadwal   : setiap hari jam 23:00 (menit 0, jam 23)
 * File ZIP disimpan di folder 'backup_dir' pada config.php (default: di luar public_html),
 * bisa dilihat & di-download lewat hPanel -> File Manager -> folder hris_backups.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya bisa dijalankan lewat Cron Job.');
}

require __DIR__ . '/lib.php';

$cfg = config();
$dir = $cfg['backup_dir'] ?? (dirname(__DIR__, 2) . '/hris_backups');
$keep = max(1, (int)($cfg['backup_keep'] ?? 14));
$includeFiles = in_array('--with-files', $argv ?? [], true);

if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "Folder backup tidak bisa dibuat: $dir\n");
    exit(1);
}
file_put_contents($dir . '/.htaccess', "Require all denied\n");

$file = $dir . '/backup-hris-' . date('Y-m-d-His') . '.zip';
build_backup_zip($file, $includeFiles);
echo "Backup tersimpan: $file\n";

$files = glob($dir . '/backup-hris-*.zip') ?: [];
rsort($files);
foreach (array_slice($files, $keep) as $old) {
    @unlink($old);
    echo "Backup lama dihapus: $old\n";
}
