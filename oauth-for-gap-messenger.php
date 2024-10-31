<?php
/**
 * Plugin Name: Oauth for Gap Messenger
 * Description: Login and Register your users using Gap Messenger API
 * Version: 1.0.0
 * Author:  Ehsan Sabet
 * Author URI:  https://gap.im/sabet
 * Text Domain: oauth-gap-messenger
 * Domain Path: /languages/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

use GapOauth\GapOauth;

include(plugin_dir_path(__FILE__) . 'inc/GapOauth.php');
include(plugin_dir_path(__FILE__) . 'inc/GapApi.php');
include(plugin_dir_path(__FILE__) . 'inc/oauth-gap-messenger-admin.php');

class OauthGapMessenger {

	public $version = '1.0.0';

	private $_configs = [];

	private $redirect_url;

	private $gap_details;

	public $gap;

	private $plugin_path;

	private $text_domain = 'oauth-gap-messenger';

	private $option_section = 'oauth_gap_configs';

	public function __construct() {
		$this->plugin_path = plugin_dir_path(__FILE__);

		add_action('plugins_loaded', [$this, 'load_plugin_textdomain']);

		// We register our short code
		add_shortcode('login_gap', [$this, 'renderShortCode']);

		// Callback URL
		add_action('wp_ajax_oauth_gap_messenger', [$this, 'gapCallback']);
		add_action('wp_ajax_nopriv_oauth_gap_messenger', [$this, 'gapCallback']);

		//  Admin
		$setting = new OauthGapMessenger_Settings($this->text_domain);
		$this->checkAuthSettings($this->get_settings($this->option_section));
		try {
			$this->gap = new \Gap\SDP\GapApi($this->_configs['client_secret']);
		} catch (Exception $e) {
		}
	}

	public function load_plugin_textdomain() {
		load_plugin_textdomain($this->text_domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	/**
	 * Render the shortcode [login_gap]
	 */
	public function renderShortCode() {
		// Start the session
		if (!session_id()) {
			session_start();
		}
		// No need for the button is the user is already logged
		if (is_user_logged_in()) {
			return;
		}

		// We save the URL for the redirection:
		if (!isset($_SESSION['oauth_gap_url'])) {
			$_SESSION['oauth_gap_url'] = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}

		// Different labels according to whether the user is allowed to register or not
		if (get_option('users_can_register')) {
			$button_label = 'Login or Register with Gap Messenger';
		} else {
			$button_label = 'Login with Gap Messenger';
		}

		// HTML markup
		$html = '<div id="oauth-gap-wrapper">';

		// Messages
		if (isset($_SESSION['oauth_gap_message'])) {
			$message = $_SESSION['oauth_gap_message'];
			$html .= '<div id="oauth-gap-message" class="alert alert-danger">' . $message . '</div>';
			// We remove them from the session
			unset($_SESSION['oauth_gap_message']);
		}

		// Button
		$html .= '<a href="' . $this->getLoginUrl() . '" class="btn" id="oauth-gap-button">' . $button_label . '</a>';

		$html .= '</div>';

		// Write it down
		return $html;
	}

	private function initApi() {

		$gap = new GapOauth([
			'clientId' => $this->_configs['client_id'],
			'clientSecret' => $this->_configs['client_secret'],
		]);

		return $gap;
	}

	private function getLoginUrl() {
		if (!session_id()) {
			session_start();
		}
		$gap = $this->initApi();
		$url = $gap->loginUrl();
		return esc_url($url);
	}

	public function gapCallback() {

		if (!session_id()) {
			session_start();
		}

		$this->redirect_url = (isset($_SESSION['oauth_gap_url'])) ? $_SESSION['oauth_gap_url'] : home_url();

		$gap = $this->initApi();
		$code = $_GET['code'] ? sanitize_text_field($_GET['code']) : null;
		$this->gap_details = $gap->check($code);

		$login = $this->loginUser();
		if (!$login) {
			$this->createUser();
		}

		header("Location: " . $this->redirect_url, true);
		die();
	}

	private function loginUser() {
		$wp_users = get_users([
			'meta_key' => 'oauth_gap_messenger_id',
			'meta_value' => $this->gap_details['data']['publicInfo']['gap_id'],
			'number' => 1,
			'count_total' => false,
			'fields' => 'id',
		]);
		if (empty($wp_users[0])) {
			return false;
		}
		wp_set_auth_cookie($wp_users[0]);
		$msg = sprintf(__('You have Login at %1$s', $this->text_domain), get_bloginfo('title'));
		try {
			$this->gap->sendText($this->gap_details['data']['publicInfo']['chat_id'], $msg);
		} catch (Exception $e) {
		}
		return true;
	}

	private function createUser() {
		$gap_user = $this->gap_details;
		$data = [
			'username' => $gap_user['data']['publicInfo']['username'],
			'email' => null,
			'first_name' => $gap_user['data']['publicInfo']['nickname'],
			'id' => $gap_user['data']['publicInfo']['gap_id'],
			'chat_id' => $gap_user['data']['publicInfo']['chat_id'],
		];

		if (empty($data['username'])) {
			$data['username'] = 'gap_' . $data['id'];
		}

		if (empty($data['email'])) {
			$data['email'] = $data['username'] . '@gap.im';
		}

		$username = sanitize_user(str_replace(' ', '_', strtolower($data['username'])));
		$new_user = wp_create_user($username, wp_generate_password(), $data['email']);

		if (is_wp_error($new_user)) {
			$_SESSION['oauth_gap_message'] = $new_user->get_error_message();
			header("Location: " . $this->redirect_url, true);
			die();
		}

		update_user_meta($new_user, 'first_name', $data['first_name']);
		update_user_meta($new_user, 'oauth_gap_messenger_id', $data['id']);
		update_user_meta($new_user, 'oauth_gap_messenger_details', json_encode($gap_user['data']));

		wp_set_auth_cookie($new_user);

		$msg = sprintf(__('You have registered at %1$s', $this->text_domain), get_bloginfo('title'));
		try {
			$this->gap->sendText($data['chat_id'], $msg);
		} catch (Exception $e) {
		}
	}

	public function validate_settings($input) {
		$input['oauth_configs_redirect_link'] = admin_url('admin-ajax.php?') . http_build_query(['action' => 'oauth_gap_messenger']);
		return $input;
	}

	public function checkAuthSettings($setting) {
		if (empty($setting['client_id']) || empty($setting['client_secret'])) {
			add_action('admin_notices', [$this, 'empty_settings_notice']);
		} else {
			$this->_configs = $setting;
		}
	}

	public function empty_settings_notice() {
		$class = 'notice notice-error';
		$message = __("Please complete oauth Gap messenger settings!", $this->text_domain);
		printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
	}

	public function get_settings_option($option, $section = null, $default = '') {
		if (is_null($section)) {
			$section = $this->option_section;
		}

		$options = get_option($section);

		if (isset($options[$option])) {
			return $options[$option];
		}

		return $default;
	}

	public function get_settings($section) {
		$options = get_option($section);

		if (is_array($options)) {
			return $options;
		}

		return [];
	}

}

new OauthGapMessenger();