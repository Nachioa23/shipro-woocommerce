<?php
/**
 * Buyer-side tracking display — muestra el rastreo del envío en la página
 * "My Account > Orders > View" del comprador.
 *
 * Diseño:
 * - Fetch server-side (PHP) contra GET /envios/rastreo-publico?tracking=<X>.
 *   ES un endpoint público del backend Shipro — NO se manda API Key (contrato v1.1).
 *   El endpoint no expone PII del comprador; sólo devuelve lo necesario para tracking UI.
 * - Cache con transient 5 min por número de tracking para no martillar el server en cada
 *   reload del comprador.
 * - Nunca fatal: 404 / timeout / body malformado → mensaje amable "no disponible todavía".
 * - Fechas humanas via wc_string_to_datetime + wc_format_datetime (con fallback al raw).
 *
 * @package Shipro_WC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shipro_WC_Tracking_Display
 */
class Shipro_WC_Tracking_Display {

	const TRANSIENT_PREFIX = 'shipro_wc_rastreo_';
	const TRANSIENT_TTL    = 5 * MINUTE_IN_SECONDS;
	const TIMEOUT_SECS     = 8;

	public function __construct() {
		// woocommerce_view_order fires ONLY on My Account > Orders > View — no
		// side-effects en thank-you page ni en emails. Signature: ( int $order_id ).
		add_action( 'woocommerce_view_order', array( $this, 'render' ), 20 );
	}

	/**
	 * Hook handler: si el pedido tiene tracking Shipro guardado, dibuja el bloque.
	 * Si algo falla, la página igual muestra el resto sin fatal.
	 *
	 * @param int $order_id ID del pedido.
	 * @return void
	 */
	public function render( $order_id ) {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order ) {
				return;
			}
			$tracking = (string) $order->get_meta( Shipro_WC_Order_Label::META_TRACKING );
			if ( '' === $tracking ) {
				return;
			}
			$this->render_seguimiento( $tracking );
		} catch ( \Throwable $e ) {
			// Última red — la página del comprador NUNCA debe fatalar por esto.
			return;
		}
	}

	/**
	 * Fetch (con cache) + render del bloque.
	 *
	 * @param string $tracking Número de tracking.
	 * @return void
	 */
	private function render_seguimiento( $tracking ) {
		$rastreo = $this->fetch_rastreo( $tracking );

		echo '<section class="shipro-wc-tracking" style="margin-top:24px; padding-top:16px; border-top:1px solid #eee;">';
		echo '<h2>' . esc_html__( 'Seguimiento de tu envío', 'shipro-woocommerce' ) . '</h2>';

		// Estado "no disponible todavía": endpoint no encontró el tracking o error de red.
		if ( ! is_array( $rastreo ) ) {
			echo '<p>' . esc_html__( 'El seguimiento todavía no está disponible. Volvé a consultar en un rato.', 'shipro-woocommerce' ) . '</p>';
			echo '</section>';
			return;
		}

		$estado_actual = isset( $rastreo['estadoActual'] ) ? (string) $rastreo['estadoActual'] : '';
		$courier       = isset( $rastreo['courier']['nombre'] ) ? (string) $rastreo['courier']['nombre'] : '';
		$tracking_num  = isset( $rastreo['trackingNumber'] ) ? (string) $rastreo['trackingNumber'] : $tracking;

		// Bloque prominente: estado actual + courier + tracking.
		if ( '' !== $estado_actual ) {
			echo '<p style="font-size:16px; margin:0 0 8px 0;"><strong>' . esc_html__( 'Estado actual:', 'shipro-woocommerce' ) . '</strong> ' . esc_html( $estado_actual ) . '</p>';
		}
		if ( '' !== $courier ) {
			echo '<p style="margin:0 0 4px 0;"><strong>' . esc_html__( 'Courier:', 'shipro-woocommerce' ) . '</strong> ' . esc_html( $courier ) . '</p>';
		}
		if ( '' !== $tracking_num ) {
			echo '<p style="margin:0 0 12px 0;"><strong>' . esc_html__( 'Tracking:', 'shipro-woocommerce' ) . '</strong> <code>' . esc_html( $tracking_num ) . '</code></p>';
		}

		// Eventos — decisión de orden: los mostramos EN EL ORDEN QUE VIENEN DEL SERVER.
		// Razón: el server v1.1 devuelve la línea de tiempo del courier ya ordenada según su
		// política (típicamente cronológico oldest → newest, como los timeline logs). Invertir
		// acá sería opinar sobre UX del comprador sin data — mejor respetar la fuente.
		// Si en el futuro el estándar del server cambia, sólo hay que ajustar acá.
		$eventos = isset( $rastreo['eventos'] ) && is_array( $rastreo['eventos'] ) ? $rastreo['eventos'] : array();
		if ( ! empty( $eventos ) ) {
			echo '<h3 style="margin:12px 0 6px 0;">' . esc_html__( 'Línea de tiempo', 'shipro-woocommerce' ) . '</h3>';
			echo '<ul style="list-style:none; padding:0; margin:0;">';
			foreach ( $eventos as $ev ) {
				if ( ! is_array( $ev ) ) {
					continue;
				}
				$ev_estado = isset( $ev['estado'] ) ? (string) $ev['estado'] : '';
				$ev_fecha  = isset( $ev['fecha'] ) ? (string) $ev['fecha'] : '';
				$ev_obs    = isset( $ev['observacion'] ) ? (string) $ev['observacion'] : '';

				$fecha_humana = $this->formato_fecha( $ev_fecha );

				echo '<li style="padding:8px 0; border-bottom:1px solid #f0f0f0;">';
				if ( '' !== $ev_estado ) {
					echo '<strong>' . esc_html( $ev_estado ) . '</strong>';
				}
				if ( '' !== $fecha_humana ) {
					echo ' <span style="color:#666;">— ' . esc_html( $fecha_humana ) . '</span>';
				}
				if ( '' !== $ev_obs ) {
					echo '<br /><span style="color:#333;">' . esc_html( $ev_obs ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '</section>';
	}

	/**
	 * Fetch al endpoint público del backend Shipro. Cachea 5 min por tracking.
	 * Devuelve el array parseado, o null si algo falló.
	 *
	 * @param string $tracking Número de tracking.
	 * @return array|null
	 */
	private function fetch_rastreo( $tracking ) {
		$cache_key = self::TRANSIENT_PREFIX . md5( $tracking );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Cacheamos también los "fail" negativos para no reintentar en cada F5 del comprador,
		// pero con TTL más corto (1 min) — así se destraba solo cuando el server empieza a responder.
		if ( 'not_available' === $cached ) {
			return null;
		}

		$api_base = apply_filters( 'shipro_wc_api_base', 'https://pm.shipro.pro/api' );
		$endpoint = trailingslashit( (string) $api_base ) . 'envios/rastreo-publico';
		$url      = add_query_arg( array( 'tracking' => $tracking ), $endpoint );

		// GET público — NO se manda Authorization; el endpoint NO acepta API Key.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => self::TIMEOUT_SECS,
				'blocking' => true,
				'headers'  => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( $cache_key, 'not_available', MINUTE_IN_SECONDS );
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			set_transient( $cache_key, 'not_available', MINUTE_IN_SECONDS );
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			set_transient( $cache_key, 'not_available', MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, $data, self::TRANSIENT_TTL );
		return $data;
	}

	/**
	 * Formatea una fecha ISO del server con el date/time format de la tienda.
	 * Fallback: devuelve el string tal cual si no se puede parsear.
	 *
	 * @param string $iso Fecha ISO 8601.
	 * @return string
	 */
	private function formato_fecha( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso ) {
			return '';
		}
		try {
			if ( function_exists( 'wc_string_to_datetime' ) ) {
				$dt = wc_string_to_datetime( $iso );
				if ( $dt ) {
					if ( function_exists( 'wc_format_datetime' ) ) {
						$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
						return (string) wc_format_datetime( $dt, $fmt );
					}
					return $dt->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
				}
			}
		} catch ( \Throwable $e ) {
			// caemos al fallback.
		}
		return $iso;
	}
}
