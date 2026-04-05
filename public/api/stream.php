<?php
/**
 * Stream State Manager
 *
 * The single source of truth for what every visitor sees right now.
 * Returns JSON: { videoId, startedAt, slotDuration, poolSource }
 *
 * On each request:
 *   1. Acquire file lock (prevents race conditions from concurrent clients)
 *   2. Read state.json
 *   3. If the current slot has expired (or ?skip=1), pick a new video
 *   4. Return the current state
 *
 * All clients compute the same playback offset from startedAt,
 * so everyone sees the same video at the same position.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// --- Configuration -----------------------------------------------------------

define('STATE_FILE', __DIR__ . '/state.json');
define('LOCK_FILE', __DIR__ . '/state.lock');
define('SLOT_MIN', 10);              // minimum slot duration (seconds)
define('SLOT_MAX', 120);             // maximum slot duration (seconds)
define('POOL_CACHE_VERSION', '4');       // increment to force a fresh pool fetch
define('POOL_CACHE_TTL', 1800);          // cache lifetime in seconds (30 min)
define('POOL_CACHE_TTL_FAILURE', 300);   // shorter TTL when serving fallback (5 min)

// --- Rate limiting -----------------------------------------------------------

require_once(__DIR__ . '/rate-limit.php');
RateLimiter::configure(100, 60); // 100 requests per 60 seconds per IP

if (!RateLimiter::isAllowed()) {
  header('HTTP/1.1 429 Too Many Requests');
  header('Retry-After: 60');
  echo json_encode([
    'error' => 'Rate limit exceeded',
    'retry_after' => 60,
    'remaining' => RateLimiter::getRemaining()
  ]);
  exit;
}

// --- Performance monitoring --------------------------------------------------

$startTime = microtime(true);
require_once(__DIR__ . '/perf.php');

// --- Fallback pool -----------------------------------------------------------
// Used only when YouTube API, Piped, and RSS all fail.
// Each video was manually verified for embeddability and amateur aesthetic.
// To add more: search YouTube for IMG_0001/VID_20230/etc., test embed at
// https://www.youtube.com/embed/VIDEO_ID, then add the ID here.

const FALLBACK_POOL = [
  // === Original pool (manually verified) ===
  'jNQXAC9IVRw',  // Me at the zoo — first YouTube video, genuinely amateur
  'O9NVK12Udj4',  // MOV_0001 — untitled amateur upload, 388s
  'N-B6I9HgA-4',  // IMG_0002.mp4 — untitled amateur upload, 157s
  'N4shgVitgxU',  // MOV_0001.mp4 — untitled amateur upload, 97s
  'DjhGJIBUYWQ',  // IMG_0002 — untitled amateur upload, 108s
  'PdH25sGIlkM',  // MOV_0001.mp4 — untitled amateur upload, 262s
  '_JCtlXFmXk4',  // MOV_0001.wmv — untitled amateur upload, 263s
  '5lEiSSPxqXE',  // MOV_0001.mp4 — untitled amateur upload, 62s
  'ryhc8_HxwVM',  // IMG_0002.mp4 — untitled amateur upload, 88s
  'LKRvZ9rZEbA',  // IMG_0001.avi — untitled amateur upload, 75s
  'GaaMh42NasM',  // daily commute to work in LA, 327s
  'Ruy_KuILcf0',  // morning dog walk, UK POV, 671s
  'r066gsM2mWU',  // 1970 Rainey family home video, 813s
  '_4NeWUWWoCk',  // early morning walk in Troyes, France, 276s
  // === Italian street footage ===
  '3J5eBZ1eZp8',  // joniuA
  '8Qk3V5lQEgo',  // deCarloFence
  'WnGm1ulheH4',  // sagraCroceRossa
  'LCekcF41R60',  // taorminaGlitchFS
  'Kj2upCRXPVI',  // catodicoRiposto
  'YIk0eKOrYEs',  // busteFaenzaFS
  '2IIjnyGT0EY',  // 03 Mercatale
  'hL_7p_CkooI',  // 05 Sanzio
  's2taiRr8Vl0',  // 06 Ducale
  'LsoLmD3gFKw',  // 08 Repubblica
  'sA9nuZFGhOw',  // 10 Raffaello
  // === Travel / vacation footage ===
  'ycmOU6p8ozk',  // Invergarry Culloden and Inverness 2024
  'sNdPFqfEeyg',  // Edinburgh 2024
  'iSek6GZpKJ4',  // Yellowstone Videos 2024
  'XF64OZahV-Q',  // Yellowstone and Grand Tetons 2024
  // === Default camera filenames (IMG/MOV/DSCF) ===
  'bu-zyEG_3Lw',  // IMG_0554
  'qhpr9kCwy84',  // IMG 2081
  'Awb4WOkYiYY',  // IMG_1106.MOV
  'dhg9wHnzt0I',  // IMG 1935
  'SI6Zped0odc',  // IMG_0000
  'X4nUbe-ql8o',  // IMG_0000.mov
  'A7t0VXUboeU',  // IMG_0001.mp4
  'HrFTg0ZOvqE',  // IMG_0
  '-R8QsuY0Noc',  // Img_
  'oIDYbT2yYis',  // IMG 0612
  'o_aryrAb8zA',  // IMG_
  'NQUxOpRcSUE',  // IMG_4569
  'mHvb4d66S4w',  // IMG_8910
  'GFbdUrMw82o',  // IMG_ 228.MOV
  'pAdjKt4tkzY',  // 3 464 MOV
  'lzgkA0TYClQ',  // DSCF0001
  'OLmg8bRrAUs',  // IMG_6,,,.AVI
  // === Domestic / mundane moments ===
  'BlxJDFl21po',  // Zebra finch playing with hair tie
  'b9UO9tn4MpI',  // Listening radio with grass
  'ByKmsHdhra8',  // How to Fold
  // === Multilingual amateur footage ===
  'E6W-KsL_5qo',  // 白沙屯媽祖：松山車站：IMG_3014
  'sar4MONgTXg',  // 白沙屯媽祖：竹南車站：IMG_3029
  '-2Jke10WmHw',  // 白沙屯媽祖：粉紅超跑體驗一晚
];

// --- Main request handling ---------------------------------------------------

// ?skip=1 forces advancement even if the slot hasn't expired
// (used by the frontend when YouTube reports a video as unembeddable)
$skipRequested = !empty($_GET['skip']) && $_GET['skip'] === '1';

// File lock ensures only one process reads/writes state.json at a time.
// Without this, two requests arriving at the slot boundary could both
// advance the video, causing a double-skip.
$lockHandle = fopen(LOCK_FILE, 'w');

if ($lockHandle && flock($lockHandle, LOCK_EX)) {
  try {
    $state = readState();

    // All timestamps are in milliseconds for JavaScript compatibility
    $nowMs = round(microtime(true) * 1000);
    $elapsedSeconds = ($nowMs - $state['startedAt']) / 1000;

    if ($skipRequested || $elapsedSeconds >= $state['slotDuration']) {
      $nextVideo = getNextVideo();
      if ($nextVideo) {
        // Each slot gets a random duration for variety
        $state = [
          'videoId' => $nextVideo,
          'startedAt' => $nowMs,
          'slotDuration' => rand(SLOT_MIN, SLOT_MAX)
        ];
        writeState($state);
      }
    }

    echo json_encode(withPoolSource($state));

  } finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
  }
} else {
  // If we can't acquire the lock (high concurrency), serve stale state
  // rather than blocking. Clients will self-correct on next poll.
  echo json_encode(withPoolSource(readState()));
}

$durationMs = round((microtime(true) - $startTime) * 1000);
PerfLogger::logRequestDuration('stream.php', $durationMs);

// ============================================================================
// State management
// ============================================================================

/** Read state.json, or initialize it if missing/corrupted. */
function readState() {
  if (!file_exists(STATE_FILE)) {
    return initializeState();
  }

  $json = file_get_contents(STATE_FILE);
  $state = json_decode($json, true);

  // All three fields are required — missing any one breaks the sync formula
  if (!is_array($state)
      || !isset($state['videoId'], $state['startedAt'], $state['slotDuration'])) {
    return initializeState();
  }

  return $state;
}

/** Create initial state with a video from the pool (or fallback). */
function initializeState() {
  $video = getNextVideo();
  if (!$video) {
    $video = FALLBACK_POOL[0];
  }

  $state = [
    'videoId' => $video,
    'startedAt' => round(microtime(true) * 1000),
    'slotDuration' => rand(SLOT_MIN, SLOT_MAX)
  ];
  writeState($state);
  return $state;
}

function writeState($state) {
  file_put_contents(STATE_FILE, json_encode($state));
}

/** Append poolSource to the response so the frontend can detect fallback mode. */
function withPoolSource($state) {
  // Static cache avoids re-reading the file within the same request
  static $source = null;
  if ($source === null) {
    $source = 'unknown';
    $poolFile = __DIR__ . '/pool-cache.v' . POOL_CACHE_VERSION . '.json';
    if (file_exists($poolFile)) {
      $cached = json_decode(file_get_contents($poolFile), true);
      if (is_array($cached) && isset($cached['source'])) {
        $source = $cached['source'];
      }
    }
  }
  $state['poolSource'] = $source;
  return $state;
}

// ============================================================================
// Pool management
// ============================================================================

/** Pick a random video from the pool. */
function getNextVideo() {
  $pool = getPool();
  if (empty($pool)) {
    return null;
  }
  return $pool[array_rand($pool)];
}

/**
 * Get the video pool, using a versioned cache to avoid hitting
 * APIs on every request. Cache TTL depends on the source:
 * live sources get POOL_CACHE_TTL, fallback gets POOL_CACHE_TTL_FAILURE
 * so the system retries sooner when all sources are down.
 * Incrementing POOL_CACHE_VERSION invalidates old caches.
 */
function getPool() {
  $poolFile = __DIR__ . '/pool-cache.v' . POOL_CACHE_VERSION . '.json';

  // Serve from cache if fresh
  if (file_exists($poolFile)) {
    $mtime = filemtime($poolFile);
    if ($mtime) {
      $json = file_get_contents($poolFile);
      $cached = json_decode($json, true);

      if (is_array($cached)) {
        // Support both old format (bare array) and new format (object with metadata)
        $pool = isset($cached['pool']) ? $cached['pool'] : $cached;
        $source = isset($cached['source']) ? $cached['source'] : 'unknown';
        $ttl = ($source === 'fallback') ? POOL_CACHE_TTL_FAILURE : POOL_CACHE_TTL;

        if ((time() - $mtime) < $ttl && is_array($pool) && count($pool) > 0) {
          return $pool;
        }
      }
    }
  }

  // Cache miss or stale — fetch fresh pool from pool.php
  try {
    $pool = include(__DIR__ . '/pool.php');
  } catch (Exception $e) {
    $pool = [];
  }

  $source = isset($GLOBALS['pool_source']) ? $GLOBALS['pool_source'] : 'unknown';

  if (!is_array($pool) || count($pool) === 0) {
    $pool = FALLBACK_POOL;
    $source = 'fallback';
  }

  if (is_array($pool) && count($pool) > 0) {
    $cacheData = ['pool' => $pool, 'source' => $source];
    file_put_contents($poolFile, json_encode($cacheData));
    cleanupOldCacheFiles($poolFile);
  }

  return $pool;
}

/** Remove cache files from previous POOL_CACHE_VERSION values. */
function cleanupOldCacheFiles($currentFile) {
  foreach (glob(__DIR__ . '/pool-cache.v*.json') as $file) {
    if ($file !== $currentFile) {
      @unlink($file);
    }
  }
  // Legacy un-versioned cache from earlier codebase versions
  $legacy = __DIR__ . '/pool-cache.json';
  if (file_exists($legacy)) {
    @unlink($legacy);
  }
}

?>
