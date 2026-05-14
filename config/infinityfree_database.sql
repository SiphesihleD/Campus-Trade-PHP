-- ================================================================
--  CAMPUS TRADE — infinityfree_database.sql
--  Import this file in phpMyAdmin with if0_41889082_campustrade selected
--  DO NOT run CREATE DATABASE or USE — InfinityFree handles that
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── USERS ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    firebase_uid  VARCHAR(128)    NOT NULL UNIQUE,
    email         VARCHAR(255)    NOT NULL UNIQUE,
    display_name  VARCHAR(100)    NOT NULL,
    role          ENUM('student','admin') NOT NULL DEFAULT 'student',
    campus        VARCHAR(100)    NOT NULL DEFAULT '',
    password_hash VARCHAR(255)    NOT NULL DEFAULT '',
    rating        DECIMAL(3,2)    NOT NULL DEFAULT 0.00,
    rating_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_sales   INT UNSIGNED    NOT NULL DEFAULT 0,
    avatar_url    VARCHAR(500)    NOT NULL DEFAULT '',
    fcm_token     VARCHAR(500)    NOT NULL DEFAULT '',
    is_banned     TINYINT(1)      NOT NULL DEFAULT 0,
    is_verified   TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_email    (email),
    INDEX idx_firebase (firebase_uid),
    INDEX idx_role     (role),
    INDEX idx_campus   (campus)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── LISTINGS ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listings (
    id             INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    firebase_id    VARCHAR(128)   NOT NULL DEFAULT '',
    seller_id      INT UNSIGNED   NOT NULL,
    title          VARCHAR(200)   NOT NULL,
    description    TEXT           NOT NULL,
    price          DECIMAL(10,2)  NOT NULL,
    category       ENUM('Textbooks','Electronics','Furniture','Clothing','Gaming','Stationery','Sports','Other')
                                  NOT NULL DEFAULT 'Other',
    condition_type ENUM('New','Like New','Used') NOT NULL DEFAULT 'Used',
    campus         VARCHAR(100)   NOT NULL DEFAULT '',
    status         ENUM('active','sold','deleted') NOT NULL DEFAULT 'active',
    sold_at        TIMESTAMP      NULL DEFAULT NULL,
    views          INT UNSIGNED   NOT NULL DEFAULT 0,
    is_featured    TINYINT(1)     NOT NULL DEFAULT 0,
    created_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status   (status),
    INDEX idx_category (category),
    INDEX idx_campus   (campus),
    INDEX idx_price    (price),
    INDEX idx_seller   (seller_id),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── LISTING IMAGES ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS listing_images (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id INT UNSIGNED NOT NULL,
    image_url  VARCHAR(500) NOT NULL,
    sort_order TINYINT      NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ORDERS ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS orders (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    firebase_id     VARCHAR(128)   NOT NULL DEFAULT '',
    buyer_id        INT UNSIGNED   NOT NULL,
    seller_id       INT UNSIGNED   NOT NULL,
    listing_id      INT UNSIGNED   NOT NULL,
    subtotal        DECIMAL(10,2)  NOT NULL,
    platform_fee    DECIMAL(10,2)  NOT NULL,
    seller_gets     DECIMAL(10,2)  NOT NULL,
    payment_method  ENUM('cash','eft','payfast') NOT NULL,
    meetup_location VARCHAR(100)   NOT NULL DEFAULT '',
    eft_reference   VARCHAR(100)   NOT NULL DEFAULT '',
    pf_payment_id   VARCHAR(128)   NOT NULL DEFAULT '',
    note            TEXT           NOT NULL DEFAULT '',
    status          ENUM('pending','confirmed','completed','disputed','cancelled')
                                   NOT NULL DEFAULT 'pending',
    rated           TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (buyer_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (seller_id)  REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_buyer      (buyer_id),
    INDEX idx_seller     (seller_id),
    INDEX idx_status     (status),
    INDEX idx_pf_payment (pf_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── RATINGS ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ratings (
    id         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    order_id   INT UNSIGNED     NOT NULL,
    seller_id  INT UNSIGNED     NOT NULL,
    buyer_id   INT UNSIGNED     NOT NULL,
    rating     TINYINT UNSIGNED NOT NULL,
    comment    TEXT             NOT NULL DEFAULT '',
    created_at TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_rating (order_id),
    FOREIGN KEY (order_id)  REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (buyer_id)  REFERENCES users(id)  ON DELETE CASCADE,
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MESSAGES ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chat_id    VARCHAR(255) NOT NULL,
    listing_id INT UNSIGNED NOT NULL,
    from_user  INT UNSIGNED NOT NULL,
    to_user    INT UNSIGNED NOT NULL,
    message    TEXT         NOT NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (from_user)  REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (to_user)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_chat    (chat_id),
    INDEX idx_to_user (to_user),
    INDEX idx_listing (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── NOTIFICATIONS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED  NOT NULL,
    type       ENUM('sale','message','rating','price_drop','system') NOT NULL DEFAULT 'system',
    title      VARCHAR(200)  NOT NULL,
    message    TEXT          NOT NULL DEFAULT '',
    is_read    TINYINT(1)    NOT NULL DEFAULT 0,
    link_url   VARCHAR(500)  NOT NULL DEFAULT '',
    created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user   (user_id),
    INDEX idx_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REPORTS ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_id   INT UNSIGNED NOT NULL,
    reported_type ENUM('listing','user') NOT NULL,
    reported_id   INT UNSIGNED NOT NULL,
    reason        ENUM('scam','inappropriate','wrong_category','spam','other') NOT NULL,
    description   TEXT         NOT NULL DEFAULT '',
    status        ENUM('open','reviewed','resolved') NOT NULL DEFAULT 'open',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_type   (reported_type, reported_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WISHLIST ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wishlist (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    listing_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wishlist (user_id, listing_id),
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
