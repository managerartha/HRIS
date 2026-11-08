<?php
/**
 * KONFIGURASI HRIS (Hostinger)
 * ----------------------------------------------------------------------
 * 1. Salin file ini menjadi  config.php  (di folder yang sama: api/config.php)
 * 2. Isi data database dari hPanel Hostinger -> Databases -> MySQL Databases
 * 3. JANGAN upload config.php ke GitHub (sudah di-ignore lewat .gitignore)
 */

return [
    // Data database MySQL dari hPanel (contoh nama di Hostinger: u123456789_hris)
    'db_host' => 'localhost',
    'db_name' => 'u123456789_hris',
    'db_user' => 'u123456789_hris',
    'db_pass' => 'GANTI_DENGAN_PASSWORD_DATABASE',

    // Kunci rahasia untuk membuka halaman setup/ (install & import data).
    // Ganti dengan teks acak yang panjang. Setelah selesai setup, HAPUS folder setup/.
    'setup_key' => 'GANTI_DENGAN_KUNCI_RAHASIA_PANJANG',

    // Lama sesi login (jam). Setelah ini karyawan harus login ulang.
    'session_hours' => 12,

    // Folder penyimpanan backup otomatis (cron). Default: di luar public_html
    // supaya tidak bisa diakses lewat browser.
    'backup_dir' => dirname(__DIR__, 2) . '/hris_backups',

    // Jumlah file backup otomatis yang disimpan (yang lebih lama dihapus).
    'backup_keep' => 14,
];
