<?php
/**
 * YouTube Data API Quota Tracker
 *
 * Tracks daily quota usage in a JSON file. The YouTube API grants
 * 10,000 units/day, resetting at midnight Pacific Time.
 *
 * Usage:
 *   QuotaTracker::canSpend(100)  → true if 100 units are available
 *   QuotaTracker::spend(100)     → deduct 100 units, returns true on success
 *   QuotaTracker::getRemaining() → units left today
 */

class QuotaTracker {
  private static $file = __DIR__ . '/quota-usage.json';
  private static $dailyLimit = 10000;
  private static $safetyMargin = 1000;

  /** Effective ceiling: dailyLimit minus safetyMargin. */
  private static function effectiveLimit() {
    return self::$dailyLimit - self::$safetyMargin;
  }

  /**
   * Check whether we can afford $units without exceeding the budget.
   * Read-only — does not acquire a lock.
   */
  public static function canSpend($units) {
    $state = self::load();
    return ($state['used'] + $units) <= self::effectiveLimit();
  }

  /**
   * Atomically deduct $units from today's budget.
   * Lock wraps the entire read-check-write cycle to prevent
   * concurrent processes from double-spending.
   *
   * Note: canSpend() reads without a lock intentionally — it's a
   * cheap pre-check to avoid unnecessary API calls. If two processes
   * race past canSpend(), this lock ensures only one succeeds.
   */
  public static function spend($units) {
    $lockFile = self::$file . '.lock';
    $lockHandle = @fopen($lockFile, 'w');
    if ($lockHandle) {
      flock($lockHandle, LOCK_EX);
    }

    try {
      $state = self::load();

      if (($state['used'] + $units) > self::effectiveLimit()) {
        return false;
      }

      $state['used'] += $units;
      self::save($state);
      return true;
    } finally {
      if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
      }
    }
  }

  /** How many units remain before hitting the safety margin. */
  public static function getRemaining() {
    $state = self::load();
    return max(0, self::effectiveLimit() - $state['used']);
  }

  /**
   * Load state, auto-resetting if the date has rolled past midnight PT.
   */
  private static function load() {
    $today = self::todayPT();

    if (!file_exists(self::$file)) {
      return ['date' => $today, 'used' => 0];
    }

    $json = file_get_contents(self::$file);
    $state = json_decode($json, true);

    if (!is_array($state) || !isset($state['date']) || $state['date'] !== $today) {
      return ['date' => $today, 'used' => 0];
    }

    return $state;
  }

  private static function save($state) {
    file_put_contents(self::$file, json_encode($state));
  }

  /** YouTube quota resets at midnight Pacific Time. */
  private static function todayPT() {
    return (new DateTime('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
  }
}
?>
