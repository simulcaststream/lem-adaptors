<?php
/**
 * Mux Streaming Provider Implementation.
 *
 * Self-contained: this class owns every Mux HTTP call and the Mux-specific
 * RS256 playback-token issuance. Core REST handlers and AJAX endpoints
 * delegate to this class via the streaming provider factory.
 *
 * Cross-cutting JWT storage helpers (DB, Redis, playback blob) remain in core
 * and are reached via $this->plugin (the LiveEventManager instance).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LEM_PLUGIN_DIR')) {
    require_once LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-interface.php';
    require_once LEM_PLUGIN_DIR . 'services/streaming/class-abstract-streaming-provider.php';
}

class LEM_Mux_Provider extends LEM_Abstract_Streaming_Provider {

    private const API_BASE = 'https://api.mux.com/video/v1';

    private $plugin;
    private $settings;

    public function __construct($plugin) {
        $this->plugin   = $plugin;
        $this->settings = get_option('lem_settings', array());
    }

    public function get_name() {
        return 'Mux';
    }

    public function get_id() {
        return 'mux';
    }

    public function is_configured() {
        $token_id    = $this->settings['mux_token_id']    ?? '';
        $token_secret = $this->settings['mux_token_secret'] ?? '';
        $key_id      = $this->settings['mux_key_id']      ?? '';
        $private_key = $this->settings['mux_private_key'] ?? '';

        return !empty($token_id) && !empty($token_secret) && !empty($key_id) && !empty($private_key);
    }

    public function get_credentials() {
        return array(
            'token_id'     => $this->settings['mux_token_id']     ?? '',
            'token_secret' => $this->settings['mux_token_secret'] ?? '',
            'key_id'       => $this->settings['mux_key_id']       ?? '',
            'private_key'  => $this->settings['mux_private_key']  ?? '',
        );
    }

    /**
     * Authorization header for Mux REST API. Returns null if creds are missing.
     */
    private function auth_header(): ?array {
        $c = $this->get_credentials();
        if (empty($c['token_id']) || empty($c['token_secret'])) {
            return null;
        }
        return array(
            'Authorization' => 'Basic ' . base64_encode($c['token_id'] . ':' . $c['token_secret']),
        );
    }

    private function not_configured_error() {
        return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
    }

    private function debug_log($msg, $ctx = null) {
        if ($this->plugin && method_exists($this->plugin, 'debug_log')) {
            $this->plugin->debug_log($msg, $ctx);
        }
    }

    private function redis() {
        if ($this->plugin && method_exists($this->plugin, 'get_redis_connection')) {
            return $this->plugin->get_redis_connection();
        }
        return null;
    }

    // ── Playback token (RS256) ─────────────────────────────────────────────────

    public function generate_playback_token($email, $event_id, $payment_id = null, $is_refresh = false) {
        $key_id      = $this->settings['mux_key_id']      ?? '';
        $private_key = $this->settings['mux_private_key'] ?? '';

        if (empty($key_id)) {
            $this->plugin->last_jwt_error = 'Mux signing key ID is missing. Set "mux_key_id" in Settings → Streaming.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }
        if (empty($private_key)) {
            $this->plugin->last_jwt_error = 'Mux signing private key is missing. Set "mux_private_key" in Settings → Streaming.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }
        if (!class_exists('\Firebase\JWT\JWT')) {
            $this->plugin->last_jwt_error = 'Firebase JWT library not loaded. Run "composer install" in the plugin directory.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }

        $event = $this->plugin->get_event_by_id($event_id);
        if (!$event) {
            $this->plugin->last_jwt_error = 'Event ID ' . $event_id . ' not found.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }

        if (empty($event->playback_id)) {
            $this->plugin->last_jwt_error = 'Event ID ' . $event_id . ' has no Mux playback_id set.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }

        // Token lifetime is the event's, and only the event's. There is no
        // "default expiry" fallback: an event without a valid start and end
        // cannot mint at all, and core refuses before reaching this method.
        $exp = LEM_Event_Window::token_expiry($event_id);
        if (!$exp) {
            $this->plugin->last_jwt_error = 'Event ' . $event_id . ' has no valid start/end, so no token lifetime can be derived.';
            $this->debug_log('generate_playback_token: ' . $this->plugin->last_jwt_error);
            return false;
        }

        if ($is_refresh) {
            $this->plugin->invalidate_existing_tokens($email, $event_id);
        } else {
            global $wpdb;
            $table    = $wpdb->prefix . 'lem_jwt_tokens';
            // Only rows that actually carry a token are reusable. Entitlement
            // rows (jwt_token NULL, written at purchase) must fall through to
            // minting, otherwise the viewer gets handed an empty token.
            // Compared in UTC — expires_at is written with gmdate().
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                 WHERE LOWER(email) = %s
                   AND event_id     = %s
                   AND revoked_at IS NULL
                   AND jwt_token IS NOT NULL AND jwt_token != ''
                   AND expires_at > %s
                 ORDER BY created_at DESC
                 LIMIT 1",
                strtolower($email),
                (string) intval($event_id),
                gmdate('Y-m-d H:i:s')
            ));

            if ($existing) {
                $exp_ts = strtotime($existing->expires_at . ' UTC');
                $this->plugin->store_playback_blob($email, $event_id, array(
                    'vendor'  => 'mux',
                    'mux_jwt' => $existing->jwt_token,
                    'jti'     => $existing->jti,
                ), $exp_ts ? $exp_ts : $exp);

                return array(
                    'jwt'        => $existing->jwt_token,
                    'jti'        => $existing->jti,
                    'session_id' => null,
                    'expires_at' => $exp_ts,
                    'vendor'     => 'mux',
                );
            }
        }

        $random_jti  = uniqid('jwt_', true);
        $placeholder = $this->plugin->generate_session_id();

        $device_identifier = array('metadata' => array('ip' => '0.0.0.0'));
        if (class_exists('DeviceIdentificationService')) {
            $device_svc = new DeviceIdentificationService();
            $identified = $device_svc->getDeviceIdentifier(array('session_id' => $placeholder));
            if (is_array($identified)) {
                $device_identifier = $identified;
            }
        } elseif (method_exists($this->plugin, 'get_device_identifier')) {
            $device_identifier = $this->plugin->get_device_identifier(array('session_id' => $placeholder));
        }

        $device_settings  = get_option('lem_device_settings', array('identification_method' => 'session_based'));
        $identifier_type  = $device_settings['identification_method'] ?? 'session_based';
        $identifier_value = $placeholder;
        $ip               = $device_identifier['metadata']['ip'] ?? '0.0.0.0';
        $hash_jti         = hash('sha256', $email . '|' . $ip . '|' . $event->playback_id);
        $exp_int          = (int) $exp;

        $payload = array(
            'sub'    => $event->playback_id,
            'aud'    => 'v',
            'exp'    => $exp_int,
            'kid'    => $key_id,
            'custom' => array(
                'jti'              => $random_jti,
                'identifier_type'  => $identifier_type,
                'identifier_value' => $identifier_value,
                'event_id'         => $event_id,
                'ip'               => $ip,
            ),
        );

        if (!empty($event->playback_restriction_id)) {
            $payload['playback_restriction_id'] = $event->playback_restriction_id;
        }

        try {
            $jwt = \Firebase\JWT\JWT::encode($payload, base64_decode($private_key), 'RS256');

            // Fills in the entitlement row written at purchase time when there is
            // one, so a ticket stays one row rather than becoming two.
            $this->plugin->record_playback_token(
                $email,
                $event_id,
                $random_jti,
                $jwt,
                $hash_jti,
                $payment_id,
                $ip,
                gmdate('Y-m-d H:i:s', $exp)
            );

            $jwt_redis_data = array(
                'jti'                     => $random_jti,
                'jwt_token'               => $jwt,
                'playback_id'             => $event->playback_id,
                'playback_restriction_id' => $event->playback_restriction_id ?? null,
                'email'                   => $email,
                'event_id'                => $event_id,
                'device_identifier'       => $device_identifier,
                'identifier_type'         => $identifier_type,
                'identifier_value'        => $identifier_value,
                'expires_at'              => gmdate('Y-m-d H:i:s', $exp),
                'created_at'              => gmdate('Y-m-d H:i:s'),
                'revoked'                 => false,
            );
            $this->plugin->store_jwt_redis_by_jti($random_jti, $jwt_redis_data, $exp);
            $this->plugin->store_jwt_redis($hash_jti, $payload);
            $this->plugin->store_jti_mapping($random_jti, $hash_jti);

            $this->plugin->store_playback_blob($email, $event_id, array(
                'vendor'  => 'mux',
                'mux_jwt' => $jwt,
                'jti'     => $random_jti,
            ), $exp_int);

            return array(
                'jwt'        => $jwt,
                'jti'        => $random_jti,
                'expires_at' => $exp_int,
                'vendor'     => 'mux',
                'session_id' => null,
            );
        } catch (Exception $e) {
            return false;
        }
    }

    // ── Stream CRUD ────────────────────────────────────────────────────────────

    public function get_rtmp_info($stream_id = null) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }

        $stream_id = $stream_id ?: ($this->settings['mux_live_stream_id'] ?? '');
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }

        $redis     = $this->redis();
        $cache_key = "mux:rtmp_info:{$stream_id}";
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $response = wp_remote_get(self::API_BASE . "/live-streams/{$stream_id}", array(
            'headers' => $auth,
            'timeout' => 10,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch RTMP info', array('status' => 500));
        }

        $stream_data = $data['data'] ?? array();
        $result = array(
            'stream_key'  => $stream_data['stream_key'] ?? '',
            'ingest_url'  => 'rtmp://live.mux.com/app',
            'playback_id' => $stream_data['playback_ids'][0]['id'] ?? '',
        );

        if ($redis) {
            $redis->setex($cache_key, 3600, json_encode($result));
        }
        return $result;
    }

    public function create_stream($params) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }

        $valid_policies = array('public', 'signed', 'drm');
        $playback_policies = $params['playback_policies'] ?? array('public');
        if (is_string($playback_policies)) {
            $playback_policies = array($playback_policies);
        }
        $playback_policies = array_values(array_filter($playback_policies, fn($p) => in_array($p, $valid_policies, true)));
        if (empty($playback_policies)) {
            $playback_policies = array('public');
        }

        $asset_playback_policies = $params['asset_playback_policies'] ?? $playback_policies;
        if (is_string($asset_playback_policies)) {
            $asset_playback_policies = array($asset_playback_policies);
        }
        $asset_playback_policies = array_values(array_filter($asset_playback_policies, fn($p) => in_array($p, $valid_policies, true)));
        if (empty($asset_playback_policies)) {
            $asset_playback_policies = array('public');
        }

        $payload = array(
            'playback_policies'  => $playback_policies,
            'new_asset_settings' => array('playback_policies' => $asset_playback_policies),
        );

        $passthrough     = $params['passthrough'] ?? '';
        $reduced_latency = $this->to_bool($params['reduced_latency'] ?? false);
        $test_mode       = $this->to_bool($params['test_mode'] ?? false);

        if (!empty($passthrough)) {
            $payload['passthrough'] = $passthrough;
        }
        if ($reduced_latency) {
            $payload['reduced_latency'] = true;
        }
        if ($test_mode) {
            $payload['test'] = true;
        }

        $response = wp_remote_post(self::API_BASE . '/live-streams', array(
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'body'    => json_encode($payload),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create live stream', array('status' => 500));
        }

        if ($redis = $this->redis()) {
            $redis->del('mux:live_streams_list');
        }

        return $data['data'] ?? $data;
    }

    public function update_stream($stream_id, $params) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }

        $payload = array();
        if (!empty($params['passthrough'])) {
            $payload['passthrough'] = $params['passthrough'];
        }
        if (isset($params['reduced_latency'])) {
            $payload['reduced_latency'] = $this->to_bool($params['reduced_latency']);
        }

        if (empty($payload)) {
            return new WP_Error('missing_params', 'No update parameters provided', array('status' => 400));
        }

        $response = wp_remote_request(self::API_BASE . "/live-streams/{$stream_id}", array(
            'method'  => 'PUT',
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'body'    => json_encode($payload),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to update live stream', array('status' => 500));
        }

        if ($redis = $this->redis()) {
            $redis->del('mux:live_streams_list');
        }

        return $data['data'] ?? $data;
    }

    public function delete_stream($stream_id) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }

        $response = wp_remote_request(self::API_BASE . "/live-streams/{$stream_id}", array(
            'method'  => 'DELETE',
            'headers' => $auth,
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($redis = $this->redis()) {
            $redis->del('mux:live_streams_list');
        }

        if ($code === 204 || $code === 200) {
            return array('success' => true, 'message' => 'Stream deleted successfully');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to delete live stream', array('status' => 500));
        }
        return array('success' => true);
    }

    public function get_stream_details($stream_id) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        $response = wp_remote_get(self::API_BASE . "/live-streams/{$stream_id}", array(
            'headers' => $auth,
            'timeout' => 10,
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to fetch stream details');
        }
        return $data['data'] ?? $data;
    }

    public function list_streams($limit = 100) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }

        $redis     = $this->redis();
        $cache_key = 'mux:live_streams_list';
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                $redis->del($cache_key);
            }
        }

        $response = wp_remote_get(self::API_BASE . '/live-streams?limit=' . intval($limit), array(
            'headers' => $auth,
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        $data      = json_decode($body, true);

        if ($http_code !== 200 || !is_array($data)) {
            $msg = $data['error']['messages'][0] ?? "HTTP {$http_code}: {$body}";
            return new WP_Error('mux_api_error', $msg, array('status' => $http_code));
        }
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Mux API error', array('status' => 500));
        }

        $result = array(
            'data'      => $data['data'] ?? array(),
            'cached_at' => time(),
        );
        if ($redis) {
            $redis->setex($cache_key, 60, json_encode($result));
        }
        return $result;
    }

    public function get_stream_status($stream_id = null) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }

        $stream_id = $stream_id ?: ($this->settings['mux_live_stream_id'] ?? '');
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }

        $response = wp_remote_get(self::API_BASE . "/live-streams/{$stream_id}", array(
            'headers' => $auth,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch stream status', array('status' => 500));
        }

        $status     = $data['data']['status'] ?? 'idle';
        $is_active  = $status === 'active';
        $recent_id  = $data['data']['recent_asset_ids'][0] ?? null;
        $recent     = null;

        if (!$is_active && $recent_id) {
            $asset_resp = wp_remote_get(self::API_BASE . "/assets/{$recent_id}", array('headers' => $auth));
            if (!is_wp_error($asset_resp)) {
                $asset = json_decode(wp_remote_retrieve_body($asset_resp), true);
                if (isset($asset['data'])) {
                    $recent = array(
                        'asset_id'     => $recent_id,
                        'playback_id'  => $asset['data']['playback_ids'][0]['id'] ?? '',
                        'duration'     => $asset['data']['duration'] ?? null,
                        'created_at'   => $asset['data']['created_at'] ?? null,
                    );
                }
            }
        }

        return array(
            'stream_id'    => $stream_id,
            'status'       => $status,
            'is_active'    => $is_active,
            'recent_asset' => $recent,
        );
    }

    public function create_simulcast_target($stream_id, $url) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        if (empty($stream_id) || empty($url)) {
            return new WP_Error('missing_params', 'Stream ID and URL are required', array('status' => 400));
        }

        $payload = array('url' => $url);
        $response = wp_remote_post(self::API_BASE . "/live-streams/{$stream_id}/simulcast-targets", array(
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'body'    => json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create simulcast target', array('status' => 500));
        }
        return $data['data'] ?? array();
    }

    public function list_simulcast_targets($stream_id = null) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        $stream_id = $stream_id ?: ($this->settings['mux_live_stream_id'] ?? '');
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }

        $response = wp_remote_get(self::API_BASE . "/live-streams/{$stream_id}/simulcast-targets", array(
            'headers' => $auth,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        return is_array($data) ? $data : array();
    }

    public function delete_simulcast_target($stream_id, $target_id) {
        $auth = $this->auth_header();
        if (!$auth) {
            return $this->not_configured_error();
        }
        if (empty($stream_id) || empty($target_id)) {
            return new WP_Error('missing_params', 'Stream ID and target ID are required', array('status' => 400));
        }

        $response = wp_remote_request(self::API_BASE . "/live-streams/{$stream_id}/simulcast-targets/{$target_id}", array(
            'method'  => 'DELETE',
            'headers' => $auth,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array('success' => true);
        }
        return new WP_Error('mux_api_error', 'Failed to delete simulcast target', array('status' => 500));
    }

    public function get_webrtc_publish_url($stream_id = null) {
        return new WP_Error('not_supported', 'Mux does not support WebRTC publishing');
    }

    public function get_webrtc_playback_url($stream_id = null) {
        return new WP_Error('not_supported', 'Mux uses HLS/DASH, not WebRTC playback');
    }

    public function get_playback_url($stream_id = null) {
        $details = $this->get_stream_details($stream_id);
        if (is_wp_error($details)) {
            return $details;
        }
        $playback_id = $details['playback_ids'][0]['id'] ?? '';
        if (empty($playback_id)) {
            return new WP_Error('no_playback_id', 'No playback ID found for stream');
        }
        return 'https://stream.mux.com/' . $playback_id . '.m3u8';
    }

    public function get_player_component($playback_id, $token = null, $options = array()) {
        $autoplay    = $options['autoplay']    ?? false;
        $muted       = $options['muted']       ?? false;
        $poster      = $options['poster']      ?? '';
        $stream_type = $options['stream_type'] ?? 'live';
        $title       = $options['title']       ?? '';

        $attrs = array(
            'playback-id="' . esc_attr($playback_id) . '"',
            'accent-color="#7f5af0"',
        );
        if ($token)       { $attrs[] = 'playback-token="' . esc_attr($token) . '"'; }
        if ($poster)      { $attrs[] = 'poster="' . esc_url($poster) . '"'; }
        if ($stream_type) { $attrs[] = 'stream-type="' . esc_attr($stream_type) . '"'; }
        if ($title)       { $attrs[] = 'metadata-video-title="' . esc_attr($title) . '"'; }
        if ($autoplay)    { $attrs[] = 'autoplay'; }
        if ($muted)       { $attrs[] = 'muted'; }

        return '<mux-player ' . implode(' ', $attrs) . ' style="width:100%;height:100%;"></mux-player>';
    }

    // ── Webhook ────────────────────────────────────────────────────────────────

    public function handle_webhook($payload, $signature = null) {
        $webhook_secret = $this->settings['mux_webhook_secret'] ?? '';

        // Fails closed. The route is public (permission_callback __return_true),
        // so an unset secret previously meant any POST was processed as genuine
        // and could create lem_event posts via maybe_create_past_stream_post().
        if (empty($webhook_secret)) {
            return new WP_Error(
                'no_secret',
                'Mux webhook secret is not configured. Set it under Services → Mux before enabling the webhook.'
            );
        }

        if (empty($signature)) {
            return new WP_Error('missing_signature', 'Mux signature header missing');
        }

        if (!$this->verify_mux_signature($payload, $signature, $webhook_secret)) {
            return new WP_Error('invalid_signature', 'Mux webhook signature invalid');
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return new WP_Error('invalid_payload', 'Mux webhook body is not valid JSON');
        }

        $event_type = $data['type'] ?? '';
        $this->debug_log('Mux webhook event: ' . $event_type);

        if ($event_type === 'video.asset.ready') {
            $this->maybe_create_past_stream_post($data['data'] ?? array());
        }

        return array(
            'type' => $event_type,
            'data' => $data['data'] ?? array(),
        );
    }

    /**
     * Create a "Past Stream" lem_event post when a Mux asset becomes ready, so
     * the site has a recording page matching the original live event.
     */
    private function maybe_create_past_stream_post(array $asset_data): void {
        $asset_id    = $asset_data['id'] ?? '';
        $playback_id = $asset_data['playback_ids'][0]['id'] ?? '';
        $status      = $asset_data['status'] ?? '';

        if (!$asset_id || !$playback_id || $status !== 'ready') {
            return;
        }

        $events = get_posts(array(
            'post_type'      => 'lem_event',
            'meta_key'       => '_lem_playback_id',
            'meta_value'     => $playback_id,
            'posts_per_page' => 1,
        ));
        if (empty($events)) {
            $this->debug_log('No event found for playback_id', array('playback_id' => $playback_id));
            return;
        }

        $event_post     = $events[0];
        $past_stream_id = wp_insert_post(array(
            'post_title'   => 'Past Stream: ' . $event_post->post_title,
            'post_content' => 'This is an automatically created past stream recording.',
            'post_status'  => 'publish',
            'post_type'    => 'lem_event',
            'post_parent'  => $event_post->ID,
            'meta_input'   => array(
                '_lem_playback_id'        => $playback_id,
                '_lem_asset_id'           => $asset_id,
                '_lem_is_past_stream'     => '1',
                '_lem_original_event_id'  => $event_post->ID,
                '_lem_status'             => 'past',
            ),
        ));

        if ($past_stream_id && !is_wp_error($past_stream_id)) {
            $this->debug_log('Past stream post created', array(
                'past_stream_id'    => $past_stream_id,
                'original_event_id' => $event_post->ID,
                'asset_id'          => $asset_id,
                'playback_id'       => $playback_id,
            ));
        } else {
            $this->debug_log('Failed to create past stream post', array(
                'error' => is_wp_error($past_stream_id) ? $past_stream_id->get_error_message() : 'Unknown error',
            ));
        }
    }

    private function verify_mux_signature(string $payload, string $signature, string $secret): bool {
        $parts = array();
        foreach (explode(',', $signature) as $segment) {
            if (strpos($segment, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }
        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        // Reject stale timestamps so a captured delivery cannot be replayed
        // indefinitely. Five minutes matches Stripe's default tolerance.
        $tolerance = (int) apply_filters('lem_mux_webhook_tolerance', 5 * MINUTE_IN_SECONDS);
        $timestamp = (int) $parts['t'];
        if ($tolerance > 0 && ($timestamp <= 0 || abs(time() - $timestamp) > $tolerance)) {
            return false;
        }

        $signed = $parts['t'] . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);
        return hash_equals($expected, $parts['v1']);
    }

    // ── Playback restrictions ──────────────────────────────────────────────────

    public function create_playback_restriction($name, $description, array $allowed_domains, $allow_no_referrer = true, $allow_no_user_agent = true, $allow_high_risk_user_agent = true) {
        $auth = $this->auth_header();
        if (!$auth) {
            return array('success' => false, 'error' => 'Mux API credentials not configured.');
        }

        $cleaned = array();
        foreach ($allowed_domains as $domain) {
            $domain = trim($domain);
            if ($domain === '') {
                continue;
            }
            $domain = preg_replace('/^https?:\/\//', '', $domain);
            $domain = rtrim($domain, '/');
            $cleaned[] = $domain;
        }
        if (empty($cleaned)) {
            return array('success' => false, 'error' => 'At least one valid domain is required');
        }

        $payload = array(
            'referrer' => array(
                'allowed_domains'   => $cleaned,
                'allow_no_referrer' => (bool) $allow_no_referrer,
            ),
            'user_agent' => array(
                'allow_no_user_agent'        => (bool) $allow_no_user_agent,
                'allow_high_risk_user_agent' => (bool) $allow_high_risk_user_agent,
            ),
        );

        $response = wp_remote_post(self::API_BASE . '/playback-restrictions', array(
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'body'    => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 201) {
            $restrictions = get_option('lem_playback_restrictions', array());
            $restrictions[$data['data']['id']] = array(
                'name'        => $name,
                'description' => $description,
                'mux_id'      => $data['data']['id'],
                'created_at'  => current_time('mysql'),
            );
            update_option('lem_playback_restrictions', $restrictions);
            return array('success' => true, 'data' => $data['data']);
        }

        $msg = 'Unknown error';
        if (isset($data['error']['message'])) {
            $msg = $data['error']['message'];
        } elseif (isset($data['error']['messages']) && is_array($data['error']['messages'])) {
            $msg = implode(', ', $data['error']['messages']);
        }
        return array('success' => false, 'error' => $msg);
    }

    public function get_playback_restrictions() {
        $auth = $this->auth_header();
        if (!$auth) {
            return array('success' => false, 'error' => 'Mux API credentials not configured.');
        }

        $response = wp_remote_get(self::API_BASE . '/playback-restrictions', array(
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) === 200) {
            return array('success' => true, 'data' => $data['data']);
        }
        return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
    }

    public function delete_playback_restriction($restriction_id) {
        $auth = $this->auth_header();
        if (!$auth) {
            return array('success' => false, 'error' => 'Mux API credentials not configured.');
        }

        $response = wp_remote_request(self::API_BASE . "/playback-restrictions/{$restriction_id}", array(
            'method'  => 'DELETE',
            'headers' => array_merge($auth, array('Content-Type' => 'application/json')),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        if (wp_remote_retrieve_response_code($response) === 204) {
            $restrictions = get_option('lem_playback_restrictions', array());
            unset($restrictions[$restriction_id]);
            update_option('lem_playback_restrictions', $restrictions);
            return array('success' => true);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
    }

    // ── Settings + interface plumbing ──────────────────────────────────────────

    public function get_settings_fields() {
        return array(
            'mux_key_id' => array(
                'label'       => 'Signing Key ID',
                'type'        => 'text',
                'required'    => true,
                'section'     => 'JWT Signing',
                'description' => 'Found in your Mux Dashboard under <strong>Settings → Signing Keys</strong>. Used as the <code>kid</code> in playback JWTs.',
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ),
            'mux_private_key' => array(
                'label'       => 'Private Key (Base64)',
                'type'        => 'textarea',
                'required'    => true,
                'section'     => 'JWT Signing',
                'description' => 'The Base64-encoded RSA private key from Mux. Paste the full key string (not a PEM file path).',
                'placeholder' => 'LS0tLS1CRUdJTi...',
            ),
            'mux_token_id' => array(
                'label'       => 'API Token ID',
                'type'        => 'text',
                'required'    => true,
                'section'     => 'API Access',
                'description' => 'Used to authenticate Mux REST API calls (stream management, restrictions, etc.).',
                'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
            ),
            'mux_token_secret' => array(
                'label'       => 'API Token Secret',
                'type'        => 'password',
                'required'    => true,
                'section'     => 'API Access',
                'description' => 'Paired with the Token ID above. Both are created in <strong>Mux Dashboard → Settings → Access Tokens</strong>.',
            ),
            'mux_webhook_secret' => array(
                'label'       => 'Webhook Secret',
                'type'        => 'password',
                'required'    => false,
                'section'     => 'Webhooks',
                'description' => 'Optional but recommended. Used to verify the signature on incoming Mux webhook events.',
            ),
        );
    }

    public function validate_settings($settings) {
        $errors = array();
        if (empty($settings['mux_key_id']))      { $errors[] = 'Mux Signing Key ID is required'; }
        if (empty($settings['mux_private_key'])) { $errors[] = 'Mux Private Key is required'; }
        if (empty($settings['mux_token_id']) || empty($settings['mux_token_secret'])) {
            $errors[] = 'Mux API Token ID and Secret are required for stream management';
        }
        return empty($errors) ? true : $errors;
    }

    /**
     * Mux playback tokens are RS256-signed and validated at Mux's edge. There is
     * no revocation API and swapping the token mid-playback is not safe, so the
     * token is issued once and covers the event.
     */
    public function supports_token_refresh() {
        return false;
    }

    public function get_token_settings_fields(): array {
        return array();
    }

    /**
     * Dry-run the parts of minting that can fail on configuration alone, without
     * issuing a token or calling Mux. Catches the failure that would otherwise
     * only appear when the audience clicks their links.
     *
     * @param int $event_id Event post ID.
     * @return true|WP_Error
     */
    public function preflight($event_id) {
        if (!class_exists('\Firebase\JWT\JWT')) {
            return new WP_Error('no_jwt_lib', 'Firebase JWT library not loaded — run composer install in the plugin directory.');
        }

        $key_id      = $this->settings['mux_key_id']      ?? '';
        $private_key = $this->settings['mux_private_key'] ?? '';

        if (empty($key_id) || empty($private_key)) {
            return new WP_Error('no_signing_key', 'Mux signing key ID or private key is missing.');
        }

        $decoded = base64_decode($private_key, true);
        if ($decoded === false) {
            return new WP_Error('bad_signing_key', 'Mux private key is not valid base64 — paste the key exactly as Mux supplies it.');
        }

        if (!function_exists('openssl_pkey_get_private')) {
            return true; // Cannot verify without OpenSSL; minting may still work.
        }

        $key = @openssl_pkey_get_private($decoded);
        if ($key === false) {
            return new WP_Error('bad_signing_key', 'Mux private key could not be parsed — playback tokens cannot be signed.');
        }

        return true;
    }

    public function describe_token_lifetime(): string {
        $buffer = class_exists('LEM_Event_Window')
            ? LEM_Event_Window::buffer_seconds() / HOUR_IN_SECONDS
            : 2;

        return sprintf(
            /* translators: %s: buffer in hours. */
            __('Event end + %sh, minted at doors. Fixed — cannot be revoked once issued.', 'lem-adaptors'),
            number_format_i18n($buffer, 1)
        );
    }

    public function get_extra_tabs() {
        return array(
            'restrictions' => array(
                'label'    => 'Playback Restrictions',
                'template' => LEM_PLUGIN_DIR . 'templates/restrictions-page.php',
            ),
        );
    }

    public function normalize_stream(array $raw): array {
        return array(
            'id'         => $raw['id'] ?? '',
            'name'       => $raw['passthrough'] ?? $raw['id'] ?? '',
            'status'     => $raw['status'] ?? 'unknown',
            'stream_key' => $raw['stream_key'] ?? '',
            'playback_id'=> $raw['playback_ids'][0]['id'] ?? '',
            'created_at' => $raw['created_at'] ?? '',
        );
    }

    public function get_create_stream_fields(): array {
        return array(
            array('key' => 'passthrough', 'label' => 'Stream Name', 'type' => 'text', 'required' => true,
                  'placeholder' => 'e.g. Main Event Stream', 'description' => 'A friendly label to identify this stream.'),
            array('key' => 'playback_policies', 'label' => 'Live Playback Policy', 'type' => 'select',
                  'options' => array('signed' => 'Signed (JWT)', 'public' => 'Public', 'public,signed' => 'Public & Signed'),
                  'default' => 'signed', 'description' => 'Controls who can play the live stream.'),
            array('key' => 'asset_playback_policies', 'label' => 'Recorded Asset Policy', 'type' => 'select',
                  'options' => array('signed' => 'Signed (JWT)', 'public' => 'Public', 'public,signed' => 'Public & Signed'),
                  'default' => 'signed', 'description' => 'Controls who can play recordings after the stream ends.'),
            array('key' => 'reduced_latency', 'label' => 'Reduced Latency', 'type' => 'checkbox'),
            array('key' => 'test_mode',       'label' => 'Test Mode',       'type' => 'checkbox'),
        );
    }

    public function get_edit_stream_fields(): array {
        return array(
            array('key' => 'passthrough',     'label' => 'Name',            'type' => 'text', 'required' => true),
            array('key' => 'reduced_latency', 'label' => 'Reduced Latency', 'type' => 'checkbox'),
        );
    }

    public function supports_simulcast(): bool {
        return true;
    }

    private function to_bool($value): bool {
        return $value === '1' || $value === 'true' || $value === true;
    }
}
