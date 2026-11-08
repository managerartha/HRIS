# Panduan Pindah HRIS ke Hostinger

Panduan ini memindahkan HRIS dari **GitHub Pages + Google Spreadsheet** ke **Hostinger (website + database MySQL)**.
Kerjakan berurutan. Selama belum selesai, versi GitHub Pages yang lama tetap bisa dipakai.

---

## Struktur file

| Folder / file | Fungsi |
|---|---|
| `index.html` | Tampilan HRIS. Di GitHub Pages otomatis memakai Apps Script; di Hostinger otomatis memakai `api/`. |
| `api/` | Backend baru (PHP). Pengganti `Code.gs`. |
| `api/config.sample.php` | Contoh pengaturan database. Disalin menjadi `api/config.php`. |
| `database/schema.sql` | Struktur tabel database. |
| `setup/` | Halaman sekali pakai untuk membuat tabel & import data lama. **Hapus setelah selesai.** |
| `uploads/` | Tempat foto absensi, foto profil & lampiran pengajuan. |
| `apps-script/Code.gs` | Versi Apps Script yang sudah diperbaiki (untuk dipakai sementara + export data). |

---

## Langkah 0: Perbarui Apps Script lama (sementara)

Supaya Riana dan karyawan lain bisa absen selama proses pindah:

1. Buka Spreadsheet HRIS → **Extensions → Apps Script**.
2. Ganti seluruh isi `Code.gs` dengan isi file `apps-script/Code.gs` dari repo ini → **Save**.
3. **Deploy → Manage deployments** → klik ikon pensil → *Version*: **New version** → **Deploy**.
   (Pakai "Manage deployments", bukan "New deployment", agar URL tidak berubah.)
4. Cek kolom **POSITION / DEPARTMENT** Riana di sheet USERS: **jangan** ada kata `remote` atau `WFH`.
   Kalau ada kata itu, sistem tetap menganggap Riana karyawan remote.

## Langkah 1: Buat database di Hostinger

1. hPanel → **Databases → MySQL Databases**.
2. Buat database baru, misalnya `hris`, beserta user dan password. Hostinger otomatis menambahkan awalan, contohnya `u123456789_hris`.
3. Catat: **nama database, username, password**. Host biasanya `localhost`.

## Langkah 2: Upload file

1. hPanel → **Files → File Manager** → buka folder `public_html` (atau folder subdomain, misalnya `hris.domainanda.com`).
2. Upload: `index.html`, `.htaccess`, folder `api/`, `database/`, `setup/`, `uploads/`.
   - Cara cepat: download repo sebagai ZIP dari GitHub (**Code → Download ZIP**), upload ZIP-nya, lalu klik kanan → **Extract**.
   - Atau pakai **hPanel → Advanced → Git** untuk menarik repo langsung dari GitHub.
3. Di folder `api/`, salin `config.sample.php` menjadi **`config.php`**, lalu edit:
   - `db_name`, `db_user`, `db_pass`: sesuai Langkah 1.
   - `setup_key`: ganti dengan kata rahasia panjang (dipakai untuk membuka halaman setup).
4. Aktifkan **SSL/HTTPS** untuk domain: hPanel → **Security → SSL**. **Wajib.** Tanpa HTTPS, GPS dan kamera di HP tidak bisa dipakai.

## Langkah 3: Pindahkan data dari Spreadsheet

1. Di Apps Script Editor pilih fungsi **`exportForHostinger`** → **Run** (Jalankan).
2. Buka Google Drive (My Drive). Akan ada file `HRIS-export-xxxx.json`. Download file itu.
3. Buka `https://domainanda.com/setup/`.
4. **Bagian 1**: masukkan setup key → **Buat Tabel**.
5. **Bagian 2**: masukkan setup key, pilih file JSON → **Import Data**.
6. Coba login di `https://domainanda.com` dengan akun admin lama. Password lama tetap berlaku.
7. Kalau semua sudah benar:
   - **Hapus folder `setup/`** di File Manager.
   - **Hapus file `HRIS-export-xxxx.json` dari Google Drive dan dari komputer**, karena file itu berisi password karyawan.

Setelah import, cek menu **Data Karyawan → Edit** untuk setiap karyawan khusus:

| Karyawan | Mode Kerja | Tidak dapat bonus |
|---|---|---|
| Irfan | Remote / WFH | (otomatis) |
| Erna, Ricardo | Kantor | ✔ |
| **Riana** | **Kantor** | ☐ (dapat bonus tepat waktu) |
| Karyawan lain | Kantor | ☐ |

Mulai sekarang aturan ini diatur dari menu admin, tidak perlu mengubah kode lagi.

## Langkah 4: Pengaturan lokasi kantor

Menu **Pengaturan GPS**:
- Isi Latitude/Longitude kantor dengan **titik** sebagai pemisah desimal, contoh `-6.175392`.
  Cara dapat koordinat: buka Google Maps → klik kanan tepat di gedung kantor → klik angka koordinatnya (otomatis tersalin).
- **Radius**: sarannya **30–50 meter**. GPS HP di dalam gedung biasanya meleset 10–30 m. Dengan radius 10 m, karyawan yang sudah di kantor sering ditolak.

---

## Di mana data & akun karyawan tersimpan?

1. **Menu admin HRIS**: Data Karyawan, Riwayat Presensi, Pengajuan, Slip Gaji (seperti biasa).
2. **phpMyAdmin**: hPanel → Databases → **phpMyAdmin** → pilih database HRIS. Tabelnya:
   - `users`: akun & data karyawan. Password tersimpan dalam bentuk *hash* (terenkripsi), jadi tidak bisa dibaca siapa pun, termasuk admin. Kalau ada karyawan lupa password, admin cukup mengisi password baru di menu Edit Karyawan.
   - `attendance`: absensi. `requests`: pengajuan cuti/izin. `payslips`: slip gaji. `agendas`, `duties`, `settings`.
3. **Foto & lampiran**: File Manager → `public_html/uploads/`.

## Backup data

**Backup manual (kapan saja):** login sebagai Admin → menu **Pengaturan GPS** → kartu **Backup Data HRIS** → **Download Backup Sekarang**.
Isi file ZIP-nya:
- `excel-csv/*.csv`: tiap tabel, bisa dibuka di Excel atau Google Sheets (`users.csv` = daftar akun karyawan).
- `hris.sql`: backup lengkap. Untuk mengembalikan data: phpMyAdmin → pilih database → **Import** → pilih `hris.sql` → **Go**.
- `uploads/`: foto & lampiran (kalau dicentang).

**Backup otomatis harian (disarankan):** hPanel → **Advanced → Cron Jobs** → tambah:
- Command: `/usr/bin/php /home/USERNAME/domains/DOMAINANDA/public_html/api/cron_backup.php`
  (path lengkapnya terlihat di File Manager. Tambahkan ` --with-files` di akhir kalau foto juga mau ikut di-backup.)
- Jadwal: setiap hari, misalnya jam 23:00.
- File backup tersimpan di folder `hris_backups/` (di luar `public_html`, jadi tidak bisa dibuka orang lewat browser). 14 backup terakhir disimpan.

Hostinger juga punya backup otomatis harian/mingguan (tergantung paket): hPanel → **Files → Backups**.
Sesekali download ZIP backup ke Google Drive atau laptop supaya ada salinan di luar hosting.

---

## Troubleshooting

| Masalah | Solusi |
|---|---|
| "File api/config.php belum dibuat" | Salin `api/config.sample.php` menjadi `api/config.php` lalu isi. |
| "Tidak bisa terhubung ke database" | Cek `db_name`, `db_user`, `db_pass` (pakai awalan `u123456789_`). |
| GPS: "Izin lokasi DITOLAK" | Di HP: ketuk ikon gembok di samping alamat web → Lokasi → Izinkan → muat ulang. iPhone: Pengaturan → Privasi → Layanan Lokasi → Safari → "Saat Digunakan". |
| GPS lama terkunci | Pastikan GPS/Lokasi HP aktif dan mode hemat baterai mati. Coba di dekat jendela, lalu tekan **Refresh GPS**. |
| "Di luar radius" padahal sudah di kantor | Cek koordinat kantor di menu Pengaturan GPS dan perbesar radius (30–50 m). |
| Upload lampiran gagal (file besar) | hPanel → Advanced → PHP Configuration → naikkan `post_max_size` & `upload_max_filesize` ke 40M. |
