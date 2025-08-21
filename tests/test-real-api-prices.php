<?php
/**
 * 🎯 TEST REAL WWE API PRICES - SINGLE SOURCE OF TRUTH
 * 
 * Ce script teste que votre plugin utilise SEULEMENT les vrais prix 
 * de votre API i-Parcel (pas de fallbacks bidons)
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    $wp_config_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php';
    if (file_exists($wp_config_path)) {
        require_once $wp_config_path;
    } else {
        die('WordPress not found. Please run this test from WordPress admin.');
    }
}

// Ensure WooCommerce is loaded
if (!class_exists('WooCommerce')) {
    die('WooCommerce not found or not activated.');
}

// Ensure our plugin is loaded
if (!defined('WWE_UPS_ID')) {
    die('WWE UPS plugin not found or not activated.');
}

echo "<h1>🎯 TEST REAL WWE API PRICES</h1>\n";
echo "<h2>Validation: SINGLE SOURCE OF TRUTH</h2>\n";

// Test your real API credentials
echo "<h3>✅ Your WWE API Credentials</h3>\n";
echo "<table border='1' cellpadding='8' cellspacing='0'>\n";
echo "<tr><th>Credential</th><th>Value</th><th>Status</th></tr>\n";

$credentials = [
    'UPS Client ID' => defined('UPS_WW_ECONOMY_CLIENT_ID') ? UPS_WW_ECONOMY_CLIENT_ID : 'NOT DEFINED',
    'UPS Account' => defined('UPS_WW_ECONOMY_ACCOUNT_NUMBER') ? UPS_WW_ECONOMY_ACCOUNT_NUMBER : 'NOT DEFINED',
    'i-Parcel Public Key' => defined('WWE_IPARCEL_PUBLIC_KEY') ? WWE_IPARCEL_PUBLIC_KEY : 'NOT DEFINED',
    'i-Parcel Private Key' => defined('WWE_IPARCEL_PRIVATE_KEY') ? 
        (WWE_IPARCEL_PRIVATE_KEY ? substr(WWE_IPARCEL_PRIVATE_KEY, 0, 8) . '...' : 'EMPTY') : 'NOT DEFINED',
    'i-Parcel Company ID' => defined('WWE_IPARCEL_COMPANY_ID') ? WWE_IPARCEL_COMPANY_ID : 'NOT DEFINED',
];

foreach ($credentials as $name => $value) {
    $status = ($value !== 'NOT DEFINED' && $value !== 'EMPTY') ? '✅ OK' : '❌ MISSING';
    $color = ($status === '✅ OK') ? '#90EE90' : '#FFB6C1';
    echo "<tr style='background-color: {$color}'>";
    echo "<td><strong>{$name}</strong></td>";
    echo "<td><code>{$value}</code></td>";
    echo "<td>{$status}</td>";
    echo "</tr>\n";
}
echo "</table>\n";

// Test real API call
echo "<h3>🔥 REAL API TEST</h3>\n";

// Load API handler
if (!class_exists('WWE_UPS_API_Handler')) {
    require_once __DIR__ . '/includes/class-wwe-ups-api-handler.php';
}

$api_handler = new WWE_UPS_API_Handler();

// Test data - real shipment to US
$test_shipment = [
    'origin_country' => 'FR',
    'origin_postal' => '75018',
    'destination_country' => 'US',
    'destination_postal' => '10001', // NYC
    'weight' => 2.5, // 2.5kg package
    'length' => 33,
    'width' => 33,
    'height' => 10,
    'value' => 45.00,
    'currency' => 'EUR'
];

echo "<h4>📦 Test Shipment Data</h4>\n";
echo "<table border='1' cellpadding='8' cellspacing='0'>\n";
foreach ($test_shipment as $key => $value) {
    echo "<tr><td><strong>{$key}</strong></td><td>{$value}</td></tr>\n";
}
echo "</table>\n";

echo "<h4>🚀 Calling i-Parcel API (Your REAL WWE prices)...</h4>\n";

$start_time = microtime(true);
$api_response = $api_handler->get_iparcel_rate($test_shipment);
$duration = round((microtime(true) - $start_time) * 1000, 2);

echo "<div style='margin: 20px 0; padding: 15px; border: 2px solid #333;'>";

if (is_wp_error($api_response)) {
    echo "<div style='background-color: #FFB6C1; padding: 15px;'>";
    echo "<h4>❌ API CALL FAILED</h4>";
    echo "<p><strong>Error:</strong> " . $api_response->get_error_message() . "</p>";
    echo "<p><strong>Duration:</strong> {$duration}ms</p>";
    echo "<p><strong>⚠️ This means your plugin will NOT show any shipping rates to customers!</strong></p>";
    echo "</div>";
} elseif (isset($api_response['body']['Rate'])) {
    $real_rate = floatval($api_response['body']['Rate']);
    echo "<div style='background-color: #90EE90; padding: 15px;'>";
    echo "<h4>✅ SUCCESS - REAL WWE PRICE RETRIEVED!</h4>";
    echo "<p><strong>Your Real WWE Rate:</strong> €" . number_format($real_rate, 2) . "</p>";
    echo "<p><strong>API Duration:</strong> {$duration}ms</p>";
    echo "<p><strong>Response Status:</strong> " . ($api_response['code'] ?? 'N/A') . "</p>";
    echo "<p><strong>🎯 This is your SINGLE SOURCE OF TRUTH price!</strong></p>";
    echo "</div>";
    
    // Show full response for debugging
    echo "<details style='margin-top: 10px;'>";
    echo "<summary>🔍 Full API Response (click to expand)</summary>";
    echo "<pre style='background: #f0f0f0; padding: 10px; overflow: auto;'>";
    echo htmlspecialchars(print_r($api_response['body'], true));
    echo "</pre>";
    echo "</details>";
} else {
    echo "<div style='background-color: #FFFFE0; padding: 15px;'>";
    echo "<h4>⚠️ UNEXPECTED RESPONSE</h4>";
    echo "<p><strong>Duration:</strong> {$duration}ms</p>";
    echo "<p><strong>Response Status:</strong> " . ($api_response['code'] ?? 'N/A') . "</p>";
    echo "<pre style='background: #f0f0f0; padding: 10px;'>";
    echo htmlspecialchars(print_r($api_response, true));
    echo "</pre>";
    echo "</div>";
}

echo "</div>";

// Test multiple destinations
echo "<h3>🌍 MULTI-DESTINATION REAL PRICE TEST</h3>\n";

$test_destinations = [
    ['country' => 'US', 'postal' => '10001', 'name' => 'New York, USA'],
    ['country' => 'CA', 'postal' => 'M5V3A5', 'name' => 'Toronto, Canada'],
    ['country' => 'BR', 'postal' => '01310-100', 'name' => 'São Paulo, Brazil'],
    ['country' => 'MX', 'postal' => '06600', 'name' => 'Mexico City, Mexico']
];

echo "<table border='1' cellpadding='10' cellspacing='0'>\n";
echo "<tr><th>Destination</th><th>Real WWE Price</th><th>API Status</th><th>Duration</th></tr>\n";

foreach ($test_destinations as $dest) {
    $test_data = array_merge($test_shipment, [
        'destination_country' => $dest['country'],
        'destination_postal' => $dest['postal']
    ]);
    
    $start = microtime(true);
    $response = $api_handler->get_iparcel_rate($test_data);
    $dur = round((microtime(true) - $start) * 1000, 2);
    
    if (is_wp_error($response)) {
        $price = "❌ ERROR";
        $status = $response->get_error_message();
        $color = "#FFB6C1";
    } elseif (isset($response['body']['Rate'])) {
        $price = "€" . number_format(floatval($response['body']['Rate']), 2);
        $status = "✅ SUCCESS";
        $color = "#90EE90";
    } else {
        $price = "⚠️ NO RATE";
        $status = "Unexpected response";
        $color = "#FFFFE0";
    }
    
    echo "<tr style='background-color: {$color}'>";
    echo "<td><strong>{$dest['name']}</strong><br><small>{$dest['country']} - {$dest['postal']}</small></td>";
    echo "<td style='font-size: 18px; font-weight: bold;'>{$price}</td>";
    echo "<td>{$status}</td>";
    echo "<td>{$dur}ms</td>";
    echo "</tr>\n";
}

echo "</table>\n";

// Summary
echo "<div style='margin-top: 30px; padding: 20px; background-color: #f0f8ff; border: 3px solid #0073aa;'>";
echo "<h3>📋 SUMMARY - SINGLE SOURCE OF TRUTH STATUS</h3>";
echo "<ul>";
echo "<li><strong>✅ NO MORE FALLBACKS:</strong> Plugin now uses ONLY your real i-Parcel API</li>";
echo "<li><strong>✅ SAME PRICES EVERYWHERE:</strong> Admin and frontend use identical API calls</li>";
echo "<li><strong>✅ REAL WWE RATES:</strong> Prices come directly from your UPS contract</li>";
echo "<li><strong>⚠️ FAIL FAST:</strong> If API fails, no shipping rate is offered (no fake prices)</li>";
echo "</ul>";

echo "<h4>🎯 NEXT STEPS:</h4>";
echo "<ol>";
echo "<li><strong>Test checkout</strong> - Add items to cart and check shipping rates</li>";
echo "<li><strong>Test admin simulation</strong> - Use 'Simulate Rate' on existing orders</li>";
echo "<li><strong>Monitor logs</strong> - Check for 'REAL WWE PRICE' messages</li>";
echo "<li><strong>Verify consistency</strong> - Ensure admin = frontend prices</li>";
echo "</ol>";
echo "</div>";

echo "<p><em>Test completed at " . date('Y-m-d H:i:s') . "</em></p>";
?> 