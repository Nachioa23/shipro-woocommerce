<?php
/**
 * Plugin Name:       Shipro para WooCommerce
 * Description:       Cotización, etiquetas y seguimiento de envíos con Shipro (multicourier Argentina).
 * Version:           0.1.0
 * Author:            Shipro
 * Author URI:        https://shipro.pro
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       shipro-woocommerce
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -----------------------------------------------------------------------------
 * Plugin constants.
 * -------------------------------------------------------------------------- */
define( 'SHIPRO_WC_VERSION', '0.1.0' );
define( 'SHIPRO_WC_PLUGIN_FILE', __FILE__ );
define( 'SHIPRO_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHIPRO_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* -----------------------------------------------------------------------------
 * WooCommerce feature compatibility declarations.
 * MUST run on before_woocommerce_init — WooCommerce lee las banderas ahí.
 * -  custom_order_tables (HPOS)  → confirmamos que no hay lecturas legacy de post_type=shop_order.
 * -  cart_checkout_blocks        → confirmamos que la UI (cuando la agreguemos) juega bien con el checkout de bloques.
 * -------------------------------------------------------------------------- */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				SHIPRO_WC_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				SHIPRO_WC_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * ¿WooCommerce está activo? Usamos class_exists porque is_plugin_active() no
 * está disponible acá — plugins_loaded corre antes de que wp-admin/includes/plugin.php se cargue en frontend.
 *
 * @return bool
 */
function shipro_wc_woocommerce_activo() {
	return class_exists( 'WooCommerce' );
}

/* -----------------------------------------------------------------------------
 * Bootstrap: si WooCommerce no está activo, no hacemos nada — un aviso admin
 * explica por qué. Si está, cargamos los módulos.
 * -------------------------------------------------------------------------- */
add_action(
	'plugins_loaded',
	function () {
		if ( ! shipro_wc_woocommerce_activo() ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p>';
					echo esc_html__( 'Shipro para WooCommerce requiere WooCommerce activo. Instalá y activá WooCommerce para usar el plugin.', 'shipro-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once SHIPRO_WC_PLUGIN_DIR . 'includes/bootstrap.php';
		shipro_wc_bootstrap();
	}
);
