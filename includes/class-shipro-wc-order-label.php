<?php
/**
 * Order Label — botón "Generar etiqueta con Shipro" en la pantalla admin del pedido.
 *
 * Responsabilidades:
 * - Persistir el codigoServicio ELEGIDO POR EL COMPRADOR en el checkout (meta del pedido)
 *   para poder respetarlo al generar la etiqueta (Opción A: honor buyer's choice).
 * - Mostrar un meta box "Envío Shipro" en la pantalla admin del pedido (HPOS + classic).
 * - Manejar el AJAX que POSTea a /api/envios del backend Shipro y persiste tracking/URL/status.
 *
 * Diseño defensivo — nunca fatal:
 * - Sin API Key → mensaje claro en la UI, no explota nada.
 * - Timeout / 5xx / body malformado → mensaje al merchant + order note; no corrompe estado.
 * - Idempotency-Key derivada del order id → re-click del botón no duplica envíos.
 *
 * NO enviamos valorDeclarado / seguro (política server-side, contrato v1.1).
 * NO inventamos datos del comprador ni del paquete — si falta algo se omite.
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shipro_WC_Order_Label
 */
class Shipro_WC_Order_Label {

	const META_CODIGO_SERVICIO = '_shipro_codigo_servicio';
	const META_COURIER_LABEL   = '_shipro_courier_label';
	const META_TRACKING        = '_shipro_tracking';
	const META_ETIQUETA_URL    = '_shipro_etiqueta_url';
	const META_STATUS          = '_shipro_status';
	const META_MOTIVO          = '_shipro_motivo_retencion';

	const AJAX_ACTION  = 'shipro_wc_generar_etiqueta';
	const NONCE_ACTION = 'shipro_wc_generar_etiqueta';

	/**
	 * Ganchos.
	 */
	public function __construct() {
		// 1) Persistir el codigoServicio elegido por el comprador al crear el order.
		//    Este hook corre en block checkout y classic (WC lo dispara desde el mismo pipeline).
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'persistir_codigo_servicio' ), 10, 4 );

		// 2) Meta box en la pantalla admin del pedido (HPOS + classic — el helper wc_get_page_screen_id
		//    devuelve el screen correcto según qué modo tiene activo la tienda).
		add_action( 'add_meta_boxes', array( $this, 'agregar_meta_box' ), 20, 2 );

		// 3) AJAX handler del botón. Sólo para usuarios autenticados con manage_woocommerce.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_generar_etiqueta' ) );
	}

	/* -----------------------------------------------------------------------
	 * 1) Persistencia de la elección del comprador al crear el order.
	 * -------------------------------------------------------------------- */

	/**
	 * Copia el codigoServicio + label del shipping rate elegido al meta del pedido.
	 * WC ya copia el meta_data del rate al WC_Order_Item_Shipping vía set_rate();
	 * lo levantamos y lo persistimos también a nivel order para lookup rápido.
	 *
	 * @param \WC_Order_Item_Shipping $item        Shipping order item.
	 * @param string                  $package_key Package key.
	 * @param array                   $package     Package array.
	 * @param \WC_Order               $order       Orden en construcción (aún no guardada).
	 * @return void
	 */
	public function persistir_codigo_servicio( $item, $package_key, $package, $order ) {
		if ( ! $item || ! is_object( $item ) || ! method_exists( $item, 'get_method_id' ) ) {
			return;
		}
		if ( 'shipro' !== $item->get_method_id() ) {
			return;
		}
		// meta copiado desde add_rate([..., meta_data => [...]]) en shipping-method.
		$codigo = $item->get_meta( 'codigoServicio' );
		if ( $codigo ) {
			$order->update_meta_data( self::META_CODIGO_SERVICIO, sanitize_text_field( (string) $codigo ) );
		}
		// Label humano ("ANDREANI - Entrega a Domicilio (Estándar)"). Útil para el meta box.
		$label = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
		if ( '' !== $label ) {
			$order->update_meta_data( self::META_COURIER_LABEL, sanitize_text_field( $label ) );
		}
	}

	/* -----------------------------------------------------------------------
	 * 2) Meta box en la pantalla admin del pedido.
	 * -------------------------------------------------------------------- */

	/**
	 * Registra el meta box tanto en HPOS (screen_id nuevo) como en el editor clásico.
	 *
	 * @return void
	 */
	public function agregar_meta_box() {
		$screens = array();

		// Classic order edit screen.
		$screens[] = 'shop_order';

		// HPOS — screen id lo resuelve el helper de WC si está disponible.
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( array_filter( $screens ) ) as $screen ) {
			add_meta_box(
				'shipro_wc_envio',
				esc_html__( 'Envío Shipro', 'shipro-woocommerce' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renderiza el contenido del meta box según el estado del pedido:
	 * - Ya tiene etiqueta → tracking + link + status.
	 * - Buyer eligió Shipro pero no hay etiqueta todavía → botón "Generar etiqueta".
	 * - No eligió Shipro → mensaje explicativo.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post (classic) u Order (HPOS).
	 * @return void
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $this->normalizar_order( $post_or_order );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Pedido no encontrado.', 'shipro-woocommerce' ) . '</p>';
			return;
		}

		$tracking     = (string) $order->get_meta( self::META_TRACKING );
		$etiqueta_url = (string) $order->get_meta( self::META_ETIQUETA_URL );
		$status       = (string) $order->get_meta( self::META_STATUS );
		$motivo       = (string) $order->get_meta( self::META_MOTIVO );
		$codigo       = (string) $order->get_meta( self::META_CODIGO_SERVICIO );
		$label        = (string) $order->get_meta( self::META_COURIER_LABEL );

		// --- Caso A: ya se generó etiqueta ---
		// STEP 3 Piece 2: mostrar de forma clara etiqueta + tracking + link a seguimiento.
		// Todos los campos son defensivos: si falta un meta, esa línea simplemente se omite.
		if ( '' !== $tracking ) {
			echo '<h4 style="margin:0 0 8px 0;">' . esc_html__( 'Etiqueta generada', 'shipro-woocommerce' ) . '</h4>';

			echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__( 'Tracking:', 'shipro-woocommerce' ) . '</strong><br />';
			echo '<code style="font-size:12px;">' . esc_html( $tracking ) . '</code></p>';

			if ( '' !== $label ) {
				echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__( 'Servicio:', 'shipro-woocommerce' ) . '</strong><br />' . esc_html( $label ) . '</p>';
			}

			if ( '' !== $etiqueta_url ) {
				echo '<p style="margin:8px 0;"><a href="' . esc_url( $etiqueta_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-secondary">'
					. esc_html__( 'Descargar etiqueta (PDF)', 'shipro-woocommerce' )
					. '</a></p>';
			}

			if ( '' !== $status ) {
				echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html__( 'Estado Shipro:', 'shipro-woocommerce' ) . '</strong> ' . esc_html( $status ) . '</p>';
			}
			if ( '' !== $motivo ) {
				echo '<p style="margin:0 0 8px 0;"><em>' . esc_html( $motivo ) . '</em></p>';
			}

			// Link "Ver seguimiento" → apunta a la página del cliente (My Account > View Order)
			// donde Piece 3 renderiza el rastreo público. Así el merchant ve LO MISMO que ve el
			// comprador, sin duplicar UI ni inventar una URL alternativa.
			$view_url = method_exists( $order, 'get_view_order_url' ) ? (string) $order->get_view_order_url() : '';
			if ( '' !== $view_url ) {
				echo '<p style="margin:8px 0 0 0;"><a href="' . esc_url( $view_url ) . '" target="_blank" rel="noopener noreferrer">'
					. esc_html__( 'Ver seguimiento (lo que ve el comprador) ↗', 'shipro-woocommerce' )
					. '</a></p>';
			}
			return;
		}

		// --- Caso B: el comprador NO eligió Shipro ---
		if ( '' === $codigo ) {
			echo '<p>' . esc_html__( 'Este pedido no usó Shipro como método de envío.', 'shipro-woocommerce' ) . '</p>';
			return;
		}

		// --- Caso C: Shipro elegido pero sin etiqueta todavía → botón ---
		if ( '' !== $label ) {
			echo '<p><strong>' . esc_html__( 'Servicio elegido:', 'shipro-woocommerce' ) . '</strong><br />' . esc_html( $label ) . '</p>';
		}

		$order_id = (int) $order->get_id();
		$nonce    = wp_create_nonce( self::NONCE_ACTION );
		$ajax_url = admin_url( 'admin-ajax.php' );

		?>
		<div id="shipro-wc-envio-actions">
			<button type="button" class="button button-primary" id="shipro-wc-generar-btn"
				data-order="<?php echo esc_attr( $order_id ); ?>"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php echo esc_html__( 'Generar etiqueta con Shipro', 'shipro-woocommerce' ); ?>
			</button>
			<p id="shipro-wc-envio-mensaje" style="margin-top:8px;"></p>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('shipro-wc-generar-btn');
			if ( ! btn ) return;
			btn.addEventListener('click', function(){
				var msg = document.getElementById('shipro-wc-envio-mensaje');
				btn.disabled = true;
				msg.textContent = <?php echo wp_json_encode( __( 'Generando etiqueta con Shipro…', 'shipro-woocommerce' ) ); ?>;

				var body = new FormData();
				body.append('action', <?php echo wp_json_encode( self::AJAX_ACTION ); ?>);
				body.append('order_id', btn.getAttribute('data-order'));
				body.append('_wpnonce', btn.getAttribute('data-nonce'));

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				}).then(function(r){ return r.json(); }).then(function(json){
					if ( json && json.success ) {
						msg.textContent = ( json.data && json.data.message ) ? json.data.message : <?php echo wp_json_encode( __( 'Etiqueta generada.', 'shipro-woocommerce' ) ); ?>;
						// Recargar la página para mostrar el tracking + link recién guardado.
						setTimeout(function(){ window.location.reload(); }, 800);
					} else {
						btn.disabled = false;
						var errMsg = ( json && json.data && json.data.message ) ? json.data.message : <?php echo wp_json_encode( __( 'Error al generar la etiqueta.', 'shipro-woocommerce' ) ); ?>;
						msg.textContent = errMsg;
					}
				}).catch(function(){
					btn.disabled = false;
					msg.textContent = <?php echo wp_json_encode( __( 'Error de red al contactar el servidor. Reintentá.', 'shipro-woocommerce' ) ); ?>;
				});
			});
		})();
		</script>
		<?php
	}

	/* -----------------------------------------------------------------------
	 * 3) AJAX handler: POST /envios al backend Shipro.
	 * -------------------------------------------------------------------- */

	/**
	 * Handler principal. Verifica capacidad + nonce, orquesta la llamada,
	 * y responde con json_success/json_error.
	 *
	 * @return void
	 */
	public function handle_generar_etiqueta() {
		try {
			// 1) Capability + nonce.
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Sin permisos.', 'shipro-woocommerce' ) ), 403 );
			}
			check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );

			// 2) Order.
			$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
			if ( $order_id <= 0 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Pedido inválido.', 'shipro-woocommerce' ) ) );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Pedido no encontrado.', 'shipro-woocommerce' ) ) );
			}

			// 3) Pre-condición: el comprador debe haber elegido Shipro.
			$codigo_servicio = (string) $order->get_meta( self::META_CODIGO_SERVICIO );
			if ( '' === $codigo_servicio ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Este pedido no usó Shipro; no hay servicio para generar etiqueta.', 'shipro-woocommerce' ) ) );
			}

			// 4) API Key.
			$api_key = shipro_wc_get_api_key();
			if ( '' === $api_key ) {
				wp_send_json_error( array( 'message' => esc_html__( 'API Key de Shipro no configurada. Configuralá en WooCommerce > Ajustes > Shipro.', 'shipro-woocommerce' ) ) );
			}

			// 5) Build request body desde el pedido.
			$body = $this->armar_body( $order, $codigo_servicio );

			// 6) POST /envios.
			$api_base = apply_filters( 'shipro_wc_api_base', 'https://pm.shipro.pro/api' );
			$endpoint = trailingslashit( (string) $api_base ) . 'envios';
			$idem_key = 'wc-order-' . $order_id;

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'  => 15,
					'blocking' => true,
					'headers'  => array(
						// La API Key nunca se logea — sólo va en el header.
						'Authorization'   => 'Bearer ' . $api_key,
						'Content-Type'    => 'application/json',
						'Accept'          => 'application/json',
						'Idempotency-Key' => $idem_key,
					),
					'body'     => wp_json_encode( $body ),
				)
			);

			// 7) Interpretar respuesta.
			if ( is_wp_error( $response ) ) {
				$msg = esc_html__( 'Shipro no respondió (timeout o error de red). Reintentá.', 'shipro-woocommerce' );
				$this->registrar_error( $order, 'wp_error: ' . $response->get_error_message() );
				wp_send_json_error( array( 'message' => $msg ) );
			}

			$status_http = (int) wp_remote_retrieve_response_code( $response );
			$body_raw    = (string) wp_remote_retrieve_body( $response );
			$data        = json_decode( $body_raw, true );
			if ( ! is_array( $data ) ) {
				$this->registrar_error( $order, 'body no-JSON HTTP ' . $status_http );
				wp_send_json_error( array( 'message' => esc_html__( 'Respuesta inválida del servidor Shipro.', 'shipro-woocommerce' ) ) );
			}

			if ( 200 !== $status_http ) {
				// Server-side error: mensaje genérico + log con snippet acotado.
				$this->registrar_error( $order, 'HTTP ' . $status_http . ' — ' . ( isset( $data['error'] ) ? (string) $data['error'] : 'sin detalle' ) );
				wp_send_json_error( array( 'message' => sprintf(
					/* translators: %d = HTTP status code */
					esc_html__( 'Shipro respondió HTTP %d. Revisá los datos del pedido y reintentá.', 'shipro-woocommerce' ),
					$status_http
				) ) );
			}

			// 8) Read the status FIELD (no HTTP alone) — contrato v1.1.
			$this->procesar_respuesta_ok( $order, $data );

		} catch ( \Throwable $e ) {
			// Última red: nunca fatal.
			if ( isset( $order ) && $order instanceof \WC_Order ) {
				$this->registrar_error( $order, 'exception: ' . $e->getMessage() );
			}
			wp_send_json_error( array( 'message' => esc_html__( 'Error inesperado. Reintentá o contactá soporte.', 'shipro-woocommerce' ) ) );
		}
	}

	/* -----------------------------------------------------------------------
	 * Helpers privados.
	 * -------------------------------------------------------------------- */

	/**
	 * Normaliza WP_Post o WC_Order al objeto WC_Order.
	 *
	 * @param mixed $post_or_order Post o Order.
	 * @return \WC_Order|null
	 */
	private function normalizar_order( $post_or_order ) {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}
		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order = wc_get_order( (int) $post_or_order->ID );
			return $order ? $order : null;
		}
		return null;
	}

	/**
	 * Arma el body para POST /envios respetando la regla "no inventes datos":
	 * si el pedido no tiene un campo, se omite (o se envía "" para calle si address_1 vacío).
	 *
	 * Asunción documentada: WooCommerce no separa street/number — se manda address_1 completo
	 * como `calle` y `altura` queda "" (el server extrae/completa según su política).
	 *
	 * @param \WC_Order $order          Order.
	 * @param string    $codigo_servicio Servicio elegido en el checkout.
	 * @return array
	 */
	private function armar_body( \WC_Order $order, $codigo_servicio ) {
		// Nombre destinatario = shipping first + last.
		$nombre = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $nombre ) {
			// Fallback: si el shipping form no capturó nombre, usar billing.
			$nombre = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		// Sumar peso × qty de line items (sin inventar; si el producto no tiene peso, no suma).
		$peso_total = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item || ! method_exists( $item, 'get_product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$qty  = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
			$peso = method_exists( $product, 'get_weight' ) ? (float) $product->get_weight() : 0.0;
			if ( $peso > 0 ) {
				$peso_total += $peso * max( 1, $qty );
			}
		}

		// Dims: se toman del PRIMER producto físico con dims cargadas — approximation.
		// No inventamos. Si nada tiene dims, se omiten y el server decide (política).
		$largo = 0.0;
		$ancho = 0.0;
		$alto  = 0.0;
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_product' ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$l = method_exists( $product, 'get_length' ) ? (float) $product->get_length() : 0.0;
			$w = method_exists( $product, 'get_width' ) ? (float) $product->get_width() : 0.0;
			$h = method_exists( $product, 'get_height' ) ? (float) $product->get_height() : 0.0;
			if ( $l > 0 || $w > 0 || $h > 0 ) {
				$largo = $l;
				$ancho = $w;
				$alto  = $h;
				break;
			}
		}

		$body = array(
			'codigoServicio'     => $codigo_servicio,
			'destinatarioNombre' => $nombre,
			'cpDestino'          => (string) $order->get_shipping_postcode(),
			'provinciaDestino'   => (string) $order->get_shipping_state(),
			'localidad'          => (string) $order->get_shipping_city(),
			// WC no separa street/número — mandamos address_1 completo como `calle`.
			'calle'              => (string) $order->get_shipping_address_1(),
			'altura'             => '',
			'email'              => (string) $order->get_billing_email(),
			'telefono'           => (string) $order->get_billing_phone(),
			'numeroOrden'        => (string) $order->get_order_number(),
		);

		// Sólo se incluyen peso/dims si el pedido las tiene. Nunca inventar.
		if ( $peso_total > 0 ) {
			$body['pesoReal'] = $peso_total;
		}
		if ( $largo > 0 ) {
			$body['largoCm'] = $largo;
		}
		if ( $ancho > 0 ) {
			$body['anchoCm'] = $ancho;
		}
		if ( $alto > 0 ) {
			$body['altoCm'] = $alto;
		}

		// DNI: WooCommerce no lo trae de fábrica. Si algún plugin lo persiste como order meta
		// (convención común: '_billing_dni' o similar), lo incluimos; si no, se omite.
		$dni = (string) $order->get_meta( '_billing_dni' );
		if ( '' !== $dni ) {
			$body['dni'] = $dni;
		}

		// NO se envía valorDeclarado / seguro — insurance es 100% server-side.
		return $body;
	}

	/**
	 * Procesa la respuesta 200 del server según el campo `status` (contrato v1.1).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Response body decodificado.
	 * @return void  (envía la respuesta JSON al cliente, no retorna).
	 */
	private function procesar_respuesta_ok( \WC_Order $order, array $data ) {
		$status   = isset( $data['status'] ) ? (string) $data['status'] : '';
		$tracking = isset( $data['tracking'] ) ? (string) $data['tracking'] : '';
		$et_url   = isset( $data['etiquetaUrl'] ) ? (string) $data['etiquetaUrl'] : '';
		$motivo   = isset( $data['motivoRetencion'] ) ? (string) $data['motivoRetencion'] : '';
		$replayed = ! empty( $data['replayed'] );

		// Persistimos SIEMPRE lo que el server informó, para el meta box y auditoría.
		if ( '' !== $tracking ) {
			$order->update_meta_data( self::META_TRACKING, sanitize_text_field( $tracking ) );
		}
		if ( '' !== $et_url ) {
			$order->update_meta_data( self::META_ETIQUETA_URL, esc_url_raw( $et_url ) );
		}
		if ( '' !== $status ) {
			$order->update_meta_data( self::META_STATUS, sanitize_text_field( $status ) );
		}
		if ( '' !== $motivo ) {
			$order->update_meta_data( self::META_MOTIVO, sanitize_text_field( $motivo ) );
		}
		$order->save();

		// Mensaje al merchant en la UI + order note.
		switch ( $status ) {
			case 'CREADO':
				$msg = $replayed
					? esc_html__( 'Etiqueta ya generada previamente (idempotente).', 'shipro-woocommerce' )
					: esc_html__( 'Etiqueta Shipro generada correctamente.', 'shipro-woocommerce' );
				$order->add_order_note( sprintf(
					/* translators: %s = tracking number */
					esc_html__( 'Etiqueta Shipro generada. Tracking: %s', 'shipro-woocommerce' ),
					$tracking
				) );
				wp_send_json_success( array( 'message' => $msg, 'tracking' => $tracking, 'etiquetaUrl' => $et_url ) );
				return;

			case 'BLOQUEADO_DATOS_PAQUETE':
				$order->add_order_note( esc_html__( 'Shipro rechazó: faltan datos del paquete (peso o dimensiones).', 'shipro-woocommerce' ) );
				$msg = esc_html__( 'Faltan datos del paquete (peso o dimensiones). Completalos en los productos y reintentá.', 'shipro-woocommerce' );
				if ( '' !== $motivo ) {
					$msg .= ' — ' . esc_html( $motivo );
				}
				wp_send_json_error( array( 'message' => $msg ) );
				return;

			case 'RETENIDO':
				$order->add_order_note( esc_html__( 'Shipro RETENIDO (dirección u otro dato del comprador).', 'shipro-woocommerce' ) );
				$msg = esc_html__( 'El envío quedó RETENIDO por Shipro.', 'shipro-woocommerce' );
				if ( '' !== $motivo ) {
					$msg .= ' — ' . esc_html( $motivo );
				}
				wp_send_json_error( array( 'message' => $msg ) );
				return;

			case 'BLOQUEADO_SALDO':
				$order->add_order_note( esc_html__( 'Shipro BLOQUEADO_SALDO: saldo insuficiente.', 'shipro-woocommerce' ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Saldo insuficiente en Shipro para generar la etiqueta.', 'shipro-woocommerce' ) ) );
				return;

			case 'BLOQUEADO_CREDENCIAL':
				$order->add_order_note( esc_html__( 'Shipro BLOQUEADO_CREDENCIAL: credencial de courier no configurada.', 'shipro-woocommerce' ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Falta credencial del courier en Shipro para este servicio.', 'shipro-woocommerce' ) ) );
				return;

			case 'BLOQUEADO_OPERATIVIDAD':
				$order->add_order_note( esc_html__( 'Shipro BLOQUEADO_OPERATIVIDAD: par depósito×courier no operativo.', 'shipro-woocommerce' ) );
				wp_send_json_error( array( 'message' => esc_html__( 'El par depósito × courier no está configurado como operativo en Shipro.', 'shipro-woocommerce' ) ) );
				return;

			case 'BLOQUEADO_DEPOSITO':
				$order->add_order_note( esc_html__( 'Shipro BLOQUEADO_DEPOSITO: sin depósito predeterminado.', 'shipro-woocommerce' ) );
				wp_send_json_error( array( 'message' => esc_html__( 'Configurá un depósito predeterminado en Shipro para poder despachar.', 'shipro-woocommerce' ) ) );
				return;

			default:
				// Status desconocido → tratamos como error suave; ya persistimos el status crudo arriba.
				$order->add_order_note( sprintf(
					/* translators: %s = status string from Shipro */
					esc_html__( 'Respuesta Shipro con status desconocido: %s', 'shipro-woocommerce' ),
					$status
				) );
				wp_send_json_error( array( 'message' => sprintf(
					/* translators: %s = status string */
					esc_html__( 'Shipro respondió con status desconocido: %s', 'shipro-woocommerce' ),
					$status
				) ) );
				return;
		}
	}

	/**
	 * Registra un error como order note + WC logger (sin exponer API Key ni headers).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $mensaje Detalle interno del error.
	 * @return void
	 */
	private function registrar_error( \WC_Order $order, $mensaje ) {
		$mensaje = (string) $mensaje;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'POST /envios failed for order ' . $order->get_id() . ' — ' . $mensaje, array( 'source' => 'shipro-wc' ) );
		}
		$order->add_order_note( sprintf(
			/* translators: %s = detalle interno del error */
			esc_html__( 'Shipro: no se pudo generar la etiqueta (%s).', 'shipro-woocommerce' ),
			$mensaje
		) );
	}
}
