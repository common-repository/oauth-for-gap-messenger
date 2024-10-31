<?php
/**
 * Author: Ehsan Sabet(ehsan.sabet@hotmail.com)
 */

namespace GapOauth;

class GapOauth {

	protected $_clientId = '';

	protected $_clientSecret = '';

	protected $_url = 'https://login.gap.im/oauth/token';

	protected $_authorizeUrl = 'https://login.gap.im/oauth/authorize';

	protected $_config = [];

	public function __construct(array $config = []) {
		$defaults = [
			'url' => $this->_url,
			'clientId' => '',
			'clientSecret' => '',
		];
		$config += $defaults;

		$this->_config = $config;
	}

	protected function _init() {
		$this->_url = $this->_config['url'];
		$this->_clientId = $this->_config['clientId'];
		$this->_clientSecret = $this->_config['clientSecret'];
	}

	public function loginUrl() {
		$data = [
			'client_id' => $this->_config['clientId'],
			'grant_type' => 'code',
		];

		return $this->_authorizeUrl . '?' . http_build_query($data);
	}

	public function check($code) {
		if (empty($code)) {
			return false;
		}
		$data = [
			'client_id' => $this->_config['clientId'],
			'grant_type' => 'code',
			'code' => $code,
			'client_secret' => $this->_config['clientSecret'],
		];
		try {
			$response = $this->sendRequest($this->_url, $data);
		} catch (\Exception $e) {
			return false;
		}
		$userData = json_decode($response, true);
		return $userData;
	}

	private function sendRequest($url, $params) {

		$url .= '?' . http_build_query($params);

		$response = wp_remote_get($url);
		return wp_remote_retrieve_body($response);
	}
}

?>