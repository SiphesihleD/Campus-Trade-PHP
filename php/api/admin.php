<?php
/* ================================================================
   CAMPUS TRADE — api/admin.php
   Admin only: manage users, listings, reports, stats
   ================================================================ */
require_once __DIR__ . '/../config/db.php';
startSession();

$action = $_GET['action'] ?? 'stats';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

match ($action) {
    'stats'           => getStats(),
    'users'           => getUsers(),
    'ban_user'        => banUser($id),
    'unban_user'      => unbanUser($id),
    'all_listings'    => getAllListings(),
    'remove_listing'  => removeListing($id),
    'approve_listing' => approveListing($id),
    'reports'         => getReports(),
    'resolve_report'  => resolveReport($id),
    'orders'          => getAllOrders(),
    default           => error("Unknown action: $action", 404)
};

/* ── PLATFORM STATS ── */
function getStats(): void {
    requireAdmin();
    $db = getDB();
    success([
        'totalUsers'    => $db->fetchOne('SELECT COUNT(*) AS n FROM users')['n'],
        'totalStudents' => $db->fetchOne('SELECT COUNT(*) AS n FROM users WHERE role="student"')['n'],
        'totalSellers'  => $db->fetchOne('SELECT COUNT(*) AS n FROM users WHERE role="admin"')['n'],
        'activeListings'=> $db->fetchOne('SELECT COUNT(*) AS n FROM listings WHERE status="active"')['n'],
        'soldListings'  => $db->fetchOne('SELECT COUNT(*) AS n FROM listings WHERE status="sold"')['n'],
        'totalOrders'   => $db->fetchOne('SELECT COUNT(*) AS n FROM orders')['n'],
        'totalRevenue'  => (float) $db->fetchOne('SELECT COALESCE(SUM(platform_fee),0) AS n FROM orders WHERE status="completed"')['n'],
        'openReports'   => $db->fetchOne('SELECT COUNT(*) AS n FROM reports WHERE status="open"')['n'],
        'bannedUsers'   => $db->fetchOne('SELECT COUNT(*) AS n FROM users WHERE is_banned=1')['n'],
    ]);
}

/* ── USERS ── */
function getUsers(): void {
    requireAdmin();
    $db    = getDB();
    $role  = sanitize($_GET['role'] ?? '');
    $where = $role ? 'WHERE role = ?' : '';
    $rows  = $role
        ? $db->fetchAll("SELECT id,email,display_name,role,campus,rating,rating_count,total_sales,is_banned,is_verified,created_at FROM users $where ORDER BY created_at DESC", 's', $role)
        : $db->fetchAll("SELECT id,email,display_name,role,campus,rating,rating_count,total_sales,is_banned,is_verified,created_at FROM users ORDER BY created_at DESC");
    success($rows);
}

function banUser(int $id): void {
    if (!$id) error('User ID required.');
    requireAdmin();
    getDB()->execute('UPDATE users SET is_banned = 1 WHERE id = ?', 'i', $id);
    success([], 'User banned.');
}

function unbanUser(int $id): void {
    if (!$id) error('User ID required.');
    requireAdmin();
    getDB()->execute('UPDATE users SET is_banned = 0 WHERE id = ?', 'i', $id);
    success([], 'User unbanned.');
}

/* ── ALL LISTINGS ── */
function getAllListings(): void {
    requireAdmin();
    $db     = getDB();
    $status = sanitize($_GET['status'] ?? '');
    $where  = $status ? 'WHERE l.status = ?' : "WHERE l.status != 'deleted'";
    $rows   = $status
        ? $db->fetchAll("SELECT l.id,l.title,l.price,l.category,l.condition_type,l.campus,l.status,l.views,l.created_at,u.display_name AS seller_name,u.email AS seller_email FROM listings l JOIN users u ON l.seller_id=u.id $where ORDER BY l.created_at DESC LIMIT 200", 's', $status)
        : $db->fetchAll("SELECT l.id,l.title,l.price,l.category,l.condition_type,l.campus,l.status,l.views,l.created_at,u.display_name AS seller_name,u.email AS seller_email FROM listings l JOIN users u ON l.seller_id=u.id $where ORDER BY l.created_at DESC LIMIT 200");
    success($rows);
}

function removeListing(int $id): void {
    if (!$id) error('Listing ID required.');
    requireAdmin();
    getDB()->execute("UPDATE listings SET status='deleted' WHERE id=?", 'i', $id);
    success([], 'Listing removed.');
}

function approveListing(int $id): void {
    if (!$id) error('Listing ID required.');
    requireAdmin();
    getDB()->execute("UPDATE listings SET status='active' WHERE id=?", 'i', $id);
    success([], 'Listing approved.');
}

/* ── REPORTS ── */
function getReports(): void {
    requireAdmin();
    $db   = getDB();
    $rows = $db->fetchAll(
        'SELECT r.*, u.display_name AS reporter_name, u.email AS reporter_email
         FROM reports r JOIN users u ON r.reporter_id = u.id
         ORDER BY r.created_at DESC LIMIT 100'
    );
    success($rows);
}

function resolveReport(int $id): void {
    if (!$id) error('Report ID required.');
    requireAdmin();
    $body   = getBody();
    $status = sanitize($body['status'] ?? 'resolved');
    getDB()->execute("UPDATE reports SET status=? WHERE id=?", 'si', $status, $id);
    success([], 'Report updated.');
}

/* ── ALL ORDERS ── */
function getAllOrders(): void {
    requireAdmin();
    $db   = getDB();
    $rows = $db->fetchAll(
        'SELECT o.id, o.subtotal, o.platform_fee, o.seller_gets, o.payment_method,
                o.status, o.rated, o.created_at,
                b.display_name AS buyer_name, b.email AS buyer_email,
                s.display_name AS seller_name, s.email AS seller_email,
                l.title AS listing_title
         FROM orders o
         JOIN users b ON o.buyer_id  = b.id
         JOIN users s ON o.seller_id = s.id
         JOIN listings l ON o.listing_id = l.id
         ORDER BY o.created_at DESC LIMIT 200'
    );
    success($rows);
}
