<?php
/**
 * Shipro Shipping Method for WooCommerce.
 *
 * Zone-based method: el merchant lo agrega a una Shipping Zone en
 * WC > Ajustes > Envío > [Zona] > Agregar método de envío > Shipro.
 * Sólo aparece en el checkout para direcciones que caen en una zona
 * donde el método fue agregado.
 *
 * En STEP 2A devuelve UNA tarifa placeholder de $9999. STEP 2B reemplaza esa
 * placeholder por una llamada real a POST /api/cotizar del backend Shipro,
 * que devuelve N tarifas (una por courier + servicio) y las mapea con add_rate().
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	// Defense-in-depth: WC_Shipping_Method sólo existe después de woocommerce_shipping_init.
	// Este archivo se requiere en ese hook, así que en la práctica no llega acá sin la clase.
	return;
}

/**
 * Class Shipro_WC_Shipping_Method
 */
class Shipro_WC_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id ID de la instancia (una por Zona). Cero cuando WC pregunta la
	 *                         "clase genérica" del método (para renderizar el listado).
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'shipro';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = esc_html__( 'Shipro', 'shipro-woocommerce' );
		$this->method_description = esc_html__( 'Envíos multicourier con Shipro (Andreani, Mocis y más).', 'shipro-woocommerce' );

		// Zone-based + ajustes per-instancia editables desde el modal de WooCommerce.
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->init();
	}

	/**
	 * Boilerplate init: define campos + carga valores persistidos + engancha el save.
	 *
	 * @return void
	 */
	public function init() {
		$this->init_instance_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title' );
		$this->enabled = $this->get_option( 'enabled' );

		// Persist changes when the merchant saves the per-instance modal.
		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Campos per-instancia. El merchant los edita por Zona.
	 *
	 * @return void
	 */
	public function init_instance_form_fields() {
		$this->instance_form_fields = array(
			'enabled' => array(
				'title'   => esc_html__( 'Habilitar', 'shipro-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => esc_html__( 'Habilitar Shipro en esta zona', 'shipro-woocommerce' ),
				'default' => 'yes',
			),
			'title'   => array(
				'title'       => esc_html__( 'Nombre a mostrar', 'shipro-woocommerce' ),
				'type'        => 'text',
				'description' => esc_html__( 'Nombre del método de envío que ve el comprador en el checkout.', 'shipro-woocommerce' ),
				'default'     => esc_html__( 'Shipro', 'shipro-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Cotización.
	 *
	 * @param array $package Info del carrito del comprador (destino, items, etc.).
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		// STEP 2B will replace this placeholder with a real call to Shipro POST /cotizar
		// — con peso + dims + CP origen (del depósito/settings) + CP destino + items del carrito.
		// El response se va a mapear en N tarifas add_rate() reales (una por courier + servicio).
		$this->add_rate(
			array(
				'id'       => $this->id . ':placeholder',
				'label'    => esc_html__( 'Shipro (placeholder)', 'shipro-woocommerce' ),
				'cost'     => 9999,
				'calc_tax' => 'per_order',
				'package'  => $package,
			)
		);
	}
}
