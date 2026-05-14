<?php
/* ================================================================
   CAMPUS TRADE — api/ratings.php
   ================================================================ */
require_once __DIR__ . '/../config/db.php';
startSession();
$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
match ($action) {
    'submit' => submitRating(),
    'list'   => getRatings($id),
    default  => error("Unknown action: $action", 404)
};

function submitRating(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);
    $user = requireLogin();
    $db   = getDB();
    $body = getBody();

    $orderId  = (int) ($body['orderId']  ?? 0);
    $rating   = (int) ($body['rating']   ?? 0);
    $comment  = sanitize($body['comment'] ?? '');

    if (!$orderId)              error('Order ID required.');
    if ($rating < 1 || $rating > 5) error('Rating must be 1–5.');

    $order = $db->fetchOne(
        'SELECT * FROM orders WHERE id = ? AND buyer_id = ? AND rated = 0',
        'ii', $orderId, $user['id']
    );
    if (!$order) error('Order not found or already rated.');

    $db->beginTransaction();
    try {
        $db->execute(
            'INSERT INTO ratings (order_id, seller_id, buyer_id, rating, comment) VALUES (?, ?, ?, ?, ?)',
            'iiiis', $orderId, $order['seller_id'], $user['id'], $rating, $comment
        );
        $db->execute('UPDATE orders SET rated = 1 WHERE id = ?', 'i', $orderId);

        // Recalculate seller average
        $agg = $db->fetchOne(
            'SELECT AVG(rating) AS avg, COUNT(*) AS cnt FROM ratings WHERE seller_id = ?',
            'i', $order['seller_id']
        );
        $db->execute(
            'UPDATE users SET rating = ?, rating_count = ? WHERE id = ?',
            'dii', round($agg['avg'], 2), $agg['cnt'], $order['seller_id']
        );

        // Notify seller
        $db->execute(
            'INSERT INTO notifications (user_id, type, title, message) VALUES (?, "rating", ?, ?)',
            'iss', $order['seller_id'],
            '⭐ New Review!',
            "You received a {$rating}-star review."
        );

        $db->commit();
        success([], 'Rating submitted.');
    } catch (Exception $e) {
        $db->rollback();
        error('Failed to submit rating: ' . $e->getMessage());
    }
}

function getRatings(int $sellerId): void {
    if (!$sellerId) error('Seller ID required.');
    $db   = getDB();
    $rows = $db->fetchAll(
        'SELECT r.rating, r.comment, r.created_at, u.display_name AS buyer_name
         FROM ratings r JOIN users u ON r.buyer_id = u.id
         WHERE r.seller_id = ? ORDER BY r.created_at DESC LIMIT 50',
        'i', $sellerId
    );
    $agg = $db->fetchOne(
        'SELECT AVG(rating) AS avg, COUNT(*) AS cnt FROM ratings WHERE seller_id = ?', 'i', $sellerId
    );
    success(['reviews' => $rows, 'average' => round((float)($agg['avg'] ?? 0), 2), 'count' => (int)($agg['cnt'] ?? 0)]);
}
