<?php
/**
 * Video Pool Fetcher
 *
 * Attempts to fetch a pool of video IDs from Invidious API (primary).
 * Falls back to YouTube playlist RSS feeds if Invidious is unavailable.
 *
 * Returns: array of video IDs
 */

// Configuration
function getConfig() {
  return [
    'invidious_instances' => [
      'https://inv.nadeko.net',
      'https://invidious.io',
      'https://yt.cdaut.de',
      'https://invidious.privacydev.net',
    ],
    'search_queries' => [
      'home video', 'family memories', 'everyday life', 'street footage', 'mundane',
      'daily life vlog', 'found footage', 'old home movies', 'neighborhood walk',
      'backyard', 'kitchen', 'living room', 'commute', 'market', 'children playing',
      'window view', 'rain sounds', 'time lapse', 'home tour', 'morning routine',
    ],
    'playlist_ids' => [
      'PLDcvjWj6b3hEARyG4CPYuARMbK81vppkd',
      'PLkDCHeEfJ0E-8WaVSEWy_SFmfuH9kFfpBZ',
    ],
  ];
}

// Try Invidious first
$pool = fetchFromInvidious();

// If Invidious fails, try playlist RSS
if (empty($pool)) {
  $pool = fetchFromPlaylistRSS();
}

// Return the pool (can be empty, caller will handle)
return $pool ?: [];

// ============================================================================
// Helper Functions
// ============================================================================

function fetchFromInvidious() {
  $config = getConfig();
  $instances = $config['invidious_instances'];
  $queries = $config['search_queries'];

  // Pick a random search query
  $query = $queries[array_rand($queries)];

  // Try each Invidious instance in order
  foreach ($instances as $instance) {
    $url = $instance . '/api/v1/search?q=' . urlencode($query)
      . '&type=video&duration=medium&page=1';

    $response = curlGet($url, 5);  // 5 second timeout
    if ($response === false) {
      continue;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || count($data) === 0) {
      continue;
    }

    // Extract video IDs from search results
    $videos = [];
    foreach ($data as $item) {
      if (isset($item['videoId']) && !empty($item['videoId'])) {
        $videos[] = $item['videoId'];
      }
    }

    if (count($videos) > 0) {
      // Shuffle for variety
      shuffle($videos);
      return array_slice($videos, 0, 30);  // Return up to 30 videos
    }
  }

  return [];
}

function fetchFromPlaylistRSS() {
  $config = getConfig();
  $playlistIds = $config['playlist_ids'];

  $allVideos = [];

  foreach ($playlistIds as $playlistId) {
    $url = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . $playlistId;
    $response = curlGet($url, 5);  // 5 second timeout

    if ($response === false) {
      continue;
    }

    // Parse XML
    $videos = parseYouTubeRSS($response);
    $allVideos = array_merge($allVideos, $videos);
  }

  // Remove duplicates and shuffle
  $allVideos = array_unique($allVideos);
  shuffle($allVideos);

  return array_slice($allVideos, 0, 30);  // Return up to 30 videos
}

function parseYouTubeRSS($xml) {
  /**
   * Extract video IDs from YouTube RSS feed.
   * YouTube RSS uses <yt:videoId> tags.
   */
  $videos = [];

  try {
    $dom = new DOMDocument();
    @$dom->loadXML($xml);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015/12/search.xsd');

    $nodeList = $xpath->query('//yt:videoId');
    foreach ($nodeList as $node) {
      $videoId = trim($node->textContent);
      if (!empty($videoId)) {
        $videos[] = $videoId;
      }
    }
  } catch (Exception $e) {
    // Parse error — return empty
  }

  return $videos;
}

function curlGet($url, $timeout = 5) {
  /**
   * Make an HTTP GET request using curl.
   * Returns the response body, or false on failure.
   */
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; bingeing-ahead/1.0)');
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // Only consider 2xx responses as success
  if ($httpCode >= 200 && $httpCode < 300 && $response !== false) {
    return $response;
  }

  return false;
}
?>
