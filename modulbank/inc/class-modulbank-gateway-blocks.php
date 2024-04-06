<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Modulbank_Gateway_Blocks extends AbstractPaymentMethodType {

	private $gateway;
	protected $name = 'modulbank';// your payment gateway name

	public function initialize() {
		$this->settings = get_option( 'woocommerce_modulbank_settings', [] );
		$this->gateway = new WC_Gateway_Modulbank();
	}

	public function is_active() {
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {

		wp_register_script(
			'modulbank-blocks-integration',
			plugin_dir_url(__FILE__) . 'checkout.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			null,
			true
		);
		if( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'modulbank-blocks-integration');

		}
		return [ 'modulbank-blocks-integration' ];
	}

	public function get_payment_method_data() {
		return [
			'title' => $this->gateway->title,
			'description' => $this->gateway->description,
		];
	}

}