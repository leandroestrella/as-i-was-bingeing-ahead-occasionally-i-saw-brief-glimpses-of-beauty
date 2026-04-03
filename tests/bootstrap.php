<?php
/**
 * PHPUnit Bootstrap File
 * Sets up test environment and provides test utilities
 */

// Define test mode
define('TEST_MODE', true);

// Ensure errors are displayed
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define temporary directory for test files
define('TEST_TMP_DIR', sys_get_temp_dir() . '/bingeing-ahead-tests-' . uniqid());
if (!is_dir(TEST_TMP_DIR)) {
    mkdir(TEST_TMP_DIR, 0755, true);
}

// Register cleanup on exit
register_shutdown_function(function () {
    // Clean up test files
    $files = glob(TEST_TMP_DIR . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir(TEST_TMP_DIR)) {
        rmdir(TEST_TMP_DIR);
    }
});

// Autoloader for test classes
spl_autoload_register(function ($class) {
    $prefix = 'Tests\\';
    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
?>
