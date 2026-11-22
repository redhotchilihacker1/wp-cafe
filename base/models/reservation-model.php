<?php
namespace WpCafe\Models;

use WpCafe\Database\Post_Model;

class Reservation_Model extends Post_Model {
    /**
     * Moderation statuses a reservation is allowed to hold.
     *
     * The status becomes the post_status, so anything outside this list —
     * including core statuses like `publish` or `trash` — could park a booking
     * where the admin list never looks for it.
     *
     * @var string[]
     */
    public const ALLOWED_STATUSES = [
        'pending',
        'confirmed',
        'cancelled',
		'completed',
        'pending_payment',
        'refunded',
    ];

	/**
	 * Meta fields the admin search box matches, shared by the list and the calendar.
	 *
	 * @var string[]
	 */
	public const SEARCH_META_KEYS = array(
		'name',
		'email',
		'phone',
		'date',
		'branch_id',
		'start_time',
		'end_time',
	);

    /**
     * Store fillable attributes
     *
     * @var array
     */
    protected array $fillable = [
        'name'          => '',
        'email'         => '',
        'phone'         => '',
        'date'          => '',
        'start_time'    => '',
        'end_time'      => '',
        'total_guest'   => '',
        'table_name'    => '',
        'status'        => '',
        'branch_id'     => '',
        'branch_name'   => '',
        'notes'         => '',
        'invoice'       => '',
        'booking_amount' => '',
        'total_price'   => '',
        'deposit_value'    => '',
        'remaining_amount' => '',
        'is_partial_payment' => '',
        'payment_amount_type' => '',
        'currency'      => '',
        'payment_method'=> '',
        'payment_intent'=> '',
        'woo_order_id'  => '',
        'food_order'    => 'no',
        // Money off paid for with points. Only the server sets it; see create_item().
        'points_discount' => '',
        // A loyalty level's money off, priced at save. Only the server sets it.
        'member_discount'       => '',
        'member_discount_label' => '',
        // Whose level the member discount was priced for; an edit prices the same customer.
        'member_customer_id'    => '',
        'seats'         => '',
		// What was booked, kept as it was at booking time. Only the server sets these.
		'area_id'        => '',
		'table_ids'      => array(),
		'table_snapshot' => array(),
		// Who gave the table: system, customer or staff. Only the server sets it.
		'table_assigned_by' => '',
        'custom_fields' => [],
    ];

    /**
     * Get the post type
     *
     * @return  string  Post type
     */
    public function get_post_type() {
        return 'wpc_reservation';
    }

    /**
     * Load attributes, taking status from the post itself.
     *
     * Status lives in post_status, not post meta; reading meta would see ''
     * and treat every save as a status change.
     *
     * @return void
     */
    protected function load_attributes() {
        parent::load_attributes();

        $this->attributes['status'] = (string) $this->post->post_status;
        $this->original['status']   = $this->attributes['status'];
    }

    /**
     * Reduce a supplied status to one the plugin actually uses.
     *
     * Every path that can write a status should go through this, so a value
     * from a request body can never reach post_status unchecked.
     *
     * @param mixed  $status   Raw status value.
     * @param string $fallback Returned when the value is not allowed.
     * @return string
     */
    public static function sanitize_status( $status, string $fallback = 'pending' ): string {
        $status = is_string( $status ) ? strtolower( trim( $status ) ) : '';

        return in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : $fallback;
    }

    /**
     * The status new bookings start in, taken from the Reservation settings.
     *
     * Runs through sanitize_status() as well, so a settings row edited by hand
     * still cannot introduce an unknown status.
     *
     * @return string
     */
    public static function default_status(): string {
        return self::sanitize_status( wpc_get_option( 'reservation_status', 'pending' ), 'pending' );
    }

	/**
	 * Turn a status saved by 2.x into one of ours.
	 *
	 * 2.x also had "Processing" (a paid booking), which is Confirmed now.
	 *
	 * @param mixed $status Raw 2.x status.
	 * @return string
	 */
	public static function from_legacy_status( $status ): string {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';

		return self::sanitize_status( 'processing' === $status ? 'confirmed' : $status, 'pending' );
	}

    /**
     * Get the total price of the reservation
     *
     * @param array|null $items Food lines already loaded, to skip a second query.
     * @return float Total price of the reservation
     */
    public function get_total_price( ?array $items = null ) {
        $total_price = (float) $this->total_price;

        foreach ( $items ?? $this->get_items() as $item ) {
            $total_price += (float) $item->price * (int) $item->quantity;
        }

        return $total_price;
    }

    /**
     * The booking and its food, less the points and member discounts.
     *
     * @param array|null $items Food lines already loaded.
     * @return float
     */
    public function get_total_after_discounts( ?array $items = null ): float {
        $discounts = (float) $this->points_discount + (float) $this->member_discount;

        // Rounded: a float subtraction like 19.99 - 10 would print 9.989999999999998.
        return round( max( 0.0, $this->get_total_price( $items ) - $discounts ), self::price_decimals() );
    }

    /**
     * @deprecated 3.0.24 Use get_total_after_discounts(); this now takes the member discount off too.
     *
     * @param array|null $items Food lines already loaded.
     * @return float
     */
    public function get_total_after_points( ?array $items = null ): float {
        _deprecated_function( __METHOD__, '3.0.24', __CLASS__ . '::get_total_after_discounts' );

        return $this->get_total_after_discounts( $items );
    }

	/**
	 * What the customer still pays: the total after discounts, less a deposit paid online.
	 *
	 * An unpaid deposit (pending or failed order) is still owed, so it stays in.
	 *
	 * @param array|null $items        Food lines already loaded.
	 * @param bool|null  $deposit_paid Known paid state, to skip loading the order again.
	 * @return float
	 */
	public function get_amount_due( ?array $items = null, ?bool $deposit_paid = null ) {
		$deposit_paid = $deposit_paid ?? $this->is_deposit_paid();
		$deposit      = $deposit_paid ? (float) $this->deposit_value : 0.0;

		return round( max( 0.0, $this->get_total_after_discounts( $items ) - $deposit ), self::price_decimals() );
	}

	/**
	 * Store's price decimals; 2 when WooCommerce isn't loaded.
	 *
	 * @return int
	 */
	private static function price_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/**
	 * Whether an online deposit was taken and its WooCommerce order is paid.
	 *
	 * @param \WC_Order|null $order The booking's order when already loaded.
	 * @return bool
	 */
	public function is_deposit_paid( $order = null ): bool {
		if ( 'yes' !== $this->is_partial_payment || (float) $this->deposit_value <= 0 ) {
			return false;
		}

		if ( ! $order instanceof \WC_Order ) {
			// A local, not empty( $this->woo_order_id ): Post_Model has no __isset, so that is always empty.
			$woo_order_id = $this->woo_order_id;
			$order        = ! empty( $woo_order_id ) && function_exists( 'wc_get_order' ) ? wc_get_order( $woo_order_id ) : null;
		}

		return $order instanceof \WC_Order && $order->is_paid();
	}

    /**
     * Get reservation items associated with this reservation.
     *
     * Retrieves all Reservation_Item_Model instances that are linked to this reservation
     * by matching the 'reservation_id' field with the current reservation's ID.
     *
     * @return array List of Reservation_Item_Model objects for this reservation.
     */
    public function get_items() {
        return Reservation_Item_Model::where( 'reservation_id', $this->id );
    }

    /**
     * Get seats associated with this reservation
     *
     * @return array Array of Seat_Plan_Model instances
     */
    public function get_seats() {
        $seat_ids = $this->seats ?? [];

        if ( empty( $seat_ids ) || ! is_array( $seat_ids ) ) {
            return [];
        }

        // Check if wpcafe-pro is active and has the Seat_Plan_Model
        if ( ! class_exists( 'WpCafePro\Models\Seat_Plan_Model' ) ) {
            return [];
        }

        $seats = [];
        foreach ( $seat_ids as $seat_id ) {
            $seat = \WpCafePro\Models\Seat_Plan_Model::find( $seat_id );
            if ( $seat ) {
                $seats[] = $seat;
            }
        }

        return $seats;
    }

	/**
	 * Build a meta query that matches any SEARCH_META_KEYS field containing the value.
	 *
	 * @param string $search_value The value to search for.
	 * @return array The meta query array for use in WP_Query.
	 */
	public function search_query( $search_value ) {
		$meta_query = array( 'relation' => 'OR' );

		foreach ( self::SEARCH_META_KEYS as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => $search_value,
				'compare' => 'LIKE',
			);
		}

		return $meta_query;
	}

    /**
     * Build a meta query array for filtering posts by a given value.
     *
     * This method should be implemented in the concrete model class to return
     * a meta query array suitable for use in WP_Query or similar, based on the
     * provided filter value.
     *
     * @param mixed $filter_value The value to filter the posts by.
     * @return array The meta query array for use in WP_Query or similar.
     */
    public function filter_query( $filters ) {
        $meta_query = [ 'relation' => 'AND' ];

		// Never use this to decide ownership: the email is typed by the guest.
        if ( ! empty( $filters['email'] ) ) {
            $meta_query[] = [
                'key'     => 'email',
                'value'   => $filters['email'],
                'compare' => '=',
            ];
        }

		// Exact match: branch_id holds one term ID, and LIKE let "2" match 12 or 27.
		if ( ! empty( $filters['branch'] ) ) {
			$meta_query[] = array(
				'key'     => 'branch_id',
				'value'   => $filters['branch'],
				'compare' => '=',
			);
		}

        // date filter (exact date)
        if ( ! empty( $filters['date'] ) ) {
            $meta_query[] = [
                'key'     => 'date',
                'value'   => $filters['date'],
                'compare' => '=',
            ];
        }

        // date filter (exact date)
        if ( ! empty( $filters['food_order'] ) ) {
            $meta_query[] = [
                'key'     => 'food_order',
                'value'   => $filters['food_order'],
                'compare' => '=',
            ];
        }

        // date range filter
        if ( ! empty( $filters['date_range'] ) && is_array( $filters['date_range'] ) ) {
            $meta_query[] = [
                'key'     => 'date',
                'value'   => $filters['date_range'],
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ];
        }

        return $meta_query;
    }

    /**
     * Convert time to timestamp
     *
     * Handles DateTime objects, time strings and ready-made Unix timestamps.
     *
     * @param string                       $date The date to use for timestamp conversion.
     * @param int|string|DateTimeInterface $time A DateTime, a time string, or a Unix timestamp.
     * @return int|false The Unix timestamp, or false when the time cannot be parsed.
     */
    private static function convert_time_to_timestamp( $date, $time ) {
        if ( is_object( $time ) && method_exists( $time, 'format' ) ) {
            return strtotime( $date . ' ' . $time->format('h:i A') );
        }
        // Already a Unix timestamp (the create path and the waitlist pass these), and
        // strtotime( "$date 1789070400" ) is false. 10+ digits only, so "0930" still parses as a time.
        if ( is_int( $time ) || ( is_string( $time ) && ctype_digit( $time ) && strlen( $time ) >= 10 ) ) {
            return (int) $time;
        }
        return strtotime( $date . ' ' . $time );
    }

	/**
	 * Move an end time that falls before its start onto the next day.
	 *
	 * "12:00 AM" parses to the start of the date, but a booking or slot ending
	 * then closes at the end of it. Same rule as Scheduler::generate_slots().
	 *
	 * @param int|string|false $start_time Start as a Unix timestamp.
	 * @param int|string|false $end_time   End as a Unix timestamp.
	 * @return int|string|false The end time, moved only when it was before the start.
	 */
	public static function end_time_after_start( $start_time, $end_time ) {
		if ( $start_time && $end_time && (int) $end_time < (int) $start_time ) {
			return strtotime( '+1 day', (int) $end_time );
		}

		return $end_time;
	}

    /**
     * Get the total guest by date and time
     *
     * @param string $date The date to get the total guest for.
     * @param string $start_time The start time to get the total guest for.
     * @param string $end_time The end time to get the total guest for.
     * @param int|string $branch_id Optional branch ID to filter by.
     * @param int        $exclude_id Booking to leave out, e.g. the one being edited.
     * @return int The total guest for the date and time.
     */
    public static function get_total_guest_by_date_time( $date, $start_time, $end_time, $branch_id = '', $exclude_id = 0 ) {
        // Convert times to timestamps
        $start_time = self::convert_time_to_timestamp( $date, $start_time );
		$end_time   = self::end_time_after_start( $start_time, self::convert_time_to_timestamp( $date, $end_time ) );

        // Cache per request: the scheduler calls this once per slot in a date-range loop.
        static $cache = [];
        $cache_key = $date . '|' . $start_time . '|' . $end_time . '|' . $branch_id . '|' . (int) $exclude_id;
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        // Ensure blocking statuses is an array
        $blocking_statuses = wpc_get_option( 'block_timeslot_statuses', ['confirmed'] );
        $blocking_statuses = self::expand_with_wc_equivalents( $blocking_statuses );

        $meta_query = self::overlap_meta_query( $date, $start_time, $end_time );

        // Add branch filter if provided
        if ( ! empty( $branch_id ) ) {
            $meta_query[] = [
                'key' => 'branch_id',
                'value' => $branch_id,
                'compare' => '=',
            ];
        }

        $reservations = get_posts( [
            'post_type' => 'wpc_reservation',
            'post_status' => $blocking_statuses,
            'numberposts' => -1,
            'post__not_in' => array_filter( [ absint( $exclude_id ) ] ),
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for report/filter functionality
            'meta_query' => $meta_query,
        ] );

        $total_guest = 0;

        foreach ( $reservations as $reservation ) {
            $guest_count = get_post_meta( $reservation->ID, 'total_guest', true );
            $total_guest += intval( $guest_count );
        }

        $total_guest = (int) apply_filters(
            'wpcafe_booked_guest_count',
            $total_guest,
            $date,
            $start_time,
            $end_time,
            $branch_id
        );

        $cache[ $cache_key ] = $total_guest;
        return $total_guest;
    }

    /**
     * Validate guest capacity for a reservation by checking if the requested number of guests exceeds available capacity
     *
     * @param int    $total_guest The total number of guests for the reservation
     * @param string $date        The reservation date
     * @param int    $start_time  The start time (timestamp)
     * @param int    $end_time    The end time (timestamp)
     * @param int    $branch_id   Optional branch ID to filter by
     * @param int    $exclude_id  Booking to leave out, e.g. the one being edited.
     * @return true|WP_Error True if valid, WP_Error if exceeds capacity
     */
    public static function validate_guest_capacity( $total_guest, $date, $start_time, $end_time, $branch_id = '', $exclude_id = 0 ) {
        $total_guest = intval( $total_guest );

        // An unreadable time must not count as an empty slot: false became midnight, found no bookings and let any request book past capacity.
        if ( empty( $start_time ) || empty( $end_time )
            || false === self::convert_time_to_timestamp( $date, $start_time )
            || false === self::convert_time_to_timestamp( $date, $end_time ) ) {
            return new \WP_Error( 'invalid_reservation_time', __( 'Cannot create reservation. The reservation time is not valid.', 'wp-cafe' ) );
        }

        $total_capacity  = wpc_get_reservation_capacity( $branch_id );
        $booked_capacity = self::get_total_guest_by_date_time( $date, $start_time, $end_time, $branch_id, $exclude_id );
        $available_capacity = $total_capacity - $booked_capacity;

        if ( $total_guest > $available_capacity ) {
            /* translators: %1$d: requested number of guests, %2$d: available capacity */
            return new \WP_Error( 'insufficient_capacity', sprintf( __( 'Cannot create reservation. Requested %1$d guest(s) exceeds available capacity of %2$d for this time slot.', 'wp-cafe' ), $total_guest, $available_capacity ) );
        }

        return true;
    }

    /**
     * Get booked seat IDs for a specific time slot
     *
     * Finds all reservations that overlap with the given time slot
     * and returns all seat IDs that are booked during that period.
     *
     * @param string $date The reservation date
     * @param string $start_time The start time (can be timestamp or time string)
     * @param string $end_time The end time (can be timestamp or time string)
     * @param int $branch_id The branch ID to filter by
     * @param int $exclude_id Booking to leave out, e.g. the one being edited.
     * @return array Array of unique booked seat IDs
     */
    public static function get_booked_seats_for_time_slot( $date, $start_time, $end_time, $branch_id, $exclude_id = 0 ) {
        // Convert time to timestamps for comparison
        $start_timestamp = is_numeric( $start_time ) ? $start_time : strtotime( $date . ' ' . $start_time );
        $end_timestamp   = is_numeric( $end_time ) ? $end_time : strtotime( $date . ' ' . $end_time );
		$end_timestamp   = self::end_time_after_start( $start_timestamp, $end_timestamp );

		$meta_query = self::overlap_meta_query( $date, $start_timestamp, $end_timestamp, wpc_get_table_buffer_seconds( $branch_id ) );

        // Add branch filter (filter by location) if provided
        if ( ! empty( $branch_id ) && $branch_id !== 'undefined' ) {
            $meta_query[] = [
                'key'     => 'branch_id',
                'value'   => $branch_id,
                'compare' => '=',
            ];
        }

        $statuses_for_blocking_seats = self::expand_with_wc_equivalents( self::seat_blocking_statuses() );

        $posts = get_posts([
            'post_type'     => 'wpc_reservation',
            'post_status'   => $statuses_for_blocking_seats ,
            'numberposts'   => -1,
            'post__not_in'  => array_filter( [ absint( $exclude_id ) ] ),
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for report/filter functionality
            'meta_query'    => $meta_query,
        ]);

        // Convert posts to model Reservation_Model instances
        $reservations = array_map( function( $post ) {
            return new self( $post );
        }, $posts );

        // Collect all booked seat IDs
        $booked_seat_ids = [];

        foreach ( $reservations as $reservation ) {
            $seats = $reservation->seats ?? [];

            if ( ! empty( $seats ) && is_array( $seats ) ) {
                $booked_seat_ids = array_merge( $booked_seat_ids, $seats );
            }
        }

        // Return unique seat IDs
        return array_values( array_unique( $booked_seat_ids ) );
    }

	/**
	 * Statuses a booking can hold that still mean "the table is expected".
	 *
	 * Cancelled, refunded and failed bookings are left out, as is a WooCommerce
	 * checkout draft (the guest never finished paying). Filterable so a site
	 * can count its own statuses.
	 *
	 * @return string[]
	 */
	public static function calendar_counted_statuses(): array {
		$statuses = self::expand_with_wc_equivalents( array( 'pending', 'confirmed', 'pending_payment' ) );
		$statuses = apply_filters( 'wpcafe_reservation_calendar_statuses', $statuses );

		return is_array( $statuses )
			? array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) )
			: array();
	}

	/**
	 * Count reservations and guests per day for a date range, in one query.
	 *
	 * Grouped per booking first, so a duplicate meta row cannot count its guests
	 * twice. The stored date is a plain `Y-m-d` with no time zone, compared as DATE.
	 *
	 * @param string $start_date First day, `Y-m-d`.
	 * @param string $end_date   Last day, `Y-m-d`.
	 * @param array  $args {
	 *     Optional scope.
	 *
	 *     @type string   $branch   Location ID to limit to.
	 *     @type string   $email    Only bookings made with this email.
	 *     @type int[]    $post__in Only these reservation IDs.
	 *     @type string[] $statuses Statuses to count. Default calendar_counted_statuses().
	 *     @type string   $search   Only bookings with a SEARCH_META_KEYS field containing this text.
	 * }
	 * @return array<string, array{total:int, guests:int, updated:string}> Keyed by `Y-m-d`; days without bookings
	 *     are absent. `updated` is the newest edit time (site time) among that day's bookings.
	 */
	public static function count_by_date( string $start_date, string $end_date, array $args = array() ): array {
		global $wpdb;

		$statuses = $args['statuses'] ?? self::calendar_counted_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$joins  = '';
		$where  = '';
		$params = array();

		if ( ! empty( $args['branch'] ) ) {
			$joins   .= " INNER JOIN {$wpdb->postmeta} AS b ON b.post_id = p.ID AND b.meta_key = 'branch_id' AND b.meta_value = %s";
			$params[] = (string) $args['branch'];
		}

		if ( ! empty( $args['email'] ) ) {
			$joins   .= " INNER JOIN {$wpdb->postmeta} AS e ON e.post_id = p.ID AND e.meta_key = 'email' AND e.meta_value = %s";
			$params[] = (string) $args['email'];
		}

		$params[] = ( new static() )->get_post_type();
		$params   = array_merge( $params, array_values( $statuses ) );
		$params[] = $start_date;
		$params[] = $end_date;

		if ( ! empty( $args['post__in'] ) ) {
			$ids    = array_map( 'absint', (array) $args['post__in'] );
			$where .= ' AND p.ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $ids );
		}

		// Same fields and LIKE match as the list's search, so both views find the same bookings.
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$key_placeholders = implode( ',', array_fill( 0, count( self::SEARCH_META_KEYS ), '%s' ) );
			$where           .= " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS s WHERE s.post_id = p.ID AND s.meta_key IN ({$key_placeholders}) AND s.meta_value LIKE %s )";
			$params           = array_merge( $params, self::SEARCH_META_KEYS );
			$params[]         = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names and placeholder lists are built above; every value goes through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.day, COUNT(*) AS total, COALESCE( SUM( t.guests ), 0 ) AS guests, MAX( t.modified ) AS updated
				FROM (
					SELECT p.ID, MIN( CAST( d.meta_value AS DATE ) ) AS day, MAX( CAST( g.meta_value AS UNSIGNED ) ) AS guests, MAX( p.post_modified ) AS modified
					FROM {$wpdb->posts} AS p
					INNER JOIN {$wpdb->postmeta} AS d ON d.post_id = p.ID AND d.meta_key = 'date'
					LEFT JOIN {$wpdb->postmeta} AS g ON g.post_id = p.ID AND g.meta_key = 'total_guest'
					{$joins}
					WHERE p.post_type = %s
					AND p.post_status IN ({$status_placeholders})
					AND CAST( d.meta_value AS DATE ) BETWEEN %s AND %s
					{$where}
					GROUP BY p.ID
				) AS t
				GROUP BY t.day
				ORDER BY t.day",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$days = array();

		foreach ( (array) $rows as $row ) {
			$days[ (string) $row->day ] = array(
				'total'   => (int) $row->total,
				'guests'  => (int) $row->guests,
				'updated' => (string) $row->updated,
			);
		}

		return $days;
	}

	/**
	 * Bookings and guests per date and start time in a date range, in one query.
	 *
	 * For the Overview page, which groups bookings by weekday and hour. Counts
	 * the same statuses as the calendar, so both pages agree. Grouped per
	 * booking first, like count_by_date(), so a duplicate meta row cannot count twice.
	 *
	 * @param string $start_date First day, `Y-m-d`.
	 * @param string $end_date   Last day, `Y-m-d`.
	 * @param string $branch     Location ID to limit to, or '' for all.
	 * @return array<int, array{date:string, start_time:string, count:int, guests:int}>
	 */
	public static function overview_rows( string $start_date, string $end_date, string $branch = '' ): array {
		global $wpdb;

		$statuses = self::calendar_counted_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$joins  = '';
		$params = array();

		if ( '' !== $branch ) {
			$joins   .= " INNER JOIN {$wpdb->postmeta} AS b ON b.post_id = p.ID AND b.meta_key = 'branch_id' AND b.meta_value = %s";
			$params[] = $branch;
		}

		$params[] = ( new static() )->get_post_type();
		$params   = array_merge( $params, array_values( $statuses ) );
		$params[] = $start_date;
		$params[] = $end_date;

		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names and placeholder lists are built above; every value goes through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.day, t.start_time, COUNT(*) AS total, COALESCE( SUM( t.guests ), 0 ) AS guests
				FROM (
					SELECT p.ID, MIN( CAST( d.meta_value AS DATE ) ) AS day, COALESCE( MIN( s.meta_value ), '' ) AS start_time, MAX( CAST( g.meta_value AS UNSIGNED ) ) AS guests
					FROM {$wpdb->posts} AS p
					INNER JOIN {$wpdb->postmeta} AS d ON d.post_id = p.ID AND d.meta_key = 'date'
					LEFT JOIN {$wpdb->postmeta} AS s ON s.post_id = p.ID AND s.meta_key = 'start_time'
					LEFT JOIN {$wpdb->postmeta} AS g ON g.post_id = p.ID AND g.meta_key = 'total_guest'
					{$joins}
					WHERE p.post_type = %s
					AND p.post_status IN ({$status_placeholders})
					AND CAST( d.meta_value AS DATE ) BETWEEN %s AND %s
					GROUP BY p.ID
				) AS t
				GROUP BY t.day, t.start_time",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		$result = array();

		foreach ( (array) $rows as $row ) {
			$result[] = array(
				'date'       => (string) $row->day,
				'start_time' => (string) $row->start_time,
				'count'      => (int) $row->total,
				'guests'     => (int) $row->guests,
			);
		}

		return $result;
	}

    /**
     * Refuse seats that another booking already holds in an overlapping time.
     *
     * @param array      $seats      Seat IDs the booking wants, e.g. [ 'S5', 'S6' ].
     * @param string     $date       Booking date (Y-m-d).
     * @param int|string $start_time Start, as a timestamp or a clock time.
     * @param int|string $end_time   End, as a timestamp or a clock time.
     * @param int|string $branch_id  Location ID, or '' for none.
     * @param int        $exclude_id Booking to leave out, e.g. the one being edited.
     * @return true|\WP_Error `seats_unavailable` (HTTP 409) lists the taken seats.
     */
    public static function validate_seat_availability( $seats, $date, $start_time, $end_time, $branch_id = '', $exclude_id = 0 ) {
        if ( empty( $seats ) || ! is_array( $seats ) ) {
            return true;
        }

        $booked = self::get_booked_seats_for_time_slot( $date, $start_time, $end_time, $branch_id, $exclude_id );
        $taken  = array_values( array_intersect( array_map( 'strval', $seats ), array_map( 'strval', $booked ) ) );

        if ( empty( $taken ) ) {
            return true;
        }

        return new \WP_Error(
            'seats_unavailable',
            sprintf(
                /* translators: %s: seat IDs, e.g. "S5, S6" */
                _n( 'Seat %s is already booked for this time. Please pick another seat.', 'Seats %s are already booked for this time. Please pick other seats.', count( $taken ), 'wp-cafe' ),
                implode( ', ', $taken )
            ),
            [
                'status'            => 409,
                'unavailable_seats' => $taken,
            ]
        );
    }

	/**
	 * Refuse seats the layout does not have, or a guest count they cannot hold.
	 *
	 * A seat of a "seats" table holds one guest. A "table" table is booked whole by
	 * a group of its "Min guests" (staff: 1) up to its chairs. Tables not bookable
	 * online are refused unless staff books them.
	 *
	 * @param array      $seats       Seat IDs the booking wants.
	 * @param int        $total_guest Number of guests.
	 * @param int|string $branch_id   Location ID, or '' for none.
	 * @param bool       $is_staff    Whether staff is making the booking.
	 * @param string     $date        Booking date, to refuse an area closed that day.
	 * @return true|\WP_Error
	 */
	public static function validate_seat_selection( $seats, $total_guest, $branch_id = '', $is_staff = false, $date = '' ) {
		if ( empty( $seats ) || ! is_array( $seats ) ) {
			return true;
		}

		$seats  = array_map( 'strval', $seats );
		$layout = self::table_layout( $branch_id );
		$tables = is_array( $layout['tables'] ?? null ) ? $layout['tables'] : array();
		$min    = 0;
		$max    = 0;
		$found  = array();
		$closed = array();

		foreach ( is_array( $layout['areas'] ?? null ) ? $layout['areas'] : array() as $area ) {
			if ( '' !== $date && ! self::area_is_open_on( $area, $date ) ) {
				$closed[] = $area['id'] ?? '';
			}
		}

		foreach ( $tables as $table ) {
			$ids = array_map( 'strval', array_column( is_array( $table['seats'] ?? null ) ? $table['seats'] : array(), 'id' ) );
			$hit = array_intersect( $seats, $ids );

			if ( ! $hit ) {
				continue;
			}

			$found    = array_merge( $found, $hit );
			$is_table = 'table' === ( $table['book_as'] ?? 'seats' );
			$name     = (string) ( $table['name'] ?? $table['id'] ?? '' );

			if ( ! $is_staff && in_array( $table['area_id'] ?? '', $closed, true ) ) {
				return new \WP_Error(
					'area_closed',
					/* translators: %s: table name */
					sprintf( __( '%s is in an area that is closed on this date. Please pick another table.', 'wp-cafe' ), $name ),
					array( 'status' => 422 )
				);
			}

			if ( ! $is_staff && false === ( $table['online'] ?? true ) ) {
				return new \WP_Error(
					'table_not_online',
					/* translators: %s: table name */
					sprintf( __( '%s cannot be booked online. Please pick another table.', 'wp-cafe' ), $name ),
					array( 'status' => 422 )
				);
			}

			if ( $is_table && count( $hit ) !== count( $ids ) ) {
				return new \WP_Error(
					'whole_table_required',
					/* translators: %s: table name */
					sprintf( __( '%s can only be booked as a whole table.', 'wp-cafe' ), $name ),
					array( 'status' => 422 )
				);
			}

			// Staff may seat a smaller group than the table's "Min guests".
			$smallest = $is_staff ? 1 : min( count( $ids ), max( 1, (int) ( $table['min_guests'] ?? 1 ) ) );
			$min     += $is_table ? $smallest : count( $hit );
			$max    += $is_table ? count( $ids ) : count( $hit );
		}

		$unknown = array_values( array_diff( $seats, $found ) );

		if ( $unknown ) {
			return new \WP_Error(
				'seats_not_found',
				sprintf(
					/* translators: %s: seat IDs, e.g. "S5, S6" */
					__( 'These seats are not in the table layout: %s. Please pick other seats.', 'wp-cafe' ),
					implode( ', ', $unknown )
				),
				array( 'status' => 422 )
			);
		}

		$total_guest = (int) $total_guest;

		if ( $total_guest >= $min && $total_guest <= $max ) {
			return true;
		}

		return new \WP_Error(
			'seats_guests_mismatch',
			$min === $max
				? sprintf(
					/* translators: 1: number of guests, 2: number of guests the picked seats hold */
					__( 'You have %1$d guest(s), but the picked seats are for %2$d. Please change the guests or the seats.', 'wp-cafe' ),
					$total_guest,
					$min
				)
				: sprintf(
					/* translators: 1: number of guests, 2: lowest number of guests, 3: highest number of guests */
					__( 'You have %1$d guest(s), but the picked table is for %2$d to %3$d. Please change the guests or the table.', 'wp-cafe' ),
					$total_guest,
					$min,
					$max
				),
			array( 'status' => 422 )
		);
	}

    /**
     * Let only one booking save per location at a time.
     *
     * The capacity and seat checks read existing bookings, then save. Two
     * requests in the same instant would both pass, so callers hold this MySQL
     * named lock from the check until the booking is saved, then call
     * unlock_booking_slot(). Hosts without GET_LOCK keep working, unlocked.
     *
     * @param int|string $branch_id Location ID, or '' for none.
     * @return string|false|\WP_Error Lock name to release, false when the
     *                                 database has no named locks, or a
     *                                 `booking_busy` error (HTTP 503) on timeout.
     */
    public static function lock_booking_slot( $branch_id ) {
        global $wpdb;

        $name = substr( 'wpcafe_booking_' . $wpdb->prefix . absint( $branch_id ), 0, 64 );
        /**
         * Seconds a booking waits for another one at the same location to finish saving.
         *
         * @param int $seconds Default 10.
         */
        $wait = max( 0, (int) apply_filters( 'wpcafe_booking_lock_timeout', 10 ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must hit the database every time.
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $wait ) );

        if ( null === $got ) {
            return false;
        }

        if ( '1' !== (string) $got ) {
            return new \WP_Error( 'booking_busy', __( 'Many bookings are being made right now. Please try again.', 'wp-cafe' ), [ 'status' => 503 ] );
        }

        return $name;
    }

    /**
     * Release a lock from lock_booking_slot(). Safe to call with false.
     *
     * @param string|false $name Lock name.
     * @return void
     */
    public static function unlock_booking_slot( $name ): void {
        global $wpdb;

        if ( is_string( $name ) && '' !== $name ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock must hit the database every time.
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
        }
    }

    /**
     * Statuses that make a booking hold its seats ("Statuses that block the time slots").
     *
     * @return string[] Falls back to `confirmed` when the setting is empty.
     */
    public static function seat_blocking_statuses(): array {
        $statuses = wpc_get_option( 'block_timeslot_statuses', [] );

        return is_array( $statuses ) && ! empty( $statuses ) ? array_values( $statuses ) : [ 'confirmed' ];
    }

    /**
     * Meta query for bookings on $date whose time overlaps start-end.
     *
     * Back-to-back bookings (one ends at 19:00, the next starts at 19:00) do not
     * overlap. The same-start clause keeps bookings saved with no length
	 * (start = end) clashing with each other. A buffer keeps that much time free
	 * on both sides, so a seat can be cleaned between bookings.
     *
	 * @param string $date   Booking date (Y-m-d).
	 * @param int    $start  Start timestamp.
	 * @param int    $end    End timestamp.
	 * @param int    $buffer Seconds to keep free between bookings.
     * @return array
     */
	private static function overlap_meta_query( $date, $start, $end, $buffer = 0 ): array {
        return [
            'relation' => 'AND',
            [
                'key'     => 'date',
                'value'   => $date,
                'compare' => '=',
            ],
            [
                'relation' => 'OR',
                [
                    'relation' => 'AND',
                    [
                        'key'     => 'start_time',
						'value'   => $end + $buffer,
                        'compare' => '<',
                        'type'    => 'NUMERIC',
                    ],
                    [
                        'key'     => 'end_time',
						'value'   => $start - $buffer,
                        'compare' => '>',
                        'type'    => 'NUMERIC',
                    ],
                ],
                [
                    'key'     => 'start_time',
                    'value'   => $start,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ];
    }

    /**
     * Bookings from today on that hold any of these seats.
     *
     * @param array      $seats     Seat IDs, e.g. [ 'S5', 'S6' ].
     * @param int|string $branch_id Location ID, or '' for every location.
     * @return array<int, array{id: int, date: string, seats: string[]}>
     */
    public static function future_bookings_for_seats( array $seats, $branch_id = '' ): array {
        $seats = array_map( 'strval', $seats );

        if ( empty( $seats ) ) {
            return [];
        }

        $meta_query = [
            [
                'key'     => 'date',
                'value'   => wp_date( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ],
        ];

        if ( ! empty( $branch_id ) ) {
            $meta_query[] = [
                'key'     => 'branch_id',
                'value'   => $branch_id,
                'compare' => '=',
            ];
        }

        $posts = get_posts(
            [
                'post_type'   => 'wpc_reservation',
                'post_status' => self::expand_with_wc_equivalents( self::seat_blocking_statuses() ),
                'numberposts' => -1,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- seats live in post meta.
                'meta_query'  => $meta_query,
            ]
        );

        $bookings = [];

        foreach ( $posts as $post ) {
            $booking = new self( $post );
            $held    = is_array( $booking->seats ) ? array_map( 'strval', $booking->seats ) : [];
            $hit     = array_values( array_intersect( $held, $seats ) );

            if ( $hit ) {
                $bookings[] = [
                    'id'    => (int) $booking->id,
                    'date'  => (string) $booking->date,
                    'seats' => $hit,
                ];
            }
        }

        usort(
            $bookings,
            function ( $a, $b ) {
                return strcmp( $a['date'], $b['date'] );
            }
        );

        return $bookings;
    }

	/**
	 * Whether customers can book an area on a date: not closed for that day, and
	 * the date's week day is one of its open days (none saved = every day).
	 *
	 * @param array  $area Area from the table layout.
	 * @param string $date Date as Y-m-d.
	 * @return bool
	 */
	public static function area_is_open_on( array $area, string $date ): bool {
		if ( ( $area['closed_date'] ?? '' ) === $date ) {
			return false;
		}

		$days = is_array( $area['open_days'] ?? null ) ? array_map( 'intval', $area['open_days'] ) : array();
		$time = strtotime( $date );

		return ! $days || false === $time || in_array( (int) gmdate( 'w', $time ), $days, true );
	}

	/**
	 * Whether bookings at this location get tables: the Pro table module is on,
	 * the location did not turn its tables off, and tables exist. The system or
	 * staff can then give a table even when the form shows no map.
	 *
	 * @param int|string $branch_id Location term ID, or '' for none.
	 * @return bool
	 */
	public static function tables_in_use( $branch_id ): bool {
		if ( ! wpc_is_module_enable( 'table_layout' ) || empty( self::table_layout( $branch_id )['tables'] ) ) {
			return false;
		}

		$location = absint( $branch_id ) ? Location_Model::find( absint( $branch_id ) ) : null;

		return ! ( $location && '1' === (string) $location->override_reservation && '1' !== (string) $location->reservation_enable_table_layout );
	}

	/**
	 * Whether the booking form shows tables for this location: tables are in use
	 * and the form has the "Select table" field turned on.
	 *
	 * @param int|string $branch_id Location term ID, or '' for none.
	 * @return bool
	 */
	public static function table_map_is_on( $branch_id ): bool {
		if ( ! self::tables_in_use( $branch_id ) ) {
			return false;
		}

		$steps = wpc_get_option( 'reservation_form_customization', array() );

		foreach ( is_array( $steps ) ? $steps : array() as $step ) {
			foreach ( is_array( $step['fields'] ?? null ) ? $step['fields'] : array() as $field ) {
				if ( 'visual_table' === ( $field['id'] ?? '' ) ) {
					return filter_var( $field['visible'] ?? false, FILTER_VALIDATE_BOOLEAN );
				}
			}
		}

		return false;
	}

    /**
	 * Table layout of an overriding location, else the "All locations" one.
     *
     * @param int|string $branch_id Location term ID, or '' for none.
     * @return array Decoded layout with `tables[]`, or [] when there is none.
     */
    public static function table_layout( $branch_id ): array {
		$location = wpc_get_reservation_override_location( $branch_id );
		$layout   = $location ? $location->visual_table_layout : '';
        if ( empty( $layout ) ) {
            $layout = wpc_get_option( 'visual_table_layout', '' );
        }
        if ( is_string( $layout ) && '' !== $layout ) {
            $layout = json_decode( $layout, true );
        }

		return is_array( $layout ) ? $layout : array();
    }

	/**
	 * Times and seats of the bookings on a date that hold their seats.
	 *
	 * One query for the day, so the booking form can check every time slot at once.
	 *
	 * @param string     $date      Booking date (Y-m-d).
	 * @param int|string $branch_id Location ID, or '' for every location.
	 * @param int        $exclude_id Booking to leave out, so one being edited does not block itself.
	 * @return array<int, array{start: int, end: int, seats: string[]}>
	 */
	public static function seat_bookings_on_date( $date, $branch_id = '', $exclude_id = 0 ): array {
		$meta_query = array(
			array(
				'key'     => 'date',
				'value'   => $date,
				'compare' => '=',
			),
		);

		if ( ! empty( $branch_id ) ) {
			$meta_query[] = array(
				'key'     => 'branch_id',
				'value'   => $branch_id,
				'compare' => '=',
			);
		}

		$posts = get_posts(
			array(
				'post_type'   => 'wpc_reservation',
				'post_status' => self::expand_with_wc_equivalents( self::seat_blocking_statuses() ),
				'numberposts' => -1,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one ID at most.
				'post__not_in' => array_filter( array( absint( $exclude_id ) ) ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- seats live in post meta.
				'meta_query'  => $meta_query,
			)
		);

		return array_map(
			function ( $post ) {
				$booking = new self( $post );

				return array(
					'start' => (int) $booking->start_time,
					'end'   => (int) $booking->end_time,
					'seats' => is_array( $booking->seats ) ? array_map( 'strval', $booking->seats ) : array(),
				);
			},
			$posts
		);
	}

	/**
	 * Area and table names for seats, to keep with the booking.
	 *
	 * A later rename or delete in the layout must not change what the customer booked.
	 *
	 * @param array      $seats     Seat IDs.
	 * @param int|string $branch_id Location ID, or '' for none.
	 * @return array{area_id: string, table_ids: string[], table_snapshot: array}
	 */
	public static function table_snapshot_for_seats( $seats, $branch_id = '' ): array {
		$seats  = is_array( $seats ) ? array_map( 'strval', $seats ) : array();
		$layout = self::table_layout( $branch_id );
		$tables = is_array( $layout['tables'] ?? null ) ? $layout['tables'] : array();
		$areas  = is_array( $layout['areas'] ?? null ) ? $layout['areas'] : array();
		$hits   = array();

		foreach ( $tables as $table ) {
			$ids = array_map( 'strval', array_column( is_array( $table['seats'] ?? null ) ? $table['seats'] : array(), 'id' ) );
			$hit = array_values( array_intersect( $seats, $ids ) );

			if ( $hit ) {
				$hits[] = array(
					'id'      => (string) ( $table['id'] ?? '' ),
					'name'    => (string) ( $table['name'] ?? $table['id'] ?? '' ),
					'area_id' => (string) ( $table['area_id'] ?? '' ),
					'seats'   => $hit,
				);
			}
		}

		$area_id = (string) ( $hits[0]['area_id'] ?? '' );
		$names   = array_column( $areas, 'name', 'id' );

		return array(
			'area_id'        => $area_id,
			'table_ids'      => array_column( $hits, 'id' ),
			'table_snapshot' => $hits ? array(
				'area'   => (string) ( $names[ $area_id ] ?? '' ),
				'tables' => $hits,
			) : array(),
		);
	}

    /**
     * Name of the area saved with the booking, or '' when it has none.
     *
     * @return string
     */
    public function area_name(): string {
        return is_array( $this->table_snapshot ) ? (string) ( $this->table_snapshot['area'] ?? '' ) : '';
    }

	/**
	 * Whether the customer may see the table: always when they picked it, else only
	 * when the owner chose to show it, since staff may still move the booking.
	 *
	 * @return bool
	 */
	public function guest_sees_table(): bool {
		return 'system' !== (string) $this->table_assigned_by || (bool) wpc_get_option( 'table_show_table_to_guest', false );
	}

    /**
     * Names of the tables saved with the booking, or '' when it has none.
     *
     * @return string e.g. "Window, Table 2".
     */
    public function table_names(): string {
        $tables = is_array( $this->table_snapshot ) ? ( $this->table_snapshot['tables'] ?? array() ) : array();

        return implode( ', ', array_column( $tables, 'name' ) );
    }

    /**
     * Booked seats grouped by the table they belong to.
     *
	 * The names saved with the booking win over the layout. Seats the layout no
	 * longer has come last with an empty table name, so staff still see them.
     *
     * @return array<int, array{table: string, seats: string[]}> e.g.
     *               [ [ 'table' => 'Table 2', 'seats' => [ 'S5', 'S6' ] ] ].
     */
    public function seat_groups(): array {
        $seats = is_array( $this->seats ) ? array_map( 'strval', $this->seats ) : [];
        if ( empty( $seats ) ) {
            return [];
        }

		$saved = is_array( $this->table_snapshot ) ? ( $this->table_snapshot['tables'] ?? array() ) : array();

		if ( $saved ) {
			return array_map(
				function ( $table ) {
					return array(
						'table' => (string) $table['name'],
						'seats' => array_map( 'strval', (array) $table['seats'] ),
					);
				},
				$saved
			);
		}

        $layout = self::table_layout( $this->branch_id );
        $tables = is_array( $layout['tables'] ?? null ) ? $layout['tables'] : [];
        $groups = [];
        $found  = [];

        foreach ( $tables as $table ) {
            $ids = array_map( 'strval', array_column( is_array( $table['seats'] ?? null ) ? $table['seats'] : [], 'id' ) );
            $hit = array_values( array_intersect( $seats, $ids ) );

            if ( $hit ) {
                $groups[] = [
                    'table' => (string) ( $table['name'] ?? $table['id'] ?? '' ),
                    'seats' => $hit,
                ];
                $found = array_merge( $found, $hit );
            }
        }

        $missing = array_values( array_diff( $seats, $found ) );
        if ( $missing ) {
            $groups[] = [
                'table' => '',
                'seats' => $missing,
            ];
        }

        return $groups;
    }

    /**
     * Expand a list of reservation statuses to also include their WooCommerce
     * order-status equivalents.
     *
     * @param array $statuses Logical reservation statuses (`pending`, `confirmed`, `cancelled`, ...).
     * @return array Statuses including WC equivalents, deduplicated.
     */
    private static function expand_with_wc_equivalents( $statuses ) {
        if ( ! is_array( $statuses ) || empty( $statuses ) ) {
            return $statuses;
        }

		/*
		 * Raw Woo values an old row may still carry. Confirmed also keeps
		 * covering Completed, as before, so a finished booking holds its table.
		 * Refunded is not here: the money is back and the table is free.
		 */
        $wc_equivalents = [
            'pending'   => ['pending', 'on-hold', 'processing'],
			'confirmed' => array( 'completed' ),
			'cancelled' => array( 'failed' ),
        ];

        $expanded = $statuses;
        foreach ( $statuses as $status ) {
            if ( isset( $wc_equivalents[ $status ] ) ) {
                $expanded = array_merge( $expanded, $wc_equivalents[ $status ] );
            }
        }

        return array_values( array_unique( $expanded ) );
    }
}