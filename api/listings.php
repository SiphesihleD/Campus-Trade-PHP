<?php
/* ================================================================
   CAMPUS TRADE — api/listings.php
   Handles: get all · get one · create · update · delete · search
   ================================================================ */

require_once __DIR__ . '/../config/db.php';

startSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;

match ($action) {
    'list'   => getListings(),
    'get'    => getListing($id),
    'create' => createListing(),
    'update' => updateListing($id),
    'delete' => deleteListing($id),
    'search' => searchListings(),
    'views'  => incrementViews($id),
    default  => error("Unknown action: $action", 404)
};

/* ── GET ALL LISTINGS ── */
function getListings(): void {
    $db       = getDB();
    $category = sanitize($_GET['category'] ?? '');
    $campus   = sanitize($_GET['campus']   ?? '');
    $cond     = sanitize($_GET['condition']?? '');
    $minPrice = (float) ($_GET['min_price'] ?? 0);
    $maxPrice = (float) ($_GET['max_price'] ?? 999999);
    $sort     = $_GET['sort'] ?? 'newest';
    $limit    = min((int) ($_GET['limit'] ?? 50), 100);
    $offset   = (int) ($_GET['offset'] ?? 0);

    $where  = ['l.status = "active"'];
    $params = [];
    $types  = '';

    if ($category) { $where[] = 'l.category = ?'; $params[] = $category; $types .= 's'; }
    if ($campus)   { $where[] = 'l.campus = ?';   $params[] = $campus;   $types .= 's'; }
    if ($cond)     { $where[] = 'l.condition_type = ?'; $params[] = $cond; $types .= 's'; }
    $where[] = 'l.price BETWEEN ? AND ?';
    $params[] = $minPrice; $params[] = $maxPrice; $types .= 'dd';

    $orderBy = match ($sort) {
        'price-asc'  => 'l.price ASC',
        'price-desc' => 'l.price DESC',
        'popular'    => 'l.views DESC',
        default      => 'l.created_at DESC'
    };

    $sql = "
        SELECT
            l.id, l.firebase_id, l.title, l.description, l.price,
            l.category, l.condition_type AS `condition`, l.campus,
            l.status, l.views, l.is_featured, l.created_at,
            u.id AS seller_id, u.display_name AS seller_name,
            u.email AS seller_email, u.rating AS seller_rating,
            u.rating_count AS seller_rating_count,
            GROUP_CONCAT(li.image_url ORDER BY li.sort_order SEPARATOR '|') AS images
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN listing_images li ON li.listing_id = l.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY l.id
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit; $params[] = $offset; $types .= 'ii';

    $rows = $db->fetchAll($sql, $types, ...$params);

    // Split image string into array
    foreach ($rows as &$row) {
        $row['images'] = $row['images'] ? explode('|', $row['images']) : [];
        $row['price']  = (float) $row['price'];
        $row['views']  = (int)   $row['views'];
    }

    success($rows);
}

/* ── GET SINGLE LISTING ── */
function getListing(int $id): void {
    if (!$id) error('Listing ID required.');
    $db = getDB();

    $row = $db->fetchOne("
        SELECT
            l.id, l.firebase_id, l.title, l.description, l.price,
            l.category, l.condition_type AS `condition`, l.campus,
            l.status, l.views, l.created_at, l.updated_at,
            u.id AS seller_id, u.display_name AS seller_name,
            u.email AS seller_email, u.rating AS seller_rating,
            u.rating_count AS seller_rating_count, u.campus AS seller_campus
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        WHERE l.id = ? AND l.status != 'deleted'
    ", 'i', $id);

    if (!$row) error('Listing not found.', 404);

    // Get images
    $row['images'] = $db->fetchAll(
        'SELECT image_url, sort_order FROM listing_images WHERE listing_id = ? ORDER BY sort_order', 'i', $id
    );
    $row['price'] = (float) $row['price'];

    // Get ratings for this seller
    $row['recent_ratings'] = $db->fetchAll(
        'SELECT r.rating, r.comment, r.created_at, u.display_name AS buyer_name
         FROM ratings r JOIN users u ON r.buyer_id = u.id
         WHERE r.seller_id = ? ORDER BY r.created_at DESC LIMIT 5',
        'i', $row['seller_id']
    );

    success($row);
}

/* ── CREATE LISTING ── */
function createListing(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('POST required', 405);
    $user = requireAdmin();
    $db   = getDB();

    // Handle multipart form (with images) or JSON
    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart');
    if ($isMultipart) {
        $body = $_POST;
    } else {
        $body = getBody();
    }

    $title    = sanitize($body['title']       ?? '');
    $desc     = sanitize($body['description'] ?? '');
    $price    = (float) ($body['price']       ?? 0);
    $category = sanitize($body['category']    ?? '');
    $cond     = sanitize($body['condition']   ?? 'Used');
    $campus   = sanitize($body['campus']      ?? '');
    $fbId     = sanitize($body['firebaseId']  ?? '');

    if (!$title)    error('Title is required.');
    if (!$desc)     error('Description is required.');
    if ($price <= 0)error('Price must be greater than 0.');
    if (!$category) error('Category is required.');
    if (!$campus)   error('Campus is required.');

    $listingId = $db->insert(
        'INSERT INTO listings (firebase_id, seller_id, title, description, price, category, condition_type, campus)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'sissdsss',
        $fbId, $user['id'], $title, $desc, $price, $category, $cond, $campus
    );

    if (!$listingId) error('Failed to create listing.');

    // Handle image uploads
    if ($isMultipart && !empty($_FILES['images'])) {
        $files  = $_FILES['images'];
        $count  = is_array($files['name']) ? count($files['name']) : 1;
        $order  = 0;

        for ($i = 0; $i < min($count, 5); $i++) {
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $mimeType = mime_content_type($tmpName);

            if (!in_array($mimeType, ALLOWED_TYPES)) continue;
            if (filesize($tmpName) > MAX_FILE_SIZE) continue;

            $ext      = pathinfo(is_array($files['name']) ? $files['name'][$i] : $files['name'], PATHINFO_EXTENSION);
            $filename = 'listing_' . $listingId . '_' . time() . '_' . $order . '.' . $ext;
            $destDir  = UPLOAD_DIR . 'listings/';

            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            if (move_uploaded_file($tmpName, $destDir . $filename)) {
                $url = '/uploads/listings/' . $filename;
                $db->execute(
                    'INSERT INTO listing_images (listing_id, image_url, sort_order) VALUES (?, ?, ?)',
                    'isi', $listingId, $url, $order
                );
                $order++;
            }
        }
    }

    // Return the created listing
    getListing($listingId);
}

/* ── UPDATE LISTING ── */
function updateListing(int $id): void {
    if (!$id) error('Listing ID required.');
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT','POST'])) error('PUT/POST required', 405);
    $user = requireAdmin();
    $db   = getDB();

    $listing = $db->fetchOne('SELECT seller_id FROM listings WHERE id = ?', 'i', $id);
    if (!$listing)                           error('Listing not found.', 404);
    if ($listing['seller_id'] !== $user['id']) error('You do not own this listing.', 403);

    $body  = getBody();
    $fields = []; $types = ''; $vals = [];

    if (isset($body['title']))       { $fields[] = 'title = ?';          $vals[] = sanitize($body['title']);  $types .= 's'; }
    if (isset($body['description'])) { $fields[] = 'description = ?';    $vals[] = sanitize($body['description']); $types .= 's'; }
    if (isset($body['price']))       { $fields[] = 'price = ?';          $vals[] = (float)$body['price'];     $types .= 'd'; }
    if (isset($body['category']))    { $fields[] = 'category = ?';       $vals[] = sanitize($body['category']); $types .= 's'; }
    if (isset($body['condition']))   { $fields[] = 'condition_type = ?'; $vals[] = sanitize($body['condition']); $types .= 's'; }
    if (isset($body['campus']))      { $fields[] = 'campus = ?';         $vals[] = sanitize($body['campus']); $types .= 's'; }
    if (isset($body['status']))      { $fields[] = 'status = ?';         $vals[] = sanitize($body['status']); $types .= 's'; }

    if (empty($fields)) error('No fields to update.');

    $vals[]  = $id; $types .= 'i';
    $db->execute('UPDATE listings SET ' . implode(', ', $fields) . ' WHERE id = ?', $types, ...$vals);

    getListing($id);
}

/* ── DELETE LISTING (soft) ── */
function deleteListing(int $id): void {
    if (!$id) error('Listing ID required.');
    $user = requireLogin();
    $db   = getDB();

    $listing = $db->fetchOne('SELECT seller_id FROM listings WHERE id = ?', 'i', $id);
    if (!$listing) error('Listing not found.', 404);
    if ($listing['seller_id'] !== $user['id'] && $user['role'] !== 'admin') error('Forbidden.', 403);

    $db->execute("UPDATE listings SET status = 'deleted' WHERE id = ?", 'i', $id);
    success([], 'Listing deleted.');
}

/* ── SEARCH ── */
function searchListings(): void {
    $q  = sanitize($_GET['q'] ?? '');
    if (!$q) error('Search query required.');
    $db = getDB();

    $rows = $db->fetchAll("
        SELECT l.id, l.title, l.price, l.category, l.condition_type AS `condition`,
               l.campus, l.created_at, u.display_name AS seller_name, u.rating AS seller_rating,
               GROUP_CONCAT(li.image_url ORDER BY li.sort_order SEPARATOR '|') AS images
        FROM listings l
        JOIN users u ON l.seller_id = u.id
        LEFT JOIN listing_images li ON li.listing_id = l.id
        WHERE l.status = 'active' AND MATCH(l.title, l.description) AGAINST(? IN BOOLEAN MODE)
        GROUP BY l.id
        ORDER BY l.created_at DESC LIMIT 50
    ", 's', $q . '*');

    foreach ($rows as &$row) {
        $row['images'] = $row['images'] ? explode('|', $row['images']) : [];
        $row['price']  = (float) $row['price'];
    }

    success($rows);
}

/* ── INCREMENT VIEWS ── */
function incrementViews(int $id): void {
    if (!$id) error('Listing ID required.');
    getDB()->execute('UPDATE listings SET views = views + 1 WHERE id = ?', 'i', $id);
    success([], 'Views updated.');
}
