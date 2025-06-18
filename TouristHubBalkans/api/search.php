<?php
/**
 * Search API Endpoint
 * 
 * Handles search requests for destinations, accommodations, restaurants, and activities.
 * Provides comprehensive search functionality with filtering and categorization.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once '../config/database.php';

try {
    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['query'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Query parameter required']);
        exit;
    }
    
    $query = trim($input['query']);
    $filters = $input['filters'] ?? [];
    $limit = min(intval($input['limit'] ?? 20), 50); // Max 50 results
    $offset = max(intval($input['offset'] ?? 0), 0);
    
    if (strlen($query) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Query must be at least 2 characters']);
        exit;
    }
    
    $db = getDatabase();
    $searchResults = [];
    
    // Search in destinations
    $destinationResults = searchDestinations($db, $query, $filters, $limit);
    $searchResults = array_merge($searchResults, $destinationResults);
    
    // Search in accommodations
    $accommodationResults = searchAccommodations($db, $query, $filters, $limit);
    $searchResults = array_merge($searchResults, $accommodationResults);
    
    // Search in historical sites
    $historicalResults = searchHistoricalSites($db, $query, $filters, $limit);
    $searchResults = array_merge($searchResults, $historicalResults);
    
    // Search in cultural sites
    $culturalResults = searchCulturalSites($db, $query, $filters, $limit);
    $searchResults = array_merge($searchResults, $culturalResults);
    
    // Sort results by relevance
    usort($searchResults, function($a, $b) {
        return $b['relevance'] - $a['relevance'];
    });
    
    // Apply pagination
    $totalResults = count($searchResults);
    $searchResults = array_slice($searchResults, $offset, $limit);
    
    // Prepare response
    $response = [
        'success' => true,
        'query' => $query,
        'total' => $totalResults,
        'limit' => $limit,
        'offset' => $offset,
        'results' => $searchResults
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Search API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => getenv('APP_ENV') === 'production' ? 'Search temporarily unavailable' : $e->getMessage()
    ]);
}

/**
 * Search destinations
 */
function searchDestinations($db, $query, $filters, $limit) {
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            category,
            rating,
            'destination' as type,
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
        FROM destinations 
        WHERE 
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE)
            OR name LIKE ?
            OR location LIKE ?
            OR description LIKE ?
    ";
    
    $params = [$query, $query, "%{$query}%", "%{$query}%", "%{$query}%"];
    
    // Add country filter if specified
    if (!empty($filters['country'])) {
        $sql .= " AND country = ?";
        $params[] = $filters['country'];
    }
    
    // Add category filter if specified
    if (!empty($filters['category'])) {
        $sql .= " AND category = ?";
        $params[] = $filters['category'];
    }
    
    $sql .= " ORDER BY relevance DESC LIMIT ?";
    $params[] = $limit;
    
    try {
        $stmt = $db->query($sql, $params);
        $results = $stmt->fetchAll();
        
        return array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => truncateText($row['description'], 150),
                'location' => $row['location'],
                'country' => $row['country'],
                'category' => $row['category'],
                'rating' => $row['rating'] ? (float)$row['rating'] : null,
                'type' => $row['type'],
                'relevance' => (float)$row['relevance']
            ];
        }, $results);
        
    } catch (PDOException $e) {
        error_log("Search destinations error: " . $e->getMessage());
        return [];
    }
}

/**
 * Search accommodations
 */
function searchAccommodations($db, $query, $filters, $limit) {
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            type as accommodation_type,
            rating,
            price_range,
            'accommodation' as type,
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
        FROM accommodations 
        WHERE 
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE)
            OR name LIKE ?
            OR location LIKE ?
            OR description LIKE ?
    ";
    
    $params = [$query, $query, "%{$query}%", "%{$query}%", "%{$query}%"];
    
    // Add filters
    if (!empty($filters['country'])) {
        $sql .= " AND country = ?";
        $params[] = $filters['country'];
    }
    
    if (!empty($filters['accommodation_type'])) {
        $sql .= " AND type = ?";
        $params[] = $filters['accommodation_type'];
    }
    
    if (!empty($filters['price_range'])) {
        $sql .= " AND price_range = ?";
        $params[] = $filters['price_range'];
    }
    
    $sql .= " ORDER BY relevance DESC LIMIT ?";
    $params[] = $limit;
    
    try {
        $stmt = $db->query($sql, $params);
        $results = $stmt->fetchAll();
        
        return array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => truncateText($row['description'], 150),
                'location' => $row['location'],
                'country' => $row['country'],
                'accommodation_type' => $row['accommodation_type'],
                'rating' => $row['rating'] ? (float)$row['rating'] : null,
                'price_range' => $row['price_range'],
                'type' => $row['type'],
                'relevance' => (float)$row['relevance']
            ];
        }, $results);
        
    } catch (PDOException $e) {
        error_log("Search accommodations error: " . $e->getMessage());
        return [];
    }
}

/**
 * Search historical sites
 */
function searchHistoricalSites($db, $query, $filters, $limit) {
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            period,
            'historical' as type,
            MATCH(name, description, location, period) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
        FROM historical_sites 
        WHERE 
            MATCH(name, description, location, period) AGAINST (? IN NATURAL LANGUAGE MODE)
            OR name LIKE ?
            OR location LIKE ?
            OR description LIKE ?
            OR period LIKE ?
    ";
    
    $params = [$query, $query, "%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];
    
    if (!empty($filters['country'])) {
        $sql .= " AND country = ?";
        $params[] = $filters['country'];
    }
    
    if (!empty($filters['period'])) {
        $sql .= " AND period = ?";
        $params[] = $filters['period'];
    }
    
    $sql .= " ORDER BY relevance DESC LIMIT ?";
    $params[] = $limit;
    
    try {
        $stmt = $db->query($sql, $params);
        $results = $stmt->fetchAll();
        
        return array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => truncateText($row['description'], 150),
                'location' => $row['location'],
                'country' => $row['country'],
                'period' => $row['period'],
                'type' => $row['type'],
                'relevance' => (float)$row['relevance']
            ];
        }, $results);
        
    } catch (PDOException $e) {
        error_log("Search historical sites error: " . $e->getMessage());
        return [];
    }
}

/**
 * Search cultural sites
 */
function searchCulturalSites($db, $query, $filters, $limit) {
    $sql = "
        SELECT 
            id,
            name,
            description,
            location,
            country,
            category,
            'cultural' as type,
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE) as relevance
        FROM cultural_sites 
        WHERE 
            MATCH(name, description, location) AGAINST (? IN NATURAL LANGUAGE MODE)
            OR name LIKE ?
            OR location LIKE ?
            OR description LIKE ?
    ";
    
    $params = [$query, $query, "%{$query}%", "%{$query}%", "%{$query}%"];
    
    if (!empty($filters['country'])) {
        $sql .= " AND country = ?";
        $params[] = $filters['country'];
    }
    
    if (!empty($filters['category'])) {
        $sql .= " AND category = ?";
        $params[] = $filters['category'];
    }
    
    $sql .= " ORDER BY relevance DESC LIMIT ?";
    $params[] = $limit;
    
    try {
        $stmt = $db->query($sql, $params);
        $results = $stmt->fetchAll();
        
        return array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'description' => truncateText($row['description'], 150),
                'location' => $row['location'],
                'country' => $row['country'],
                'category' => $row['category'],
                'type' => $row['type'],
                'relevance' => (float)$row['relevance']
            ];
        }, $results);
        
    } catch (PDOException $e) {
        error_log("Search cultural sites error: " . $e->getMessage());
        return [];
    }
}

/**
 * Truncate text to specified length
 */
function truncateText($text, $length) {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return rtrim(substr($text, 0, $length)) . '...';
}

/**
 * Sanitize and validate search query
 */
function sanitizeQuery($query) {
    // Remove potentially harmful characters
    $query = preg_replace('/[^\w\s\-\.]+/u', '', $query);
    
    // Normalize whitespace
    $query = preg_replace('/\s+/', ' ', $query);
    
    return trim($query);
}
?>
