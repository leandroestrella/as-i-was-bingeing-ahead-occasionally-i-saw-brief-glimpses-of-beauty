<?php
/**
 * Shared Utilities
 *
 * Common functions used by multiple API scripts (perf.php, rate-limit.php).
 * Only add functions here if they're genuinely needed in 2+ places.
 */

/**
 * Resolve the real client IP behind Cloudflare, reverse proxies, or direct connections.
 *
 * Priority order:
 *   1. CF-Connecting-IP — set by Cloudflare, most trustworthy when behind CF
 *   2. X-Forwarded-For  — set by proxies; first IP is the original client
 *   3. REMOTE_ADDR      — direct connection fallback
 *
 * Note: these headers can be spoofed if not behind a trusted proxy.
 * For rate-limiting this is acceptable; for security-critical checks it is not.
 */
function getClientIp() {
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'];
  }
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    // X-Forwarded-For may contain multiple IPs: "client, proxy1, proxy2"
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($ips[0]);
  }
  if (!empty($_SERVER['REMOTE_ADDR'])) {
    return $_SERVER['REMOTE_ADDR'];
  }
  return 'unknown';
}
?>
