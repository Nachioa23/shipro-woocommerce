<?php
/**
 * Pantalla de configuración de Shipro dentro de WooCommerce > Ajustes.
 *
 * DECISIÓN DE UBICACIÓN: agregamos una TAB nueva "Shipro" al costado de
 * General/Productos/Envío/etc. en WooCommerce > Ajustes. Esta es la convención
 * estándar que usan integraciones como Stripe, Mercado Pago o CorreoArgentino —
 * hace que la configuración de credenciales del plugin viva en un lugar
 * predecible para el merchant y separada del catálogo de shipping methods
 * (que sí va en la tab "Envío"; ahí registramos el shipping method concreto
 * en un paso posterior).
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shipro_WC_Settings
 */
class Shipro_WC_Settings {

	/**
	 * ID de la tab en la URL: ?page=wc-settings&tab=shipro
	 */
	const TAB_ID = 'shipro';

	/**
	 * Nombre de la opción en la wp_options — leída con get_option() por
	 * shipro_wc_get_api_key() en bootstrap.php.
	 */
	const OPTION_KEY = 'shipro_wc_api_key';

	/**
	 * Constructor: engancha los 3 hooks estándar de WC settings.
	 */
	public function __construct() {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'agregar_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB_ID, array( $this, 'render_settings' ) );
		add_action( 'woocommerce_update_options_' . self::TAB_ID, array( $this, 'guardar_settings' ) );
	}

	/**
	 * Suma la tab "Shipro" al array de tabs de WC > Ajustes.
	 *
	 * @param array $tabs Tabs actuales.
	 * @return array Tabs con "Shipro" agregado.
	 */
	public function agregar_tab( $tabs ) {
		$tabs[ self::TAB_ID ] = esc_html__( 'Shipro', 'shipro-woocommerce' );
		return $tabs;
	}

	/**
	 * Renderiza el contenido de la tab (usa el helper oficial de WC que arma la tabla).
	 *
	 * @return void
	 */
	public function render_settings() {
		woocommerce_admin_fields( $this->get_fields() );
	}

	/**
	 * Persiste los valores (helper oficial de WC — hace nonce + sanitize + guardar).
	 *
	 * @return void
	 */
	public function guardar_settings() {
		woocommerce_update_options( $this->get_fields() );
	}

	/**
	 * Definición de campos de la tab. Por ahora sólo la API Key.
	 * Formato estándar de WC settings framework (misma shape que usan los
	 * WC_Shipping_Method::init_form_fields, en el nivel Settings).
	 *
	 * @return array
	 */
	private function get_fields() {
		return array(
			array(
				'title' => esc_html__( 'Configuración de Shipro', 'shipro-woocommerce' ),
				'type'  => 'title',
				'desc'  => esc_html__( 'Conectá tu tienda con Shipro para cotizar, imprimir etiquetas y rastrear envíos.', 'shipro-woocommerce' ),
				'id'    => 'shipro_wc_settings_start',
			),
			array(
				'title'    => esc_html__( 'API Key de Shipro', 'shipro-woocommerce' ),
				'desc'     => esc_html__( 'Pegá acá tu API Key de Shipro (empieza con shipro_live_). La obtenés desde tu panel de Shipro.', 'shipro-woocommerce' ),
				'id'       => self::OPTION_KEY,
				'type'     => 'password',
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'shipro_wc_settings_end',
			),
		);
	}
}
