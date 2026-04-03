<?php
/**
 * Rate Limiting
 *
 * Tracks requests per IP address with configurable limits.
 * Stores request timestamps in a JSON file (logs/rate-limit.json).
 *
 * Uses file locking around the read-modify-write cycle to prevent
 * TOCTOU (time-of-check-time-of-use) race conditions: without the lock,
 * two concurrent requests could both read the same count, both increment,
 * and write back — effectively losing one count.
 */

require_once(__DIR__ . '/utils.php');

class RateLimiter {
  const DEFAULT_LIMIT = 100;   // requests per window
  const DEFAULT_WINDOW = 60;   // window size in seconds

  private static $storageFile;
  private static $limit;
  private static $window;

  /** Set the rate limit. Call before isAllowed(). */
  public static function configure($limit = self::DEFAULT_LIMIT, $window = self::DEFAULT_WINDOW) {
    self::$limit = $limit;
    self::$window = $window;
  }

  /**
   * Check whether this IP's request should be allowed.
   * Also records the request timestamp if allowed.
   *
   * @return bool true = allowed, false = rate limit exceeded
   */
  public static function isAllowed($ip = null) {
    if (!$ip) {
      $ip = getClientIp();
    }

    if (!self::$storageFile) {
      self::initStorage();
    }

    $now = time();

    // Lock prevents concurrent requests from reading stale counts
    $lockFile = self::$storageFile . '.lock';
    $lockHandle = @fopen($lockFile, 'w');
    if ($lockHandle) {
      flock($lockHandle, LOCK_EX);
    }

    try {
      $requests = self::getRequestLog();

      // Evict timestamps older than the window for all IPs
      foreach ($requests as $recordedIp => $timestamps) {
        $requests[$recordedIp] = array_values(array_filter($timestamps, function($ts) use ($now) {
          return ($now - $ts) < self::$window;
        }));
        // Remove IP entry entirely if no recent requests remain (keeps file small)
        if (empty($requests[$recordedIp])) {
          unset($requests[$recordedIp]);
        }
      }

      $ipRequests = isset($requests[$ip]) ? count($requests[$ip]) : 0;

      if ($ipRequests >= self::$limit) {
        return false;
      }

      // Record this request
      if (!isset($requests[$ip])) {
        $requests[$ip] = [];
      }
      $requests[$ip][] = $now;
      self::saveRequestLog($requests);

      return true;
    } finally {
      if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
      }
    }
  }

  /** How many more requests this IP can make in the current window. */
  public static function getRemaining($ip = null) {
    if (!$ip) {
      $ip = getClientIp();
    }

    if (!self::$storageFile) {
      self::initStorage();
    }

    $now = time();
    $requests = self::getRequestLog();

    if (!isset($requests[$ip])) {
      return self::$limit;
    }

    $active = array_filter($requests[$ip], function($ts) use ($now) {
      return ($now - $ts) < self::$window;
    });

    return max(0, self::$limit - count($active));
  }

  // ========================================================================
  // Private helpers
  // ========================================================================

  private static function initStorage() {
    $storageDir = __DIR__ . '/../../logs';
    if (!is_dir($storageDir)) {
      @mkdir($storageDir, 0755, true);
    }
    self::$storageFile = $storageDir . '/rate-limit.json';

    if (!self::$limit) {
      self::$limit = self::DEFAULT_LIMIT;
      self::$window = self::DEFAULT_WINDOW;
    }
  }

  private static function getRequestLog() {
    if (!file_exists(self::$storageFile)) {
      return [];
    }
    $json = @file_get_contents(self::$storageFile);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
  }

  private static function saveRequestLog($requests) {
    // LOCK_EX for atomic write (separate from the advisory lock above,
    // which guards the full read-modify-write cycle)
    @file_put_contents(self::$storageFile, json_encode($requests), LOCK_EX);
  }
}
?>
