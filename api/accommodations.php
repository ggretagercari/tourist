<?php
/**
 * Accommodations API Endpoint
 * 
 * Provides access to accommodation data including hotels, guesthouses, and other lodging options
 * with filtering, searching, and detailed information capabilities.
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
            getFeaturedAccommodations();
            break;
        case 'detail':
            getAccommodationDetail();
            break;
        case 'availability':
            checkAvailability();
            break;
        case 'list':
        default:
            getAccommodationsList();
            break;
    }
    
} catch (Exception $e) {
    error_log("Accommodations API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => getenv('APP_ENV') === 'production' ? 'Service temporarily unavailable' : $e->getMessage()
    ]);
}

/**
 * Get featured accommodations
 */
function getFeaturedAccommodations() {
    $db = getDatabase();
    
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            type,
            rating,
            price_range,
            featured,
            amenities,
            contact_info,
            coordinates,
            created_at
        FROM accommodations 
        WHERE featured = 1 
        ORDER BY rating DESC, created_at DESC 
        LIMIT 8
    ";
    
    $results = dbQuery($sql);
    
    if ($results === false) {
        echo json_encode([
            'success' => true,
            'accommodations' => [],
            'message' => 'No featured accommodations available at the moment'
        ]);
        return;
    }
    
    $accommodations = array_map('formatAccommodation', $results);
    
    echo json_encode([
        'success' => true,
        'accommodations' => $accommodations,
        'total' => count($accommodations)
    ]);
}

/**
 * Get accommodations list with filtering and pagination
 */
function getAccommodationsList() {
    $db = getDatabase();
    
    // Get parameters
    $country = $_GET['country'] ?? '';
    $location = $_GET['location'] ?? '';
    $type = $_GET['type'] ?? '';
    $priceRange = $_GET['price_range'] ?? '';
    $minRating = floatval($_GET['min_rating'] ?? 0);
    $amenities = $_GET['amenities'] ?? '';
    $featured = isset($_GET['featured']) ? (bool)$_GET['featured'] : null;
    $limit = min(intval($_GET['limit'] ?? 12), 50);
    $offset = max(intval($_GET['offset'] ?? 0), 0);
    $search = $_GET['search'] ?? '';
    
    // Build query conditions
    $conditions = [];
    $params = [];
    
    if (!empty($country)) {
        $conditions[] = "country = ?";
        $params[] = $country;
    }
    
    if (!empty($location)) {
        $conditions[] = "location LIKE ?";
        $params[] = "%{$location}%";
    }
    
    if (!empty($type)) {
        $conditions[] = "type = ?";
        $params[] = $type;
    }
    
    if (!empty($priceRange)) {
        $conditions[] = "price_range = ?";
        $params[] = $priceRange;
    }
    
    if ($minRating > 0) {
        $conditions[] = "rating >= ?";
        $params[] = $minRating;
    }
    
    if (!empty($amenities)) {
        $amenitiesList = explode(',', $amenities);
        foreach ($amenitiesList as $amenity) {
            $conditions[] = "JSON_CONTAINS(amenities, ?)";
            $params[] = json_encode(trim($amenity));
        }
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
    $countSql = "SELECT COUNT(*) as total FROM accommodations {$whereClause}";
    $countResult = dbQuerySingle($countSql, $params);
    $total = $countResult ? (int)$countResult['total'] : 0;
    
    // Get accommodations
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            type,
            rating,
            price_range,
            featured,
            amenities,
            contact_info,
            coordinates,
            created_at
        FROM accommodations 
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
            'accommodations' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
            'message' => 'No accommodations found matching your criteria'
        ]);
        return;
    }
    
    $accommodations = array_map('formatAccommodation', $results);
    
    echo json_encode([
        'success' => true,
        'accommodations' => $accommodations,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total,
        'filters' => [
            'countries' => getAvailableCountries(),
            'types' => getAccommodationTypes(),
            'price_ranges' => getPriceRanges(),
            'amenities' => getPopularAmenities()
        ]
    ]);
}

/**
 * Get detailed accommodation information
 */
function getAccommodationDetail() {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid accommodation ID required']);
        return;
    }
    
    $db = getDatabase();
    
    // Get accommodation details
    $sql = "
        SELECT 
            a.*,
            COUNT(r.id) as review_count,
            AVG(r.rating) as average_rating
        FROM accommodations a
        LEFT JOIN accommodation_reviews r ON a.id = r.accommodation_id AND r.status = 'approved'
        WHERE a.id = ?
        GROUP BY a.id
    ";
    
    $accommodation = dbQuerySingle($sql, [$id]);
    
    if (!$accommodation) {
        http_response_code(404);
        echo json_encode(['error' => 'Accommodation not found']);
        return;
    }
    
    // Get recent reviews
    $reviewsSql = "
        SELECT 
            id,
            guest_name,
            rating,
            comment,
            stay_date,
            created_at
        FROM accommodation_reviews 
        WHERE accommodation_id = ? AND status = 'approved'
        ORDER BY created_at DESC 
        LIMIT 10
    ";
    
    $reviews = dbQuery($reviewsSql, [$id]) ?: [];
    
    // Get nearby accommodations
    $nearbySql = "
        SELECT 
            id,
            name,
            location,
            type,
            rating,
            price_range
        FROM accommodations 
        WHERE id != ? AND country = ? AND location = ?
        ORDER BY rating DESC 
        LIMIT 4
    ";
    
    $nearby = dbQuery($nearbySql, [$id, $accommodation['country'], $accommodation['location']]) ?: [];
    
    // Format the response
    $response = [
        'success' => true,
        'accommodation' => formatAccommodationDetail($accommodation),
        'reviews' => array_map('formatAccommodationReview', $reviews),
        'nearby_accommodations' => array_map('formatAccommodation', $nearby),
        'stats' => [
            'review_count' => (int)$accommodation['review_count'],
            'average_rating' => $accommodation['average_rating'] ? round((float)$accommodation['average_rating'], 1) : null
        ]
    ];
    
    echo json_encode($response);
}

/**
 * Check accommodation availability
 */
function checkAvailability() {
    $accommodationId = intval($_GET['accommodation_id'] ?? 0);
    $checkIn = $_GET['check_in'] ?? '';
    $checkOut = $_GET['check_out'] ?? '';
    $guests = intval($_GET['guests'] ?? 1);
    
    if ($accommodationId <= 0 || empty($checkIn) || empty($checkOut)) {
        http_response_code(400);
        echo json_encode(['error' => 'Accommodation ID, check-in and check-out dates required']);
        return;
    }
    
    // Validate dates
    $checkInDate = DateTime::createFromFormat('Y-m-d', $checkIn);
    $checkOutDate = DateTime::createFromFormat('Y-m-d', $checkOut);
    
    if (!$checkInDate || !$checkOutDate || $checkInDate >= $checkOutDate) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid check-in or check-out dates']);
        return;
    }
    
    $db = getDatabase();
    
    // Get accommodation capacity
    $accommodationSql = "SELECT id, name, capacity, contact_info FROM accommodations WHERE id = ?";
    $accommodation = dbQuerySingle($accommodationSql, [$accommodationId]);
    
    if (!$accommodation) {
        http_response_code(404);
        echo json_encode(['error' => 'Accommodation not found']);
        return;
    }
    
    // Check capacity
    $capacity = json_decode($accommodation['capacity'], true);
    $maxGuests = $capacity['max_guests'] ?? 2;
    
    if ($guests > $maxGuests) {
        echo json_encode([
            'success' => false,
            'available' => false,
            'message' => "This accommodation can accommodate maximum {$maxGuests} guests",
            'max_guests' => $maxGuests
        ]);
        return;
    }
    
    // For this implementation, we'll assume availability based on basic rules
    // In a real system, this would check actual bookings and availability calendar
    $isAvailable = true;
    $message = 'Accommodation is available for your selected dates';
    
    // Check if dates are too far in advance (more than 1 year)
    $today = new DateTime();
    $interval = $today->diff($checkInDate);
    if ($interval->days > 365) {
        $isAvailable = false;
        $message = 'Bookings are not available more than 1 year in advance';
    }
    
    // Check if dates are in the past
    if ($checkInDate < $today) {
        $isAvailable = false;
        $message = 'Check-in date cannot be in the past';
    }
    
    $response = [
        'success' => true,
        'available' => $isAvailable,
        'message' => $message,
        'accommodation' => [
            'id' => $accommodation['id'],
            'name' => $accommodation['name']
        ],
        'booking_info' => [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => $guests,
            'nights' => $checkInDate->diff($checkOutDate)->days
        ],
        'contact_info' => json_decode($accommodation['contact_info'], true)
    ];
    
    echo json_encode($response);
}

/**
 * Format accommodation data for API response
 */
function formatAccommodation($accommodation) {
    return [
        'id' => (int)$accommodation['id'],
        'name' => $accommodation['name'],
        'description' => $accommodation['description'],
        'location' => $accommodation['location'],
        'country' => $accommodation['country'],
        'type' => $accommodation['type'],
        'rating' => $accommodation['rating'] ? (float)$accommodation['rating'] : null,
        'price_range' => $accommodation['price_range'],
        'featured' => (bool)$accommodation['featured'],
        'amenities' => $accommodation['amenities'] ? json_decode($accommodation['amenities'], true) : [],
        'contact_info' => $accommodation['contact_info'] ? json_decode($accommodation['contact_info'], true) : null,
        'coordinates' => $accommodation['coordinates'] ? json_decode($accommodation['coordinates'], true) : null,
        'created_at' => $accommodation['created_at']
    ];
}

/**
 * Format detailed accommodation data
 */
function formatAccommodationDetail($accommodation) {
    $formatted = formatAccommodation($accommodation);
    
    // Add additional fields for detail view
    $detailFields = [
        'full_description',
        'capacity',
        'room_types',
        'policies',
        'accessibility',
        'languages',
        'check_in_time',
        'check_out_time',
        'cancellation_policy'
    ];
    
    foreach ($detailFields as $field) {
        if (isset($accommodation[$field])) {
            $value = $accommodation[$field];
            $formatted[$field] = is_string($value) && isJson($value) ? json_decode($value, true) : $value;
        }
    }
    
    return $formatted;
}

/**
 * Format accommodation review data
 */
function formatAccommodationReview($review) {
    return [
        'id' => (int)$review['id'],
        'guest_name' => $review['guest_name'],
        'rating' => (int)$review['rating'],
        'comment' => $review['comment'],
        'stay_date' => $review['stay_date'],
        'created_at' => $review['created_at']
    ];
}

/**
 * Get available countries
 */
function getAvailableCountries() {
    $sql = "SELECT DISTINCT country FROM accommodations ORDER BY country";
    $results = dbQuery($sql);
    return $results ? array_column($results, 'country') : [];
}

/**
 * Get accommodation types
 */
function getAccommodationTypes() {
    $sql = "SELECT DISTINCT type FROM accommodations ORDER BY type";
    $results = dbQuery($sql);
    return $results ? array_column($results, 'type') : [];
}

/**
 * Get price ranges
 */
function getPriceRanges() {
    return ['budget', 'mid-range', 'luxury', 'premium'];
}

/**
 * Get popular amenities
 */
function getPopularAmenities() {
    return [
        'WiFi', 'Parking', 'Air Conditioning', 'Restaurant', 'Bar', 
        'Gym', 'Swimming Pool', 'Spa', 'Room Service', 'Laundry',
        'Business Center', 'Pet Friendly', 'Wheelchair Accessible'
    ];
}

/**
 * Check if string is valid JSON
 */
function isJson($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}
?>
