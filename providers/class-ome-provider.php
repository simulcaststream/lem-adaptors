<?php
/**
 * OvenMediaEngine (OME) Streaming Provider
 *
 * Free, self-hosted, full-featured live streaming server.
 * https://ovenmediaengine.com
 *
 * Token strategy: OME Signed Policy — HMAC-SHA1 of a base64url-encoded JSON
 * policy appended as ?policy=...&signature=... query params on the stream URL.
 * No third-party JWT library required.
 *
 * Player: OvenPlayer (https://ovenplayer.com) — the official OSS player for OME.
 * Supports WebRTC (ultra-low latency), LLHLS, HLS, DASH with hot source swap.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (defined('LEM_PLUGIN_DIR')) {
    require_once LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-interface.php';
}

class LEM_OME_Provider implements LEM_Streaming_Provider_Interface {

    private $plugin;
    private $settings;

    // OME config keys stored under lem_settings
    const SETTING_SERVER_URL   = 'ome_server_url';    // e.g. https://live.example.com
    const SETTING_API_URL      = 'ome_api_url';       // e.g. https://live.example.com:8081
    const SETTING_API_TOKEN    = 'ome_api_token';     // OME API access token
    const SETTING_APP_NAME     = 'ome_app_name';      // OME application name (default: app)
    const SETTING_STREAM_NAME  = 'ome_stream_name';   // Default stream name
    const SETTING_SIGNING_KEY  = 'ome_signing_key';   // Signed Policy HMAC key
    const SETTING_WEBRTC_PORT  = 'ome_webrtc_port';   // Default: 3333
    const SETTING_LLHLS_PORT   = 'ome_llhls_port';    // Default: 3334
    const SETTING_TOKEN_TTL    = 'ome_token_ttl';     // Signed URL TTL in minutes (default: 60)

    public function __construct( $plugin ) {
        $this->plugin   = $plugin;
        $this->settings = get_option( 'lem_settings', array() );
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function get_name() {
        return 'OvenMediaEngine';
    }

    public function get_id() {
        return 'ome';
    }

    public function is_configured() {
        return ! empty( $this->settings[ self::SETTING_SERVER_URL ] )
            && ! empty( $this->settings[ self::SETTING_SIGNING_KEY ] );
    }

    public function get_credentials() {
        return array(
            'server_url'  => $this->settings[ self::SETTING_SERVER_URL ]  ?? '',
            'api_url'     => $this->settings[ self::SETTING_API_URL ]     ?? '',
            'api_token'   => $this->settings[ self::SETTING_API_TOKEN ]   ?? '',
            'app_name'    => $this->settings[ self::SETTING_APP_NAME ]    ?? 'app',
            'stream_name' => $this->settings[ self::SETTING_STREAM_NAME ] ?? 'stream',
            'signing_key' => $this->settings[ self::SETTING_SIGNING_KEY ] ?? '',
            'webrtc_port' => $this->settings[ self::SETTING_WEBRTC_PORT ] ?? '3333',
            'llhls_port'  => $this->settings[ self::SETTING_LLHLS_PORT ]  ?? '3334',
            'token_ttl'   => (int) ( $this->settings[ self::SETTING_TOKEN_TTL ] ?? 60 ),
        );
    }

    // -------------------------------------------------------------------------
    // Signed Policy token generation
    //
    // OME Signed Policy spec:
    //   policy    = base64url( json_encode({ "url_expire": "<ISO8601>" }) )
    //   signature = base64url( hmac_sha1( signing_key, policy ) )
    //   append    ?policy={policy}&signature={signature} to every stream URL
    // -------------------------------------------------------------------------

    /**
     * Generate a Signed Policy token string (policy + signature) valid for
     * $ttl_minutes. The same token is appended to every stream URL for this
     * viewer session.
     *
     * @param int $ttl_minutes
     * @return array{ policy: string, signature: string, expires_at: string }
     */
    public function generate_signed_policy( $ttl_minutes = 60 ) {
        $creds      = $this->get_credentials();
        $signing_key = $creds['signing_key'];
        $expires_at  = gmdate( 'Y-m-d\TH:i:s+00:00', time() + ( $ttl_minutes * 60 ) );

        $policy_json    = json_encode( array( 'url_expire' => $expires_at ), JSON_UNESCAPED_SLASHES );
        $policy_b64     = $this->base64url_encode( $policy_json );
        $hmac_raw       = hash_hmac( 'sha1', $policy_b64, $signing_key, true );
        $signature_b64  = $this->base64url_encode( $hmac_raw );

        return array(
            'policy'     => $policy_b64,
            'signature'  => $signature_b64,
            'expires_at' => $expires_at,
        );
    }

    /**
     * Append Signed Policy query params to a stream URL.
     */
    private function sign_url( $url, $policy, $signature ) {
        $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&';
        return $url . $sep . 'policy=' . $policy . '&signature=' . $signature;
    }

    /**
     * URL-safe Base64 encoding (no padding, + → -, / → _).
     */
    private function base64url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    // -------------------------------------------------------------------------
    // Provider interface — token & playback
    // -------------------------------------------------------------------------

    /**
     * Generate an OME Signed Policy for a viewer session.
     * Returns a shape compatible with the rest of the plugin:
     *   jwt        — the WebRTC signed URL (used as the primary "token" stored in DB)
     *   llhls_url  — LLHLS fallback signed URL
     *   policy     — raw policy string (for JS hot-swap)
     *   signature  — raw signature string (for JS hot-swap)
     *   expires_at — ISO 8601 expiry
     */
    public function generate_playback_token( $email, $event_id, $payment_id = null, $is_refresh = false ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'OvenMediaEngine credentials are not configured.' );
        }

        $creds   = $this->get_credentials();
        $ttl     = $creds['token_ttl'];
        $signed  = $this->generate_signed_policy( $ttl );

        $webrtc_url = $this->build_webrtc_url( $creds );
        $llhls_url  = $this->build_llhls_url( $creds );

        $signed_webrtc = $this->sign_url( $webrtc_url, $signed['policy'], $signed['signature'] );
        $signed_llhls  = $this->sign_url( $llhls_url,  $signed['policy'], $signed['signature'] );

        // Return in the standard shape the main plugin expects
        return array(
            'jwt'        => $signed_webrtc,   // primary "token" stored in DB
            'llhls_url'  => $signed_llhls,
            'policy'     => $signed['policy'],
            'signature'  => $signed['signature'],
            'expires_at' => $signed['expires_at'],
            'jti'        => 'ome_' . uniqid( '', true ),
            'session_id' => $_COOKIE['lem_session_id'] ?? '',
        );
    }

    // -------------------------------------------------------------------------
    // Player component
    // -------------------------------------------------------------------------

    /**
     * Render OvenPlayer HTML + inline init script.
     *
     * OvenPlayer tries sources in order: WebRTC first (lowest latency),
     * then LLHLS as fallback. Hot-swapping sources on token refresh is done
     * via player.setCurrentSource() — no full reload.
     *
     * @param string $playback_id  Not used for OME; stream URL comes from token.
     * @param string $token        The signed WebRTC URL returned by generate_playback_token.
     * @param array  $options      poster, title, llhls_url, policy, signature
     */
    public function get_player_component( $playback_id, $token = null, $options = array() ) {
        $creds       = $this->get_credentials();
        $player_id   = 'lem-ome-player-' . uniqid();
        $poster      = esc_url( $options['poster'] ?? '' );
        $title       = esc_js( $options['title'] ?? '' );
        $webrtc_url  = $token ?? '';
        $llhls_url   = $options['llhls_url'] ?? '';
        $policy      = $options['policy']    ?? '';
        $signature   = $options['signature'] ?? '';

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $player_id ); ?>" class="lem-ome-player"></div>
        <script>
        (function() {
            var playerId   = <?php echo wp_json_encode( $player_id ); ?>;
            var webrtcUrl  = <?php echo wp_json_encode( $webrtc_url ); ?>;
            var llhlsUrl   = <?php echo wp_json_encode( $llhls_url ); ?>;
            var policy     = <?php echo wp_json_encode( $policy ); ?>;
            var signature  = <?php echo wp_json_encode( $signature ); ?>;

            function initOvenPlayer() {
                if (typeof OvenPlayer === 'undefined') {
                    setTimeout(initOvenPlayer, 200);
                    return;
                }

                var sources = [];

                if (webrtcUrl) {
                    sources.push({ label: 'WebRTC', type: 'webrtc', file: webrtcUrl });
                }
                if (llhlsUrl) {
                    sources.push({ label: 'LLHLS',  type: 'llhls',  file: llhlsUrl  });
                }

                var player = OvenPlayer.create(playerId, {
                    sources:          sources,
                    <?php if ( $poster ) : ?>poster: <?php echo wp_json_encode( $poster ); ?>,<?php endif; ?>
                    autoStart:        true,
                    autoFallback:     true,
                    mute:             false,
                    showBigPlayButton: false,
                });

                // Expose on window for token hot-swap from public.js refresh cycle
                window.lemOmePlayer = player;
                window.lemOmePolicy    = policy;
                window.lemOmeSignature = signature;

                player.on('error', function(err) {
                    console.warn('[LEM] OvenPlayer error:', err);
                });
            }

            initOvenPlayer();
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Stream URL builders
    // -------------------------------------------------------------------------

    private function get_host_only( $url ) {
        $parts = parse_url( $url );
        return $parts['host'] ?? $url;
    }

    private function build_webrtc_url( $creds ) {
        $host   = $this->get_host_only( $creds['server_url'] );
        $port   = $creds['webrtc_port'];
        $app    = rawurlencode( $creds['app_name'] );
        $stream = rawurlencode( $creds['stream_name'] );
        return "wss://{$host}:{$port}/{$app}/{$stream}";
    }

    private function build_llhls_url( $creds ) {
        $host   = $this->get_host_only( $creds['server_url'] );
        $port   = $creds['llhls_port'];
        $app    = rawurlencode( $creds['app_name'] );
        $stream = rawurlencode( $creds['stream_name'] );
        return "https://{$host}:{$port}/{$app}/{$stream}/llhls.m3u8";
    }

    // -------------------------------------------------------------------------
    // OME REST API helpers
    // -------------------------------------------------------------------------

    private function api_request( $path, $method = 'GET', $body = null ) {
        $creds   = $this->get_credentials();
        $api_url = rtrim( $creds['api_url'] ?: preg_replace( '#(:\d+)?$#', ':8081', rtrim( $creds['server_url'], '/' ) ), '/' );
        $url     = $api_url . '/v1' . $path;

        $args = array(
            'method'  => $method,
            'timeout' => 10,
            'headers' => array(
                // OME expects `Authorization: Basic base64(AccessToken)` (no username/password form).
                // Using `token:` will yield 401 Invalid credential.
                'Authorization' => 'Basic ' . base64_encode( (string) ( $creds['api_token'] ?? '' ) ),
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            return new WP_Error(
                'ome_api_error',
                $data['message'] ?? "OME API returned HTTP {$code}"
            );
        }

        return $data;
    }

    private function vhost_app_path( $creds = null ) {
        if ( ! $creds ) $creds = $this->get_credentials();
        $vhost  = 'default';   // OME default virtual host
        $app    = rawurlencode( $creds['app_name'] );
        return "/vhosts/{$vhost}/apps/{$app}";
    }

    // -------------------------------------------------------------------------
    // Stream management
    // -------------------------------------------------------------------------

    public function get_rtmp_info( $stream_id = null ) {
        $creds = $this->get_credentials();
        $host  = $this->get_host_only( $creds['server_url'] );

        return array(
            'ingest_url'  => "rtmp://{$host}:1935/{$creds['app_name']}",
            'stream_key'  => $creds['stream_name'],
            'playback_id' => $creds['stream_name'],
        );
    }

    public function list_streams( $limit = 100 ) {
        $creds = $this->get_credentials();
        $path  = $this->vhost_app_path( $creds ) . '/streams';
        $data  = $this->api_request( $path );

        if ( is_wp_error( $data ) ) return $data;

        $streams = array();
        foreach ( ( $data['response'] ?? array() ) as $stream_name ) {
            $streams[] = array(
                'id'   => $stream_name,
                'name' => $stream_name,
            );
        }

        // Merge in any locally-saved stream names (useful for OME, where streams
        // "exist" once an encoder pushes, but operators may want to predefine
        // names for event assignment).
        $settings = get_option( 'lem_settings', array() );
        $saved    = $settings['lem_saved_stream_ids'] ?? $settings['mux_saved_stream_ids'] ?? array();
        if ( is_array( $saved ) ) {
            foreach ( $saved as $sid ) {
                $sid = (string) $sid;
                if ( $sid === '' ) continue;
                $exists = false;
                foreach ( $streams as $s ) {
                    if ( ( $s['id'] ?? '' ) === $sid ) { $exists = true; break; }
                }
                if ( ! $exists ) {
                    $streams[] = array( 'id' => $sid, 'name' => $sid );
                }
            }
        }

        return $streams;
    }

    public function get_stream_status( $stream_id = null ) {
        $creds  = $this->get_credentials();
        $name   = $stream_id ?? $creds['stream_name'];
        $path   = $this->vhost_app_path( $creds ) . '/streams/' . rawurlencode( $name );
        $data   = $this->api_request( $path );

        if ( is_wp_error( $data ) ) {
            return array( 'status' => 'idle', 'error' => $data->get_error_message() );
        }

        $resp   = $data['response'] ?? array();
        $inputs = $resp['input']['sourceType'] ?? null;
        $status = $inputs ? 'active' : 'idle';

        return array(
            'status'    => $status,
            'stream_id' => $name,
            'raw'       => $resp,
        );
    }

    public function get_stream_details( $stream_id ) {
        $creds = $this->get_credentials();
        $path  = $this->vhost_app_path( $creds ) . '/streams/' . rawurlencode( $stream_id );
        $data  = $this->api_request( $path );
        if ( is_wp_error( $data ) ) return $data;
        return $data['response'] ?? $data;
    }

    public function create_stream( $params ) {
        // OME streams are created by the encoder pushing RTMP — no API call needed.
        // Return a stub with the RTMP ingest info.
        return $this->get_rtmp_info();
    }

    public function update_stream( $stream_id, $params ) {
        return new WP_Error( 'not_supported', 'OME stream config is managed server-side.' );
    }

    public function delete_stream( $stream_id ) {
        $creds = $this->get_credentials();
        $path  = $this->vhost_app_path( $creds ) . '/streams/' . rawurlencode( $stream_id );
        return $this->api_request( $path, 'DELETE' );
    }

    // OME does not support simulcast targets natively (use OBS multi-output for that)
    public function create_simulcast_target( $stream_id, $url ) {
        return new WP_Error( 'not_supported', 'Use OBS multi-output or a relay to simulcast from OME.' );
    }

    public function list_simulcast_targets( $stream_id = null ) {
        return array();
    }

    public function delete_simulcast_target( $stream_id, $target_id ) {
        return new WP_Error( 'not_supported', 'Simulcast targets are not managed via the OME API.' );
    }

    // WebRTC is OME's primary protocol
    public function get_webrtc_publish_url( $stream_id = null ) {
        $creds  = $this->get_credentials();
        $host   = $this->get_host_only( $creds['server_url'] );
        $port   = $creds['webrtc_port'];
        $app    = rawurlencode( $creds['app_name'] );
        $stream = rawurlencode( $stream_id ?? $creds['stream_name'] );
        return "wss://{$host}:{$port}/{$app}/{$stream}";
    }

    public function get_webrtc_playback_url( $stream_id = null ) {
        return $this->get_webrtc_publish_url( $stream_id );
    }

    public function get_playback_url( $stream_id = null ) {
        $creds = $this->get_credentials();
        return $this->build_llhls_url( $creds );
    }

    // -------------------------------------------------------------------------
    // Webhook (OME Admission Webhook — optional)
    // -------------------------------------------------------------------------

    public function handle_webhook( $payload, $signature = null ) {
        // OME Admission Webhooks allow access control on connect.
        // Currently we just return allowed=true; extend here to check session tokens.
        return array( 'allowed' => true );
    }

    // -------------------------------------------------------------------------
    // Admin settings
    // -------------------------------------------------------------------------

    public function get_settings_fields() {
        return array(
            self::SETTING_SERVER_URL => array(
                'label'       => 'OME Server URL',
                'type'        => 'url',
                'required'    => true,
                'section'     => 'Server',
                'description' => 'Public-facing URL of your OME server. E.g. <code>https://live.example.com</code>. '
                               . 'Ports for WebRTC (3333) and LLHLS (3334) are derived from this unless overridden below.',
                'placeholder' => 'https://live.example.com',
            ),
            self::SETTING_APP_NAME => array(
                'label'       => 'Application Name',
                'type'        => 'text',
                'required'    => true,
                'section'     => 'Server',
                'description' => 'OME application name as defined in <code>Server.xml</code>. Default is <code>app</code>.',
                'placeholder' => 'app',
            ),
            self::SETTING_STREAM_NAME => array(
                'label'       => 'Default Stream Name',
                'type'        => 'text',
                'required'    => true,
                'section'     => 'Server',
                'description' => 'The stream key / stream name broadcasters push to. Can be overridden per event.',
                'placeholder' => 'stream',
            ),
            self::SETTING_WEBRTC_PORT => array(
                'label'       => 'WebRTC Port',
                'type'        => 'number',
                'required'    => false,
                'section'     => 'Server',
                'description' => 'OME WebRTC signalling port. Default: <code>3333</code>.',
                'placeholder' => '3333',
            ),
            self::SETTING_LLHLS_PORT => array(
                'label'       => 'LLHLS Port',
                'type'        => 'number',
                'required'    => false,
                'section'     => 'Server',
                'description' => 'OME LLHLS/HLS port. Default: <code>3334</code>.',
                'placeholder' => '3334',
            ),
            self::SETTING_SIGNING_KEY => array(
                'label'       => 'Signed Policy Key',
                'type'        => 'password',
                'required'    => true,
                'section'     => 'Access Control',
                'description' => 'Secret key used to sign stream URLs (HMAC-SHA1). Must match the <code>SignedPolicy > SecretKey</code> '
                               . 'value in your OME <code>Server.xml</code>.',
                'placeholder' => 'your-hmac-secret',
            ),
            self::SETTING_TOKEN_TTL => array(
                'label'       => 'Token TTL (minutes)',
                'type'        => 'number',
                'required'    => false,
                'section'     => 'Access Control',
                'description' => 'How long a signed stream URL stays valid. Default: <code>60</code>. '
                               . 'Keep this longer than a typical stream duration to avoid mid-stream expiry.',
                'placeholder' => '60',
            ),
            self::SETTING_API_URL => array(
                'label'       => 'API URL (optional)',
                'type'        => 'url',
                'required'    => false,
                'section'     => 'Management API',
                'description' => 'OME REST API base URL including port. Leave blank to auto-derive as <code>{server_url}:8081</code>.',
                'placeholder' => 'https://live.example.com:8081',
            ),
            self::SETTING_API_TOKEN => array(
                'label'       => 'API Access Token',
                'type'        => 'password',
                'required'    => false,
                'section'     => 'Management API',
                'description' => 'Token configured in OME\'s <code>Server.xml</code> under <code>Managers > Host > AccessToken</code>. '
                               . 'Required only for stream management features (list/status).',
            ),
        );
    }

    public function validate_settings( $settings ) {
        $errors = array();

        if ( empty( $settings[ self::SETTING_SERVER_URL ] ) ) {
            $errors[] = 'OME Server URL is required.';
        } elseif ( ! filter_var( $settings[ self::SETTING_SERVER_URL ], FILTER_VALIDATE_URL ) ) {
            $errors[] = 'OME Server URL must be a valid URL.';
        }

        if ( empty( $settings[ self::SETTING_SIGNING_KEY ] ) ) {
            $errors[] = 'Signed Policy Key is required for secure stream URLs.';
        }

        if ( empty( $settings[ self::SETTING_APP_NAME ] ) ) {
            $errors[] = 'Application Name is required.';
        }

        return empty( $errors ) ? true : $errors;
    }

    public function supports_token_refresh() {
        return false;
    }

    public function get_extra_tabs() {
        return array();
    }

    public function normalize_stream( array $raw ): array {
        // OME list_streams returns minimal objects; enrich where possible.
        $id   = $raw['id'] ?? $raw['name'] ?? '';
        $name = $raw['name'] ?? $id;
        return array(
            'id'         => $id,
            'name'       => $name,
            'status'     => $raw['status'] ?? 'idle',
            'stream_key' => '',  // OME has no per-stream key; auth is app-level
            'playback_id'=> $id, // Stream name doubles as the playback identifier
            'created_at' => $raw['created_at'] ?? '',
        );
    }

    public function get_create_stream_fields(): array {
        // OME streams are created by the encoder pushing RTMP — nothing to configure here.
        return array();
    }

    public function get_edit_stream_fields(): array {
        // OME streams are managed server-side; no in-app editing.
        return array();
    }

    public function supports_simulcast(): bool {
        // OME does not expose a simulcast API; users should use OBS multi-output.
        return false;
    }
}
