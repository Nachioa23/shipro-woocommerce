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
 * Por ahora sólo hay uno: la pantalla de configuración con la API Key.
 *
 * @return void
 */
function shipro_wc_bootstrap() {
	new Shipro_WC_Settings();
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
