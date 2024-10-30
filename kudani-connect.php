<?php
/**
 * Kudani WordPress connector
 *
 * @package   KudaniConnect
 * @author    Kudani
 * @link      https://kudani.com
 * @copyright Copyright © 2022 Kudani
 *
 * Plugin Name: KudaniConnect
 * Plugin URI: https://kudani.com
 * Description: Content Marketing for Professionals
 * Version: 2.1.7
 * Author: PageOneTraffic Ltd
 * Author URI: http://www.pageonetraffic.com
 * Tested up to: 6.0
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class KudaniConnect {

	private $version = '2.1.6';

	function __construct() {
		require_once( plugin_dir_path( __FILE__ ) . 'lib/jwt/class-jwt-auth.php' );

		if ( class_exists( 'Jwt_Auth' ) ) {
			$plugin = new Jwt_Auth();
			$plugin->run();
		}

		add_filter( 'jwt_auth_expire', array( $this, 'jwt_extend_expire' ) );
		add_action( 'plugins_loaded', array( $this, 'jwt_validate_secret' ) );
		add_action( 'plugins_loaded', array( $this, 'kc_init' ) );
		add_action( 'admin_init', array( $this, 'detect_rest' ) );
		add_action( 'admin_init', array( $this, 'permalinks_regenerate' ) );
		add_filter( 'safe_style_css', array( $this, 'kudani_css' ) );
		add_filter( 'wp_insert_post_data' , array( $this, 'post_save' ), 99, 2 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'kudani_iframe' ), 1, 1 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'kudani_kses' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'endpoints' ) );
		add_filter( 'rest_prepare_post', array( $this, 'kc_rest_prepare_post' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'this_screen' ) );
	}

	public function post_save( $data, $postarr ) {
                if (  isset( $data['post_type'] ) &&  $data['post_type'] === 'page' ) {
                    return $data;
		}
            
		if ( ! isset( $data['post_content'] ) || empty( $data['post_content'])) {
                    return $data;
		}
                $trimmedData = trim( $data['post_content'] );
                if(empty($trimmedData)) {
                    return $data;
                }
                
		$dom = new DOMDocument;
                libxml_use_internal_errors(true);
                $content = mb_convert_encoding(stripslashes( $data['post_content'] ), 'HTML-ENTITIES', "UTF-8");
		$dom->loadHTML( $content );

		$xpath = new DomXPath( $dom );
		$xpath_results = $xpath->query( "//div[contains(@class, 'kudani-video-wrapper')]" );

		for ( $i = $xpath_results->length - 1; $i >= 0; $i -- ) {
			$node_pre = $xpath_results->item( $i );
			$node_div = $dom->createElement( 'iframe', $node_pre->nodeValue );

			if ( $node_pre->hasAttributes() ) {
				foreach ( $node_pre->attributes as $attr ) {
					$attrVal = ( strlen( $attr->nodeValue ) == 0 ) ? '' : $attr->nodeValue;
					$node_div->setAttribute( str_replace( 'data-attr-', '', $attr->nodeName ), $attrVal );
				}
			}

			$node_pre->parentNode->replaceChild( $node_div, $node_pre );
		}

		$stripped = preg_replace( '/^<!DOCTYPE.+?>/', '', str_replace( array( '<html>', '</html>', '<body>', '</body>' ), array( '', '', '', '' ), $dom->saveHTML() ) );
                $asciiOutput = mb_convert_encoding($stripped, 'HTML-ENTITIES', 'UTF-8');
                $data['post_content'] = addslashes( $asciiOutput );

		return $data;
	}

	public function kudani_kses( $allowed, $context ) {
		if ( is_array( $context ) ) {
			return $allowed;
		}

		if ( 'post' === $context ) {
			$allowed['div']['data-attr-height'] = true;
			$allowed['div']['data-attr-width'] = true;
			$allowed['div']['data-attr-frameborder'] = true;
			$allowed['div']['data-attr-src'] = true;
			$allowed['div']['data-attr-allowfullscreen'] = true;
		}

		return $allowed;
	}

	public function kudani_iframe( $allowed ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return $allowed;
		}

		$allowed['iframe'] = array(
			'align' => true,
			'width' => true,
			'height' => true,
			'frameborder' => true,
			'name' => true,
			'src' => true,
			'id' => true,
			'class' => true,
			'style' => true,
			'scrolling' => true,
			'marginwidth' => true,
			'marginheight' => true,
		);

		return $allowed;
	}

	public function kudani_css( $styles ) {
		$styles[] = 'border-radius';
		return $styles;
	}

	public function detect_rest() {
		$wp_version = get_bloginfo( 'version' );

		if ( $wp_version < 4.7 ) {
			if ( ! is_plugin_active( 'rest-api/plugin.php' ) ) {
				add_action( 'admin_notices', array( $this, 'rest_admin_notice__warning' ) );
			}
		}
	}

	public function rest_admin_notice__warning() {
		$url = 'https://wordpress.org/plugins/rest-api/';
		$message = sprintf( wp_kses( __( '<a href="%s">WP REST API plugin</a> is required to fully activate KudaniConnect.', 'kudani-connect' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( $url ) );
		printf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-warning', $message );
	}

	/**
	 * Forces to save new permalinks configuration
	 * @since 2.0.9
	 */
	private function update_permalinks() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		update_option( 'rewrite_rules', false );
		$wp_rewrite->flush_rules( true );

		$message = __( 'Permalink structure updated.' );
		add_settings_error( 'general', 'settings_updated', $message, 'updated' );
	}

	/**
	 * Detect whether user clicked "invalid permalinks" notification or not
	 * @since 2.0.9
	 */
	public function permalinks_regenerate() {
		if ( 'fix-permalinks' == filter_input( INPUT_GET, 'kudani' ) ) {
			$this->update_permalinks();
		}
	}

	private function rest_admin_notice__dependencies() {
		//$url = get_site_url() . '/wp-admin/options-permalink.php';

		$url = get_site_url() . '/wp-admin/options-permalink.php?kudani=fix-permalinks';
		$message = sprintf( wp_kses( __( 'KudaniConnect can\'t work without correct configuration of permalinks. <a href="%s">Click to regenerate permalinks settings</a>.', 'kudani-connect' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( $url ) );
		printf( '<div class="%1$s"><p>%2$s</p></div>', 'notice notice-error', $message );
	}

	public function kc_init() {
		add_filter( 'pre_get_posts', array( $this, 'show_public_preview' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'wpseo_whitelist_permalink_vars', array( $this, 'add_query_var' ) );
	}

	public function jwt_validate_secret() {
		$skey = get_option( 'kudani_jwt_secret_key' );

		if ( empty( $skey ) ) {
			update_option( 'kudani_jwt_secret_key', wp_generate_password( 70, true, true ) );
		}
	}

	public function endpoints() {
		register_rest_route('jwt-auth/v1', '/token/reset', array(
			'methods' => 'GET',
			'callback' => array( $this, 'jwt_reset_secret' ),
			'permission_callback' => array( $this, 'get_item_permissions_check' ),
		));
                
                register_rest_route('jwt-auth/v1', '/kudani/check', array(
			'methods' => 'GET',
			'callback' => array( $this, 'get_kudani_check_callback' ),
			'permission_callback' => array( $this, 'get_permission_true' ),
		));                
	}
        
        public function get_permission_true($request) {
            return true;
        }
        
        public function get_kudani_check_callback() {
            return rest_ensure_response( array( 'result' => true ) );
        }

	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_user' ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to reset access' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	public function jwt_reset_secret() {
		update_option( 'kudani_jwt_secret_key', wp_generate_password( 70, true, true ) );

		return rest_ensure_response( array( 'result' => true ) );
	}

	public function jwt_extend_expire() {
		return time() + ( 60 * 60 * 24 * 730 );
	}

	public function show_public_preview( $query ) {
		if (
			$query->is_main_query() &&
			$query->is_preview() &&
			$query->is_singular() &&
			$query->get( 'kc_p' )
		) {
			add_filter( 'posts_results', array( $this, 'kc_post_to_publish' ) , 10, 2 );
		}

		return $query;
	}

	public function kc_post_to_publish( $posts ) {
		// Remove the filter again, otherwise it will be applied to other queries too.
		remove_filter( 'posts_results', array( $this, 'kc_post_to_publish' ) , 10, 2 );

		if ( empty( $posts ) ) {
			return;
		}
		$post_id = $posts[0]->ID;

		// If the post has gone live, redirect to it's proper permalink.
		$this->kc_redirect_to_published_post( $post_id );

		if ( $this->kc_is_public_preview_available( $post_id ) ) {
			// Set post status to publish so that it's visible.
			$posts[0]->post_status = 'publish';

			// Disable comments and pings for this post.
			add_filter( 'comments_open', '__return_false' );
			add_filter( 'pings_open', '__return_false' );
		}

		return $posts;
	}

	public function kc_redirect_to_published_post( $post_id ) {
	    if ( ! in_array( get_post_status( $post_id ), $this->kc_get_published_statuses() ) ) {
	        return false;
	    }
	    wp_redirect( get_permalink( $post_id ), 301 );
	    exit;
	}

	public function kc_is_public_preview_available( $post_id ) {
		if ( empty( $post_id ) ) {
			return false;
		}

		if ( ! $this->kc_verify_nonce( get_query_var( 'kc_p' ), 'kc_post_preview_' . $post_id ) ) {
			wp_die( __( 'The link has been expired!', 'kc-post-preview' ) );
		}

		if ( ! in_array( $post_id, $this->kc_get_preview_post_ids() ) ) {
			wp_die( __( 'No Public Preview available!', 'kc-post-preview' ) );
		}

		return true;
	}

	public function kc_get_published_statuses() {
		$published_statuses = array( 'publish', 'private' );

		return apply_filters( 'kc_published_statuses', $published_statuses );
	}

	public function add_query_var( $qv ) {
		$qv[] = 'kc_p';

		return $qv;
	}

	public function kc_nonce_tick() {
		$nonce_life = 60 * 60 * 24 * 7; // 7 days

		return ceil( time() / ( $nonce_life / 2 ) );
	}

	public function kc_create_nonce( $action = -1 ) {
		$nonce_tick = $this->kc_nonce_tick();

		return substr( wp_hash( $nonce_tick . $action, 'nonce' ), -12, 10 );
	}

	public function kc_verify_nonce( $nonce, $action = -1 ) {
		$nonce_tick = $this->kc_nonce_tick();

		// Nonce generated 0-12 hours ago.
		if ( substr( wp_hash( $nonce_tick . $action, 'nonce' ), -12, 10 ) == $nonce ) {
			return 1;
		}

		// Nonce generated 12-24 hours ago.
		if ( substr( wp_hash( ( $nonce_tick - 1) . $action, 'nonce' ), -12, 10 ) == $nonce ) {
			return 2;
		}

		// Invalid nonce.
		return false;
	}

	public function kc_get_preview_link( $post ) {
		if ( 'page' == $post->post_type ) {
			$args = array(
				'page_id' => $post->ID,
			);
		} elseif ( 'post' == $post->post_type ) {
			$args = array(
				'p' => $post->ID,
			);
		} else {
			$args = array(
				'p' => $post->ID,
				'post_type' => $post->post_type,
			);
		}

		$args['preview'] = true;
		$args['kc_p'] = $this->kc_create_nonce( 'kc_post_preview_' . $post->ID );

		$preview_post_ids = $this->kc_get_preview_post_ids();
		$flag = 0;
		$preview_post_id = (int) $post->ID;

		if ( ('page' == $post->post_status && ! current_user_can( 'edit_page', $preview_post_id ) ) || ! current_user_can( 'edit_post', $preview_post_id ) ) {
			$flag = 1;
		}

		if ( in_array( $post->post_status, $this->kc_get_published_statuses() ) ) {
			$flag = 1;
		}

		if ( 0 == $flag ) {
			$preview_post_ids = array_merge( $preview_post_ids, (array) $post->ID );
		}

		$this->kc_set_preview_post_ids( $preview_post_ids );
		$link = add_query_arg( $args, home_url( '/' ) );

		return apply_filters( 'kc_preview_link', $link, $post->ID, $post );
	}

	public function kc_set_preview_post_ids( $post_ids = array() ) {
		return update_option( 'kc_post_preview', $post_ids );
	}

	public function kc_get_preview_post_ids() {
		return get_option( 'kc_post_preview', array() );
	}

	public function kc_rest_prepare_post( $response, $post ) {
		$response->data['kl_preview_url'] = $this->kc_get_preview_link( $post );
		return $response;
	}

	public function this_screen() {
		$current_screen = get_current_screen();
		if ( in_array( $current_screen->id, array( 'dashboard', 'plugins' ) ) ) {
			if ( ! $this->met_dependencies() ) {
				$this->rest_admin_notice__dependencies();
			}
		}
	}

	/**
	 * Check if permalinks are coorectly set
	 * @since 2.0.9
	 * @return bool True if condition met
	 */
	private function met_dependencies() {
		$response = wp_remote_get( get_site_url() . '/wp-json/jwt-auth/v1', array(
			'timeout' => 3,
		) );

		return ( 200 == wp_remote_retrieve_response_code( $response ) );
	}
}

/**
 * WordPress < 4.4 dependencies
 */
if ( ! function_exists( 'rest_get_url_prefix' ) ) {
	function rest_get_url_prefix() {
		/**
		 * Filters the REST URL prefix.
		 *
		 * @since 4.4.0
		 *
		 * @param string $prefix URL prefix. Default 'wp-json'.
		 */
		return apply_filters( 'rest_url_prefix', 'wp-json' );
	}
}

if ( ! function_exists( 'get_rest_url' ) ) {
	function get_rest_url( $blog_id = null, $path = '/', $scheme = 'rest' ) {
		if ( empty( $path ) ) {
			$path = '/';
		}

		if ( is_multisite() && get_blog_option( $blog_id, 'permalink_structure' ) || get_option( 'permalink_structure' ) ) {
			$url = get_home_url( $blog_id, rest_get_url_prefix(), $scheme );
			$url .= '/' . ltrim( $path, '/' );
		} else {
			$url = trailingslashit( get_home_url( $blog_id, '', $scheme ) );
			$path = '/' . ltrim( $path, '/' );
			$url = add_query_arg( 'rest_route', $path, $url );
		}

		if ( is_ssl() ) {
			// If the current host is the same as the REST URL host, force the REST URL scheme to HTTPS.
			if ( parse_url( get_home_url( $blog_id ), PHP_URL_HOST ) === $_SERVER['SERVER_NAME'] ) {
				$url = set_url_scheme( $url, 'https' );
			}
		}

		/**
		 * Filters the REST URL.
		 *
		 * Use this filter to adjust the url returned by the get_rest_url() function.
		 *
		 * @since 4.4.0
		 *
		 * @param string $url     REST URL.
		 * @param string $path    REST route.
		 * @param int    $blog_id Blog ID.
		 * @param string $scheme  Sanitization scheme.
		 */
		return apply_filters( 'rest_url', $url, $path, $blog_id, $scheme );
	}
}

/**
 * Main loader
 */
new KudaniConnect;
