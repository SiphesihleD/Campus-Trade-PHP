<?php
/* ================================================================
   CAMPUS TRADE — api/payfast.php  (PHP 7.4 compatible)
   ================================================================ */

@header('Access-Control-Allow-Origin: *');
@header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
@header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payfast_config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'initiate') {
    initiatePayment();
} elseif ($action === 'itn') {
    handleITN();
} elseif ($action === 'status') {
    getPaymentStatus();
} else {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(array('success' => false, 'message' => 'Unknown action'));
    exit();
}

/* ----------------------------------------------------------------
   1. INITIATE
   ---------------------------------------------------------------- */
function initiatePayment() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        showErrorPage('POST required.');
        return;
    }

    $listingId     = (int)(isset($_POST['listingId'])      ? $_POST['listingId']      : 0);
    $pm            = pf_sanitize(isset($_POST['paymentMethod'])  ? $_POST['paymentMethod']  : '');
    $meetup        = pf_sanitize(isset($_POST['meetupLocation']) ? $_POST['meetupLocation'] : '');
    $note          = pf_sanitize(isset($_POST['note'])           ? $_POST['note']           : '');
    $firebaseToken = trim(isset($_POST['firebaseToken'])         ? $_POST['firebaseToken']   : '');

    if (!$listingId)       { showErrorPage('Listing ID required.');            return; }
    if ($pm !== 'payfast') { showErrorPage('Invalid payment method.');         return; }
    if (!$firebaseToken)   { showErrorPage('Not authenticated. Please log in again.'); return; }

    // Verify Firebase ID token
    $fbUrl  = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=AIzaSyB7kNXwaXbrsa9M_N6jVNdOGs70EGqdFNQ';
    $ctx    = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => json_encode(array('idToken' => $firebaseToken)),
        'timeout'       => 10,
        'ignore_errors' => true
    )));
    $fbResp = @file_get_contents($fbUrl, false, $ctx);

    if (!$fbResp) { showErrorPage('Could not verify identity. Please try again.'); return; }
    $fbData = json_decode($fbResp, true);
    if (empty($fbData['users'][0])) { showErrorPage('Invalid session. Please log in again.'); return; }

    $fbUser      = $fbData['users'][0];
    $firebaseUid = $fbUser['localId'];
    $email       = isset($fbUser['email']) ? $fbUser['email'] : '';
    $displayName = isset($fbUser['displayName']) ? $fbUser['displayName'] : explode('@', $email)[0];

    // Get or create MySQL user
    $db   = getDB();
    $user = $db->fetchOne('SELECT * FROM users WHERE firebase_uid = ?', 's', $firebaseUid);
    if (!$user) {
        $uid  = $db->insert(
            'INSERT INTO users (firebase_uid, email, display_name, role) VALUES (?, ?, ?, "student")',
            'sss', $firebaseUid, $email, $displayName
        );
        $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', 'i', $uid);
    }

    // Load listing
    $listing = $db->fetchOne(
        'SELECT id, title, price, seller_id FROM listings WHERE id = ? AND status = "active" LIMIT 1',
        'i', $listingId
    );
    if (!$listing) { showErrorPage('Listing not found or already sold.'); return; }

    $subtotal   = (float)$listing['price'];
    $fee        = round($subtotal * 0.15, 2);
    $sellerGets = round($subtotal * 0.85, 2);
    $total      = round($subtotal + $fee, 2);

    // Create pending order
    try {
        $sellerId = (int)$listing['seller_id'];
        $buyerId  = (int)$user['id'];
        $orderId  = $db->insert(
            'INSERT INTO orders (buyer_id, seller_id, listing_id, subtotal, platform_fee, seller_gets,
             payment_method, meetup_location, note, status)
             VALUES (?, ?, ?, ?, ?, ?, "payfast", ?, ?, "pending")',
            'iiidddss', $buyerId, $sellerId, (int)$listing['id'],
            $subtotal, $fee, $sellerGets, $meetup, $note
        );
    } catch (Exception $e) {
        showErrorPage('Could not create order: ' . $e->getMessage());
        return;
    }

    // Build PayFast data
    $nameParts = explode(' ', trim($displayName));
    $firstName = isset($nameParts[0]) ? $nameParts[0] : 'Campus';
    $lastName  = isset($nameParts[1]) ? $nameParts[1] : 'Student';

    $data = array(
        'merchant_id'         => PF_MERCHANT_ID,
        'merchant_key'        => PF_MERCHANT_KEY,
        'return_url'          => PF_RETURN_URL  . '?order_id=' . $orderId,
        'cancel_url'          => PF_CANCEL_URL  . '?order_id=' . $orderId,
        'notify_url'          => PF_NOTIFY_URL,
        'name_first'          => $firstName,
        'name_last'           => $lastName,
        'email_address'       => $email,
        'merchant_payment_id' => 'CT-' . $orderId,
        'amount'              => number_format($total, 2, '.', ''),
        'item_name'           => substr($listing['title'], 0, 100),
        'item_description'    => 'Campus Trade order #' . $orderId,
        'custom_int1'         => $orderId,
        'custom_str1'         => (string)$user['id'],
    );
    $data['signature'] = generatePFSignature($data);

    // Output auto-submitting HTML
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8">
<title>Redirecting to PayFast...</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:system-ui,sans-serif;background:#f0f4ff;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.card{background:#fff;border-radius:16px;padding:2.5rem 2rem;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.1);max-width:360px;width:90%;}
.logo{font-size:2rem;margin-bottom:.5rem;}
h2{color:#1a237e;font-size:1.1rem;margin-bottom:.3rem;}
p{color:#666;font-size:.85rem;margin-bottom:1.5rem;}
.spinner{width:44px;height:44px;border:4px solid #e8eaf6;border-top:4px solid #1a237e;border-radius:50%;animation:spin .9s linear infinite;margin:0 auto;}
@keyframes spin{to{transform:rotate(360deg);}}
</style></head>
<body>
<div class="card">
  <div class="logo">&#128722;</div>
  <h2>Redirecting to PayFast</h2>
  <p>Please wait while we redirect you to complete your payment...</p>
  <div class="spinner"></div>
</div>
<form id="pf" method="POST" action="' . htmlspecialchars(PF_PAYMENT_URL) . '">';

    foreach ($data as $k => $v) {
        echo '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
    }

    echo '</form>
<script>setTimeout(function(){ document.getElementById("pf").submit(); }, 800);</script>
</body></html>';
    exit();
}

/* ----------------------------------------------------------------
   2. ITN
   ---------------------------------------------------------------- */
function handleITN() {
    $pfData = $_POST;

    $receivedSig = isset($pfData['signature']) ? $pfData['signature'] : '';
    $calcSig     = generatePFSignature($pfData, true);
    if ($receivedSig !== $calcSig) {
        http_response_code(400);
        exit('Invalid signature');
    }

    $orderId       = (int)(isset($pfData['custom_int1'])     ? $pfData['custom_int1']     : 0);
    $paymentStatus = strtolower(isset($pfData['payment_status']) ? $pfData['payment_status'] : '');
    $pfPaymentId   = pf_sanitize(isset($pfData['pf_payment_id'])  ? $pfData['pf_payment_id']  : '');
    $amountGross   = (float)(isset($pfData['amount_gross'])  ? $pfData['amount_gross']   : 0);

    if (!$orderId) { http_response_code(400); exit('Missing order'); }

    $db    = getDB();
    $order = $db->fetchOne('SELECT * FROM orders WHERE id = ?', 'i', $orderId);
    if (!$order) { http_response_code(400); exit('Order not found'); }

    $expectedAmount = round((float)$order['subtotal'] + (float)$order['platform_fee'], 2);
    if (abs($amountGross - $expectedAmount) > 0.01) {
        http_response_code(400);
        exit('Amount mismatch');
    }

    if ($paymentStatus === 'complete') {
        $newStatus = 'confirmed';
    } elseif ($paymentStatus === 'failed' || $paymentStatus === 'cancelled') {
        $newStatus = 'cancelled';
    } else {
        $newStatus = 'pending';
    }

    $db->execute(
        'UPDATE orders SET status = ?, pf_payment_id = ? WHERE id = ?',
        'ssi', $newStatus, $pfPaymentId, $orderId
    );

    if ($newStatus === 'confirmed') {
        $db->execute("UPDATE listings SET status='sold', sold_at=NOW() WHERE id=?", 'i', (int)$order['listing_id']);
    }

    http_response_code(200);
    exit('OK');
}

/* ----------------------------------------------------------------
   3. STATUS
   ---------------------------------------------------------------- */
function getPaymentStatus() {
    $orderId = (int)(isset($_GET['order_id']) ? $_GET['order_id'] : 0);
    if (!$orderId) {
        header('Content-Type: application/json');
        echo json_encode(array('success' => false, 'message' => 'order_id required.'));
        exit();
    }

    $db    = getDB();
    $order = $db->fetchOne(
        'SELECT id, status, subtotal, platform_fee, payment_method, pf_payment_id, created_at
         FROM orders WHERE id = ?',
        'i', $orderId
    );

    header('Content-Type: application/json');
    if (!$order) {
        http_response_code(404);
        echo json_encode(array('success' => false, 'message' => 'Order not found.'));
    } else {
        echo json_encode(array('success' => true, 'data' => $order));
    }
    exit();
}

/* ----------------------------------------------------------------
   HELPERS
   ---------------------------------------------------------------- */
function generatePFSignature($data, $skipSig = false) {
    if ($skipSig) unset($data['signature']);
    $parts = array();
    foreach ($data as $key => $value) {
        if ($key === 'signature') continue;
        if ($value === '' || $value === null) continue;
        $parts[] = $key . '=' . urlencode(trim((string)$value));
    }
    $str = implode('&', $parts);
    if (!empty(PF_PASSPHRASE)) {
        $str .= '&passphrase=' . urlencode(trim(PF_PASSPHRASE));
    }
    return md5($str);
}

function pf_sanitize($val) {
    return htmlspecialchars(strip_tags(trim((string)$val)), ENT_QUOTES, 'UTF-8');
}

function showErrorPage($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment Error</title>
<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:system-ui,sans-serif;background:#fff5f5;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.card{background:#fff;border-radius:16px;padding:2rem;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:380px;width:90%;}
.icon{font-size:2.5rem;margin-bottom:.5rem;}h2{color:#c62828;margin-bottom:.5rem;}p{color:#555;font-size:.9rem;margin-bottom:1.5rem;}
a{display:inline-block;padding:.6rem 1.4rem;background:#1a237e;color:#fff;border-radius:8px;text-decoration:none;font-size:.9rem;}</style></head>
<body><div class="card"><div class="icon">&#10060;</div><h2>Payment Error</h2>
<p>' . htmlspecialchars($msg) . '</p><a href="javascript:history.back()">Go Back</a></div></body></html>';
    exit();
}
