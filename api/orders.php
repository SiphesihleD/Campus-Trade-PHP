<?php
/* ================================================================
   CAMPUS TRADE — api/orders.php
   Handles: create order · get buyer orders · get seller orders · update status
   ================================================================ */

require_once __DIR__ . '/../config/db.php';
startSession();

$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

match ($action) {
    'create'  => createOrder(),
    'list'    => getOrders(),
    'get'     => getOrder($id),
    'status'  => updateStatus($id),
    default   => error("Unknown action: $action", 404)
};

function createOrder(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);
    $user = requireLogin();
    $db   = getDB();
    $body = getBody();

    $listingId  = (int) ($body['listingId']      ?? 0);
    $pm         = sanitize($body['paymentMethod'] ?? '');
    $meetup     = sanitize($body['meetupLocation']?? '');
    $note       = sanitize($body['note']          ?? '');
    $eftRef     = sanitize($body['eftRef']        ?? '');

    if (!$listingId) error('Listing ID required.');
    if (!$pm)        error('Payment method required.');

    $listing = $db->fetchOne(
        'SELECT l.*, u.id AS seller_user_id FROM listings l JOIN users u ON l.seller_id = u.id WHERE l.id = ? AND l.status = "active"',
        'i', $listingId
    );
    if (!$listing) error('Listing not found or already sold.');
    if ($listing['seller_id'] === $user['id']) error('You cannot buy your own listing.');

    $subtotal   = (float) $listing['price'];
    $fee        = round($subtotal * COMMISSION, 2);
    $sellerGets = round($subtotal * (1 - COMMISSION), 2);

    $db->beginTransaction();
    try {
        $orderId = $db->insert(
            'INSERT INTO orders (buyer_id, seller_id, listing_id, subtotal, platform_fee, seller_gets, payment_method, meetup_location, eft_reference, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'iiidddssss',
            $user['id'], $listing['seller_id'], $listingId,
            $subtotal, $fee, $sellerGets,
            $pm, $meetup, $eftRef, $note
        );

        // Mark listing as sold
        $db->execute("UPDATE listings SET status = 'sold' WHERE id = ?", 'i', $listingId);

        // Notify seller
        $db->execute(
            'INSERT INTO notifications (user_id, type, title, message, link_url) VALUES (?, "sale", ?, ?, ?)',
            'isss',
            $listing['seller_id'],
            '🎉 Item Sold!',
            '"' . $listing['title'] . '" was purchased via ' . $pm . '.',
            '/pages/dashboard.html'
        );

        $db->commit();
        success(['orderId' => $orderId], 'Order placed successfully.');
    } catch (Exception $e) {
        $db->rollback();
        error('Order failed: ' . $e->getMessage());
    }
}

function getOrders(): void {
    $user = requireLogin();
    $db   = getDB();
    $role = $_GET['role'] ?? 'buyer';

    $field = $role === 'seller' ? 'o.seller_id' : 'o.buyer_id';
    $rows  = $db->fetchAll("
        SELECT o.*, l.title AS listing_title, l.price AS listing_price,
               b.display_name AS buyer_name, b.email AS buyer_email,
               s.display_name AS seller_name, s.email AS seller_email
        FROM orders o
        JOIN listings l ON o.listing_id = l.id
        JOIN users b ON o.buyer_id  = b.id
        JOIN users s ON o.seller_id = s.id
        WHERE $field = ?
        ORDER BY o.created_at DESC
    ", 'i', $user['id']);

    success($rows);
}

function getOrder(int $id): void {
    if (!$id) error('Order ID required.');
    $user  = requireLogin();
    $db    = getDB();
    $order = $db->fetchOne(
        'SELECT o.*, l.title AS listing_title FROM orders o JOIN listings l ON o.listing_id = l.id
         WHERE o.id = ? AND (o.buyer_id = ? OR o.seller_id = ?)',
        'iii', $id, $user['id'], $user['id']
    );
    if (!$order) error('Order not found.', 404);
    success($order);
}

function updateStatus(int $id): void {
    if (!$id) error('Order ID required.');
    $user   = requireLogin();
    $db     = getDB();
    $body   = getBody();
    $status = sanitize($body['status'] ?? '');

    $allowed = ['confirmed','completed','cancelled','disputed'];
    if (!in_array($status, $allowed)) error('Invalid status.');

    $order = $db->fetchOne('SELECT * FROM orders WHERE id = ?', 'i', $id);
    if (!$order) error('Order not found.', 404);
    if ($order['buyer_id'] !== $user['id'] && $order['seller_id'] !== $user['id']) error('Forbidden.', 403);

    $db->execute("UPDATE orders SET status = ? WHERE id = ?", 'si', $status, $id);
    success([], 'Status updated.');
}
