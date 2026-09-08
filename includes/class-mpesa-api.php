<?php
/**
 * M-Pesa API Integration Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Mpesa_API {

    private $consumer_key;
    private $consumer_secret;
    private $shortcode;
    private $till_number;
    private $passkey;
    private $testmode;
    private $base_url;

    public function __construct($consumer_key, $consumer_secret, $shortcode, $till_number, $passkey, $testmode = true) {
        $this->consumer_key = $consumer_key;
        $this->consumer_secret = $consumer_secret;
        $this->shortcode = $shortcode;
        $this->till_number = $till_number;
        $this->passkey = $passkey;
        $this->testmode = $testmode;
        $this->base_url = $testmode ? 'https://sandbox.safaricom.co.ke' : 'https://api.safaricom.co.ke';
    }

    /**
     * Generate access token
     */
    private function get_access_token() {
        $result = $this->request_oauth_token();

        if ($result['raw'] === false) {
            error_log('M-Pesa API Error (get_access_token): ' . $result['http_error']);
            return false;
        }

        if ($result['status'] == 200) {
            $decoded = json_decode($result['raw'], true);
            return isset($decoded['access_token']) ? $decoded['access_token'] : false;
        }

        // Non-200 from Safaricom (typically 400/401 for bad/mismatched
        // credentials) previously returned false with zero detail logged,
        // leaving "Failed to get access token" with no way to tell why.
        // Always log this -- not gated behind WP_DEBUG -- since it's a rare
        // failure path and the response body is the only way to tell wrong
        // credentials apart from a wrong environment (sandbox vs live) or a
        // Safaricom-side outage.
        error_log('M-Pesa API Error (get_access_token): HTTP ' . $result['status'] . ' -- ' . $result['raw']);

        return false;
    }

    /**
     * Perform the OAuth token request and return the raw outcome, without
     * interpreting it. Shared by get_access_token() (internal, used by
     * stk_push()/query_stk_status(), returns just the token or false) and
     * test_connection() (admin-facing diagnostic, needs the status code and
     * body to explain *why* it failed) so the actual HTTP call can't drift
     * between the two.
     *
     * Uses wp_remote_get() rather than raw cURL (WordPress.org coding
     * standards flag direct curl_* usage) -- this endpoint takes no body
     * and authenticates via HTTP Basic Auth, which the original code got
     * from CURLOPT_USERPWD; the WP HTTP API equivalent is building the
     * `Authorization: Basic base64(key:secret)` header by hand, since
     * wp_remote_get() has no dedicated basic-auth option.
     *
     * @return array{raw: string|false, status: int, http_error: string}
     */
    private function request_oauth_token() {
        $url = $this->base_url . '/oauth/v1/generate?grant_type=client_credentials';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret),
            ),
            'timeout' => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            return array('raw' => false, 'status' => 0, 'http_error' => $response->get_error_message());
        }

        return array(
            'raw' => wp_remote_retrieve_body($response),
            'status' => wp_remote_retrieve_response_code($response),
            'http_error' => '',
        );
    }

    /**
     * Admin-facing connectivity test: attempts an OAuth token request with
     * whatever credentials this instance was constructed with, and returns
     * a plain-language result -- never the credentials or Safaricom's raw
     * response body, which stay server-side (only logged, same as
     * get_access_token() already does).
     *
     * Confirmed directly against Safaricom's live OAuth endpoint
     * (2026-08-28): it rejects invalid credentials with an HTTP 400 and an
     * EMPTY body -- identical for real-but-wrong credentials and
     * deliberately-garbage ones. So the non-200 branch below usually has no
     * Daraja error message to surface and falls back to a generic,
     * actionable explanation instead of a blank one.
     *
     * @return array{success: bool, message: string}
     */
    public function test_connection() {
        if (empty($this->consumer_key) || empty($this->consumer_secret)) {
            return array(
                'success' => false,
                'message' => __('Consumer Key/Secret for the currently selected mode (Test or Live) are not set.', 'mpesa-till-gateway'),
            );
        }

        $result = $this->request_oauth_token();

        if ($result['raw'] === false) {
            return array(
                'success' => false,
                /* translators: %s: the underlying HTTP/connection error message */
                'message' => sprintf(__('Could not reach Safaricom: %s', 'mpesa-till-gateway'), $result['http_error']),
            );
        }

        if ($result['status'] == 200) {
            $decoded = json_decode($result['raw'], true);
            if (isset($decoded['access_token'])) {
                return array(
                    'success' => true,
                    'message' => __('Connected successfully -- Safaricom accepted these credentials and issued an access token.', 'mpesa-till-gateway'),
                );
            }
            return array(
                'success' => false,
                'message' => __('Safaricom returned an unexpected response (HTTP 200 with no access token).', 'mpesa-till-gateway'),
            );
        }

        $decoded = json_decode($result['raw'], true);
        $reason = '';
        if (is_array($decoded)) {
            $reason = isset($decoded['errorMessage']) ? $decoded['errorMessage'] : (isset($decoded['error_description']) ? $decoded['error_description'] : '');
        }

        if ($reason === '') {
            $reason = __('the Consumer Key/Secret for the currently selected mode (Test or Live) were rejected. Double-check you copied the Consumer Secret -- not the Passkey -- from the Daraja app page.', 'mpesa-till-gateway');
        }

        return array(
            'success' => false,
            /* translators: 1: HTTP status code, 2: reason */
            'message' => sprintf(__('Connection failed (HTTP %1$d): %2$s', 'mpesa-till-gateway'), $result['status'], $reason),
        );
    }

    /**
     * Generate password for STK Push
     * Uses Shortcode + Passkey + Timestamp
     */
    private function generate_password($timestamp) {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }

    /**
     * Initiate STK Push
     */
    public function stk_push($phone, $amount, $order_id, $callback_url) {
        $access_token = $this->get_access_token();

        if (!$access_token) {
            return array(
                'ResponseCode' => '1',
                'errorMessage' => 'Failed to get access token'
            );
        }

        $timestamp = gmdate('YmdHis');
        $password = $this->generate_password($timestamp);

        $url = $this->base_url . '/mpesa/stkpush/v1/processrequest';

        $curl_post_data = array(
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => round($amount),
            'PartyA' => $phone,
            'PartyB' => $this->till_number,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callback_url,
            'AccountReference' => 'Order-' . $order_id,
            'TransactionDesc' => 'Payment for Order ' . $order_id
        );

        $data_string = wp_json_encode($curl_post_data);

        $http_response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'body' => $data_string,
            'timeout' => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($http_response)) {
            error_log('M-Pesa API Error (stk_push): ' . $http_response->get_error_message());

            return array(
                'ResponseCode' => '1',
                'errorMessage' => 'Connection error'
            );
        }

        $status = wp_remote_retrieve_response_code($http_response);
        $result = wp_remote_retrieve_body($http_response);
        $response = json_decode($result, true);

        // Log the request and response
        $this->log_api_call('STK_PUSH_REQUEST', $curl_post_data, $response, $status);

        return $response;
    }

    /**
     * Query STK Push transaction status
     */
    public function query_stk_status($checkout_request_id) {
        $access_token = $this->get_access_token();

        if (!$access_token) {
            return array(
                'ResponseCode' => '1',
                'errorMessage' => 'Failed to get access token'
            );
        }

        $timestamp = gmdate('YmdHis');
        $password = $this->generate_password($timestamp);

        $url = $this->base_url . '/mpesa/stkpushquery/v1/query';

        $curl_post_data = array(
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        );

        $data_string = wp_json_encode($curl_post_data);

        $http_response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ),
            'body' => $data_string,
            'timeout' => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($http_response)) {
            error_log('M-Pesa API Error (query_stk_status): ' . $http_response->get_error_message());
            return null;
        }

        return json_decode(wp_remote_retrieve_body($http_response), true);
    }

    /**
     * Log API calls for debugging
     *
     * The request's Password field is derived from the shortcode + passkey
     * and is redacted before logging so the passkey can't be recovered from
     * debug.log.
     */
    private function log_api_call($type, $request, $response, $status_code) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (isset($request['Password'])) {
                $request['Password'] = '***REDACTED***';
            }

            error_log('=== M-Pesa API Call: ' . $type . ' ===');
            error_log('Status Code: ' . $status_code);
            error_log('Request: ' . print_r($request, true));
            error_log('Response: ' . print_r($response, true));
        }
    }
}
