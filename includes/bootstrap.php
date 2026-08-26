<?php
/**
 * Bootstrap: wire up the plugin modules.
 * Corre en plugins_loaded, sólo si WooCommerce está activo (garantizado por el main file).
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SHIPRO_WC_PLUGIN_DIR . 'includes/class-shipro-wc-settings.php';

/**
 * Instancia todos los módulos del plugin.
 *
 * @return void
 */
function shipro_wc_bootstrap() {
	// 1) Pantalla de configuración (WC > Ajustes > Shipro) con la API Key.
	new Shipro_WC_Settings();

	// 2) Método de envío zone-based. Se registra con el patrón estándar de WC:
	//    - woocommerce_shipping_init  → require de la clase (WC_Shipping_Method ya existe acá).
	//    - woocommerce_shipping_methods → filter para sumar 'shipro' al mapa.
	add_action( 'woocommerce_shipping_init', 'shipro_wc_load_shipping_method' );
	add_filter( 'woocommerce_shipping_methods', 'shipro_wc_register_shipping_method' );
}

/**
 * Load the shipping method class file. Debe correr en woocommerce_shipping_init
 * porque recién ahí WC_Shipping_Method está definida (nuestra clase la extiende).
 *
 * @return void
 */
function shipro_wc_load_shipping_method() {
	require_once SHIPRO_WC_PLUGIN_DIR . 'includes/class-shipro-wc-shipping-method.php';
}

/**
 * Register the Shipro method with WooCommerce's registry.
 *
 * @param array $methods Métodos registrados hasta ahora.
 * @return array
 */
function shipro_wc_register_shipping_method( $methods ) {
	$methods['shipro'] = 'Shipro_WC_Shipping_Method';
	return $methods;
}

/**
 * Devuelve la Shipro API Key persistida (string vacío si no está seteada).
 * Helper de lectura reusable — el resto del plugin (cotizador, etiquetas, tracking)
 * la va a llamar en vez de tocar get_option() directo.
 *
 * @return string
 */
function shipro_wc_get_api_key() {
	return (string) get_option( 'shipro_wc_api_key', '' );
}
