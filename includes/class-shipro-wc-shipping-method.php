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
	 * Cotización — llama a POST {base}/cotizar del backend Shipro con la API Key
	 * del merchant y mapea cada OpcionTarifa devuelta en una tarifa WooCommerce.
	 *
	 * Diseño defensivo — la venta NUNCA se cae por esta pieza:
	 * - Sin API Key configurada → 0 tarifas, sin fatal.
	 * - Sin CP destino → 0 tarifas, sin fatal.
	 * - Timeout / conexión rota (Camino 1 del contrato: 5s hard) → 0 tarifas, sin fatal.
	 * - HTTP != 200 / body malformado → 0 tarifas, sin fatal.
	 * - Cualquier Throwable en el parseo → 0 tarifas, sin fatal (catch \Throwable abajo).
	 *
	 * NO inventamos peso/dimensiones (regla del contrato: peso/dims faltantes son
	 * responsabilidad del server; el plugin sólo transmite lo que el WC_Product tiene).
	 * NO enviamos ningún campo de seguro / valorDeclarado — insurance es 100% server-side.
	 *
	 * @param array $package Info del carrito del comprador (destino, items, contents_cost, etc.).
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		try {
			$this->cotizar_y_emitir_tarifas( $package );
		} catch ( \Throwable $e ) {
			// Última red: cualquier excepción no capturada dentro de la cotización cae acá
			// para que el checkout siga funcionando (otros métodos, o el mensaje "no hay opciones").
			$this->debug_log( 'Shipro /cotizar unexpected exception: ' . $e->getMessage() );
		}
	}

	/**
	 * Orquesta el llamado real. Separado para poder wraparlo con try/catch limpio.
	 *
	 * @param array $package Package del carrito.
	 * @return void
	 */
	private function cotizar_y_emitir_tarifas( array $package ) {
		// 1) API Key — sin esto no hay a quién autenticarse. Salida silenciosa; el merchant
		//    verá que el método existe (WC lo lista en la zona) pero no emite tarifas hasta configurarla.
		$api_key = shipro_wc_get_api_key();
		if ( '' === $api_key ) {
			$this->debug_log( 'Shipro: API Key no configurada — no se emiten tarifas.' );
			return;
		}

		// 2) Destino. El server necesita al menos el CP. Si el carrito no lo tiene todavía
		//    (etapa temprana del checkout), salimos sin fatal — WC va a re-calcular cuando el
		//    comprador complete el address form.
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : array();
		$cp_destino  = isset( $destination['postcode'] ) ? sanitize_text_field( (string) $destination['postcode'] ) : '';
		if ( '' === $cp_destino ) {
			return;
		}
		$provincia_destino = isset( $destination['state'] ) ? sanitize_text_field( (string) $destination['state'] ) : '';

		// 3) Construir paquetes[]. Convención: UN paquete por line-item del carrito.
		//    Peso × cantidad (line total); dims per-unit (multiplicar dims no tiene sentido físico,
		//    el server hace su propio empaquetado). Producto sin weight/dims → OMITIMOS esas keys
		//    (no inventamos 10×10×10 ni 1kg — la política de datos faltantes es server-side).
		$paquetes = array();
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();
		foreach ( $contents as $item ) {
			$product = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
			if ( ! $product || ! method_exists( $product, 'get_weight' ) ) {
				continue;
			}
			$qty        = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$peso_unit  = (float) $product->get_weight();
			$largo_unit = method_exists( $product, 'get_length' ) ? (float) $product->get_length() : 0.0;
			$ancho_unit = method_exists( $product, 'get_width' ) ? (float) $product->get_width() : 0.0;
			$alto_unit  = method_exists( $product, 'get_height' ) ? (float) $product->get_height() : 0.0;

			$paquete = array();
			if ( $peso_unit > 0 ) {
				$paquete['pesoKg'] = $peso_unit * $qty;
			}
			if ( $largo_unit > 0 ) {
				$paquete['largoCm'] = $largo_unit;
			}
			if ( $ancho_unit > 0 ) {
				$paquete['anchoCm'] = $ancho_unit;
			}
			if ( $alto_unit > 0 ) {
				$paquete['altoCm'] = $alto_unit;
			}
			if ( method_exists( $product, 'get_name' ) ) {
				$paquete['contenido'] = sanitize_text_field( (string) $product->get_name() );
			}
			$paquetes[] = $paquete;
		}

		if ( empty( $paquetes ) ) {
			// Carrito vacío o sin productos válidos — WC ya no debería llamar acá, pero defensivo.
			return;
		}

		// 4) valorCarrito — sub-total del package (ya calculado por WC en contents_cost).
		$valor_carrito = isset( $package['contents_cost'] ) ? (float) $package['contents_cost'] : 0.0;

		// 5) Body de la request.
		$request = array(
			'cpDestino' => $cp_destino,
			'paquetes'  => $paquetes,
		);
		if ( '' !== $provincia_destino ) {
			$request['provinciaDestino'] = $provincia_destino;
		}
		if ( $valor_carrito > 0 ) {
			$request['valorCarrito'] = $valor_carrito;
		}

		// 6) Endpoint — base URL filterable para poder apuntar a staging desde código.
		$api_base = apply_filters( 'shipro_wc_api_base', 'https://pm.shipro.pro/api' );
		$endpoint = trailingslashit( (string) $api_base ) . 'cotizar';

		// 7) Fire. Timeout 5s = ventana dura del checkout (contrato DEUDA 129 / Camino 1).
		// TEMPORAL (2026-09-04, validación e2e): timeout subido 5s→15s SOLO para confirmar
		// que las tarifas aparecen en el checkout cuando Shipro responde. El valor CORRECTO
		// de producción es 5s (ventana dura del checkout / Tiendanube). Revertir a 5 cuando
		// la DEUDA 145 (núcleo) garantice respuesta <5s con tarifa de rescate. NO deployar a
		// producción con 15s.
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'  => 15,
				'blocking' => true,
				'headers'  => array(
					// La API Key NO debe aparecer en ningún log ni error message — sólo en el header.
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json; charset=utf-8',
					'Accept'        => 'application/json',
					// Defensivo: pedimos UTF-8 explícito para que el server devuelva
					// chars acentuados (ej. "miércoles" en fechaEstimadaString) sin
					// garble. Ver también el comentario en el bloque de parseo abajo:
					// la fuente exacta del mojibake observado 2026-09-04 no está
					// confirmada aún; este header es defensa no destructiva.
					'Accept-Charset' => 'utf-8',
				),
				'body'     => wp_json_encode( $request ),
			)
		);

		// 8) Timeout / conexión rota → no publicamos nada. Camino 1: mejor no mostrar Shipro
		//    que hacer esperar al comprador y perder el checkout.
		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'Shipro /cotizar wp_error: ' . $response->get_error_message() );
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			// Loggear body PODA-do para debug; NO logueamos headers (donde vive la API Key).
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $body, 0, 500 ) : substr( $body, 0, 500 );
			$this->debug_log( 'Shipro /cotizar HTTP ' . $status . ' — body: ' . $snippet );
			return;
		}

		// 9) Parse. Un JSON malformado no debe explotar el checkout.
		// UTF-8: wp_remote_retrieve_body devuelve los bytes crudos tal cual y
		// json_decode los interpreta como UTF-8 nativo (comportamiento estándar).
		// NO se aplica utf8_encode/utf8_decode/mb_convert_encoding acá — cualquier
		// re-encode sobre una string ya-UTF-8 la corrompe (mojibake tipo "miÃ©rcoles").
		// Si el mojibake se ve en el checkout, la causa probable está downstream (theme,
		// template, storage de meta_data), NO en este parseo. Investigación pendiente.
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$this->debug_log( 'Shipro /cotizar body no es JSON válido.' );
			return;
		}

		if ( ! empty( $data['coberturaVacia'] ) ) {
			// El server dijo "no hay cobertura" — no mostramos Shipro pero tampoco error.
			return;
		}

		// 10) Emitir las tarifas EN EL ORDEN devuelto por el server (ya viene ranked por la
		//     regla de ruteo del merchant). Domicilio primero, luego sucursal.
		$grupos = array();
		if ( isset( $data['domicilio'] ) && is_array( $data['domicilio'] ) ) {
			$grupos[] = $data['domicilio'];
		}
		if ( isset( $data['sucursal'] ) && is_array( $data['sucursal'] ) ) {
			$grupos[] = $data['sucursal'];
		}

		foreach ( $grupos as $tarifas ) {
			foreach ( $tarifas as $opcion ) {
				if ( ! is_array( $opcion ) ) {
					continue;
				}

				// precioFinal viene como DECIMAL STRING ("12345.67"). Defensivo: normalizamos
				// coma → punto (por si algún day el server lo AR-formatea) y validamos que sea
				// numérico antes de cast a float. Tolerancia: para plata >~$10M el float pierde
				// precisión — no aplica en tarifas de courier AR, así que float es OK acá.
				$precio_str = isset( $opcion['precioFinal'] ) ? (string) $opcion['precioFinal'] : '';
				$precio_str = str_replace( ',', '.', $precio_str );
				if ( '' === $precio_str || ! is_numeric( $precio_str ) ) {
					continue;
				}
				$precio = (float) $precio_str;
				if ( $precio < 0 ) {
					continue;
				}

				$codigo_servicio = isset( $opcion['codigoServicio'] ) ? sanitize_key( (string) $opcion['codigoServicio'] ) : '';
				$courier         = isset( $opcion['courier'] ) ? (string) $opcion['courier'] : '';
				$modalidad       = isset( $opcion['modalidad'] ) ? (string) $opcion['modalidad'] : '';
				$label           = trim( $courier . ( '' !== $modalidad ? ' - ' . $modalidad : '' ) );
				if ( '' === $label ) {
					$label = esc_html__( 'Shipro', 'shipro-woocommerce' );
				}

				// Rate id determinístico POR OPCIÓN. Clave: incluimos SIEMPRE el courier en
				// el suffix porque codigoServicio del server NO es único cross-courier
				// (ej. Mocis, Intralog y Andreani-domicilio comparten
				// "entrega_domicilio_estandar"). Si el suffix fuera sólo codigoServicio,
				// WC recibiría el mismo id repetido en add_rate() y sobrescribiría los
				// rates previos → el checkout terminaría mostrando 0/1 opciones en vez de N
				// (bug real observado 2026-09-04 con 3 opciones domicilio + 1 sucursal
				// devueltas por /cotizar). Combinamos courier + (codigoServicio || modalidad):
				// la tupla (courier, servicio/modalidad) es la clave natural de una opción.
				$discriminador  = '' !== $codigo_servicio ? $codigo_servicio : $modalidad;
				$rate_id_suffix = sanitize_key( strtolower( trim( $courier . '-' . $discriminador, '-' ) ) );
				if ( '' === $rate_id_suffix ) {
					$rate_id_suffix = 'opt-' . md5( (string) ( $opcion['id'] ?? $label ) );
				}

				$this->add_rate(
					array(
						'id'        => $this->id . ':' . $rate_id_suffix,
						'label'     => wp_strip_all_tags( $label ),
						'cost'      => $precio,
						'calc_tax'  => 'per_order',
						'package'   => $package,
						// STEP 2C (polish) va a leer fechaEstimada de acá y mostrarla en el checkout
						// bajo el label. Por ahora sólo la CARGAMOS con la tarifa para no perderla.
						'meta_data' => array(
							'fechaEstimada'  => isset( $opcion['fechaEstimadaString'] ) ? (string) $opcion['fechaEstimadaString'] : '',
							'esFallback'     => ! empty( $opcion['esFallback'] ),
							'codigoServicio' => $codigo_servicio,
							// El "courier" del server (ANDREANI/MOCI'S/INTRALOG/…) — se persiste
							// después al meta del pedido (_shipro_nombre_courier) y viaja tal
							// cual al POST /api/envios (`nombreCourier`). Núcleo normaliza,
							// el plugin no mantiene mapeo. Sin transformar acá.
							'courier'        => $courier,
							'etiquetaSla'    => isset( $opcion['etiquetaSla'] ) ? (string) $opcion['etiquetaSla'] : '',
							'slaHs'          => isset( $opcion['slaHs'] ) ? (int) $opcion['slaHs'] : 0,
						),
					)
				);
			}
		}
	}

	/**
	 * Debug log helper — usa WC logger si está, no-op si no.
	 * IMPORTANTE: nunca pasar por acá la API Key ni el header Authorization.
	 *
	 * @param string $message Texto a loggear.
	 * @return void
	 */
	private function debug_log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'shipro-wc' ) );
		}
	}
}
