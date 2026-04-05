<?php
/**
 * Video Pool Fetcher
 *
 * Builds a pool of ~30 amateur video IDs for the stream.
 *
 * Fetching priority:
 *   1. YouTube Data API v3 (best results, costs quota — 10k units/day free)
 *   2. Piped API search (free, no key) — fallback when quota exhausted
 *   3. YouTube playlist RSS feeds — fallback if Piped is also down
 *   4. Hardcoded FALLBACK_POOL in stream.php — last resort
 *
 * This file is included by stream.php via `include()` and returns an array.
 */

$startTime = microtime(true);
require_once(__DIR__ . '/perf.php');
require_once(__DIR__ . '/env.php');
require_once(__DIR__ . '/quota-tracker.php');

loadEnv();

/**
 * Central configuration for video pool fetching.
 * All tunable values live here so they can be adjusted without
 * hunting through function bodies.
 */
function getConfig() {
  return [
    'pool_size' => 30,           // max videos to return per pool refresh
    'max_view_count' => 50000,   // reject videos above this — high views = likely professional

    // YouTube Data API v3 — primary source (requires API key in .env)
    // Free tier: 10,000 units/day. search.list = 100 units, videos.list = 1 unit.
    'youtube_api_key' => getenv('YOUTUBE_API_KEY') ?: '',

    // Tried in order; first successful response wins.
    // Check https://github.com/TeamPiped/documentation for uptime.
    // Last verified: 2026-04-05
    'piped_instances' => [
      'https://pipedapi.kavin.rocks',
      'https://pipedapi.adminforge.de',
      'https://api.piped.yt',
      'https://pipedapi.drgns.space',
      'https://piped-api.privacy.com.de',
      'https://api.piped.private.coffee',
      'https://pipedapi.darkness.services',
    ],

    // One query is picked at random per pool refresh.
    // Inspired by the IMG_0001 project (walzr.com/IMG_0001):
    // default camera filenames surface genuinely amateur uploads
    // because only real people leave the default title.
    'search_queries' => [
      // Default camera filenames
      'IMG_0001', 'IMG_0002', 'IMG_0003', 'IMG_0004', 'IMG_0005',
      'VID_20230', 'VID_20220', 'VID_20210', 'VID_20200',
      'MOV_0001', 'MVI_0001', 'DSCN', 'DSC_0001',
      // Hyper-specific domestic — only a real person would title a video this way
      'my backyard', 'our kitchen', 'kids in the garden', 'cat sleeping',
      'dog walk morning', 'my neighborhood', 'view from my window',
      'sunday morning at home', 'family dinner', 'baby first steps',
      // Proven queries that return amateur content
      'home video', 'family memories', 'everyday life', 'street footage',
      'found footage', 'old home movies', 'neighborhood walk',
      'backyard', 'children playing', 'window view',
      'family gathering', 'birthday party', 'quiet moments',
      // Camera/format-specific — signals amateur origin
      'handycam footage', 'gopro walk', 'VHS home video',
      'camcorder family', 'dashcam commute',
      // Event-specific
      'christmas morning family', 'backyard bbq', 'school pickup',
      // Place-specific mundane
      'grocery store walk', 'bus ride', 'train window',
      // Multilingual — amateur content is richer outside English YouTube
      'video casero', 'vida cotidiana', 'paseo por la ciudad',
      'promenade dans la rue', 'minha casa', 'giornata normale',
    ],

    // YouTube playlist IDs for the RSS fallback path.
    // Each playlist was manually verified for amateur/home-video content.
    // RSS feeds are free, require no API key, and use YouTube's own infra.
    // Last verified: 2026-04-05
    'playlist_ids' => [
      'PLwqMUQlKzNnr42eO4I5cd9jZeeZ0_tfHQ',
      'PLwqMUQlKzNnrKCnLw5koOWpV6q-rKDaww',
      'PLLPmrja_ERqkI6ISdExTJvTZKO9wMuLvf',
      'PLuja0qET5CWguEI7sS1Q5HjwCYtPQ4BK-',
      'PL5Juk5amHcUdzNa449391mCktDxlhzDje',
      'PLuiHi9r3DU42-jFVL7oDNTL4VUORUPYhl',
      'PLQ6UBcyGN8xI10wdyrKdv-mlr1chPNFkG',
      'PLYXCN-cYXR2UJ7lTZybCW_zXv9wziR_QM',
      'PL2a9ajKy0oo8RMOGjficjffdTHUryvwm8',
      'PLyBheebviAaQqYBuWT2SiF2kW3-ZQeKJz',
    ],

    // Any of these terms in a video title → rejected.
    // Organized by category for easy maintenance.
    'title_blocklist' => [
      // Music/performance
      'music', 'song', 'band', 'concert', 'cover', 'official video', 'official audio',
      'lyrics', 'remix', 'feat.', 'ft.', 'album', 'playlist', 'dj ',
      // Education
      'tutorial', 'lesson', 'class', 'course', 'how to', 'guide', 'instructions',
      'training', 'learning', 'lecture', 'explained', 'for beginners', 'step by step',
      'masterclass', 'webinar', 'workshop',
      // Gaming
      'gameplay', 'walkthrough', 'let\'s play', 'playthrough', 'speedrun',
      'fortnite', 'minecraft', 'roblox',
      // Commercial/professional
      'trailer', 'teaser', 'promo', 'advertisement', 'sponsored',
      'review', 'unboxing', 'haul', 'top 10', 'top 5', 'compilation',
      'reaction', 'challenge', 'prank',
      // News/politics
      'breaking news', 'news update', 'election',
      // Fitness/self-help
      'workout', 'exercise', 'motivation', 'productivity',
      // Tech
      'setup tour', 'gadget',
      // Ambient/non-footage
      'asmr', '10 hours', '8 hours', 'white noise', 'sleep sounds',
    ],

    // Any of these terms in a channel name → rejected.
    // Filters out professional/corporate uploaders.
    'channel_blocklist' => [
      'news', 'media', 'official', 'records', 'entertainment',
      'network', 'studios', 'productions',
    ],
  ];
}

// ============================================================================
// Main execution — called when stream.php includes this file
// ============================================================================

// Primary: YouTube Data API v3 (best results, costs quota)
$pool = fetchFromYouTubeAPI();
if (!empty($pool)) { $GLOBALS['pool_source'] = 'youtube_api'; }

// Fallback 1: Piped API (free, no key)
if (empty($pool)) {
  $pool = fetchFromPiped();
  if (!empty($pool)) { $GLOBALS['pool_source'] = 'piped'; }
}

// Fallback 2: YouTube playlist RSS (free, limited selection)
if (empty($pool)) {
  $pool = fetchFromPlaylistRSS();
  if (!empty($pool)) { $GLOBALS['pool_source'] = 'rss'; }
}

$durationMs = (microtime(true) - $startTime) * 1000;
PerfLogger::logRequestDuration('pool.php', round($durationMs));

// Return to caller (stream.php). Empty array triggers FALLBACK_POOL there.
return $pool ?: [];

// ============================================================================
// Filtering
// ============================================================================

/**
 * Decide whether a video belongs in the pool.
 *
 * Returns false (reject) if ANY blocklist term matches the title or channel,
 * or if the view count exceeds the ceiling. Returns true only when all
 * checks pass.
 *
 * @param array  $config    Config from getConfig() — passed in to avoid
 *                          rebuilding the array on every call in a filter loop
 * @param string $title     Video title (any case — lowercased internally)
 * @param string $author    Channel name (optional, empty string if unknown)
 * @param int    $viewCount View count (-1 if unknown, skips the check)
 */
function isVideoAllowed($config, $title, $author = '', $viewCount = -1) {
  $title = strtolower($title);
  $author = strtolower($author);

  foreach ($config['title_blocklist'] as $term) {
    if (strpos($title, $term) !== false) return false;
  }

  foreach ($config['channel_blocklist'] as $term) {
    if ($author !== '' && strpos($author, $term) !== false) return false;
  }

  if ($viewCount >= 0 && $viewCount > $config['max_view_count']) return false;

  return true;
}

// ============================================================================
// YouTube Data API v3 (primary source)
// ============================================================================

/**
 * Search YouTube via the official Data API v3.
 *
 * Costs 100 quota units per search.list call, plus 1 unit for the
 * videos.list call that checks embeddable status.
 *
 * Returns an array of embeddable video IDs, or empty on failure/quota exhaustion.
 */
function fetchFromYouTubeAPI() {
  $config = getConfig();
  $apiKey = $config['youtube_api_key'];

  if (empty($apiKey)) {
    return []; // no key configured — skip to fallback
  }

  // search.list costs 100 units
  if (!QuotaTracker::canSpend(101)) { // 100 for search + 1 for videos.list
    return []; // quota exhausted — skip to fallback
  }

  $queries = $config['search_queries'];
  if (empty($queries)) {
    return [];
  }

  $query = $queries[array_rand($queries)];

  $url = 'https://www.googleapis.com/youtube/v3/search?'
    . http_build_query([
        'part'           => 'snippet',
        'q'              => $query,
        'type'           => 'video',
        'videoDuration'  => 'medium',     // 4-20 minutes
        'order'          => 'date',       // recent uploads, not SEO-optimized
        'maxResults'     => 50,           // max allowed per page
        'videoEmbeddable'=> 'true',       // only embeddable videos
        'key'            => $apiKey,
      ]);

  $response = curlGet($url, 8);
  if ($response === false) {
    return [];
  }

  $data = json_decode($response, true);
  if (!is_array($data) || !isset($data['items']) || empty($data['items'])) {
    return [];
  }

  QuotaTracker::spend(100);

  // Filter through title/channel blocklists
  $candidates = [];
  foreach ($data['items'] as $item) {
    $videoId = isset($item['id']['videoId']) ? $item['id']['videoId'] : '';
    if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
      continue;
    }

    $title  = isset($item['snippet']['title']) ? $item['snippet']['title'] : '';
    $author = isset($item['snippet']['channelTitle']) ? $item['snippet']['channelTitle'] : '';

    // View count not available in search results — checked via videos.list below
    if (isVideoAllowed($config, $title, $author)) {
      $candidates[] = $videoId;
    }
  }

  if (empty($candidates)) {
    return [];
  }

  // Batch-check embeddable status and view counts via videos.list (1 unit)
  $candidates = filterByVideoDetails($candidates, $apiKey, $config['max_view_count']);

  if (empty($candidates)) {
    return [];
  }

  shuffle($candidates);
  return array_slice($candidates, 0, $config['pool_size']);
}

/**
 * Use videos.list to check embeddable status and view counts.
 * Costs 1 quota unit regardless of how many IDs (up to 50).
 *
 * @param array  $videoIds     Video IDs to check
 * @param string $apiKey       YouTube API key
 * @param int    $maxViewCount Reject videos above this view count
 * @return array Filtered video IDs that are embeddable and under the view cap
 */
function filterByVideoDetails($videoIds, $apiKey, $maxViewCount) {
  if (empty($videoIds)) {
    return [];
  }

  if (!QuotaTracker::canSpend(1)) {
    return $videoIds; // can't afford the check — return unfiltered
  }

  $url = 'https://www.googleapis.com/youtube/v3/videos?'
    . http_build_query([
        'part' => 'status,statistics',
        'id'   => implode(',', array_slice($videoIds, 0, 50)),
        'key'  => $apiKey,
      ]);

  $response = curlGet($url, 8);
  if ($response === false) {
    return $videoIds; // API error — return unfiltered rather than empty
  }

  $data = json_decode($response, true);
  if (!is_array($data) || !isset($data['items'])) {
    return $videoIds;
  }

  QuotaTracker::spend(1);

  $filtered = [];
  foreach ($data['items'] as $item) {
    $id = isset($item['id']) ? $item['id'] : '';
    if (!preg_match('/^[a-zA-Z0-9_-]{11}$/', $id)) {
      continue;
    }

    // Check embeddable
    $embeddable = isset($item['status']['embeddable']) ? $item['status']['embeddable'] : false;
    if (!$embeddable) {
      continue;
    }

    // Check view count ceiling
    $viewCount = isset($item['statistics']['viewCount']) ? intval($item['statistics']['viewCount']) : -1;
    if ($viewCount >= 0 && $viewCount > $maxViewCount) {
      continue;
    }

    $filtered[] = $id;
  }

  return $filtered;
}

// ============================================================================
// Piped (fallback)
// ============================================================================

/**
 * Search Piped for amateur videos.
 * Used as fallback when YouTube API quota is exhausted or key is missing.
 *
 * Replaces the old Invidious integration — Invidious disabled its API
 * network-wide in early 2026. Piped instances remain active.
 *
 * Strategy:
 * - Pick one random query from the config (variety across refreshes)
 * - Try each Piped instance in order; first success wins
 * - Filter results through title/channel/viewCount checks
 */
function fetchFromPiped() {
  $config = getConfig();
  $instances = $config['piped_instances'];
  $queries = $config['search_queries'];

  if (empty($queries)) {
    return [];
  }

  $query = $queries[array_rand($queries)];

  foreach ($instances as $instance) {
    try {
      $url = $instance . '/search?q=' . urlencode($query) . '&filter=videos';

      $response = curlGet($url, 5);
      if ($response === false) {
        continue; // instance unreachable — try next
      }

      $data = json_decode($response, true);
      if (!is_array($data) || !isset($data['items']) || empty($data['items'])) {
        continue; // malformed or empty response
      }

      // Filter results through title/channel/viewCount checks
      $videos = [];
      foreach ($data['items'] as $item) {
        // Piped returns url like "/watch?v=VIDEO_ID"
        $videoUrl = isset($item['url']) ? $item['url'] : '';
        if (empty($videoUrl) || !preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $videoUrl, $matches)) {
          continue;
        }
        $videoId = $matches[1];

        $title = isset($item['title']) ? $item['title'] : '';
        $author = isset($item['uploaderName']) ? $item['uploaderName'] : '';
        $viewCount = isset($item['views']) ? intval($item['views']) : -1;

        if (isVideoAllowed($config, $title, $author, $viewCount)) {
          $videos[] = $videoId;
        }
      }

      if (!empty($videos)) {
        shuffle($videos);
        return array_slice($videos, 0, $config['pool_size']);
      }
    } catch (Exception $e) {
      continue; // instance error — try next
    }
  }

  return [];
}

// ============================================================================
// YouTube RSS (fallback)
// ============================================================================

/**
 * Fetch videos from YouTube playlist RSS feeds.
 * Only used when YouTube API and Piped both fail.
 * Playlist IDs must be manually curated in getConfig().
 */
function fetchFromPlaylistRSS() {
  $config = getConfig();
  $playlistIds = $config['playlist_ids'];

  if (empty($playlistIds)) {
    return [];
  }

  $allVideos = [];

  foreach ($playlistIds as $playlistId) {
    try {
      $url = 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . urlencode($playlistId);
      $response = curlGet($url, 5);

      if ($response === false) {
        continue;
      }

      $videos = parseYouTubeRSS($config, $response);
      if (!empty($videos)) {
        $allVideos = array_merge($allVideos, $videos);
      }
    } catch (Exception $e) {
      continue;
    }
  }

  if (empty($allVideos)) {
    return [];
  }

  $allVideos = array_unique($allVideos);
  shuffle($allVideos);
  return array_slice($allVideos, 0, $config['pool_size']);
}

/**
 * Parse a YouTube RSS feed XML string into an array of video IDs.
 * Applies the same title filter used for other sources, but without
 * author or viewCount data (RSS doesn't provide those fields).
 */
function parseYouTubeRSS($config, $xml) {
  if (empty($xml)) {
    return [];
  }

  $videos = [];

  try {
    $dom = new DOMDocument();
    $loaded = @$dom->loadXML($xml); // suppress warnings for malformed XML

    if (!$loaded) {
      return [];
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
    $xpath->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');

    $nodeList = $xpath->query('//atom:entry');
    if ($nodeList === false) {
      return [];
    }

    foreach ($nodeList as $entry) {
      $videoIdNodes = $xpath->query('.//yt:videoId', $entry);
      if ($videoIdNodes->length === 0) {
        continue;
      }
      $videoId = trim($videoIdNodes->item(0)->textContent);

      // YouTube video IDs are 11 base64url characters (alphanumeric, -, _)
      if (empty($videoId) || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
        continue;
      }

      $titleNodes = $xpath->query('.//atom:title', $entry);
      $title = $titleNodes->length > 0 ? strtolower(trim($titleNodes->item(0)->textContent)) : '';

      if (isVideoAllowed($config, $title)) {
        $videos[] = $videoId;
      }
    }
  } catch (Exception $e) {
    return [];
  }

  return $videos;
}

// ============================================================================
// HTTP
// ============================================================================

/**
 * Make an HTTP GET request with timeout and performance logging.
 * Returns the response body on 2xx success, false on any failure.
 */
function curlGet($url, $timeout = 5) {
  if (!is_string($url) || empty($url)) {
    return false;
  }

  $ch = curl_init();
  if ($ch === false) {
    return false;
  }

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; bingeing-ahead/1.0)');
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

  $requestStart = microtime(true);
  $response = curl_exec($ch);
  $durationMs = round((microtime(true) - $requestStart) * 1000);

  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // Log every API call for debugging slow/failing instances
  $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
  $status = $httpCode >= 200 && $httpCode < 300 ? 'OK' : "HTTP {$httpCode}";
  PerfLogger::logApiCall($host, $durationMs, $status);

  if ($httpCode >= 200 && $httpCode < 300 && $response !== false && !empty($response)) {
    return $response;
  }

  return false;
}
?>
