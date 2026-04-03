<?php
/**
 * Stream State Manager
 *
 * Returns the current stream state: { videoId, startedAt, slotDuration }
 * Advances to the next video if the current slot has expired.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('STATE_FILE', __DIR__ . '/state.json');
define('SLOT_DURATION', 300);  // seconds (5 minutes)

// Check if we should skip to next video
$skipRequested = isset($_GET['skip']) && $_GET['skip'] === '1';

// Lock the state file to prevent race conditions
$lockFile = __DIR__ . '/state.lock';
$lockHandle = fopen($lockFile, 'w');

if ($lockHandle && flock($lockHandle, LOCK_EX)) {
  try {
    $state = readState();
    $nowMs = round(microtime(true) * 1000);
    $elapsedSeconds = ($nowMs - $state['startedAt']) / 1000;

    // Check if current slot has expired or skip was requested
    if ($skipRequested || $elapsedSeconds >= $state['slotDuration']) {
      // Get next video from pool
      $nextVideo = getNextVideo();
      if ($nextVideo) {
        $state = [
          'videoId' => $nextVideo,
          'startedAt' => $nowMs,
          'slotDuration' => SLOT_DURATION
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
  // Fallback if lock fails — just return current state
  echo json_encode(readState());
}

// ============================================================================
// Helper Functions
// ============================================================================

function readState() {
  if (!file_exists(STATE_FILE)) {
    // Bootstrap: get first video and create state
    $video = getNextVideo();
    $state = [
      'videoId' => $video ?: 'dQw4w9WgXcQ',  // fallback video
      'startedAt' => round(microtime(true) * 1000),
      'slotDuration' => SLOT_DURATION
    ];
    writeState($state);
    return $state;
  }

  $json = file_get_contents(STATE_FILE);
  $state = json_decode($json, true);

  if (!$state || !isset($state['videoId'])) {
    // Corrupted state file — reset
    return readState();
  }

  return $state;
}

function writeState($state) {
  $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  file_put_contents(STATE_FILE, $json);
}

function getNextVideo() {
  /**
   * Fetch the next video from the pool.
   * Returns a single random video ID, or null if pool is empty.
   */
  $pool = getPool();
  if (empty($pool)) {
    return null;
  }

  // Pick a random video from the pool
  $randomIndex = array_rand($pool);
  return $pool[$randomIndex];
}

function getPool() {
  /**
   * Fetch the video pool from pool.php
   * pool.php returns a JSON array of video IDs.
   */
  $poolFile = __DIR__ . '/pool-cache.json';

  // Use cached pool if it exists and is fresh (< 1 hour)
  if (file_exists($poolFile)) {
    $mtime = filemtime($poolFile);
    if ($mtime && (time() - $mtime) < 3600) {
      $json = file_get_contents($poolFile);
      $pool = json_decode($json, true);
      if (is_array($pool) && count($pool) > 0) {
        return $pool;
      }
    }
  }

  // Refresh pool from pool.php
  $pool = include(__DIR__ . '/pool.php');

  if (!is_array($pool) || count($pool) === 0) {
    // Fallback to a hardcoded list of reliable, embeddable videos
    $pool = getHardcodedVideoPool();
  }

  // Cache the pool
  if (is_array($pool) && count($pool) > 0) {
    file_put_contents($poolFile, json_encode($pool));
  }

  return $pool;
}

function getHardcodedVideoPool() {
  /**
   * Fallback video pool — YouTube videos known to be:
   * - Embeddable (no embedding restrictions)
   * - Accessible and stable
   * - Varied content (home footage, daily life, mundane moments)
   */
  return [
    '9bZkp7q19f0',  // Cats and nature
    'dQw4w9WgXcQ',  // Rick Roll (iconic, always embeddable)
    'jNQXAC9IVRw',  // Me at the Zoo (first YouTube video)
    'OPf0YbXqDm0',  // Nyan Cat
    'kJQP7kiw9Fk',  // Keyboard cat
    '4qqHaM82FaI',  // Dramatic chipmunk
    'sNMnot5qB9w',  // Numa Numa (iconic web video)
    'ZZ5LpwO-An4',  // Charlie Bit My Finger
    'sTTrG5d8-oc',  // Surprised Koala
    'spnmBkJiKcE',  // David After Dentist
  ];
}
?>
