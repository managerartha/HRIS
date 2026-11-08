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

## Langkah 0: Perbarui Apps Script lama (WAJIB)

Langkah ini wajib, karena `Code.gs` baru berisi fungsi **`exportForHostinger`** untuk memindahkan data.
Selain itu, Riana dan karyawan lain jadi bisa absen di versi lama selama masa persiapan:

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

## Langkah 3: Pindahkan SEMUA data lama dari Spreadsheet

Yang ikut pindah: akun & password karyawan, data gaji/BPJS/kasbon, saldo cuti & tabungan libur,
seluruh riwayat absensi beserta foto selfie, pengajuan cuti/izin, slip gaji, agenda, tugas dinas, dan pengaturan kantor.
**Spreadsheet lama tidak diubah atau dihapus**, jadi tetap aman sebagai arsip.

### Uji coba dulu (boleh kapan saja)
1. Di Apps Script Editor pilih fungsi **`exportForHostinger`** → **Run** (Jalankan).
2. Di Google Drive (My Drive) akan muncul folder **`HRIS-export-<tanggal>`** berisi `...-part1.json`
   (dan `part2`, `part3`, dst. kalau foto absensinya banyak). Download **semua** file di folder itu.
3. Buka `https://domainanda.com/setup/`.
4. **Bagian 1**: masukkan setup key → **Buat Tabel**.
5. **Bagian 2**: masukkan setup key, pilih **semua file part sekaligus**, centang "Kosongkan data" → **Import Data**.
6. Baca **Laporan Import**. Untuk tiap sheet terlihat jumlah baris di spreadsheet dan berapa yang masuk.
   - ✅ artinya semua baris masuk.
   - Baris yang *tidak masuk* (misalnya tanggal tidak terbaca atau EMP_ID kosong) ditampilkan dengan **nomor barisnya di spreadsheet**.
     Perbaiki baris itu di Google Sheets, lalu ulangi export + import.
   - Absensi dobel di hari yang sama otomatis **digabung**: jam masuk paling awal, jam keluar paling akhir.
   - Akun dengan email kosong atau dobel **tetap diimport** dengan email sementara `emp-xxxx@ganti-email.local`.
     Karyawan itu bisa login pakai ID-nya (misal `EMP-0005`), lalu admin memperbaiki emailnya di menu Edit Karyawan.
7. Coba login dengan beberapa akun karyawan. **Password lama tetap berlaku.**

### Hari pindah (go-live)
Supaya absensi yang masuk di versi GitHub selama masa uji coba **tidak tertinggal**:
1. Pilih waktu di luar jam absen, misalnya malam setelah semua karyawan absen keluar.
2. Jalankan **`exportForHostinger`** sekali lagi, supaya data yang diambil adalah data terbaru.
3. Import ulang di `/setup/` **dengan centang "Kosongkan data"**. Data hasil uji coba diganti dengan data terbaru.
4. Cek Laporan Import, lalu bagikan alamat baru `https://domainanda.com` ke semua karyawan.
5. Mulai besok semua orang absen di Hostinger. Versi GitHub jangan dipakai lagi.
   Kalau mau, tambahkan pengumuman di halaman lama yang mengarahkan karyawan ke alamat baru.
6. Setelah semua beres:
   - **Hapus folder `setup/`** di File Manager Hostinger.
   - **Hapus folder `HRIS-export-...` dari Google Drive dan file download-nya di komputer**, karena berisi password karyawan.

Kalau file terlalu besar untuk diupload sekaligus: upload `part1` dulu (dengan centang), lalu part berikutnya satu per satu
**tanpa** centang. Halaman import akan memberi tahu kalau masih ada part yang belum diupload.

> **Lampiran pengajuan & dokumen kontrak/BPJS** yang dulu diupload ke Google Drive tetap disimpan sebagai **link Google Drive**
> dan tetap bisa dibuka dari HRIS Hostinger. **Jangan hapus file-file itu dari Google Drive.**
> Lampiran baru yang diupload setelah pindah tersimpan di Hostinger (`uploads/`).

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

---

## Fitur tambahan (update November 2026)

| Fitur | Cara pakai |
|---|---|
| **Koreksi Absen** | Karyawan: menu Pengajuan → jenis **Koreksi Absen** → pilih Lupa Absen Masuk / Keluar + jam sebenarnya. **Hanya bisa untuk hari ini** (tanggal ditentukan server). Setelah HR menyetujui, data absen otomatis diperbaiki (lokasi tercatat "Koreksi Absen (disetujui HR)"). |
| **Lembur** | Karyawan: jenis **Lembur** → tanggal, jam mulai & selesai. Upah dihitung otomatis sesuai **PP 35/2021**: upah sejam = (gaji pokok + tunjangan) ÷ 173. Hari kerja: jam ke-1 ×1,5, berikutnya ×2 (maks. 4 jam/hari, 18 jam/minggu, di luar jam kerja). Minggu/tanggal merah: jam 1–7 ×2, jam ke-8 ×3, jam 9–11 ×4. Tanggal merah di hari Sabtu: jam 1–5 ×2, jam ke-6 ×3, jam 7–9 ×4. Diajukan setelah lembur selesai, paling lambat 7 hari. Setelah disetujui, otomatis masuk slip gaji bulan tersebut. |
| **Hari kerja** | Senin–Sabtu. Jam pulang Sabtu diatur di Pengaturan Kantor (default 12:00). Hari Minggu tidak dihitung Alpha. |
| **Kalender Libur** | Admin: Pengaturan Kantor → **Kalender Hari Libur & Cuti Bersama**. Tanggal ini tidak dihitung Alpha & tampil merah di kalender dashboard. Isi setiap awal tahun sesuai SKB 3 Menteri. |
| **Karyawan Nonaktif** | Admin: Kelola Karyawan → Edit → **Status Karyawan: Nonaktif / Resign** + tanggal terakhir bekerja. Karyawan tidak bisa login, tidak dibuatkan slip baru, tidak dihitung Alpha setelah tanggal tersebut; riwayatnya tetap tersimpan. Bisa diaktifkan lagi kapan saja. |
| **Login tersimpan & Aplikasi HP** | Karyawan cukup login sekali (berlaku 30 hari / sampai Logout). Tombol **Pasang Aplikasi di HP** ada di menu samping & layar login. |
| **Notifikasi Email** | 1) hPanel → **Emails** → buat akun email, mis. `hris@arthasafetyindonesia.com`. 2) HRIS → Pengaturan Kantor → **Notifikasi Email**: isi email & password akun tsb, server `smtp.hostinger.com`, port `465`, centang **Aktifkan** → Simpan → **Kirim Email Tes**. Slip gaji: menu Slip Gaji → pilih periode → **Kirim Slip ke Email**. |

Struktur database diperbarui **otomatis** saat HRIS pertama kali dibuka setelah update (tidak perlu phpMyAdmin).
