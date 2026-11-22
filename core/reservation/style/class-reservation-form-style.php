<?php
/**
 * Reservation form style setting: cleaning, CSS tokens and layout rules.
 *
 * @package WpCafe\Reservation\Style
 */

namespace WpCafe\Reservation\Style;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, cleans and turns the reservation_form_style setting into CSS tokens.
 *
 * Every rule comes from token-map.json, which the JS side imports too, so the
 * admin preview and the printed page can never disagree on a value.
 */
class Reservation_Form_Style {
	/**
	 * Current shape of the stored value.
	 */
	const VERSION = 1;

	/**
	 * Allowed per-field widths in reservation_form_customization.
	 */
	const FIELD_WIDTHS = array( 'auto', 'full', 'half', 'third' );

	/**
	 * Field types that always take a full row, whatever the column count.
	 */
	const FULL_ROW_TYPES = array( 'textarea', 'food_menu', 'visual_table' );

	/**
	 * Parsed token-map.json, loaded once per request.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * The token map: version and one rule per setting path.
	 *
	 * @return array{version:int,tokens:array<string,array>}
	 */
	public static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$file = __DIR__ . '/token-map.json';
		$data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, not a URL.

		self::$map = array(
			'version' => self::VERSION,
			'tokens'  => is_array( $data['tokens'] ?? null ) ? $data['tokens'] : array(),
		);

		return self::$map;
	}

	/**
	 * Turn any stored value into a complete style: every group and key present,
	 * unknown keys dropped, every value checked. Missing values stay '' (unset),
	 * which is what keeps an untouched site looking the same as before.
	 *
	 * @param mixed $raw Stored value: '', null, a partial array or an older version.
	 * @return array Complete style with version and one array per group.
	 */
	public static function normalize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$map   = self::map();
		$style = array( 'version' => self::VERSION );

		foreach ( $map['tokens'] as $path => $rule ) {
			list( $group, $key ) = explode( '.', $path, 2 );

			$value = is_array( $raw[ $group ] ?? null ) && array_key_exists( $key, $raw[ $group ] ) ? $raw[ $group ][ $key ] : '';

			$style[ $group ][ $key ] = self::clean_value( $rule, $value );
		}

		return $style;
	}

	/**
	 * Settings sanitizer for the reservation_form_style key.
	 *
	 * @param mixed $value Raw value from the REST payload.
	 * @return array Complete, cleaned style.
	 */
	public static function sanitize( $value ): array {
		return self::normalize( $value );
	}

	/**
	 * CSS custom property declarations for one style, ready for an inline style
	 * block. Empty when nothing is set, so untouched sites print nothing.
	 *
	 * @param array $style A style from normalize().
	 * @return string Bare declarations, e.g. "--wpc-rf-input-bg:#fff;--wpc-rf-cols:2".
	 */
	public static function tokens( array $style ): string {
		$declarations = array();
		foreach ( self::declarations( $style ) as $name => $value ) {
			$declarations[] = $name . ':' . $value;
		}

		return implode( ';', $declarations );
	}

	/**
	 * Names of the set tokens, printed as data-wpc-rf-set on the form root.
	 *
	 * The theme stylesheet (reservation-form-theme.css) only restyles a part when
	 * its name is in this list, so a token nobody set can't override theme or builder CSS.
	 *
	 * @param array $style A style from normalize().
	 * @return string Space-separated names, e.g. "input-bg input-size-l".
	 */
	public static function flags( array $style ): string {
		$flags = array();

		foreach ( array_keys( self::declarations( $style ) ) as $name ) {
			$flags[] = substr( $name, strlen( '--wpc-rf-' ) );
		}

		foreach ( self::map()['tokens'] as $path => $rule ) {
			if ( empty( $rule['flag'] ) ) {
				continue;
			}
			list( $group, $key ) = explode( '.', $path, 2 );
			$value               = $style[ $group ][ $key ] ?? '';

			if ( 'bool' === $rule['kind'] && true === $value ) {
				$flags[] = $rule['flag'];
			} elseif ( 'enum' === $rule['kind'] && '' !== $value ) {
				$flags[] = $rule['flag'] . '-' . $value;
			}
		}

		return implode( ' ', array_map( 'sanitize_html_class', $flags ) );
	}

	/**
	 * How a form lays out its fields.
	 *
	 * Flow forms (style-2) always keep their layout, and style-1 keeps its stacked
	 * one until the admin picks a column count, so existing pages never move.
	 *
	 * @param string $reservation_style Shortcode or widget value.
	 * @param array  $style             A style from normalize().
	 * @return array{mode:string,columns:int} mode is legacy or grid.
	 */
	public static function resolve_layout( string $reservation_style, array $style ): array {
		$columns = $style['layout']['columns'] ?? '';

		if ( 'style-2' === $reservation_style || '' === $columns ) {
			return array(
				'mode'    => 'legacy',
				'columns' => 0,
			);
		}

		return array(
			'mode'    => 'grid',
			'columns' => (int) $columns,
		);
	}

	/**
	 * How many grid columns one field spans.
	 *
	 * @param array $field   A field from reservation_form_customization.
	 * @param int   $columns Column count from resolve_layout().
	 * @return int Between 1 and $columns.
	 */
	public static function field_span( array $field, int $columns ): int {
		$columns = max( 1, $columns );

		if ( in_array( $field['type'] ?? '', self::FULL_ROW_TYPES, true ) ) {
			return $columns;
		}

		switch ( self::field_width( $field['width'] ?? '' ) ) {
			case 'full':
				return $columns;
			case 'half':
				return (int) ceil( $columns / 2 );
			case 'third':
				// 1 of up to 4 columns, 2 of 5.
				return max( 1, (int) round( $columns / 3 ) );
			default:
				return 1;
		}
	}

	/**
	 * Read a stored field width; anything outside the allowlist means auto.
	 *
	 * @param mixed $width Stored value.
	 * @return string One of FIELD_WIDTHS.
	 */
	public static function field_width( $width ): string {
		return is_string( $width ) && in_array( $width, self::FIELD_WIDTHS, true ) ? $width : 'auto';
	}

	/**
	 * Var tokens of one style after the filter, each checked again so a filter
	 * can never print an unsafe value.
	 *
	 * @param array $style A style from normalize().
	 * @return array<string,string> Custom property name => CSS value.
	 */
	private static function declarations( array $style ): array {
		$by_var = array();
		$tokens = array();

		foreach ( self::map()['tokens'] as $path => $rule ) {
			if ( empty( $rule['var'] ) ) {
				continue;
			}
			$by_var[ $rule['var'] ] = $rule;

			list( $group, $key ) = explode( '.', $path, 2 );
			$css                 = self::css_value( $rule, $style[ $group ][ $key ] ?? '' );
			if ( '' !== $css ) {
				$tokens[ $rule['var'] ] = $css;
			}
		}

		/**
		 * Filters the reservation form CSS tokens before they are printed.
		 *
		 * Unknown names and values that fail the token rules are dropped.
		 *
		 * @param array<string,string> $tokens Custom property name => CSS value.
		 * @param array                $style  The normalized style.
		 */
		$filtered = apply_filters( 'wpcafe_reservation_form_style_tokens', $tokens, $style );

		$safe = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $name => $value ) {
			if ( isset( $by_var[ $name ] ) && is_scalar( $value ) && self::is_valid_css( $by_var[ $name ], (string) $value ) ) {
				$safe[ $name ] = (string) $value;
			}
		}

		return $safe;
	}

	/**
	 * Clean one stored value against its rule. '' always means unset.
	 *
	 * @param array $rule  Token rule from the map.
	 * @param mixed $value Raw value.
	 * @return string|int|bool Clean value, or '' when unset or invalid.
	 */
	private static function clean_value( array $rule, $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		switch ( $rule['kind'] ) {
			case 'color':
				return wpc_sanitize_hex_color_value( $value );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return '';
				}
				$value = max( (int) $rule['min'], min( (int) $rule['max'], (int) $value ) );
				// Snap to the step counted from min, so 450 with step 100 becomes 500.
				$step = (int) ( $rule['step'] ?? 1 );
				return $step > 1 ? (int) ( $rule['min'] + round( ( $value - $rule['min'] ) / $step ) * $step ) : $value;

			case 'enum':
				$value = is_scalar( $value ) ? (string) $value : '';
				return in_array( $value, $rule['enum'], true ) ? $value : ( $rule['default'] ?? '' );

			case 'bool':
				return rest_sanitize_boolean( $value );
		}

		return '';
	}

	/**
	 * CSS value printed for one clean setting value.
	 *
	 * @param array           $rule  Token rule from the map.
	 * @param string|int|bool $value Clean value.
	 * @return string CSS value, or '' when nothing should be printed.
	 */
	private static function css_value( array $rule, $value ): string {
		if ( '' === $value || is_bool( $value ) ) {
			return '';
		}

		if ( 'int' === $rule['kind'] ) {
			return $value . ( $rule['unit'] ?? '' );
		}

		if ( 'enum' === $rule['kind'] && isset( $rule['map'] ) ) {
			return (string) ( $rule['map'][ $value ] ?? '' );
		}

		return (string) $value;
	}

	/**
	 * Whether a printed CSS value is one its rule could have produced.
	 *
	 * @param array  $rule  Token rule from the map.
	 * @param string $value CSS value.
	 * @return bool
	 */
	private static function is_valid_css( array $rule, string $value ): bool {
		switch ( $rule['kind'] ) {
			case 'color':
				return '' !== wpc_sanitize_hex_color_value( $value );

			case 'int':
				$unit = (string) ( $rule['unit'] ?? '' );
				if ( ! preg_match( '/^(\d+)' . preg_quote( $unit, '/' ) . '$/', $value, $match ) ) {
					return false;
				}
				$number = (int) $match[1];
				$step   = max( 1, (int) ( $rule['step'] ?? 1 ) );
				return $number >= (int) $rule['min'] && $number <= (int) $rule['max'] && 0 === ( $number - (int) $rule['min'] ) % $step;

			case 'enum':
				$allowed = isset( $rule['map'] ) ? array_values( $rule['map'] ) : $rule['enum'];
				return in_array( $value, $allowed, true );
		}

		return false;
	}
}
