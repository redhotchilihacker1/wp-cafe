<?php
/**
 * Admin-only page that renders the real reservation form for the Style tab preview.
 *
 * @package WpCafe\Reservation\Style
 */

namespace WpCafe\Reservation\Style;

use WpCafe\Contracts\Hookable_Service_Contract;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves `?wpc_rf_preview=1` as a bare frontend document holding only the
 * reservation shortcode, so the admin iframe gets the theme's real styles.
 */
class Reservation_Form_Preview implements Hookable_Service_Contract {

	const QUERY_VAR = 'wpc_rf_preview';

	const NONCE_ACTION = 'wpc_rf_preview';

	const RESERVATION_STYLES = array( 'style-1', 'style-2' );

	const DISPLAY_TYPES = array( 'single_page', 'wizard' );

	const DATE_SELECTORS = array( 'date_picker', 'calendar' );

	/**
	 * Core wp_footer callbacks the form needs (its scripts and late styles).
	 * Everything else hooked there is a widget: mini-cart, location modal, chat.
	 */
	const FOOTER_CALLBACKS = array( 'wp_print_footer_scripts', 'wp_enqueue_global_styles', 'wp_enqueue_stored_styles', 'wp_maybe_inline_styles' );

	/**
	 * True only while the authorized preview response renders the form.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Shortcode output for the current preview response.
	 *
	 * @var string
	 */
	private $form = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Before _wp_admin_bar_init (priority 0), so the admin bar never loads.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), -1 );
	}

	/**
	 * Whether the current response is the authorized preview page.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * Preview URL with a fresh nonce for the current user.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => '1',
				'_wpnonce'      => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Render the preview document and stop, or do nothing for other requests.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only detects the request; the nonce is checked right below.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		nocache_headers();

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die(
				esc_html__( 'You do not have permission to preview the reservation form.', 'wp-cafe' ),
				esc_html__( 'Forbidden', 'wp-cafe' ),
				array( 'response' => 403 )
			);
		}

		$atts = array(
			'reservation_style' => self::allowed( 'reservation_style', self::RESERVATION_STYLES ),
			'form_display_type' => self::allowed( 'form_display_type', self::DISPLAY_TYPES ),
			'date_selector'     => self::allowed( 'date_selector', self::DATE_SELECTORS ),
		);

		add_filter( 'show_admin_bar', '__return_false' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		/*
		 * Render after the plugin registers its assets (priority 10) and before
		 * wp_head() prints them, so the shortcode's enqueues land in <head> as on a
		 * normal page. Every attribute comes from an allowlist.
		 */
		add_action(
			'wp_enqueue_scripts',
			function () use ( $atts ) {
				self::$active = true;
				$this->form   = do_shortcode(
					sprintf(
						'[wpc_reservation_form reservation_style="%1$s" form_display_type="%2$s" date_selector="%3$s"]',
						$atts['reservation_style'],
						$atts['form_display_type'],
						$atts['date_selector']
					)
				);
				self::$active = false;
			},
			20
		);

		// Cart fragments POST on page load; the preview must send no writes.
		add_action(
			'wp_enqueue_scripts',
			function () {
				wp_dequeue_script( 'wc-cart-fragments' );
			},
			100
		);

		$this->render_document();
		exit;
	}

	/**
	 * Read a query value, falling back to the first allowed value.
	 *
	 * @param string   $key     Query key.
	 * @param string[] $allowed Allowed values; the first is the default.
	 *
	 * @return string
	 */
	private static function allowed( string $key, array $allowed ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Called after maybe_render() verified the nonce.
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * Drop every callback on a hook except the named core functions.
	 *
	 * @param string   $hook Action name.
	 * @param string[] $keep Function names to keep.
	 * @return void
	 */
	private static function keep_only( string $hook, array $keep ): void {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! is_string( $callback['function'] ) || ! in_array( $callback['function'], $keep, true ) ) {
					remove_action( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Print the bare document; wp_head() renders the form (see maybe_render()).
	 *
	 * The page is a picture of the form, not a working page: no footer widgets,
	 * and `inert` on <main> stops clicks, typing and submit.
	 *
	 * @return void
	 */
	private function render_document(): void {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
		<?php /* The root's 20px page gutter would double the frame's own padding and squeeze the form. */ ?>
		<?php /* body > :not(main) hides widgets that scripts append to <body>, such as chat bubbles. */ ?>
	<style id="wpc-rf-preview-page">html{margin-top:0!important}body.wpc-rf-preview-page{margin:0}body.wpc-rf-preview-page>:not(.wpc-rf-preview-page__main){display:none!important}.wpc-rf-preview-page__main{box-sizing:border-box;padding:12px;pointer-events:none;user-select:none}.wpc-rf-preview-page__main .wpc-reservation-form-root{padding:0}</style>
</head>
<body <?php body_class( 'wpc-rf-preview-page' ); ?>>
	<main class="wpc-rf-preview-page__main" inert>
		<?php
		/*
		 * Printed as WordPress prints any shortcode in post content: the shortcode
		 * escapes every value itself. wp_kses() would drop the root's data-*
		 * attributes and turn ">" in its inline CSS into "&gt;".
		 */
		echo $this->form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output, escaped at the source; see above.
		?>
	</main>
		<?php
		// Pruned this late so callbacks added after template_redirect are caught too.
		self::keep_only( 'wp_footer', self::FOOTER_CALLBACKS );
		wp_footer();
		?>
</body>
</html>
		<?php
	}
}
