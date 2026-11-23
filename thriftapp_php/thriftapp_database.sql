-- =====================================================================
-- THRIFTAPP - SKRIP DATABASE (Draft 2)
-- Target : MySQL 5.7+/8.x atau MariaDB 10.4+ (bawaan XAMPP)
-- Cara   : phpMyAdmin > tab Import > pilih file ini > Go
--          (atau: mysql -u root < thriftapp_database.sql)
-- Isi    : 39 tabel, 5 view, data master, dan data contoh dari desain
-- Catatan: kata sandi semua akun contoh = "password"
-- =====================================================================

CREATE DATABASE IF NOT EXISTS thriftapp
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE thriftapp;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+08:00';

-- Hapus tabel lama (urutan bebas karena FK dimatikan)
DROP VIEW  IF EXISTS v_admin_dashboard, v_user_admin_list, v_shop_balances, v_shop_stats, v_catalog;
DROP TABLE IF EXISTS
  negative_records, complaint_evidence, complaints,
  reviews, refunds, withdrawals, wallet_transactions, order_status_logs,
  order_items, orders, payments, checkouts, payment_methods, couriers,
  favorites, cart_items,
  prohibited_items, verification_checks, verifications, rejection_reasons, verification_rules,
  product_defects, product_measurements, product_photos, products,
  category_measurements, measurement_types, defect_tags, condition_levels, brands, categories,
  notifications, newsletter_subscribers, bank_accounts, shops, addresses, password_resets, users,
  system_settings;

-- =====================================================================
-- 1. PENGGUNA, TOKO, DAN NOTIFIKASI
-- =====================================================================

CREATE TABLE users (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100) NOT NULL,
  email            VARCHAR(150) NOT NULL,
  phone            VARCHAR(20)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_seller        TINYINT(1) NOT NULL DEFAULT 0,
  status           ENUM('active','blocked') NOT NULL DEFAULT 'active',
  block_reason     VARCHAR(255) NULL,
  blocked_at       DATETIME NULL,
  blocked_by       BIGINT UNSIGNED NULL,
  terms_accepted_at DATETIME NULL,
  last_login_at    DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status),
  CONSTRAINT fk_users_blocked_by FOREIGN KEY (blocked_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pwreset_user (user_id),
  CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  label          VARCHAR(50)  NOT NULL,
  recipient_name VARCHAR(100) NOT NULL,
  phone          VARCHAR(20)  NOT NULL,
  street         TEXT NOT NULL,
  district       VARCHAR(80) NULL,
  city           VARCHAR(80)  NOT NULL,
  province       VARCHAR(80)  NOT NULL,
  postal_code    VARCHAR(10)  NOT NULL,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_addresses_user (user_id),
  CONSTRAINT fk_addresses_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shops (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              BIGINT UNSIGNED NOT NULL,
  name                 VARCHAR(100) NOT NULL,
  slug                 VARCHAR(120) NOT NULL,
  description          TEXT NULL,
  city                 VARCHAR(80)  NOT NULL,
  avatar_path          VARCHAR(255) NULL,
  rating_avg           DECIMAL(2,1) NOT NULL DEFAULT 0.0,   -- cache dari reviews
  review_count         INT UNSIGNED NOT NULL DEFAULT 0,     -- cache dari reviews
  sold_count           INT UNSIGNED NOT NULL DEFAULT 0,     -- cache barang terjual
  avg_response_minutes INT UNSIGNED NULL,                   -- cache "balas rata-rata"
  is_active            TINYINT(1) NOT NULL DEFAULT 1,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shops_user (user_id),
  UNIQUE KEY uq_shops_slug (slug),
  KEY idx_shops_city (city),
  CONSTRAINT fk_shops_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bank_accounts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id        BIGINT UNSIGNED NOT NULL,
  bank_name      VARCHAR(50)  NOT NULL,
  account_number VARCHAR(30)  NOT NULL,
  account_holder VARCHAR(100) NOT NULL,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bank_shop (shop_id),
  CONSTRAINT fk_bank_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(150) NOT NULL,
  user_id         BIGINT UNSIGNED NULL,
  subscribed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email (email),
  CONSTRAINT fk_newsletter_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  type         ENUM('order_paid','order_shipped','order_delivered','order_cancelled',
                    'listing_approved','listing_rejected','complaint_filed',
                    'complaint_replied','complaint_decided','fund_released',
                    'withdrawal_processed','favorite_available','system') NOT NULL,
  title        VARCHAR(150) NOT NULL,
  body         VARCHAR(500) NULL,
  related_type VARCHAR(30) NULL,            -- contoh: order, product, complaint
  related_id   BIGINT UNSIGNED NULL,
  is_read      TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2. DATA MASTER BARANG
-- =====================================================================

CREATE TABLE categories (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id  BIGINT UNSIGNED NULL,
  name       VARCHAR(60) NOT NULL,
  slug       VARCHAR(80) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  is_new     TINYINT(1) NOT NULL DEFAULT 0,       -- lencana "BARU" di halaman admin
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent (parent_id),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brands (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_brands_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE condition_levels (
  score       TINYINT UNSIGNED NOT NULL,          -- 1 sampai 10
  name        VARCHAR(40) NOT NULL,
  description VARCHAR(200) NOT NULL,
  is_sellable TINYINT(1) NOT NULL DEFAULT 1,      -- 0 untuk skor di bawah 6
  PRIMARY KEY (score),
  CONSTRAINT chk_condlevel_score CHECK (score BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE defect_tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_defect_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE measurement_types (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30) NOT NULL,
  name       VARCHAR(60) NOT NULL,
  unit       VARCHAR(10) NOT NULL DEFAULT 'cm',
  PRIMARY KEY (id),
  UNIQUE KEY uq_measurement_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kolom ukuran yang tampil di form upload sesuai kategori utama
CREATE TABLE category_measurements (
  category_id         BIGINT UNSIGNED NOT NULL,
  measurement_type_id BIGINT UNSIGNED NOT NULL,
  sort_order          SMALLINT NOT NULL DEFAULT 0,
  is_required         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (category_id, measurement_type_id),
  CONSTRAINT fk_catmeas_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_catmeas_type FOREIGN KEY (measurement_type_id) REFERENCES measurement_types (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3. BARANG (LISTING)
-- =====================================================================

CREATE TABLE products (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id                BIGINT UNSIGNED NOT NULL,
  category_id            BIGINT UNSIGNED NOT NULL,       -- subkategori (atau kategori utama)
  brand_id               BIGINT UNSIGNED NULL,           -- NULL = tanpa merek
  title                  VARCHAR(150) NOT NULL,
  description            TEXT NULL,
  material               VARCHAR(60) NULL,
  color                  VARCHAR(30) NULL,
  gender                 ENUM('male','female','unisex') NOT NULL DEFAULT 'unisex',
  size_type              ENUM('letter','number','shoe','one_size') NOT NULL DEFAULT 'letter',
  size_label             VARCHAR(10) NOT NULL,           -- M, 32, 41, One size
  price                  BIGINT UNSIGNED NOT NULL,       -- rupiah
  weight_gram            INT UNSIGNED NOT NULL,
  seller_condition_score TINYINT UNSIGNED NOT NULL,      -- skor dari penjual
  condition_score        TINYINT UNSIGNED NOT NULL,      -- skor tampil (bisa dikoreksi admin)
  status                 ENUM('draft','pending_review','rejected','active','reserved','sold','inactive')
                         NOT NULL DEFAULT 'draft',
  reserved_until         DATETIME NULL,                  -- batas tahan saat checkout
  submitted_at           DATETIME NULL,                  -- dikirim untuk verifikasi
  published_at           DATETIME NULL,                  -- disetujui admin
  sold_at                DATETIME NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_shop_status (shop_id, status),
  KEY idx_products_category (category_id, status),
  KEY idx_products_brand (brand_id),
  KEY idx_products_status_pub (status, published_at),
  KEY idx_products_price (price),
  KEY idx_products_condition (condition_score),
  FULLTEXT KEY ft_products_search (title, description),
  CONSTRAINT chk_products_cond   CHECK (condition_score BETWEEN 6 AND 10),
  CONSTRAINT chk_products_scond  CHECK (seller_condition_score BETWEEN 6 AND 10),
  CONSTRAINT chk_products_price  CHECK (price > 0),
  CONSTRAINT fk_products_shop     FOREIGN KEY (shop_id)     REFERENCES shops (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_products_brand    FOREIGN KEY (brand_id)    REFERENCES brands (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_photos (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  path       VARCHAR(255) NOT NULL,
  type       ENUM('front','back','label','defect','extra') NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_photos_product (product_id, sort_order),
  CONSTRAINT fk_photos_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_measurements (
  product_id          BIGINT UNSIGNED NOT NULL,
  measurement_type_id BIGINT UNSIGNED NOT NULL,
  value_cm            DECIMAL(5,1) NOT NULL,
  PRIMARY KEY (product_id, measurement_type_id),
  CONSTRAINT chk_prodmeas_value CHECK (value_cm > 0),
  CONSTRAINT fk_prodmeas_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_prodmeas_type FOREIGN KEY (measurement_type_id) REFERENCES measurement_types (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tag cacat per barang, lengkap dengan foto close-up dan catatan (wajib)
CREATE TABLE product_defects (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    BIGINT UNSIGNED NOT NULL,
  defect_tag_id BIGINT UNSIGNED NOT NULL,
  photo_id      BIGINT UNSIGNED NULL,             -- foto close-up (tipe defect)
  note          VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_proddefect (product_id, defect_tag_id),
  CONSTRAINT fk_proddefect_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_proddefect_tag FOREIGN KEY (defect_tag_id) REFERENCES defect_tags (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_proddefect_photo FOREIGN KEY (photo_id) REFERENCES product_photos (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4. VERIFIKASI LISTING (ADMIN)
-- =====================================================================

CREATE TABLE verification_rules (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(200) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rejection_reasons (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label      VARCHAR(150) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verifications (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id          BIGINT UNSIGNED NOT NULL,
  admin_id            BIGINT UNSIGNED NOT NULL,
  decision            ENUM('approved','rejected') NOT NULL,
  adjusted_score      TINYINT UNSIGNED NULL,       -- skor koreksi admin bila diubah
  rejection_reason_id BIGINT UNSIGNED NULL,
  note                TEXT NULL,                   -- alasan penolakan untuk penjual
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verif_product (product_id, created_at),
  KEY idx_verif_admin (admin_id),
  CONSTRAINT chk_verif_score CHECK (adjusted_score IS NULL OR adjusted_score BETWEEN 6 AND 10),
  CONSTRAINT fk_verif_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_verif_admin FOREIGN KEY (admin_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_verif_reason FOREIGN KEY (rejection_reason_id) REFERENCES rejection_reasons (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verification_checks (
  verification_id BIGINT UNSIGNED NOT NULL,
  rule_id         BIGINT UNSIGNED NOT NULL,
  is_passed       TINYINT(1) NOT NULL,
  note            VARCHAR(255) NULL,
  PRIMARY KEY (verification_id, rule_id),
  CONSTRAINT fk_vcheck_verif FOREIGN KEY (verification_id) REFERENCES verifications (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_vcheck_rule FOREIGN KEY (rule_id) REFERENCES verification_rules (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prohibited_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(150) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5. KERANJANG, FAVORIT, KURIR, METODE BAYAR
-- =====================================================================

CREATE TABLE cart_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_user_product (user_id, product_id),
  KEY idx_cart_product (product_id),
  CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE favorites (
  user_id    BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  KEY idx_fav_product (product_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_fav_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE couriers (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code      VARCHAR(20) NOT NULL,
  name      VARCHAR(60) NOT NULL,            -- tampil: "JNE Reguler"
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_couriers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(30) NOT NULL,
  group_type  ENUM('virtual_account','qris','ewallet','card') NOT NULL,
  name        VARCHAR(60) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paymethod_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6. CHECKOUT, PEMBAYARAN, PESANAN
-- =====================================================================

-- Satu checkout = satu kali bayar = satu atau lebih pesanan (per toko)
CREATE TABLE checkouts (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  buyer_id           BIGINT UNSIGNED NOT NULL,
  address_id         BIGINT UNSIGNED NULL,
  items_total        BIGINT UNSIGNED NOT NULL,
  shipping_total     BIGINT UNSIGNED NOT NULL,
  service_fee        BIGINT UNSIGNED NOT NULL DEFAULT 0,     -- "Biaya layanan" Rp3.000
  grand_total        BIGINT UNSIGNED NOT NULL,
  status             ENUM('awaiting_payment','paid','expired','cancelled') NOT NULL DEFAULT 'awaiting_payment',
  payment_expires_at DATETIME NOT NULL,                      -- dibuat + 30 menit
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_checkouts_buyer (buyer_id, status),
  KEY idx_checkouts_expiry (status, payment_expires_at),
  CONSTRAINT fk_checkouts_buyer FOREIGN KEY (buyer_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_checkouts_address FOREIGN KEY (address_id) REFERENCES addresses (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  checkout_id       BIGINT UNSIGNED NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  gateway_ref       VARCHAR(80) NULL,
  va_number         VARCHAR(30) NULL,
  amount            BIGINT UNSIGNED NOT NULL,
  status            ENUM('pending','paid','failed','expired','refunded') NOT NULL DEFAULT 'pending',
  is_sandbox        TINYINT(1) NOT NULL DEFAULT 1,           -- "Mode sandbox"
  expires_at        DATETIME NOT NULL,
  paid_at           DATETIME NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_checkout (checkout_id),
  KEY idx_payments_status (status, expires_at),
  CONSTRAINT fk_payments_checkout FOREIGN KEY (checkout_id) REFERENCES checkouts (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number          VARCHAR(20) NOT NULL,                -- contoh TA-2041
  checkout_id           BIGINT UNSIGNED NOT NULL,
  buyer_id              BIGINT UNSIGNED NOT NULL,
  shop_id               BIGINT UNSIGNED NOT NULL,
  recipient_name        VARCHAR(100) NOT NULL,               -- salinan alamat saat checkout
  phone                 VARCHAR(20)  NOT NULL,
  address_text          TEXT NOT NULL,
  destination_city      VARCHAR(80) NOT NULL,
  courier_id            BIGINT UNSIGNED NOT NULL,
  courier_name          VARCHAR(60) NOT NULL,                -- salinan nama kurir
  shipping_etd          VARCHAR(20) NULL,                    -- contoh "3-4 hari"
  total_weight_gram     INT UNSIGNED NOT NULL,
  items_total           BIGINT UNSIGNED NOT NULL,
  shipping_cost         BIGINT UNSIGNED NOT NULL,
  commission_rate       DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  commission            BIGINT UNSIGNED NOT NULL,            -- 5% dari items_total
  seller_net            BIGINT UNSIGNED NOT NULL,            -- items_total - commission
  total                 BIGINT UNSIGNED NOT NULL,            -- items_total + shipping_cost
  status                ENUM('awaiting_payment','paid','processing','shipped','delivered',
                             'completed','complained','refunded','cancelled','expired')
                        NOT NULL DEFAULT 'awaiting_payment',
  cancel_reason         ENUM('buyer_cancelled','payment_timeout','seller_not_shipped','admin') NULL,
  payment_expires_at    DATETIME NOT NULL,
  paid_at               DATETIME NULL,
  ship_deadline_at      DATETIME NULL,                       -- dibayar + 2x24 jam
  processing_at         DATETIME NULL,
  shipped_at            DATETIME NULL,
  tracking_number       VARCHAR(50) NULL,
  estimated_arrival_date DATE NULL,
  delivered_at          DATETIME NULL,
  complaint_deadline_at DATETIME NULL,                       -- delivered_at + 2x24 jam
  completed_at          DATETIME NULL,
  completion_type       ENUM('buyer_confirmed','auto') NULL,
  cancelled_at          DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_checkout (checkout_id),
  KEY idx_orders_buyer_status (buyer_id, status),
  KEY idx_orders_shop_status (shop_id, status),
  KEY idx_orders_ship_deadline (status, ship_deadline_at),
  KEY idx_orders_complaint_deadline (status, complaint_deadline_at),
  CONSTRAINT fk_orders_checkout FOREIGN KEY (checkout_id) REFERENCES checkouts (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_orders_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_orders_courier FOREIGN KEY (courier_id) REFERENCES couriers (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             BIGINT UNSIGNED NOT NULL,
  product_id           BIGINT UNSIGNED NOT NULL,
  title_snapshot       VARCHAR(150) NOT NULL,
  price_snapshot       BIGINT UNSIGNED NOT NULL,
  size_snapshot        VARCHAR(10) NOT NULL,
  condition_snapshot   TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_orderitems_order (order_id),
  KEY idx_orderitems_product (product_id),
  CONSTRAINT fk_orderitems_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_orderitems_product FOREIGN KEY (product_id) REFERENCES products (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Garis waktu status pesanan (halaman Detail pesanan)
CREATE TABLE order_status_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id   BIGINT UNSIGNED NOT NULL,
  status     VARCHAR(30) NOT NULL,
  note       VARCHAR(255) NULL,
  actor_id   BIGINT UNSIGNED NULL,                 -- NULL = sistem
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_statuslog_order (order_id, created_at),
  CONSTRAINT fk_statuslog_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_statuslog_actor FOREIGN KEY (actor_id) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7. SALDO PENJUAL, PENARIKAN, REFUND, ULASAN
-- =====================================================================

-- Buku kas toko. Saldo dihitung dari jumlah baris per bucket (lihat view v_shop_balances).
--   hold        : +items_total ke held saat pesanan dibayar
--   commission  : -komisi dari held
--   release     : sepasang baris: -net di held, +net di available (pembeli konfirmasi/auto)
--   refund      : -net dari held bila pesanan dikembalikan ke pembeli
--   withdrawal  : -jumlah dari available saat penarikan diajukan
--   withdrawal_reversal : +jumlah ke available bila penarikan ditolak
CREATE TABLE wallet_transactions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id    BIGINT UNSIGNED NOT NULL,
  order_id   BIGINT UNSIGNED NULL,
  withdrawal_id BIGINT UNSIGNED NULL,
  type       ENUM('hold','commission','release','refund','withdrawal','withdrawal_reversal') NOT NULL,
  bucket     ENUM('held','available') NOT NULL,
  amount     BIGINT NOT NULL,
  note       VARCHAR(150) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wallet_shop_bucket (shop_id, bucket),
  KEY idx_wallet_order (order_id),
  CONSTRAINT fk_wallet_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_wallet_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE withdrawals (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_id         BIGINT UNSIGNED NOT NULL,
  bank_account_id BIGINT UNSIGNED NULL,
  amount          BIGINT UNSIGNED NOT NULL,
  bank_name       VARCHAR(50)  NOT NULL,            -- salinan rekening saat diajukan
  account_number  VARCHAR(30)  NOT NULL,
  account_holder  VARCHAR(100) NOT NULL,
  status          ENUM('requested','processing','completed','rejected') NOT NULL DEFAULT 'requested',
  reject_reason   VARCHAR(200) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_withdraw_shop (shop_id, created_at),
  CONSTRAINT chk_withdraw_amount CHECK (amount > 0),
  CONSTRAINT fk_withdraw_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_withdraw_bank FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE wallet_transactions
  ADD CONSTRAINT fk_wallet_withdrawal FOREIGN KEY (withdrawal_id) REFERENCES withdrawals (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE refunds (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  payment_id   BIGINT UNSIGNED NULL,
  complaint_id BIGINT UNSIGNED NULL,                -- FK ditambahkan setelah tabel complaints
  type         ENUM('full','partial','seller_not_shipped','cancelled') NOT NULL,
  amount       BIGINT UNSIGNED NOT NULL,
  status       ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_refunds_order (order_id),
  CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id   BIGINT UNSIGNED NOT NULL,
  buyer_id   BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,
  rating     TINYINT UNSIGNED NOT NULL,
  comment    TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reviews_order (order_id),
  KEY idx_reviews_shop (shop_id, created_at),
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_reviews_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_reviews_buyer FOREIGN KEY (buyer_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reviews_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 8. KOMPLAIN
-- =====================================================================

CREATE TABLE complaints (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id             BIGINT UNSIGNED NOT NULL,
  order_item_id        BIGINT UNSIGNED NULL,          -- barang yang dikomplain
  buyer_id             BIGINT UNSIGNED NOT NULL,
  reason               ENUM('undisclosed_defect','size_mismatch','color_mismatch','wrong_item',
                            'damaged_in_shipping','not_arrived','other') NOT NULL,
  description          TEXT NOT NULL,
  seller_stance        ENUM('agree','dispute') NULL,  -- "Barang sesuai listing" = dispute
  seller_response      TEXT NULL,
  seller_deadline_at   DATETIME NOT NULL,             -- batas penjual menjawab (2x24 jam)
  seller_replied_at    DATETIME NULL,
  status               ENUM('waiting_seller','needs_decision','resolved') NOT NULL DEFAULT 'waiting_seller',
  decision             ENUM('pending','full_refund','partial_refund','rejected') NOT NULL DEFAULT 'pending',
  decision_deadline_at DATETIME NULL,                 -- "sisa waktu keputusan"
  refund_amount        BIGINT UNSIGNED NULL,          -- ke pembeli
  seller_payout        BIGINT UNSIGNED NULL,          -- ke penjual
  decision_note        TEXT NULL,                     -- terlihat pembeli dan penjual
  decided_by           BIGINT UNSIGNED NULL,
  resolved_at          DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_complaints_order (order_id),
  KEY idx_complaints_status (status, decision_deadline_at),
  KEY idx_complaints_buyer (buyer_id),
  CONSTRAINT fk_complaints_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_complaints_item FOREIGN KEY (order_item_id) REFERENCES order_items (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_complaints_buyer FOREIGN KEY (buyer_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_complaints_admin FOREIGN KEY (decided_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE refunds
  ADD CONSTRAINT fk_refunds_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (id)
    ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE complaint_evidence (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  complaint_id BIGINT UNSIGNED NOT NULL,
  uploaded_by  BIGINT UNSIGNED NOT NULL,
  side         ENUM('buyer','seller') NOT NULL,
  media_type   ENUM('photo','video') NOT NULL DEFAULT 'photo',
  path         VARCHAR(255) NOT NULL,
  caption      VARCHAR(100) NULL,                     -- contoh "Noda dada", "Label"
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_evidence_complaint (complaint_id, side),
  CONSTRAINT fk_evidence_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_evidence_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan negatif akun (kolom "Catatan negatif" di halaman Kelola pengguna)
CREATE TABLE negative_records (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  order_id     BIGINT UNSIGNED NULL,
  complaint_id BIGINT UNSIGNED NULL,
  reason       ENUM('late_shipping','item_not_as_listed','wrong_item','other') NOT NULL,
  note         VARCHAR(255) NULL,
  created_by   BIGINT UNSIGNED NULL,                  -- NULL = sistem
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_negrec_user (user_id),
  CONSTRAINT fk_negrec_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_negrec_order FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_negrec_complaint FOREIGN KEY (complaint_id) REFERENCES complaints (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_negrec_creator FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 9. PENGATURAN SISTEM ("Aturan otomatis sistem", hanya baca di admin)
-- =====================================================================

CREATE TABLE system_settings (
  setting_key VARCHAR(50) NOT NULL,
  value       VARCHAR(100) NOT NULL,
  description VARCHAR(200) NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 10. VIEW (laporan siap pakai)
-- =====================================================================

-- Saldo tersedia dan tertahan tiap toko (halaman Saldo dan penarikan)
CREATE VIEW v_shop_balances AS
SELECT s.id AS shop_id,
       s.name AS shop_name,
       COALESCE(SUM(CASE WHEN w.bucket = 'available' THEN w.amount END), 0) AS available_balance,
       COALESCE(SUM(CASE WHEN w.bucket = 'held'      THEN w.amount END), 0) AS held_balance
FROM shops s
LEFT JOIN wallet_transactions w ON w.shop_id = s.id
GROUP BY s.id, s.name;

-- Rating, ulasan, dan jumlah terjual per toko (halaman Toko)
CREATE VIEW v_shop_stats AS
SELECT s.id AS shop_id,
       s.name AS shop_name,
       s.city,
       ROUND(COALESCE(AVG(r.rating), 0), 1) AS rating_avg,
       COUNT(DISTINCT r.id) AS review_count,
       (SELECT COUNT(*) FROM products p WHERE p.shop_id = s.id AND p.status = 'sold') AS sold_count,
       (SELECT COUNT(*) FROM products p WHERE p.shop_id = s.id AND p.status IN ('active','reserved')) AS listing_count
FROM shops s
LEFT JOIN reviews r ON r.shop_id = s.id
GROUP BY s.id, s.name, s.city;

-- Katalog pembeli: barang yang tampil (aktif, dipesan, terjual)
CREATE VIEW v_catalog AS
SELECT p.id, p.title, p.price, p.size_label, p.size_type, p.color, p.gender,
       p.condition_score, p.status, p.published_at,
       c.id AS category_id, c.name AS category_name, pc.name AS parent_category_name,
       b.name AS brand_name,
       s.id AS shop_id, s.name AS shop_name, s.city AS shop_city,
       (SELECT COUNT(*) FROM product_defects d WHERE d.product_id = p.id) AS defect_count,
       (SELECT ph.path FROM product_photos ph
         WHERE ph.product_id = p.id ORDER BY ph.sort_order, ph.id LIMIT 1) AS main_photo
FROM products p
JOIN categories c      ON c.id = p.category_id
LEFT JOIN categories pc ON pc.id = c.parent_id
LEFT JOIN brands b     ON b.id = p.brand_id
JOIN shops s           ON s.id = p.shop_id
WHERE p.status IN ('active','reserved','sold');

-- Daftar pengguna admin: peran, jumlah transaksi, catatan negatif
CREATE VIEW v_user_admin_list AS
SELECT u.id, u.name, u.email, u.status, u.block_reason,
       CASE WHEN u.role = 'admin' THEN 'Admin'
            WHEN u.is_seller = 1  THEN 'Pembeli + Penjual'
            ELSE 'Pembeli' END AS role_label,
       (SELECT COUNT(*) FROM orders o WHERE o.buyer_id = u.id AND o.status = 'completed')
       + COALESCE((SELECT COUNT(*) FROM orders o JOIN shops s ON s.id = o.shop_id
                    WHERE s.user_id = u.id AND o.status = 'completed'), 0) AS transaction_count,
       (SELECT COUNT(*) FROM negative_records n WHERE n.user_id = u.id) AS negative_count
FROM users u;

-- Angka dashboard admin
CREATE VIEW v_admin_dashboard AS
SELECT
  (SELECT COUNT(*) FROM products WHERE status = 'pending_review')                 AS listing_pending,
  (SELECT COUNT(*) FROM complaints WHERE status <> 'resolved')                    AS complaint_open,
  (SELECT COUNT(*) FROM orders WHERE status IN ('paid','processing','shipped'))   AS active_orders,
  (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS new_users_week,
  (SELECT COUNT(*) FROM products WHERE status = 'active')                         AS active_listings,
  (SELECT COUNT(*) FROM orders WHERE status = 'cancelled'
      AND cancelled_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))                        AS cancelled_week;

-- =====================================================================
-- 11. DATA MASTER
-- =====================================================================

INSERT INTO system_settings (setting_key, value, description) VALUES
 ('payment_timeout_minutes', '30',   'Batas bayar setelah checkout, lalu pesanan batal'),
 ('ship_deadline_hours',     '48',   'Batas kirim penjual setelah dibayar (2x24 jam)'),
 ('complaint_window_hours',  '48',   'Batas pembeli komplain setelah barang diterima'),
 ('seller_reply_hours',      '48',   'Batas penjual menjawab komplain'),
 ('auto_complete_hours',     '48',   'Pesanan selesai otomatis setelah barang diterima'),
 ('commission_percent',      '5',    'Komisi platform dari harga barang'),
 ('service_fee',             '3000', 'Biaya layanan per checkout (rupiah)'),
 ('size_tolerance_cm',       '2',    'Toleransi selisih ukuran untuk komplain'),
 ('min_photos',              '4',    'Jumlah minimal foto per listing'),
 ('min_condition_score',     '6',    'Skor kondisi terendah yang boleh dijual');

INSERT INTO condition_levels (score, name, description, is_sellable) VALUES
 (10, 'Seperti baru',        'Nyaris tanpa tanda pemakaian', 1),
 (9,  'Sangat baik',         'Tanda pakai sangat tipis', 1),
 (8,  'Baik',                'Pemakaian ringan, tidak mengganggu', 1),
 (7,  'Layak',               'Ada tanda pakai yang terlihat, masih rapi', 1),
 (6,  'Ada kekurangan nyata','Cacat jelas, wajib difoto close-up', 1),
 (5,  'Tidak boleh dijual',  'Di bawah batas jual', 0),
 (4,  'Tidak boleh dijual',  'Di bawah batas jual', 0),
 (3,  'Tidak boleh dijual',  'Di bawah batas jual', 0),
 (2,  'Tidak boleh dijual',  'Di bawah batas jual', 0),
 (1,  'Tidak boleh dijual',  'Di bawah batas jual', 0);

-- Kategori utama (id 1-6) dan subkategori (id 7 dst)
INSERT INTO categories (id, parent_id, name, slug, sort_order, is_active, is_new) VALUES
 (1, NULL, 'Atasan',    'atasan',    1, 1, 0),
 (2, NULL, 'Bawahan',   'bawahan',   2, 1, 0),
 (3, NULL, 'Outerwear', 'outerwear', 3, 1, 0),
 (4, NULL, 'Sepatu',    'sepatu',    4, 1, 0),
 (5, NULL, 'Aksesoris', 'aksesoris', 5, 1, 0),
 (6, NULL, 'Terusan',   'terusan',   6, 1, 1),
 (7,  1, 'Kaos',         'kaos',         1, 1, 0),
 (8,  1, 'Kemeja',       'kemeja',       2, 1, 0),
 (9,  1, 'Sweater',      'sweater',      3, 1, 0),
 (10, 2, 'Jeans',        'jeans',        1, 1, 0),
 (11, 2, 'Celana kain',  'celana-kain',  2, 1, 0),
 (12, 2, 'Rok',          'rok',          3, 1, 0),
 (13, 3, 'Jaket',        'jaket',        1, 1, 0),
 (14, 3, 'Hoodie',       'hoodie',       2, 1, 0),
 (15, 3, 'Blazer',       'blazer',       3, 1, 0),
 (16, 3, 'Cardigan',     'cardigan',     4, 1, 0),
 (17, 4, 'Sneakers',     'sneakers',     1, 1, 0),
 (18, 4, 'Formal',       'formal',       2, 1, 0),
 (19, 4, 'Sandal',       'sandal',       3, 1, 0),
 (20, 5, 'Tas',          'tas',          1, 1, 0),
 (21, 5, 'Topi',         'topi',         2, 1, 0),
 (22, 5, 'Ikat pinggang','ikat-pinggang',3, 1, 0),
 (23, 6, 'Dress',        'dress',        1, 1, 0),
 (24, 6, 'Jumpsuit',     'jumpsuit',     2, 1, 0);

INSERT INTO brands (id, name) VALUES
 (1, 'Westbay'), (2, 'Northline'), (3, 'Harbor');

INSERT INTO defect_tags (id, name, sort_order) VALUES
 (1, 'Noda', 1), (2, 'Pudar', 2), (3, 'Pilling atau berbulu', 3), (4, 'Benang longgar', 4),
 (5, 'Bolong kecil', 5), (6, 'Kancing atau resleting bermasalah', 6), (7, 'Bau apak', 7),
 (8, 'Luntur', 8), (9, 'Aus', 9), (10, 'Bekas lipatan', 10);

INSERT INTO measurement_types (id, code, name, unit) VALUES
 (1, 'chest_width',   'Lebar dada',          'cm'),
 (2, 'body_length',   'Panjang badan',       'cm'),
 (3, 'sleeve_length', 'Panjang lengan',      'cm'),
 (4, 'shoulder_width','Lebar bahu',          'cm'),
 (5, 'waist',         'Lingkar pinggang',    'cm'),
 (6, 'inseam',        'Panjang dalam',       'cm'),
 (7, 'total_length',  'Panjang total',       'cm'),
 (8, 'insole_length', 'Panjang insole',      'cm');

INSERT INTO category_measurements (category_id, measurement_type_id, sort_order, is_required) VALUES
 (1,1,1,1),(1,2,2,1),(1,3,3,1),(1,4,4,1),
 (3,1,1,1),(3,2,2,1),(3,3,3,1),(3,4,4,1),
 (6,1,1,1),(6,2,2,1),(6,3,3,0),(6,4,4,0),
 (2,5,1,1),(2,6,2,1),(2,7,3,1),
 (4,8,1,1);

INSERT INTO verification_rules (id, label, sort_order) VALUES
 (1, 'Minimal 4 foto', 1),
 (2, 'Foto label terbaca', 2),
 (3, 'Tiap tag cacat punya foto close-up', 3),
 (4, 'Ukuran asli diisi', 4),
 (5, 'Skor kondisi wajar untuk foto yang ada', 5),
 (6, 'Bukan barang terlarang (pakaian dalam bekas, tiruan)', 6),
 (7, 'Harga masuk akal untuk kategori dan kondisi', 7);

INSERT INTO rejection_reasons (id, label, sort_order) VALUES
 (1, 'Foto close-up cacat kurang', 1),
 (2, 'Foto label tidak terbaca', 2),
 (3, 'Skor kondisi terlalu tinggi', 3),
 (4, 'Harga tidak wajar', 4),
 (5, 'Barang terlarang', 5),
 (6, 'Ukuran asli belum diisi', 6);

INSERT INTO prohibited_items (name, sort_order) VALUES
 ('Pakaian dalam bekas', 1),
 ('Barang tiruan atau palsu', 2),
 ('Barang rusak berat atau tidak layak pakai', 3),
 ('Barang curian atau tanpa kepemilikan jelas', 4),
 ('Barang berbahaya atau ilegal', 5);

INSERT INTO couriers (id, code, name) VALUES
 (1, 'jne_reg',   'JNE Reguler'),
 (2, 'jnt_exp',   'J&T Express'),
 (3, 'sicepat_halu', 'SiCepat Halu');

INSERT INTO payment_methods (id, code, group_type, name, sort_order) VALUES
 (1,  'va_bca',     'virtual_account', 'Virtual account BCA',     1),
 (2,  'va_bni',     'virtual_account', 'Virtual account BNI',     2),
 (3,  'va_bri',     'virtual_account', 'Virtual account BRI',     3),
 (4,  'va_mandiri', 'virtual_account', 'Virtual account Mandiri', 4),
 (5,  'qris',       'qris',            'QRIS',                    5),
 (6,  'gopay',      'ewallet',         'GoPay',                   6),
 (7,  'ovo',        'ewallet',         'OVO',                     7),
 (8,  'dana',       'ewallet',         'DANA',                    8),
 (9,  'card',       'card',            'Kartu debit atau kredit', 9);

-- =====================================================================
-- 12. DATA CONTOH (diambil dari isi desain, semua fiktif)
-- Kata sandi semua akun: "password"
-- =====================================================================

INSERT INTO users (id, name, email, phone, password_hash, role, is_seller, status, block_reason, terms_accepted_at, created_at) VALUES
 (1, 'Admin Moderator', 'admin@contoh.id', '081200000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 0, 'active', NULL, '2026-08-01 08:00:00', '2026-08-01 08:00:00'),
 (2, 'Rani',  'rani@contoh.id',  '081234561234', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 0, 'active', NULL, '2026-10-01 09:00:00', '2026-10-01 09:00:00'),
 (3, 'Dimas', 'dimas@contoh.id', '081234560003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1, 'active', NULL, '2026-08-05 10:00:00', '2026-08-05 10:00:00'),
 (4, 'Sari',  'sari@contoh.id',  '081234560004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1, 'active', NULL, '2026-08-10 10:00:00', '2026-08-10 10:00:00'),
 (5, 'Bayu',  'bayu@contoh.id',  '081234560005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1, 'active', NULL, '2026-08-12 10:00:00', '2026-08-12 10:00:00'),
 (6, 'Rini',  'rini@contoh.id',  '081234560006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user', 1, 'blocked', '3 kali kirim barang tidak sesuai listing', '2026-08-15 10:00:00', '2026-08-15 10:00:00');

UPDATE users SET blocked_by = 1, blocked_at = '2026-10-05 11:00:00' WHERE id = 6;

INSERT INTO addresses (id, user_id, label, recipient_name, phone, street, district, city, province, postal_code, is_default) VALUES
 (1, 2, 'Rumah',  'Rani', '081234561234', 'Jl. Contoh No. 12', 'Panakkukang', 'Makassar', 'Sulawesi Selatan', '90231', 1),
 (2, 2, 'Kampus', 'Rani', '081234561234', 'Jl. Contoh Raya No. 3', 'Tamalanrea', 'Makassar', 'Sulawesi Selatan', '90245', 0);

INSERT INTO shops (id, user_id, name, slug, description, city, rating_avg, review_count, sold_count, avg_response_minutes, created_at) VALUES
 (1, 3, 'Toko Dimas Thrift', 'toko-dimas-thrift', 'Thrift pilihan dari Bandung, kondisi dijelaskan apa adanya.', 'Bandung', 4.9, 48, 63, 180, '2026-08-05 10:30:00'),
 (2, 4, 'Toko Sari',  'toko-sari',  'Preloved rapi dari Jakarta.', 'Jakarta', 4.8, 20, 41, 240, '2026-08-10 10:30:00'),
 (3, 5, 'Toko Bayu',  'toko-bayu',  'Sneakers dan outerwear.', 'Yogyakarta', 4.6, 12, 28, 300, '2026-08-12 10:30:00'),
 (4, 6, 'Toko Rini',  'toko-rini',  'Atasan dan celana.', 'Medan', 3.9, 9, 17, 600, '2026-08-15 10:30:00');

INSERT INTO bank_accounts (id, shop_id, bank_name, account_number, account_holder, is_default) VALUES
 (1, 1, 'BCA', '1234561234', 'Dimas', 1);

INSERT INTO newsletter_subscribers (email, user_id) VALUES ('rani@contoh.id', 2);

-- Barang contoh
INSERT INTO products (id, shop_id, category_id, brand_id, title, description, material, color, gender, size_type, size_label, price, weight_gram, seller_condition_score, condition_score, status, reserved_until, submitted_at, published_at, sold_at, created_at) VALUES
 (1, 1, 13, 1, 'Westbay Denim Jacket', 'Jaket denim dengan efek wash alami. Ada fading pada lengan dan bekas lipatan pemakaian. Sudah dicuci dan dikemas dalam plastik sebelum dikirim.', 'Denim katun', 'Biru medium', 'unisex', 'letter', 'M', 140000, 800, 7, 7, 'active', NULL, '2026-10-04 08:00:00', '2026-10-04 10:00:00', NULL, '2026-10-04 07:30:00'),
 (2, 1, 8, 3, 'Harbor Oxford Shirt', 'Kemeja oxford rapi, hampir tanpa tanda pakai.', 'Katun oxford', 'Putih', 'male', 'letter', 'M', 85000, 400, 9, 9, 'active', NULL, '2026-10-02 08:00:00', '2026-10-02 09:00:00', NULL, '2026-10-02 07:30:00'),
 (3, 1, 10, NULL, 'Stonewash Straight Jeans', 'Jeans straight stonewash dengan fading natural.', 'Denim', 'Biru', 'male', 'number', '32', 125000, 700, 8, 8, 'reserved', '2026-10-25 12:30:00', '2026-10-01 08:00:00', '2026-10-01 09:30:00', NULL, '2026-10-01 07:30:00'),
 (4, 1, 16, NULL, 'Cardigan Rajut Krem', 'Cardigan rajut warna krem.', 'Rajut', 'Krem', 'female', 'letter', 'M', 70000, 450, 9, 9, 'active', NULL, '2026-10-03 08:00:00', '2026-10-03 09:00:00', NULL, '2026-10-03 07:30:00'),
 (5, 2, 14, NULL, 'Basic Zip Hoodie', 'Hoodie zip polos.', 'Fleece katun', 'Abu-abu', 'unisex', 'letter', 'L', 120000, 600, 8, 8, 'active', NULL, '2026-10-02 12:00:00', '2026-10-02 13:00:00', NULL, '2026-10-02 11:30:00'),
 (6, 3, 17, NULL, 'Mono Court Sneakers', 'Sneakers court hitam putih, bekas pakai di bagian depan.', 'Kulit sintetis', 'Hitam putih', 'unisex', 'shoe', '41', 150000, 900, 7, 7, 'reserved', '2026-10-25 12:45:00', '2026-10-03 12:00:00', '2026-10-03 13:00:00', NULL, '2026-10-03 11:30:00'),
 (7, 4, 11, NULL, 'Utility Cargo Pants', 'Celana cargo, ada noda kecil dan pudar.', 'Katun twill', 'Hijau army', 'male', 'number', '30', 110000, 650, 6, 6, 'rejected', NULL, '2026-10-09 07:00:00', NULL, NULL, '2026-10-09 06:30:00'),
 (8, 1, 23, NULL, 'Dress Floral Midi', 'Dress floral panjang midi.', 'Rayon', 'Merah muda', 'female', 'letter', 'S', 95000, 350, 9, 9, 'pending_review', NULL, '2026-10-09 09:00:00', NULL, NULL, '2026-10-09 08:30:00'),
 (9, 1, 12, NULL, 'Rok Plisket Hitam', 'Rok plisket hitam (draft, foto belum lengkap).', 'Poliester', 'Hitam', 'female', 'letter', 'M', 60000, 300, 8, 8, 'draft', NULL, NULL, NULL, NULL, '2026-10-09 09:30:00'),
 (10, 2, 7, 2, 'Northline Vintage Tee', 'Kaos vintage Northline.', 'Katun', 'Krem', 'unisex', 'letter', 'L', 65000, 250, 8, 8, 'sold', NULL, '2026-09-20 08:00:00', '2026-09-20 09:00:00', '2026-10-01 15:00:00', '2026-09-20 07:30:00');

INSERT INTO product_photos (id, product_id, path, type, sort_order) VALUES
 (1, 1, 'img/product-01.jpg', 'front', 1),
 (2, 1, 'img/detail-a.jpg',   'back',  2),
 (3, 1, 'img/detail-b.jpg',   'label', 3),
 (4, 1, 'img/detail-c.jpg',   'defect',4),
 (5, 1, 'img/detail-d.jpg',   'defect',5),
 (6, 2, 'img/product-02.jpg', 'front', 1),
 (7, 3, 'img/product-03.jpg', 'front', 1),
 (8, 4, 'img/product-04.jpg', 'front', 1),
 (9, 5, 'img/product-05.jpg', 'front', 1),
 (10, 6, 'img/product-06.jpg', 'front', 1);

INSERT INTO product_measurements (product_id, measurement_type_id, value_cm) VALUES
 (1, 1, 56.0), (1, 2, 66.0), (1, 3, 62.0), (1, 4, 46.0),
 (2, 1, 54.0), (2, 2, 72.0), (2, 3, 63.0), (2, 4, 45.0),
 (3, 5, 84.0), (3, 6, 76.0), (3, 7, 102.0);

INSERT INTO product_defects (product_id, defect_tag_id, photo_id, note) VALUES
 (1, 2, 4, 'Pudar di kedua lengan, terlihat jelas dari jarak dekat.'),
 (1, 10, NULL, 'Bekas lipatan pemakaian.'),
 (3, 2, NULL, 'Fading natural.');

-- Riwayat verifikasi: barang 1 disetujui, barang 7 ditolak
INSERT INTO verifications (id, product_id, admin_id, decision, adjusted_score, rejection_reason_id, note, created_at) VALUES
 (1, 1, 1, 'approved', NULL, NULL, NULL, '2026-10-04 10:00:00'),
 (2, 7, 1, 'rejected', NULL, 2, 'Foto label tidak terbaca.', '2026-10-09 09:00:00');

INSERT INTO verification_checks (verification_id, rule_id, is_passed, note) VALUES
 (1,1,1,NULL),(1,2,1,NULL),(1,3,1,NULL),(1,4,1,NULL),(1,5,1,NULL),(1,6,1,NULL),(1,7,1,NULL),
 (2,1,1,NULL),(2,2,0,'Label tidak terbaca'),(2,3,1,NULL),(2,4,1,NULL),(2,5,1,NULL),(2,6,1,NULL),(2,7,1,NULL);

-- Keranjang dan favorit Rani
INSERT INTO cart_items (user_id, product_id) VALUES (2, 4), (2, 5);
INSERT INTO favorites (user_id, product_id) VALUES (2, 1), (2, 6), (2, 10);

-- Pesanan #TA-2041: selesai dibayar, diterima, lalu dikomplain (contoh alur penuh)
INSERT INTO checkouts (id, buyer_id, address_id, items_total, shipping_total, service_fee, grand_total, status, payment_expires_at, created_at) VALUES
 (1, 2, 1, 225000, 18000, 3000, 246000, 'paid', '2026-10-06 14:32:00', '2026-10-06 14:02:00');

INSERT INTO payments (id, checkout_id, payment_method_id, gateway_ref, va_number, amount, status, is_sandbox, expires_at, paid_at, created_at) VALUES
 (1, 1, 1, 'SBX-0001', '8808120034567890', 246000, 'paid', 1, '2026-10-06 14:32:00', '2026-10-06 14:11:00', '2026-10-06 14:02:00');

INSERT INTO orders (id, order_number, checkout_id, buyer_id, shop_id, recipient_name, phone, address_text, destination_city, courier_id, courier_name, shipping_etd, total_weight_gram, items_total, shipping_cost, commission_rate, commission, seller_net, total, status, payment_expires_at, paid_at, ship_deadline_at, processing_at, shipped_at, tracking_number, estimated_arrival_date, delivered_at, complaint_deadline_at, created_at) VALUES
 (1, 'TA-2041', 1, 2, 1, 'Rani', '081234561234', 'Jl. Contoh No. 12, Panakkukang, Makassar, Sulawesi Selatan 90231', 'Makassar', 1, 'JNE Reguler', '3-4 hari', 1200, 225000, 18000, 5.00, 11250, 213750, 243000, 'complained', '2026-10-06 14:32:00', '2026-10-06 14:11:00', '2026-10-08 14:11:00', '2026-10-06 17:40:00', '2026-10-07 09:15:00', 'JNE0000007745', '2026-10-10', '2026-10-09 14:00:00', '2026-10-11 14:00:00', '2026-10-06 14:02:00');

INSERT INTO order_items (id, order_id, product_id, title_snapshot, price_snapshot, size_snapshot, condition_snapshot) VALUES
 (1, 1, 1, 'Westbay Denim Jacket', 140000, 'M', 7),
 (2, 1, 2, 'Harbor Oxford Shirt',   85000, 'M', 9);

INSERT INTO order_status_logs (order_id, status, note, actor_id, created_at) VALUES
 (1, 'awaiting_payment', 'Pesanan dibuat. Barang ditahan 30 menit', 2, '2026-10-06 14:02:00'),
 (1, 'paid',             'Dibayar. Dana ditahan platform', NULL, '2026-10-06 14:11:00'),
 (1, 'processing',       'Diproses penjual. Dikemas dalam plastik', 3, '2026-10-06 17:40:00'),
 (1, 'shipped',          'Dikirim. JNE Reguler, resi JNE0000007745', 3, '2026-10-07 09:15:00'),
 (1, 'delivered',        'Diterima. Cek barang dan ukurannya', NULL, '2026-10-09 14:00:00'),
 (1, 'complained',       'Pembeli mengajukan komplain', 2, '2026-10-10 09:05:00');

INSERT INTO wallet_transactions (shop_id, order_id, type, bucket, amount, note, created_at) VALUES
 (1, 1, 'hold',       'held',  225000, 'Dana pesanan TA-2041 ditahan', '2026-10-06 14:11:00'),
 (1, 1, 'commission', 'held',  -11250, 'Komisi 5% TA-2041',            '2026-10-06 14:11:00'),
 (1, NULL, 'release', 'available', 1245000, 'Saldo awal contoh (dana cair pesanan lama)', '2026-10-08 10:00:00');

INSERT INTO complaints (id, order_id, order_item_id, buyer_id, reason, description, seller_stance, seller_response, seller_deadline_at, seller_replied_at, status, decision, decision_deadline_at, created_at) VALUES
 (1, 1, 1, 2, 'size_mismatch', 'Label M, tapi lebar dada hanya 52 cm. Di listing tertulis 56 cm. Tidak muat.', 'dispute', 'Sudah saya ukur ulang sebelum kirim, hasilnya 56 cm. Mungkin cara ukur pembeli beda.', '2026-10-12 09:05:00', '2026-10-10 10:30:00', 'needs_decision', 'pending', '2026-10-11 09:05:00', '2026-10-10 09:05:00');

INSERT INTO complaint_evidence (complaint_id, uploaded_by, side, media_type, path, caption) VALUES
 (1, 2, 'buyer',  'photo', 'img/bukti-1.jpg', 'Foto meteran lebar dada'),
 (1, 2, 'buyer',  'photo', 'img/bukti-2.jpg', 'Label'),
 (1, 3, 'seller', 'photo', 'img/detail-a.jpg','Foto listing asli'),
 (1, 3, 'seller', 'photo', 'img/detail-b.jpg','Foto label');

INSERT INTO negative_records (user_id, reason, note, created_by) VALUES
 (3, 'item_not_as_listed', 'Contoh catatan negatif', 1),
 (6, 'item_not_as_listed', 'Barang tidak sesuai listing (1)', 1),
 (6, 'item_not_as_listed', 'Barang tidak sesuai listing (2)', 1),
 (6, 'item_not_as_listed', 'Barang tidak sesuai listing (3)', 1),
 (6, 'late_shipping',      'Tidak kirim dalam 2x24 jam', NULL),
 (6, 'late_shipping',      'Tidak kirim dalam 2x24 jam', NULL);

INSERT INTO notifications (user_id, type, title, body, related_type, related_id) VALUES
 (3, 'order_paid',       'Pesanan baru dibayar', 'Pesanan TA-2041 sudah dibayar. Kirim dalam 2x24 jam.', 'order', 1),
 (3, 'listing_rejected', 'Listing ditolak',      'Utility Cargo Pants: foto label tidak terbaca.', 'product', 7),
 (2, 'order_shipped',    'Pesanan dikirim',      'Pesanan TA-2041 dikirim via JNE Reguler.', 'order', 1);

-- =====================================================================
-- 13. TUGAS TERJADWAL (OPSIONAL)
-- Aturan otomatis (batal 30 menit, batal 2x24 jam, auto-selesai) paling
-- aman dijalankan oleh aplikasi/cron. Contoh event MySQL di bawah dinonaktifkan;
-- aktifkan dengan: SET GLOBAL event_scheduler = ON; lalu hapus tanda komentar.
-- =====================================================================
-- CREATE EVENT ev_expire_unpaid ON SCHEDULE EVERY 1 MINUTE DO
--   UPDATE orders SET status = 'expired', cancel_reason = 'payment_timeout', cancelled_at = NOW()
--   WHERE status = 'awaiting_payment' AND payment_expires_at < NOW();
