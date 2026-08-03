<?php
/**
 * PayPal Payment Provider
 *
 * Uses the PayPal Orders v2 REST API (no SDK dependency — plain wp_remote_post).
 *
 * Checkout flow:
 *   1. create_checkout_session() creates a PayPal order and returns the approval URL.
 *   2. Buyer approves on PayPal and is redirected to admin-ajax.php?action=lem_paypal_capture.
 *   3. handle_paypal_capture() calls capture_order(), which takes the money.
 *   4. PayPal fires PAYMENT.CAPTURE.COMPLETED webhook → handle_paypal_webhook() → JWT issued.
 *
 * Webhook URL to register in PayPal Developer Dashboard → Apps & Credentials → Webhooks:
 *   {site_url}/wp-admin/admin-ajax.php?action=lem_payment_webhook
 * Event to subscribe: PAYMENT.CAPTURE.COMPLETED
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LEM_PLUGIN_DIR')) {
    require_once LEM_PLUGIN_DIR . 'services/payments/class-payment-provider-interface.php';
}

class LEM_PayPal_Provider implements LEM_Payment_Provider_Interface {

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function base_url(): string {
        $settings = get_option('lem_settings', array());
        $mode     = $settings['paypal_mode'] ?? 'sandbox';
        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function client_credentials(): array {
        $settings = get_option('lem_settings', array());
        $mode     = $settings['paypal_mode'] ?? 'sandbox';
        if ($mode === 'live') {
            return array(
                'client_id'     => $settings['paypal_live_client_id']     ?? '',
                'client_secret' => $settings['paypal_live_client_secret'] ?? '',
            );
        }
        return array(
            'client_id'     => $settings['paypal_sandbox_client_id']     ?? '',
            'client_secret' => $settings['paypal_sandbox_client_secret'] ?? '',
        );
    }

    private function get_access_token(): string|\WP_Error {
        $creds = $this->client_credentials();

        $response = wp_remote_post($this->base_url() . '/v1/oauth2/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($creds['client_id'] . ':' . $creds['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $msg = $body['error_description'] ?? 'Failed to obtain PayPal access token.';
            return new \WP_Error('paypal_auth_error', $msg);
        }

        return $body['access_token'];
    }

    /**
     * GET /v2/checkout/orders/{id} to read payer email (capture webhooks often omit it).
     */
    private function fetch_order_payer_email( string $order_id ): ?string {
        $order_id = trim( $order_id );
        if ( $order_id === '' ) {
            return null;
        }
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return null;
        }
        $url      = $this->base_url() . '/v2/checkout/orders/' . rawurlencode( $order_id );
        $response = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 15,
            )
        );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['payer']['email_address'] ?? null;
    }

    /**
     * Parent Checkout order id from a PAYMENT.CAPTURE.COMPLETED resource (for buyer email lookup).
     */
    private function order_id_from_capture_resource( array $resource ): string {
        $oid = $resource['supplementary_data']['related_ids']['order_id'] ?? '';
        if ( is_string( $oid ) && $oid !== '' ) {
            return $oid;
        }
        foreach ( $resource['links'] ?? array() as $link ) {
            if ( ( $link['rel'] ?? '' ) === 'up' && ! empty( $link['href'] ) ) {
                $path = (string) parse_url( $link['href'], PHP_URL_PATH );
                $last = $path !== '' ? basename( $path ) : '';
                if ( $last !== '' ) {
                    return $last;
                }
            }
        }
        return '';
    }

    // ── Interface ─────────────────────────────────────────────────────────────

    public function get_name(): string {
        return 'PayPal';
    }

    public function get_id(): string {
        return 'paypal';
    }

    public function is_configured(): bool {
        $creds = $this->client_credentials();
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    public function create_checkout_session(array $args): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'PayPal is not configured.');
        }

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $event_id = $args['event_id'] ?? '';

        // Resolve numeric amount: explicit arg → _lem_amount meta → display_price stripped.
        $amount = $args['amount'] ?? '';
        if (empty($amount) && $event_id) {
            $amount = get_post_meta(intval($event_id), '_lem_amount', true);
        }
        if (empty($amount)) {
            $display = get_post_meta(intval($event_id), '_lem_display_price', true) ?? '';
            $amount  = preg_replace('/[^0-9.]/', '', $display);
        }
        $amount = $amount ?: '0.00';

        $settings = get_option('lem_settings', array());
        $currency = $settings['paypal_currency'] ?? 'USD';

        // Capture URL: user lands here after PayPal approval.
        $capture_url = add_query_arg(
            array('action' => 'lem_paypal_capture', 'event_id' => $event_id),
            admin_url('admin-ajax.php')
        );

        $order_body = array(
            'intent'         => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'custom_id'   => $event_id,
                    'description' => $args['event_title'] ?? 'Event Access',
                    'amount'      => array(
                        'currency_code' => $currency,
                        'value'         => number_format((float) $amount, 2, '.', ''),
                    ),
                ),
            ),
            'payment_source' => array(
                'paypal' => array(
                    'experience_context' => array(
                        'brand_name'          => get_bloginfo('name'),
                        'locale'              => 'en-US',
                        'landing_page'        => 'LOGIN',
                        'shipping_preference' => 'NO_SHIPPING',
                        'user_action'         => 'PAY_NOW',
                        'return_url'          => $capture_url,
                        'cancel_url'          => $args['cancel_url'] ?? home_url('/'),
                    ),
                ),
            ),
        );

        if (!empty($args['email'])) {
            $order_body['payment_source']['paypal']['email_address'] = $args['email'];
        }

        $order_body = apply_filters('lem_paypal_order_args', $order_body, $args);

        $response = wp_remote_post($this->base_url() . '/v2/checkout/orders', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'return=representation',
            ),
            'body'    => wp_json_encode($order_body),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $http = (int) wp_remote_retrieve_response_code($response);

        if ($http >= 400 || empty($body['id'])) {
            $msg = $body['message'] ?? wp_remote_retrieve_response_message($response);
            return new \WP_Error('paypal_order_error', $msg ?: 'Failed to create PayPal order.');
        }

        $approval_url = '';
        foreach ($body['links'] ?? array() as $link) {
            if (($link['rel'] ?? '') === 'payer-action') {
                $approval_url = $link['href'];
                break;
            }
        }

        if (empty($approval_url)) {
            return new \WP_Error('paypal_no_approval_url', 'No approval URL returned by PayPal.');
        }

        return array(
            'checkout_url' => $approval_url,
            'session_id'   => $body['id'],
        );
    }

    /**
     * Capture an approved order. Called from the return-URL handler after the buyer approves.
     * This is PayPal-specific (not in the interface); the capture must happen before the
     * PAYMENT.CAPTURE.COMPLETED webhook fires.
     *
     * @param  string $order_id PayPal order ID (the ?token= value in the return URL).
     * @return array|WP_Error
     */
    public function capture_order(string $order_id): array|\WP_Error {
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $response = wp_remote_post($this->base_url() . "/v2/checkout/orders/{$order_id}/capture", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => '{}',
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $http = (int) wp_remote_retrieve_response_code($response);

        if ($http >= 400) {
            $msg = $body['message'] ?? wp_remote_retrieve_response_message($response);
            return new \WP_Error('paypal_capture_error', $msg ?: 'PayPal capture failed.');
        }

        return $body;
    }

    public function verify_webhook(): array|\WP_Error {
        $settings   = get_option('lem_settings', array());
        $webhook_id = $settings['paypal_webhook_id'] ?? '';

        $payload    = (string) @file_get_contents('php://input');
        $body_array = json_decode($payload, true);

        if (empty($body_array)) {
            return new \WP_Error('invalid_payload', 'Empty or invalid PayPal webhook payload.');
        }

        // ── Signature verification — fails closed ─────────────────────────────
        //
        // Every branch below returns an error rather than falling through.
        // Previously an unset webhook id, a failed token fetch, or a transient
        // network error to PayPal all skipped verification and let the payload be
        // treated as trusted. Combined with header-based provider routing in core,
        // that let an unauthenticated POST carrying a PayPal-Transmission-Sig
        // header mint a ticket for any event and email.
        if (empty($webhook_id)) {
            return new \WP_Error(
                'no_secret',
                'PayPal webhook ID is not configured. Set it under Services → PayPal before enabling the webhook.'
            );
        }

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return new \WP_Error(
                'verification_unavailable',
                'Could not obtain a PayPal access token to verify this webhook: ' . $token->get_error_message()
            );
        }

        $verify_body = array(
            'auth_algo'         => $_SERVER['HTTP_PAYPAL_AUTH_ALGO']         ?? '',
            'cert_url'          => $_SERVER['HTTP_PAYPAL_CERT_URL']          ?? '',
            'transmission_id'   => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID']   ?? '',
            'transmission_sig'  => $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG']  ?? '',
            'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
            'webhook_id'        => $webhook_id,
            'webhook_event'     => $body_array,
        );

        foreach (array('auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time') as $required) {
            if ($verify_body[$required] === '') {
                return new \WP_Error('missing_signature', 'PayPal webhook is missing required signature headers.');
            }
        }

        $verify_resp = wp_remote_post(
            $this->base_url() . '/v1/notifications/verify-webhook-signature',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($verify_body),
                'timeout' => 15,
            )
        );

        if (is_wp_error($verify_resp)) {
            // PayPal retries on a non-2xx, so refusing here loses nothing.
            return new \WP_Error(
                'verification_unavailable',
                'Could not reach PayPal to verify this webhook: ' . $verify_resp->get_error_message()
            );
        }

        $v = json_decode(wp_remote_retrieve_body($verify_resp), true);
        if (($v['verification_status'] ?? '') !== 'SUCCESS') {
            return new \WP_Error('invalid_signature', 'PayPal webhook signature verification failed.');
        }

        $event_type = $body_array['event_type'] ?? '';

        if ($event_type !== 'PAYMENT.CAPTURE.COMPLETED') {
            return array(
                'type'       => $event_type,
                'payment_id' => null,
                'event_id'   => null,
                'email'      => null,
                'raw'        => $body_array,
            );
        }

        $resource   = $body_array['resource'] ?? array();
        $payment_id = $resource['id'] ?? null;

        // event_id: Orders API uses purchase_units[0].custom_id; capture webhooks put custom_id on the capture resource.
        $units    = $resource['purchase_units'] ?? array();
        $event_id = $units[0]['custom_id'] ?? $resource['custom_id'] ?? null;
        if ( $event_id !== null && $event_id !== '' ) {
            $event_id = (string) $event_id;
        } else {
            $event_id = null;
        }

        // Buyer email (often absent on capture resource — hydrate from parent order).
        $email = $resource['payer']['email_address']
            ?? ( $resource['payment_source']['paypal']['email_address'] ?? null );
        if ( empty( $email ) ) {
            $parent_order_id = $this->order_id_from_capture_resource( $resource );
            if ( $parent_order_id !== '' ) {
                $email = $this->fetch_order_payer_email( $parent_order_id );
            }
        }

        return array(
            'type'       => 'checkout.completed',
            'payment_id' => $payment_id,
            'event_id'   => $event_id,
            'email'      => $email,
            'raw'        => $body_array,
        );
    }

    public function finalize_checkout(string $reference_id, array $context = array()) {
        $capture = $this->capture_order($reference_id);
        if (is_wp_error($capture)) {
            return $capture;
        }

        return $this->get_payment_status($reference_id);
    }

    public function get_payment_status(string $order_id): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', 'PayPal is not configured.');
        }

        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $response = wp_remote_get($this->base_url() . "/v2/checkout/orders/{$order_id}", array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $status = $body['status'] ?? '';
        $paid   = ($status === 'COMPLETED');

        $units    = $body['purchase_units'] ?? array();
        $event_id = $units[0]['custom_id'] ?? null;
        $email    = $body['payer']['email_address'] ?? null;

        $order_id   = $body['id'] ?? $order_id;
        $payment_id = $order_id;
        $captures   = $units[0]['payments']['captures'] ?? array();
        if (! empty($captures[0]['id'])) {
            $payment_id = $captures[0]['id'];
        }

        return array(
            'paid'        => $paid,
            'email'       => $email,
            'event_id'    => $event_id,
            'payment_id'  => $payment_id,
            'order_id'    => $order_id,
        );
    }

    public function get_settings_fields(): array {
        return array(
            array(
                'key'         => 'paypal_mode',
                'label'       => 'Mode',
                'type'        => 'select',
                'options'     => array('sandbox' => 'Sandbox (testing)', 'live' => 'Live'),
                'description' => 'Use Sandbox while testing; switch to Live for production.',
            ),
            array(
                'key'         => 'paypal_sandbox_client_id',
                'label'       => 'Sandbox Client ID',
                'type'        => 'text',
                'description' => '',
            ),
            array(
                'key'         => 'paypal_sandbox_client_secret',
                'label'       => 'Sandbox Client Secret',
                'type'        => 'password',
                'description' => '',
            ),
            array(
                'key'         => 'paypal_live_client_id',
                'label'       => 'Live Client ID',
                'type'        => 'text',
                'description' => '',
            ),
            array(
                'key'         => 'paypal_live_client_secret',
                'label'       => 'Live Client Secret',
                'type'        => 'password',
                'description' => '',
            ),
            array(
                'key'         => 'paypal_currency',
                'label'       => 'Currency',
                'type'        => 'text',
                'placeholder' => 'USD',
                'description' => 'ISO 4217 currency code (e.g. USD, EUR, GBP).',
            ),
            array(
                'key'         => 'paypal_webhook_id',
                'label'       => 'Webhook ID',
                'type'        => 'text',
                'description' => 'From PayPal Developer Dashboard → Apps &amp; Credentials → Webhooks. Subscribe to <code>PAYMENT.CAPTURE.COMPLETED</code>.',
            ),
        );
    }

    public function validate_settings(array $settings): array {
        $out = array();
        foreach ($this->get_settings_fields() as $field) {
            $k = $field['key'];
            if (isset($settings[$k])) {
                $out[$k] = sanitize_text_field($settings[$k]);
            }
        }
        if (isset($out['paypal_mode']) && !in_array($out['paypal_mode'], array('sandbox', 'live'), true)) {
            $out['paypal_mode'] = 'sandbox';
        }
        if (!empty($out['paypal_currency'])) {
            $out['paypal_currency'] = strtoupper(substr($out['paypal_currency'], 0, 3));
        }
        return $out;
    }
}
