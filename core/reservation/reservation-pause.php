<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- plugin files drop the class- prefix.
/**
 * Pause and reopen new reservations.
 *
 * @package WpCafe/Reservation
 */

namespace WpCafe\Reservation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WpCafe\Models\Location_Model;

/**
 * Lets staff stop taking new bookings between two times (temporary, which can
 * start later) or until they reopen by hand (indefinite), for every location or some.
 *
 * Pauses live in one option keyed by location term ID; 0 means all locations.
 * A temporary pause stores its start and end and only counts between them, so
 * starting and reopening need no cron job and survive restarts.
 *
 * @package WpCafe/Reservation
 */
class Reservation_Pause {
	/**
	 * Option holding the pauses.
	 *
	 * @var string
	 */
	public const OPTION = 'wpcafe_reservation_pause';

	/**
	 * Key used for a pause that covers every location.
	 *
	 * @var int
	 */
	public const ALL_LOCATIONS = 0;

	/**
	 * Pause types.
	 */
	public const TYPE_TEMPORARY  = 'temporary';
	public const TYPE_INDEFINITE = 'indefinite';

	/**
	 * Format the dashboard sends times in, read in the site's timezone.
	 *
	 * @var string
	 */
	public const LOCAL_FORMAT = 'Y-m-d H:i';

	/**
	 * Longest temporary pause, in seconds. Longer belongs to an indefinite
	 * pause, so a wrong year in the date picker cannot close bookings for ages.
	 *
	 * @return int
	 */
	public static function max_duration(): int {
		/**
		 * Longest temporary pause the dashboard accepts.
		 *
		 * @param int $seconds Maximum length in seconds. Default 1 year.
		 */
		return max( MINUTE_IN_SECONDS, absint( apply_filters( 'wpcafe_reservation_pause_max_duration', YEAR_IN_SECONDS ) ) );
	}

	/**
	 * Read a dashboard time ("2026-11-03 18:30") in the site's timezone.
	 *
	 * @param mixed $value Time in LOCAL_FORMAT.
	 * @return int|false Unix timestamp, or false when it is not a valid time.
	 */
	public static function parse_site_time( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		// "!" resets the unset fields (seconds) to zero instead of the current time.
		$date = \DateTimeImmutable::createFromFormat( '!' . self::LOCAL_FORMAT, $value, wp_timezone() );

		// Reject overflow such as "2026-02-31", which PHP would roll into March.
		if ( ! $date || $date->format( self::LOCAL_FORMAT ) !== $value ) {
			return false;
		}

		return $date->getTimestamp();
	}

	/**
	 * Every pause blocking bookings right now, keyed by location ID.
	 *
	 * Read-only: expired entries are skipped and removed on the next write. An
	 * all-locations pause hides any single-location row stored next to it.
	 *
	 * @return array<int, array{type: string, starts_at: int, until: int|null, paused_at: int, paused_by: int}>
	 */
	public static function get_all(): array {
		return self::read( fn( $entry ) => self::is_active( $entry ) );
	}

	/**
	 * Pauses blocking now or set to start later; what the dashboard shows.
	 *
	 * @return array<int, array> Keyed by location ID.
	 */
	public static function get_upcoming(): array {
		return self::read( fn( $entry ) => self::is_live( $entry ) );
	}

	/**
	 * The pause set on exactly this key (not counting an all-locations pause).
	 *
	 * @param int $location_id Location term ID, or ALL_LOCATIONS.
	 * @return array|null Null when that key is not paused.
	 */
	public static function get( int $location_id ): ?array {
		return self::get_all()[ $location_id ] ?? null;
	}

	/**
	 * The pause that blocks a booking at this location: the all-locations one
	 * or its own. The two never coexist (see get_all() and replace()).
	 *
	 * @param int|null $location_id Booking's location; empty for none.
	 * @return array|null Pause entry plus `location_id`, or null when open.
	 */
	public static function get_effective( $location_id = null ): ?array {
		$all = self::get_all();
		$key = isset( $all[ self::ALL_LOCATIONS ] ) ? self::ALL_LOCATIONS : absint( $location_id );

		return isset( $all[ $key ] ) ? $all[ $key ] + array( 'location_id' => $key ) : null;
	}

	/**
	 * Whether new bookings are refused at this location right now.
	 *
	 * @param int|null $location_id Booking's location; empty for none.
	 * @return bool
	 */
	public static function is_paused( $location_id = null ): bool {
		return null !== self::get_effective( $location_id );
	}

	/**
	 * Close bookings at these locations with one pause, replacing every pause
	 * set before. An empty list or ALL_LOCATIONS means every location. A start
	 * in the past means now. Nothing is saved on an error.
	 *
	 * @param int|int[] $location_ids Location term IDs, or ALL_LOCATIONS.
	 * @param string    $type         TYPE_TEMPORARY or TYPE_INDEFINITE.
	 * @param int       $starts_at    Temporary only: Unix time bookings close; 0 for now.
	 * @param int       $ends_at      Temporary only: Unix time bookings reopen.
	 * @return array|WP_Error The saved entry, or an error for bad input.
	 */
	public static function replace( $location_ids, string $type, int $starts_at = 0, int $ends_at = 0 ) {
		if ( ! in_array( $type, array( self::TYPE_TEMPORARY, self::TYPE_INDEFINITE ), true ) ) {
			return new WP_Error( 'wpcafe_invalid_pause_type', __( 'Choose how long to pause reservations.', 'wp-cafe' ), array( 'status' => 422 ) );
		}

		$now       = time();
		$starts_at = self::TYPE_TEMPORARY === $type ? max( $starts_at, $now ) : $now;

		if ( self::TYPE_TEMPORARY === $type ) {
			if ( $ends_at <= $now ) {
				return new WP_Error( 'wpcafe_invalid_pause_end', __( 'Choose an end time that is still to come.', 'wp-cafe' ), array( 'status' => 422 ) );
			}

			if ( $ends_at <= $starts_at ) {
				return new WP_Error( 'wpcafe_invalid_pause_end', __( 'The end must be after the start.', 'wp-cafe' ), array( 'status' => 422 ) );
			}

			if ( $ends_at - $starts_at > self::max_duration() ) {
				return new WP_Error(
					'wpcafe_invalid_pause_duration',
					/* translators: %s: longest temporary pause, e.g. "1 year". */
					sprintf( __( 'A pause can last at most %s. For longer, choose Until I reopen them.', 'wp-cafe' ), human_time_diff( 0, self::max_duration() ) ),
					array( 'status' => 422 )
				);
			}
		}

		$location_ids = self::normalize_location_ids( $location_ids );

		foreach ( $location_ids as $location_id ) {
			if ( self::ALL_LOCATIONS !== $location_id && ! Location_Model::find( $location_id ) ) {
				return new WP_Error( 'wpcafe_invalid_location', __( 'Location not found.', 'wp-cafe' ), array( 'status' => 404 ) );
			}
		}

		$previous = self::get_upcoming();
		$entry    = array(
			'type'      => $type,
			'starts_at' => $starts_at,
			'until'     => self::TYPE_TEMPORARY === $type ? $ends_at : null,
			'paused_at' => $now,
			'paused_by' => get_current_user_id(),
		);

		self::save( array_fill_keys( $location_ids, $entry ) );

		foreach ( array_diff( array_keys( $previous ), $location_ids ) as $location_id ) {
			/** This action is documented in clear(). */
			do_action( 'wpcafe_reservations_resumed', (int) $location_id );
		}

		foreach ( $location_ids as $location_id ) {
			/**
			 * Reservations were paused at a location. The pause may start later:
			 * see `starts_at` in the entry.
			 *
			 * @param int   $location_id Location term ID, 0 for all locations.
			 * @param array $entry       Saved pause.
			 */
			do_action( 'wpcafe_reservations_paused', $location_id, $entry );
		}

		return $entry;
	}

	/**
	 * Start taking bookings again everywhere.
	 *
	 * @return void
	 */
	public static function clear(): void {
		$previous = self::get_upcoming();

		// Still write when nothing is active, so expired leftovers get cleared.
		self::save( array() );

		foreach ( array_keys( $previous ) as $location_id ) {
			/**
			 * Reservations were reopened.
			 *
			 * @param int $location_id Location term ID, 0 for all locations.
			 */
			do_action( 'wpcafe_reservations_resumed', (int) $location_id );
		}
	}

	/**
	 * The pause as the dashboard reads it, including one set to start later.
	 * `*_local` are in LOCAL_FORMAT so the date pickers show site time.
	 *
	 * @return array
	 */
	public static function admin_state(): array {
		$pauses = self::get_upcoming();
		$entry  = $pauses ? reset( $pauses ) : null;
		$ids    = array_values( array_diff( array_map( 'intval', array_keys( $pauses ) ), array( self::ALL_LOCATIONS ) ) );
		$starts = $entry ? (int) ( $entry['starts_at'] ?? 0 ) : 0;
		$until  = $entry && $entry['until'] ? (int) $entry['until'] : 0;

		return array(
			'enabled'          => null !== $entry,
			'active'           => $entry && self::is_active( $entry ),
			'location_ids'     => $ids,
			'type'             => $entry ? $entry['type'] : self::TYPE_INDEFINITE,
			'starts_at'        => $starts ? wp_date( DATE_ATOM, $starts ) : null,
			'starts_at_label'  => $starts ? self::format_reopen_time( $starts ) : null,
			'starts_local'     => $starts ? wp_date( self::LOCAL_FORMAT, $starts ) : null,
			'reopens_at'       => $until ? wp_date( DATE_ATOM, $until ) : null,
			'reopens_at_label' => $until ? self::format_reopen_time( $until ) : null,
			'ends_local'       => $until ? wp_date( self::LOCAL_FORMAT, $until ) : null,
			'site_now'         => wp_date( self::LOCAL_FORMAT ),
			'max_duration'     => self::max_duration(),
		);
	}

	/**
	 * What the booking form needs to know about a location. Leaves out who
	 * paused it (served to logged-out visitors). The text is `notice`, not
	 * `message`: Base_Rest_Controller::response() lifts a `message` key out of data.
	 *
	 * @param int|null $location_id Booking's location; empty for none.
	 * @return array{paused: bool, type: string|null, location_id: int|null, reopens_at: string|null, reopens_at_label: string|null, notice: string}
	 */
	public static function public_status( $location_id = null ): array {
		$entry = self::get_effective( $location_id );

		if ( ! $entry ) {
			return array(
				'paused'           => false,
				'type'             => null,
				'location_id'      => null,
				'reopens_at'       => null,
				'reopens_at_label' => null,
				'notice'           => '',
			);
		}

		return array(
			'paused'           => true,
			'type'             => $entry['type'],
			'location_id'      => (int) $entry['location_id'],
			'reopens_at'       => $entry['until'] ? wp_date( DATE_ATOM, $entry['until'] ) : null,
			'reopens_at_label' => $entry['until'] ? self::format_reopen_time( (int) $entry['until'] ) : null,
			'notice'           => self::message( $entry ),
		);
	}

	/**
	 * Customer-facing text for a pause.
	 *
	 * @param array $entry Pause entry.
	 * @return string
	 */
	public static function message( array $entry ): string {
		if ( self::TYPE_TEMPORARY === $entry['type'] && ! empty( $entry['until'] ) ) {
			$message = sprintf(
				/* translators: %s: time reservations reopen, e.g. "7:30 PM". */
				__( 'We are currently busy and have temporarily paused reservations. Reservations will reopen at %s.', 'wp-cafe' ),
				self::format_reopen_time( (int) $entry['until'] )
			);
		} else {
			$message = __( 'We are currently not accepting reservations. Please check back later.', 'wp-cafe' );
		}

		/**
		 * Text shown to customers while reservations are paused.
		 *
		 * @param string $message Message.
		 * @param array  $entry   Pause entry.
		 */
		return (string) apply_filters( 'wpcafe_reservation_pause_message', $message, $entry );
	}

	/**
	 * Reopening time in the site's timezone: the time alone when it is today,
	 * else date and time (a 6-hour pause set at night ends tomorrow).
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	public static function format_reopen_time( int $timestamp ): string {
		$time_format = get_option( 'time_format', 'g:i a' );

		if ( wp_date( 'Y-m-d', $timestamp ) === wp_date( 'Y-m-d' ) ) {
			return wp_date( $time_format, $timestamp );
		}

		return wp_date( get_option( 'date_format', 'F j, Y' ) . ' ' . $time_format, $timestamp );
	}

	/**
	 * Sorted, unique location IDs; an empty list or one with ALL_LOCATIONS
	 * becomes just ALL_LOCATIONS.
	 *
	 * @param mixed $location_ids Raw IDs.
	 * @return int[]
	 */
	private static function normalize_location_ids( $location_ids ): array {
		$location_ids = array_values( array_unique( array_map( 'absint', (array) $location_ids ) ) );
		sort( $location_ids );

		if ( empty( $location_ids ) || in_array( self::ALL_LOCATIONS, $location_ids, true ) ) {
			return array( self::ALL_LOCATIONS );
		}

		return $location_ids;
	}

	/**
	 * Stored pauses that pass a check, keyed by location ID. An all-locations
	 * pause hides any single-location row stored next to it.
	 *
	 * @param callable $keep Gets an entry, returns whether to keep it.
	 * @return array<int, array>
	 */
	private static function read( callable $keep ): array {
		$stored = get_option( self::OPTION, array() );
		$kept   = array();

		if ( ! is_array( $stored ) ) {
			return $kept;
		}

		foreach ( $stored as $location_id => $entry ) {
			if ( $keep( $entry ) ) {
				$kept[ (int) $location_id ] = $entry;
			}
		}

		if ( isset( $kept[ self::ALL_LOCATIONS ] ) ) {
			return array( self::ALL_LOCATIONS => $kept[ self::ALL_LOCATIONS ] );
		}

		return $kept;
	}

	/**
	 * Whether a stored entry blocks bookings right now.
	 *
	 * @param mixed $entry Stored value.
	 * @return bool
	 */
	private static function is_active( $entry ): bool {
		return self::is_live( $entry ) && (int) ( $entry['starts_at'] ?? 0 ) <= time();
	}

	/**
	 * Whether a stored entry blocks bookings now or will later (not yet ended).
	 *
	 * @param mixed $entry Stored value.
	 * @return bool
	 */
	private static function is_live( $entry ): bool {
		if ( ! is_array( $entry ) || empty( $entry['type'] ) ) {
			return false;
		}

		if ( self::TYPE_INDEFINITE === $entry['type'] ) {
			return true;
		}

		return self::TYPE_TEMPORARY === $entry['type'] && (int) ( $entry['until'] ?? 0 ) > time();
	}

	/**
	 * Store the pauses. Autoloaded: the booking routes read it on every request.
	 *
	 * @param array $pauses Pauses keyed by location ID.
	 * @return void
	 */
	private static function save( array $pauses ): void {
		if ( empty( $pauses ) ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $pauses, true );
	}
}
