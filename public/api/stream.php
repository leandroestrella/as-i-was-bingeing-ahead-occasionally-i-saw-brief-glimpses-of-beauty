<?php
/**
 * Stream State Manager
 *
 * The single source of truth for what every visitor sees right now.
 * Returns JSON: { videoId, startedAt, slotDuration }
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
define('POOL_CACHE_VERSION', '3');   // increment to force a fresh pool fetch
define('POOL_CACHE_TTL', 1800);      // cache lifetime in seconds (30 min)

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
// Used only when both Invidious and YouTube RSS fail.
// Each video was manually verified for embeddability and amateur aesthetic.
// To add more: search YouTube for IMG_0001/VID_20230/etc., test embed at
// https://www.youtube.com/embed/VIDEO_ID, then add the ID here.

const FALLBACK_POOL = [
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
  'Sor6pDozLiY',  // kids garden, 442s
  '_4NeWUWWoCk',  // early morning walk in Troyes, France, 276s
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

    echo json_encode($state);

  } finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
  }
} else {
  // If we can't acquire the lock (high concurrency), serve stale state
  // rather than blocking. Clients will self-correct on next poll.
  echo json_encode(readState());
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

  if (!is_array($state) || !isset($state['videoId'])) {
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
 * Invidious on every request. Cache TTL is POOL_CACHE_TTL seconds.
 * Incrementing POOL_CACHE_VERSION invalidates old caches.
 */
function getPool() {
  $poolFile = __DIR__ . '/pool-cache.v' . POOL_CACHE_VERSION . '.json';

  // Serve from cache if fresh
  if (file_exists($poolFile)) {
    $mtime = filemtime($poolFile);
    if ($mtime && (time() - $mtime) < POOL_CACHE_TTL) {
      $json = file_get_contents($poolFile);
      $pool = json_decode($json, true);
      if (is_array($pool) && count($pool) > 0) {
        return $pool;
      }
    }
  }

  // Cache miss or stale — fetch fresh pool from pool.php
  try {
    $pool = include(__DIR__ . '/pool.php');
  } catch (Exception $e) {
    $pool = [];
  }

  if (!is_array($pool) || count($pool) === 0) {
    $pool = FALLBACK_POOL;
  }

  if (is_array($pool) && count($pool) > 0) {
    file_put_contents($poolFile, json_encode($pool));
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
