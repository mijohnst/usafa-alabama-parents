<?php
/**
 * USAFA News Feed Fetcher
 * This script fetches the USAFA RSS feed and caches it to improve performance
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Cache settings
$cacheFile = 'usafa-news-cache.json';
$cacheTime = 1800; // Cache for 30 minutes (1800 seconds)

// Check if cache exists and is still valid
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    // Serve from cache
    echo file_get_contents($cacheFile);
    exit;
}

// Cache expired or doesn't exist - fetch fresh data
$feedUrl = 'https://www.usafa.edu/feed/';

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $feedUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Alabama USAFA Parents Club Website');

$xmlContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Check if fetch was successful
if ($httpCode != 200 || !$xmlContent) {
    // If fetch failed, try to serve old cache if it exists
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
    } else {
        echo json_encode(['error' => 'Unable to fetch news feed']);
    }
    exit;
}

// Parse XML
$xml = simplexml_load_string($xmlContent);

if (!$xml) {
    echo json_encode(['error' => 'Unable to parse RSS feed']);
    exit;
}

// Extract news items
$newsItems = [];
$count = 0;

foreach ($xml->channel->item as $item) {
    if ($count >= 4) break; // Only get 4 articles
    
    // Clean description - remove HTML tags
    $description = strip_tags((string)$item->description);
    $description = html_entity_decode($description);
    $description = preg_replace('/\s+/', ' ', $description);
    $description = trim($description);
    
    // Limit excerpt length
    if (strlen($description) > 180) {
        $description = substr($description, 0, 180) . '...';
    }
    
    $newsItems[] = [
        'title' => (string)$item->title,
        'link' => (string)$item->link,
        'pubDate' => (string)$item->pubDate,
        'description' => $description
    ];
    
    $count++;
}

// Create JSON response
$response = [
    'status' => 'ok',
    'items' => $newsItems,
    'cached_at' => date('Y-m-d H:i:s')
];

$jsonResponse = json_encode($response, JSON_PRETTY_PRINT);

// Save to cache
file_put_contents($cacheFile, $jsonResponse);

// Output response
echo $jsonResponse;
?>
