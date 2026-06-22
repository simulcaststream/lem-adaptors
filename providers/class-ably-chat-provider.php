<?php
/**
 * Ably chat provider for Live Event Manager.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('LEM_PLUGIN_DIR')) {
    require_once LEM_PLUGIN_DIR . 'services/chat/class-chat-provider-interface.php';
}

class LEM_Ably_Chat_Provider implements LEM_Chat_Provider_Interface {

    public function get_name(): string {
        return 'Ably';
    }

    public function get_id(): string {
        return 'ably';
    }

    public function is_configured(): bool {
        $key = $this->get_api_key();
        return $key !== '' && strpos($key, ':') !== false;
    }

    /**
     * @return array|WP_Error Ably TokenRequest fields for authCallback (keyName, mac, …).
     */
    public function issue_viewer_token(string $event_id, string $email, string $display_name, array $caps = array()) {
        if (! $this->is_configured()) {
            return new WP_Error('not_configured', 'Ably is not configured.');
        }

        $ably_key   = $this->get_api_key();
        $colon_pos  = strrpos($ably_key, ':');
        $key_name   = substr($ably_key, 0, $colon_pos);
        $key_secret = substr($ably_key, $colon_pos + 1);

        $client_id = ! empty($caps['client_id'])
            ? (string) $caps['client_id']
            : ( $email !== '' ? sanitize_key($email) : 'viewer' );

        $channel    = $this->get_channel_name($event_id);
        $capability = wp_json_encode(array( $channel => array( 'subscribe', 'publish', 'presence', 'history' ) ));
        $ttl        = isset($caps['ttl_ms']) ? (int) $caps['ttl_ms'] : 3600 * 1000;
        $timestamp  = (int) round(microtime(true) * 1000);
        $nonce      = bin2hex(random_bytes(8));

        $sign_string = implode("\n", array(
            $key_name,
            $ttl,
            $capability,
            $client_id,
            $timestamp,
            $nonce,
            '',
        ));

        $mac = base64_encode(hash_hmac('sha256', $sign_string, $key_secret, true));

        return array(
            'keyName'    => $key_name,
            'clientId'   => $client_id,
            'nonce'      => $nonce,
            'timestamp'  => $timestamp,
            'capability' => $capability,
            'ttl'        => $ttl,
            'mac'        => $mac,
            'channel'    => $channel,
            'expires_at' => $timestamp + $ttl,
        );
    }

    public function issue_host_token(string $event_id, string $user_id) {
        return $this->issue_viewer_token($event_id, '', 'Host', array( 'client_id' => 'host-' . $user_id ) );
    }

    public function revoke_viewer(string $event_id, string $email) {
        // Ably revocation is handled via playback JWT / session; no server-side revoke API required here.
        return true;
    }

    public function get_channel_name(string $event_id): string {
        return 'lem:chat:' . preg_replace('/[^0-9]/', '', $event_id);
    }

    public function get_settings_fields(): array {
        return array(
            array(
                'key'         => 'ably_api_key',
                'label'       => 'Ably API Key',
                'type'        => 'password',
                'description' => 'Format: APP_ID.KEY_ID:KEY_SECRET from Ably Dashboard → API Keys. Subscribe, Publish, and Presence only — not the root key.',
            ),
        );
    }

    public function validate_settings(array $settings): array {
        $key = trim($settings['ably_api_key'] ?? '');
        if ($key !== '' && strpos($key, ':') === false) {
            return array('Ably API key must be in APP_ID.KEY_ID:SECRET format.');
        }
        $out = array();
        if (isset($settings['ably_api_key'])) {
            $out['ably_api_key'] = sanitize_text_field($settings['ably_api_key']);
        }
        return $out;
    }

    private function get_api_key(): string {
        $settings = get_option('lem_settings', array());
        return trim($settings['ably_api_key'] ?? '');
    }
}
