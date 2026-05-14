<?php
/* ================================================================
   CAMPUS TRADE — api/messages.php
   Real-time polling chat between buyer and seller
   ================================================================ */
require_once __DIR__ . '/../config/db.php';
startSession();
$action = $_GET['action'] ?? 'list';
match ($action) {
    'send'  => sendMessage(),
    'list'  => getMessages(),
    'chats' => getChats(),
    default => error("Unknown action: $action", 404)
};

function sendMessage(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);
    $user = requireLogin();
    $db   = getDB();
    $body = getBody();

    $toUser    = (int) ($body['toUserId']   ?? 0);
    $listingId = (int) ($body['listingId']  ?? 0);
    $message   = sanitize($body['message']  ?? '');

    if (!$toUser)   error('Recipient required.');
    if (!$listingId)error('Listing ID required.');
    if (!$message)  error('Message cannot be empty.');
    if (strlen($message) > 1000) error('Message too long (max 1000 chars).');

    $chatId = implode('_', [min($user['id'], $toUser), max($user['id'], $toUser)]);

    $db->execute(
        'INSERT INTO messages (chat_id, listing_id, from_user, to_user, message) VALUES (?, ?, ?, ?, ?)',
        'siiis', $chatId, $listingId, $user['id'], $toUser, $message
    );

    // Notify recipient
    $db->execute(
        'INSERT INTO notifications (user_id, type, title, message, link_url) VALUES (?, "message", ?, ?, ?)',
        'isss', $toUser,
        '💬 New Message',
        'You have a new message from ' . ($user['display_name'] ?? $user['email']),
        '/pages/dashboard.html'
    );

    success([], 'Message sent.');
}

function getMessages(): void {
    $user      = requireLogin();
    $db        = getDB();
    $listingId = (int) ($_GET['listingId'] ?? 0);
    $otherUser = (int) ($_GET['userId']    ?? 0);
    if (!$listingId || !$otherUser) error('listingId and userId required.');

    $chatId = implode('_', [min($user['id'], $otherUser), max($user['id'], $otherUser)]);

    $rows = $db->fetchAll(
        'SELECT m.id, m.message, m.from_user, m.to_user, m.is_read, m.created_at,
                u.display_name AS sender_name
         FROM messages m JOIN users u ON m.from_user = u.id
         WHERE m.chat_id = ? AND m.listing_id = ?
         ORDER BY m.created_at ASC',
        'si', $chatId, $listingId
    );

    // Mark as read
    $db->execute(
        'UPDATE messages SET is_read = 1 WHERE chat_id = ? AND to_user = ? AND is_read = 0',
        'si', $chatId, $user['id']
    );

    success($rows);
}

function getChats(): void {
    $user = requireLogin();
    $db   = getDB();

    // Get latest message per chat for this user
    $rows = $db->fetchAll(
        "SELECT m.chat_id, m.listing_id, m.message, m.created_at, m.is_read,
                u.id AS other_user_id, u.display_name AS other_name, u.email AS other_email,
                l.title AS listing_title
         FROM messages m
         JOIN users u ON u.id = IF(m.from_user = ?, m.to_user, m.from_user)
         JOIN listings l ON l.id = m.listing_id
         WHERE m.id IN (
             SELECT MAX(id) FROM messages
             WHERE from_user = ? OR to_user = ?
             GROUP BY chat_id
         )
         ORDER BY m.created_at DESC",
        'iii', $user['id'], $user['id'], $user['id']
    );

    success($rows);
}
