<?php
/**
 * Performance Monitoring
 *
 * Logs API call durations and total request times to logs/perf.log.
 * Useful for identifying slow or failing Invidious instances.
 *
 * Log format:
 *   [2026-04-03 14:35:22] API: inv.nadeko.net | 125ms | Status: OK | IP: 1.2.3.4
 *   [2026-04-03 14:35:22] REQUEST: pool.php completed in 342ms | IP: 1.2.3.4
 */

require_once(__DIR__ . '/utils.php');

class PerfLogger {
  private static $logFile;

  public static function init() {
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) {
      @mkdir($logDir, 0755, true);
    }
    self::$logFile = $logDir . '/perf.log';
  }

  /** Log an individual API call (e.g. one Invidious instance request). */
  public static function logApiCall($endpoint, $durationMs, $status, $details = '') {
    if (!self::$logFile) {
      self::init();
    }

    $timestamp = date('Y-m-d H:i:s');
    $clientIp = getClientIp();
    $logLine = "[{$timestamp}] API: {$endpoint} | {$durationMs}ms | Status: {$status}";

    if ($details) {
      $logLine .= " | {$details}";
    }
    $logLine .= " | IP: {$clientIp}\n";

    // LOCK_EX prevents interleaved writes when multiple PHP processes
    // append to the same log file concurrently
    @file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
  }

  /** Log total request duration for an entire script (stream.php, pool.php). */
  public static function logRequestDuration($endpoint, $durationMs) {
    if (!self::$logFile) {
      self::init();
    }

    $timestamp = date('Y-m-d H:i:s');
    $clientIp = getClientIp();
    $logLine = "[{$timestamp}] REQUEST: {$endpoint} completed in {$durationMs}ms | IP: {$clientIp}\n";

    @file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
  }
}

PerfLogger::init();
?>
