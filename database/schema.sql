-- =====================================================================
-- HRIS PT Artha Safety Indonesia - Struktur Database MySQL / MariaDB
-- Dipakai otomatis oleh setup/index.php, atau bisa di-import manual
-- lewat hPanel Hostinger -> Databases -> phpMyAdmin -> tab "Import".
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  emp_id            VARCHAR(20)  NOT NULL PRIMARY KEY,
  full_name         VARCHAR(150) NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  role              VARCHAR(20)  NOT NULL DEFAULT 'EMPLOYEE',
  -- Hak akses tambahan untuk non-admin, dipisah koma: ATT_ALL, REPORT, APPROVE
  permissions       VARCHAR(255) NOT NULL DEFAULT '',
  position          VARCHAR(150) NOT NULL DEFAULT '',
  department        VARCHAR(150) NOT NULL DEFAULT '',
  join_date         DATE         NULL,
  leave_balance     INT          NOT NULL DEFAULT 12,
  saved_holidays    INT          NOT NULL DEFAULT 0,
  contract_doc_url  VARCHAR(500) NOT NULL DEFAULT '-',
  bpjs_doc_url      VARCHAR(500) NOT NULL DEFAULT '-',
  basic_salary      BIGINT       NOT NULL DEFAULT 0,
  allowance         BIGINT       NOT NULL DEFAULT 0,
  profile_pic       VARCHAR(500) NOT NULL DEFAULT '',
  bpjs_tk_amount    BIGINT       NOT NULL DEFAULT 0,
  has_bpjs_kes      TINYINT(1)   NOT NULL DEFAULT 0,
  bpjs_kes_amount   BIGINT       NOT NULL DEFAULT 0,
  kasbon_amount     BIGINT       NOT NULL DEFAULT 0,
  encashed_holidays INT          NOT NULL DEFAULT 0,
  -- OFFICE = wajib absen di kantor (radius GPS). REMOTE = kerja remote/WFH (bebas Alpha di laporan).
  work_mode         VARCHAR(10)  NOT NULL DEFAULT 'OFFICE',
  -- 1 = tidak mendapat bonus ketepatan waktu (direksi / remote / pengecualian khusus).
  bonus_exempt      TINYINT(1)   NOT NULL DEFAULT 0,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  resign_date       DATE         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token_hash  CHAR(64)    NOT NULL PRIMARY KEY,
  emp_id      VARCHAR(20) NOT NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME    NOT NULL,
  KEY idx_sessions_emp (emp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  identifier  VARCHAR(190) NOT NULL,
  ip          VARCHAR(64)  NOT NULL,
  attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_attempts (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  setting_value VARCHAR(500) NOT NULL DEFAULT '',
  description   VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  att_id            VARCHAR(20)  NOT NULL PRIMARY KEY,
  date              DATE         NOT NULL,
  emp_id            VARCHAR(20)  NOT NULL,
  full_name         VARCHAR(150) NOT NULL DEFAULT '',
  checkin_time      TIME         NULL,
  checkout_time     TIME         NULL,
  latitude          DECIMAL(10,7) NULL,
  longitude         DECIMAL(10,7) NULL,
  distance_meters   DECIMAL(10,1) NULL,
  gps_accuracy      DECIMAL(10,1) NULL,
  checkin_photo     VARCHAR(500) NOT NULL DEFAULT '-',
  checkout_photo    VARCHAR(500) NOT NULL DEFAULT '-',
  checkout_latitude  DECIMAL(10,7) NULL,
  checkout_longitude DECIMAL(10,7) NULL,
  checkout_distance  DECIMAL(10,1) NULL,
  status            VARCHAR(20)  NOT NULL DEFAULT 'TEPAT WAKTU',
  location_name     VARCHAR(255) NOT NULL DEFAULT '',
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_attendance_emp_date (emp_id, date),
  KEY idx_attendance_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requests (
  req_id          VARCHAR(20)  NOT NULL PRIMARY KEY,
  submit_date     DATE         NULL,
  emp_id          VARCHAR(20)  NOT NULL,
  full_name       VARCHAR(150) NOT NULL DEFAULT '',
  type            VARCHAR(100) NOT NULL DEFAULT '',
  start_date      DATE         NULL,
  end_date        DATE         NULL,
  reason          TEXT,
  doc_attachment  VARCHAR(500) NOT NULL DEFAULT '-',
  status          VARCHAR(20)  NOT NULL DEFAULT 'PENDING',
  approved_by     VARCHAR(150) NOT NULL DEFAULT '-',
  approved_at     DATETIME     NULL,
  -- Koreksi absen: IN = lupa absen masuk, OUT = lupa absen keluar
  correction_kind VARCHAR(10)  NULL,
  correction_time TIME         NULL,
  -- Lembur (PP 35/2021)
  ot_start        TIME         NULL,
  ot_end          TIME         NULL,
  ot_hours        DECIMAL(5,2) NULL,
  ot_pay          BIGINT       NULL,
  admin_note      TEXT,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requests_emp (emp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payslips (
  pay_id               VARCHAR(20)  NOT NULL PRIMARY KEY,
  period               VARCHAR(30)  NOT NULL,
  emp_id               VARCHAR(20)  NOT NULL,
  full_name            VARCHAR(150) NOT NULL DEFAULT '',
  position             VARCHAR(150) NOT NULL DEFAULT '',
  department           VARCHAR(150) NOT NULL DEFAULT '',
  basic_salary         BIGINT NOT NULL DEFAULT 0,
  allowance            BIGINT NOT NULL DEFAULT 0,
  on_time_bonus        BIGINT NOT NULL DEFAULT 0,
  encashed_holidays    INT    NOT NULL DEFAULT 0,
  encashed_holiday_pay BIGINT NOT NULL DEFAULT 0,
  overtime_hours       DECIMAL(6,2) NOT NULL DEFAULT 0,
  overtime_pay         BIGINT NOT NULL DEFAULT 0,
  late_count           INT    NOT NULL DEFAULT 0,
  excused_late_count   INT    NOT NULL DEFAULT 0,
  late_deduction       BIGINT NOT NULL DEFAULT 0,
  bpjs_tk_amount       BIGINT NOT NULL DEFAULT 0,
  has_bpjs_kes         TINYINT(1) NOT NULL DEFAULT 0,
  bpjs_kes_amount      BIGINT NOT NULL DEFAULT 0,
  kasbon_amount        BIGINT NOT NULL DEFAULT 0,
  deductions           BIGINT NOT NULL DEFAULT 0,
  net_salary           BIGINT NOT NULL DEFAULT 0,
  payment_date         VARCHAR(30)  NOT NULL DEFAULT '',
  status               VARCHAR(20)  NOT NULL DEFAULT 'TERBIT',
  finance_name         VARCHAR(150) NOT NULL DEFAULT '',
  leader_name          VARCHAR(150) NOT NULL DEFAULT '',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_payslips_emp_period (emp_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agendas (
  agenda_id      VARCHAR(20)  NOT NULL PRIMARY KEY,
  date           DATE         NULL,
  title          VARCHAR(255) NOT NULL DEFAULT '',
  description    TEXT,
  category       VARCHAR(100) NOT NULL DEFAULT '',
  created_by     VARCHAR(150) NOT NULL DEFAULT '',
  created_by_id  VARCHAR(20)  NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS duties (
  duty_id        VARCHAR(20)  NOT NULL PRIMARY KEY,
  emp_id         VARCHAR(20)  NOT NULL,
  full_name      VARCHAR(150) NOT NULL DEFAULT '',
  location_name  VARCHAR(255) NOT NULL DEFAULT '',
  latitude       DECIMAL(10,7) NOT NULL,
  longitude      DECIMAL(10,7) NOT NULL,
  radius_meters  INT          NOT NULL DEFAULT 50,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  reason         TEXT,
  created_by     VARCHAR(150) NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_duties_emp (emp_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
  holiday_date DATE NOT NULL PRIMARY KEY,
  name         VARCHAR(150) NOT NULL DEFAULT '',
  type         VARCHAR(20)  NOT NULL DEFAULT 'LIBUR NASIONAL',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
  ('OFFICE_NAME', 'PT Artha Safety Indonesia - Kantor Pusat HQ', 'Nama Lokasi Kantor Pusat'),
  ('OFFICE_LAT', '-6.175392', 'Latitude Lokasi Kantor Pusat'),
  ('OFFICE_LNG', '106.827153', 'Longitude Lokasi Kantor Pusat'),
  ('MAX_RADIUS_METERS', '50', 'Batas Maksimal Radius Absensi HQ (Meter)'),
  ('WORK_START_TIME', '08:00', 'Jam Masuk Kerja Resmi HQ (WIB)'),
  ('WORK_END_TIME', '17:00', 'Jam Pulang Kerja Resmi HQ (WIB)'),
  ('WORK_END_SATURDAY', '12:00', 'Jam Pulang Hari Sabtu (WIB)'),
  ('COMPANY_NAME', 'PT Artha Safety Indonesia', 'Nama Perusahaan'),
  ('FINANCE_NAME', 'Avitya Fitriana', 'Nama penandatangan Finance di slip gaji'),
  ('LEADER_NAME', 'Saut Reynald Ricardo Simbolon', 'Nama penandatangan Pimpinan di slip gaji');
