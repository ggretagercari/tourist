<?php
/**
 * Destinations API Endpoint
 * 
 * Provides access to destination data including featured destinations,
 * detailed destination information, and destination listings with filtering.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once '../config/database.php';

try {
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'featured':
            getFeaturedDestinations();
            break;
        case 'detail':
            getDestinationDetail();
            break;
        case 'list':
        default:
            getDestinationsList();
            break;
    }
    
} catch (Exception $e) {
    error_log("Destinations API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => getenv('APP_ENV') === 'production' ? 'Service temporarily unavailable' : $e->getMessage()
    ]);
}

/**
 * Get featured destinations
 */
function getFeaturedDestinations() {
    $db = getDatabase();
    
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            category,
            rating,
            featured,
            coordinates,
            best_time_to_visit,
            created_at
        FROM destinations 
        WHERE featured = 1 
        ORDER BY rating DESC, created_at DESC 
        LIMIT 6
    ";
    
    $results = dbQuery($sql);
    
    if ($results === false) {
        // Return empty state instead of error for better UX
        echo json_encode([
            'success' => true,
            'destinations' => [],
            'message' => 'No featured destinations available at the moment'
        ]);
        return;
    }
    
    $destinations = array_map('formatDestination', $results);
    
    echo json_encode([
        'success' => true,
        'destinations' => $destinations,
        'total' => count($destinations)
    ]);
}

/**
 * Get destinations list with filtering and pagination
 */
function getDestinationsList() {
    $db = getDatabase();
    
    // Get parameters
    $country = $_GET['country'] ?? '';
    $category = $_GET['category'] ?? '';
    $featured = isset($_GET['featured']) ? (bool)$_GET['featured'] : null;
    $limit = min(intval($_GET['limit'] ?? 12), 50); // Max 50 results
    $offset = max(intval($_GET['offset'] ?? 0), 0);
    $search = $_GET['search'] ?? '';
    
    // Build query
    $conditions = [];
    $params = [];
    
    if (!empty($country)) {
        $conditions[] = "country = ?";
        $params[] = $country;
    }
    
    if (!empty($category)) {
        $conditions[] = "category = ?";
        $params[] = $category;
    }
    
    if ($featured !== null) {
        $conditions[] = "featured = ?";
        $params[] = $featured ? 1 : 0;
    }
    
    if (!empty($search)) {
        $conditions[] = "(name LIKE ? OR description LIKE ? OR location LIKE ?)";
        $searchParam = "%{$search}%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }
    
    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM destinations {$whereClause}";
    $countResult = dbQuerySingle($countSql, $params);
    $total = $countResult ? (int)$countResult['total'] : 0;
    
    // Get destinations
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            category,
            rating,
            featured,
            coordinates,
            best_time_to_visit,
            created_at
        FROM destinations 
        {$whereClause}
        ORDER BY featured DESC, rating DESC, name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $results = dbQuery($sql, $params);
    
    if ($results === false) {
        echo json_encode([
            'success' => true,
            'destinations' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'message' => 'No destinations found'
        ]);
        return;
    }
    
    $destinations = array_map('formatDestination', $results);
    
    echo json_encode([
        'success' => true,
        'destinations' => $destinations,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total
    ]);
}

/**
 * Get detailed destination information
 */
function getDestinationDetail() {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid destination ID required']);
        return;
    }
    
    $db = getDatabase();
    
    // Get destination details
    $sql = "
        SELECT 
            d.*,
            COUNT(r.id) as review_count,
            AVG(r.rating) as average_rating
        FROM destinations d
        LEFT JOIN reviews r ON d.id = r.destination_id AND r.status = 'approved'
        WHERE d.id = ?
        GROUP BY d.id
    ";
    
    $destination = dbQuerySingle($sql, [$id]);
    
    if (!$destination) {
        http_response_code(404);
        echo json_encode(['error' => 'Destination not found']);
        return;
    }
    
    // Get recent reviews
    $reviewsSql = "
        SELECT 
            id,
            visitor_name,
            rating,
            comment,
            visit_date,
            created_at
        FROM reviews 
        WHERE destination_id = ? AND status = 'approved'
        ORDER BY created_at DESC 
        LIMIT 5
    ";
    
    $reviews = dbQuery($reviewsSql, [$id]) ?: [];
    
    // Get nearby destinations
    $nearbySql = "
        SELECT 
            id,
            name,
            location,
            category,
            rating
        FROM destinations 
        WHERE id != ? AND country = ?
        ORDER BY rating DESC 
        LIMIT 4
    ";
    
    $nearby = dbQuery($nearbySql, [$id, $destination['country']]) ?: [];
    
    // Format the response
    $response = [
        'success' => true,
        'destination' => formatDestinationDetail($destination),
        'reviews' => array_map('formatReview', $reviews),
        'nearby_destinations' => array_map('formatDestination', $nearby),
        'stats' => [
            'review_count' => (int)$destination['review_count'],
            'average_rating' => $destination['average_rating'] ? round((float)$destination['average_rating'], 1) : null
        ]
    ];
    
    echo json_encode($response);
}

/**
 * Format destination data for API response
 */
function formatDestination($destination) {
    return [
        'id' => (int)$destination['id'],
        'name' => $destination['name'],
        'description' => $destination['description'],
        'location' => $destination['location'],
        'country' => $destination['country'],
        'category' => $destination['category'],
        'rating' => $destination['rating'] ? (float)$destination['rating'] : null,
        'featured' => (bool)$destination['featured'],
        'coordinates' => $destination['coordinates'] ? json_decode($destination['coordinates'], true) : null,
        'best_time_to_visit' => $destination['best_time_to_visit'],
        'created_at' => $destination['created_at']
    ];
}

/**
 * Format detailed destination data
 */
function formatDestinationDetail($destination) {
    $formatted = formatDestination($destination);
    
    // Add additional fields available in detail view
    $detailFields = [
        'full_description',
        'activities',
        'facilities',
        'accessibility',
        'entrance_fee',
        'opening_hours',
        'contact_info',
        'transportation',
        'safety_info'
    ];
    
    foreach ($detailFields as $field) {
        if (isset($destination[$field])) {
            $formatted[$field] = $destination[$field];
        }
    }
    
    // Parse JSON fields
    if (isset($destination['activities']) && $destination['activities']) {
        $formatted['activities'] = json_decode($destination['activities'], true) ?: [];
    }
    
    if (isset($destination['facilities']) && $destination['facilities']) {
        $formatted['facilities'] = json_decode($destination['facilities'], true) ?: [];
    }
    
    return $formatted;
}

/**
 * Format review data
 */
function formatReview($review) {
    return [
        'id' => (int)$review['id'],
        'visitor_name' => $review['visitor_name'],
        'rating' => (int)$review['rating'],
        'comment' => $review['comment'],
        'visit_date' => $review['visit_date'],
        'created_at' => $review['created_at']
    ];
}

/**
 * Get available countries for filtering
 */
function getCountries() {
    $sql = "SELECT DISTINCT country FROM destinations ORDER BY country";
    $results = dbQuery($sql);
    
    return $results ? array_column($results, 'country') : [];
}

/**
 * Get available categories for filtering
 */
function getCategories() {
    $sql = "SELECT DISTINCT category FROM destinations ORDER BY category";
    $results = dbQuery($sql);
    
    return $results ? array_column($results, 'category') : [];
}
?>
