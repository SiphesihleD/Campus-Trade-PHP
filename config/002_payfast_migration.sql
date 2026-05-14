-- ================================================================
--  CAMPUS TRADE — migrations/002_payfast.sql
--  Run this migration to add PayFast support to your database.
-- ================================================================

-- 1. Add pf_payment_id column to store the PayFast transaction ID
ALTER TABLE orders
    ADD COLUMN pf_payment_id VARCHAR(128) NOT NULL DEFAULT ''
        COMMENT 'PayFast pf_payment_id from ITN'
        AFTER eft_reference;

-- 2. Expand payment_method ENUM to include 'payfast'
ALTER TABLE orders
    MODIFY COLUMN payment_method
        ENUM('cash', 'eft', 'payfast') NOT NULL
        COMMENT 'cash = on-campus meetup, eft = manual bank transfer, payfast = online card/EFT';

-- 3. (Optional) Index to quickly look up orders by PayFast payment ID
ALTER TABLE orders
    ADD INDEX idx_pf_payment (pf_payment_id);

-- 4. Add sold_at column to listings if not already present
ALTER TABLE listings
    ADD COLUMN IF NOT EXISTS sold_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When the listing was marked sold';
