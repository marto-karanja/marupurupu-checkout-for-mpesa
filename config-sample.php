<?php
/**
 * Sample Configuration File
 *
 * Copy this file to config.php and fill in your actual credentials.
 * DO NOT commit config.php to version control!
 *
 * This is an alternative way to store credentials if you prefer
 * not to use the WordPress admin interface.
 */

// Uncomment and fill in your credentials

// Test/Sandbox Credentials
/*
define('WC_MPESA_TEST_CONSUMER_KEY', 'your_test_consumer_key_here');
define('WC_MPESA_TEST_CONSUMER_SECRET', 'your_test_consumer_secret_here');
define('WC_MPESA_TEST_PASSKEY', 'your_test_passkey_here');
define('WC_MPESA_TEST_TILL_NUMBER', '174379');
*/

// Production/Live Credentials
/*
define('WC_MPESA_LIVE_CONSUMER_KEY', 'your_live_consumer_key_here');
define('WC_MPESA_LIVE_CONSUMER_SECRET', 'your_live_consumer_secret_here');
define('WC_MPESA_LIVE_PASSKEY', 'your_live_passkey_here');
define('WC_MPESA_LIVE_TILL_NUMBER', 'your_till_number_here');
*/

// General Settings
/*
define('WC_MPESA_TEST_MODE', true); // Set to false for production
define('WC_MPESA_DEBUG', true); // Enable detailed logging
*/

/**
 * To use these constants in the plugin, modify the gateway class
 * to check for these constants before using settings from the database.
 *
 * Example:
 * $this->consumer_key = defined('WC_MPESA_LIVE_CONSUMER_KEY')
 *     ? WC_MPESA_LIVE_CONSUMER_KEY
 *     : $this->get_option('consumer_key');
 */
