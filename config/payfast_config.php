<?php
/* ================================================================
   CAMPUS TRADE — config/payfast_config.php
   PayFast Sandbox credentials and URLs.
   ================================================================ */

define('PF_SANDBOX', true);

// PayFast public sandbox test credentials
define('PF_MERCHANT_ID',  '10000100');
define('PF_MERCHANT_KEY', '46f0cd694581a');
define('PF_PASSPHRASE',   'jt7NOE43FZPn');

// PayFast endpoints
define('PF_PAYMENT_URL',
    PF_SANDBOX
        ? 'https://sandbox.payfast.co.za/eng/process'
        : 'https://www.payfast.co.za/eng/process'
);

// InfinityFree public URL — PHP backend lives here
define('APP_BASE_URL',  'https://campus-trade-php.onrender.com');

define('PF_RETURN_URL',  APP_BASE_URL . '/pages/checkout.html');
define('PF_CANCEL_URL',  APP_BASE_URL . '/pages/checkout.html?cancelled=1');
define('PF_NOTIFY_URL',  APP_BASE_URL . '/php/api/payfast.php?action=itn');
