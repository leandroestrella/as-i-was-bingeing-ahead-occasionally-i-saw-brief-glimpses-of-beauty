<?php
/**
 * Video Pool Fetcher
 *
 * Builds a pool of ~30 amateur video IDs for the stream.
 *
 * Fetching priority:
 *   1. Invidious API search (free, no key) — primary source
 *   2. YouTube playlist RSS feeds — fallback if all Invidious instances are down
 *   3. Hardcoded FALLBACK_POOL in stream.php — last resort
 *
 * This file is included by stream.php via `include()` and returns an array.
 */

$startTime = microtime(true);
require_once(__DIR__ . '/perf.php');

/**
 * Central configuration for video pool fetching.
 * All tunable values live here so they can be adjusted without
 * hunting through function bodies.
 */
function getConfig() {
  return [
    'pool_size' => 30,           // max videos to return per pool refresh
    'max_view_count' => 50000,   // reject videos above this — high views = likely professional

    // Tried in order; first successful response wins.
    // Check https://docs.invidious.io/instances/ for uptime.
    'invidious_instances' => [
      'https://inv.nadeko.net',
      'https://invidious.io',
      'https://yt.cdaut.de',
      'https://invidious.privacydev.net',
      'https://invidious.garudalinux.org',
      'https://iv.datura.network',
      'https://invidious.jing.rocks',
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
    // Add manually verified playlists here — each should contain
    // actual amateur footage, not curated/commercial content.
    'playlist_ids' => [],

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

// Primary: Invidious API
$pool = fetchFromInvidious();

// Fallback: YouTube playlist RSS (only if Invidious returned nothing)
if (empty($pool)) {
  $pool = fetchFromPlaylistRSS();
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
 * @param string $title     Video title (any case — lowercased internally)
 * @param string $author    Channel name (optional, empty string if unknown)
 * @param int    $viewCount View count (-1 if unknown, skips the check)
 */
function isVideoAllowed($title, $author = '', $viewCount = -1) {
  $config = getConfig();
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
// Invidious (primary source)
// ============================================================================

/**
 * Search Invidious for amateur videos.
 *
 * Strategy:
 * - Pick one random query from the config (variety across refreshes)
 * - Sort by upload_date instead of relevance — relevance surfaces
 *   SEO-optimized professional content; upload_date surfaces genuine
 *   recent personal uploads
 * - Randomize page 1-5 so repeated queries still yield different results
 * - duration=medium (4-20 min) fits the slot duration range
 * - Try each instance in order; first success wins
 */
function fetchFromInvidious() {
  $config = getConfig();
  $instances = $config['invidious_instances'];
  $queries = $config['search_queries'];

  if (empty($queries)) {
    return [];
  }

  $query = $queries[array_rand($queries)];
  $page = rand(1, 5);

  foreach ($instances as $instance) {
    try {
      $url = $instance . '/api/v1/search?q=' . urlencode($query)
        . '&type=video&duration=medium&sort_by=upload_date&page=' . intval($page);

      $response = curlGet($url, 5);
      if ($response === false) {
        continue; // instance unreachable — try next
      }

      $data = json_decode($response, true);
      if (!is_array($data) || empty($data)) {
        continue; // malformed or empty response
      }

      // Filter results through title/channel/viewCount checks
      $videos = [];
      foreach ($data as $item) {
        if (!isset($item['videoId']) || !is_string($item['videoId']) || empty($item['videoId'])) {
          continue;
        }

        $title = isset($item['title']) ? $item['title'] : '';
        $author = isset($item['author']) ? $item['author'] : '';
        $viewCount = isset($item['viewCount']) ? intval($item['viewCount']) : -1;

        if (isVideoAllowed($title, $author, $viewCount)) {
          $videos[] = $item['videoId'];
        }
      }

      if (!empty($videos)) {
        // Shuffle before slicing so the pool isn't biased toward
        // whichever videos Invidious returns first
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
 * Only used when all Invidious instances fail.
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

      $videos = parseYouTubeRSS($response);
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
 * Applies the same title filter used for Invidious results, but without
 * author or viewCount data (RSS doesn't provide those fields).
 */
function parseYouTubeRSS($xml) {
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
    $xpath->registerNamespace('yt', 'http://www.youtube.com/xml/schemas/2015/12/search.xsd');

    $nodeList = $xpath->query('//entry');
    if ($nodeList === false) {
      return [];
    }

    foreach ($nodeList as $entry) {
      $videoIdNodes = $xpath->query('.//yt:videoId', $entry);
      if ($videoIdNodes->length === 0) {
        continue;
      }
      $videoId = trim($videoIdNodes->item(0)->textContent);

      // YouTube video IDs are always 11 alphanumeric characters
      if (empty($videoId) || strlen($videoId) !== 11 || !ctype_alnum($videoId)) {
        continue;
      }

      $titleNodes = $xpath->query('.//title', $entry);
      $title = $titleNodes->length > 0 ? strtolower(trim($titleNodes->item(0)->textContent)) : '';

      if (isVideoAllowed($title)) {
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
