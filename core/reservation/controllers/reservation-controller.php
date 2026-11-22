<?php
namespace WpCafe\Reservation\Controllers;

if ( ! defined( 'ABSPATH' ) ) exit;

use WpCafe\Abstract\Base_Rest_Controller;
use WpCafe\Models\Reservation_Model;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Server;
use WpCafe\Resources\Reservation_Resource;
use WpCafe\Models\Reservation_Item_Model;
use WpCafe\Scheduler;
use WpCafe\Models\Location_Model;
use WpCafe\Session;
use WpCafe\CustomerAccounts\Booking_Account;
use WpCafe\Reservation\Member_Discount;
use WpCafe\Reservation\Reservation_Pause;

/**
 * Reservation controller
 *
 * Handles all REST API endpoints for reservations.
 *
 * @package WpCafe/Reservation
 */
class Reservation_Controller extends Base_Rest_Controller {
    /**
     * Endpoint namespace
     *
     * @var string
     */
    protected $namespace = 'wpcafe/v2';

    /**
     * Route base
     *
     * @var string
     */
    protected $rest_base = 'reservations';

	/**
	 * Statuses the calendar's status filter accepts: ours plus the WooCommerce
	 * order statuses a paid booking can carry.
	 *
	 * @var string[]
	 */
	private const CALENDAR_FILTER_STATUSES = array(
		'pending',
		'confirmed',
		'cancelled',
		'pending_payment',
		'refunded',
		'processing',
		'on-hold',
		'completed',
		'failed',
	);

    /**
     * Per-request cache of invoice+email lookups.
     *
     * @var array<string, Reservation_Model|null>
     */
    private $reservation_lookup_cache = [];

    /**
     * Register all routes related to reservation
     *
     * @return void
     */
    public function register_routes(): void {

        register_rest_route( $this->namespace,
            '/' . $this->rest_base, [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'create_item_permissions_check'],
            ],
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'get_items_permissions_check'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'bulk_delete_item'],
                'permission_callback' => [$this, 'delete_item_permissions_check'],
            ],
        ] );

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_item'],
                    'permission_callback' => [$this, 'get_item_permissions_check'],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'update_item'],
                    'permission_callback' => [$this, 'update_item_permissions_check'],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_item'],
                    'permission_callback' => [$this, 'delete_item_permissions_check'],
                ],
            ]
        );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calendar',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_calendar' ),
					'permission_callback' => array( $this, 'get_calendar_permissions_check' ),
					'args'                => array(
						'start_date' => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^\d{4}-\d{2}-\d{2}$',
						),
						'end_date'   => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^\d{4}-\d{2}-\d{2}$',
						),
						'branch'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'search'     => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'get_overview_permissions_check' ),
					'args'                => array(
						'start_date' => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^\d{4}-\d{2}-\d{2}$',
						),
						'end_date'   => array(
							'required' => true,
							'type'     => 'string',
							'pattern'  => '^\d{4}-\d{2}-\d{2}$',
						),
						// An integer, so "5abc" is refused instead of loading location 5's schedule.
						'branch'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
			)
		);

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/time-slots', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_slots'],
                'permission_callback' => [$this, 'get_slots_permissions_check'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/reservation-capacity', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_reservation_capacity'],
                'permission_callback' => [$this, 'get_reservation_capacity_permissions_check'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/seat-bookings', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_seat_bookings'],
                'permission_callback' => [$this, 'can_manage_reservations'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/reservation-cancel', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'cancel_reservation'],
                'permission_callback' => [$this, 'cancel_reservation_permissions_check'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/food-list', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_food_list'],
                'permission_callback' => [$this, 'get_food_list_permissions_check'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/cart-has-items', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'check_cart_has_items'],
                'permission_callback' => [$this, 'check_cart_has_items_permissions_check'],
            ]
        ] );

        register_rest_route( $this->namespace,
            '/' . $this->rest_base . '/cart-total', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_reservation_cart_total'],
                'permission_callback' => [$this, 'check_cart_has_items_permissions_check'],
            ]
        ] );

		$pause_location_arg = array(
			'type'              => 'integer',
			'default'           => Reservation_Pause::ALL_LOCATIONS,
			'minimum'           => 0,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pause',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pause' ),
					'permission_callback' => array( $this, 'get_pause_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'pause_reservations' ),
					'permission_callback' => array( $this, 'manage_pause_permissions_check' ),
					'args'                => array(
						'location_ids' => array(
							'type'    => 'array',
							'items'   => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
							'default' => array(),
						),
						'type'         => array(
							'required' => true,
							'type'     => 'string',
							'enum'     => array( Reservation_Pause::TYPE_TEMPORARY, Reservation_Pause::TYPE_INDEFINITE ),
						),
						// Site-timezone wall clock, e.g. "2026-11-03 18:30"; temporary only.
						'starts_at'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'ends_at'      => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'resume_reservations' ),
					'permission_callback' => array( $this, 'manage_pause_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pause/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pause_status' ),
					'permission_callback' => array( $this, 'get_pause_status_permissions_check' ),
					'args'                => array( 'location_id' => $pause_location_arg ),
				),
			)
		);
    }

    /**
     * Check whether user can view all reservations.
     *
     * @return bool
     */
    private function can_view_all_reservations(): bool {
        return wpc_current_user_can_view_reservations();
    }

    /**
     * Check whether user can view reservations.
     *
     * @return bool
     */
    private function can_view_reservations(): bool {
        return $this->can_view_all_reservations()
            || current_user_can( 'wpcafe_view_own_reservations' );
    }

    /**
     * Check whether user can manage reservations.
     *
     * @return bool
     */
    public function can_manage_reservations(): bool {
        // One definition, so the member discount and the routes agree on who is staff.
        return Member_Discount::books_for_others();
    }

    /**
     * Check whether current user can only view own reservations.
     *
     * @return bool
     */
    private function view_own_reservations_only(): bool {
        return ! $this->can_view_all_reservations()
            && current_user_can( 'wpcafe_view_own_reservations' );
    }

	/**
	 * Whether the signed-in customer owns this booking.
	 *
	 * Ownership is the post author, never the typed email: a customer can change
	 * their account email to a guest's address without proving it is theirs.
	 *
	 * @param int $reservation_id Booking.
	 * @return bool
	 */
	private function current_user_owns_reservation( int $reservation_id ): bool {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || (int) get_post_field( 'post_author', $reservation_id ) !== $user_id ) {
			return false;
		}

		// Linked by its typed email only; the owner has not proved that email yet.
		if ( Booking_Account::is_link_pending( $reservation_id ) ) {
			return false;
		}

		// A booking made this account from a typed email: other bookings with that email wait for a password.
		$only = Booking_Account::unverified_booking( $user_id );

		return ! $only || $only === $reservation_id;
	}

	/**
	 * The invoice is the secret the public cancel route checks, so a customer
	 * reading their own bookings does not get it back from the API.
	 *
	 * @param array $data Resolved booking resource.
	 * @return array
	 */
	private function own_reservation_data( array $data ): array {
		unset( $data['invoice'] );

		return $data;
	}


    /**
     * Create a new reservation item
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response|WP_Error
     */
    public function create_item($request) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 10 * MINUTE_IN_SECONDS, 'create' );

        $data = $this->prepare_item_for_database($request);

        if ( is_wp_error( $data ) ) {
            return $this->error( $data->get_error_message() );
        }

		/*
		 * Paused from the dashboard while the restaurant is busy. Staff can still
		 * add bookings (phone, walk-in); the public form and direct API calls cannot.
		 */
		$pause = Reservation_Pause::get_effective( $data['branch_id'] ?? null );
		if ( $pause && ! $this->can_manage_reservations() ) {
			return new WP_Error(
				'wpcafe_reservations_paused',
				Reservation_Pause::message( $pause ),
				array(
					'status' => 409,
					'pause'  => Reservation_Pause::public_status( $data['branch_id'] ?? null ),
				)
			);
		}
        /*
         * Held from the checks until the row is saved, so two customers who
         * press "Confirm" in the same second cannot both get the last seat.
         */
        $slot_lock = Reservation_Model::lock_booking_slot( $data['branch_id'] ?? '' );

        if ( is_wp_error( $slot_lock ) ) {
            return $this->error( $slot_lock->get_error_message(), 503, $slot_lock->get_error_code() );
        }

        try {
            // Validate guest capacity before creating reservation.
            $capacity_validation = Reservation_Model::validate_guest_capacity(
                $data['total_guest'] ?? 1,
                $data['date'] ?? '',
                $data['start_time'] ?? '',
                $data['end_time'] ?? '',
                $data['branch_id'] ?? ''
            );

            if ( is_wp_error( $capacity_validation ) ) {
                return $this->error( $capacity_validation->get_error_message() );
            }

			// Runs inside the lock, so two customers at the same second get different tables.
			$table_pick = $this->pick_table( $data );

			if ( is_wp_error( $table_pick ) ) {
				return $this->error( $table_pick->get_error_message(), 409, $table_pick->get_error_code() );
			}

			if ( $table_pick ) {
				$data['seats'] = $table_pick['seats'];
			}

			$selection_validation = Reservation_Model::validate_seat_selection(
				$data['seats'] ?? array(),
				$data['total_guest'] ?? 1,
				$data['branch_id'] ?? '',
				$this->can_manage_reservations(),
				$data['date'] ?? ''
			);

			if ( is_wp_error( $selection_validation ) ) {
				return $this->error( $selection_validation->get_error_message(), 422, $selection_validation->get_error_code() );
			}

            $seat_validation = Reservation_Model::validate_seat_availability(
                $data['seats'] ?? [],
                $data['date'] ?? '',
                $data['start_time'] ?? '',
                $data['end_time'] ?? '',
                $data['branch_id'] ?? ''
            );

            if ( is_wp_error( $seat_validation ) ) {
                return $this->error( $seat_validation->get_error_message(), 409, $seat_validation->get_error_code(), $seat_validation->get_error_data() );
            }

            $food_items = [];

            if ( ! empty( $data['food_items'] ) ) {
                $food_items = $data['food_items'];
                unset( $data['food_items'] );
            }

            $data['invoice'] = $this->generate_invoice_number();

            /*
             * The moderation status belongs to the site owner, not the caller. This
             * route is open to logged-out visitors (it is the booking form), so only
             * a user who can manage reservations may choose one; everyone else gets
             * the configured default. Without this an anonymous POST could file
             * itself as "confirmed" and skip the approval queue (CVE-2026-14550).
             */
            $data['status'] = $this->can_manage_reservations()
                ? Reservation_Model::sanitize_status( $data['status'] ?? '', Reservation_Model::default_status() )
                : Reservation_Model::default_status();

            /*
             * Payment linkage is written by the checkout hooks once money actually
             * moves, and discounts are priced below. Taking either from the request
             * would let a booking point at another order or set its own price.
             */
			unset( $data['woo_order_id'], $data['payment_intent'], $data['points_discount'], $data['member_discount'], $data['member_discount_label'], $data['member_customer_id'], $data['area_id'], $data['table_ids'], $data['table_snapshot'], $data['table_assigned_by'] );

            // Price the reservation from trusted server settings, never the client.
            $this->set_server_calculated_total( $data );

            // When the form shows a food menu, the deposit is worked out on the whole
            // order (booking + food), not the booking alone. The food is still in the
            // cart at this point (create_food_items_from_woocart runs just below), so
            // read its subtotal now and fold it into the deposit split.
            $food_subtotal = $this->food_menu_is_visible_in_reservation_form()
                ? $this->get_reservation_food_cart_subtotal()
                : 0.0;

            $this->apply_partial_payment( $data, $food_subtotal );

            /**
             * The booking is priced and about to be saved. An integration may lower
             * what the customer pays (a points discount) or refuse the booking.
             *
             * @param array|\WP_Error $data          Booking data, priced on the server.
             * @param \WP_REST_Request $request       Create request.
             * @param float            $food_subtotal Food in the cart for this booking.
             */
            $data = apply_filters( 'wpcafe_reservation_before_save', $data, $request, $food_subtotal );

            // A points discount is priced for paying at the restaurant; checkout would charge the full price.
            if ( is_array( $data ) && 'wc' === ( $data['payment_method'] ?? '' ) && (float) ( $data['points_discount'] ?? 0 ) > 0 ) {
                $data = new WP_Error( 'wpcafe_points_pay_at_restaurant', __( 'This booking used points and is paid at the restaurant.', 'wp-cafe' ), [ 'status' => 409 ] );
            }

            if ( is_wp_error( $data ) ) {
                /** The booking was refused after the save filter; an integration undoes what it did there. */
                do_action( 'wpcafe_reservation_create_failed', $request );
                return $data;
            }

            // After the save filter, so the member discount is priced on what points leave.
            $data = Member_Discount::apply( $data, $food_subtotal );

            // Integrations may add their own form keys (wpcafe.reservationCreatePayload); only booking fields are saved.
            $data = array_intersect_key( $data, array_flip( ( new Reservation_Model() )->get_fillable_keys() ) );

			$data = array_merge( $data, Reservation_Model::table_snapshot_for_seats( $data['seats'] ?? array(), $data['branch_id'] ?? '' ) );

			if ( $table_pick ) {
				$data['table_assigned_by'] = $table_pick['assigned_by'];

				// Staff mode saves no table yet; keep the area the customer asked for, for emails too.
				if ( '' === $data['area_id'] && '' !== $table_pick['area_id'] ) {
					$areas                  = Reservation_Model::table_layout( $data['branch_id'] ?? '' )['areas'] ?? array();
					$names                  = array_column( is_array( $areas ) ? $areas : array(), 'name', 'id' );
					$data['area_id']        = $table_pick['area_id'];
					$data['table_snapshot'] = array(
						'area'   => (string) ( $names[ $table_pick['area_id'] ] ?? '' ),
						'tables' => array(),
					);
				}
			} elseif ( ! empty( $data['seats'] ) ) {
				$data['table_assigned_by'] = $this->can_manage_reservations() ? 'staff' : 'customer';
			}

            try {
                $reservation = Reservation_Model::create( $data );
            } catch ( \Throwable $e ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[WPCafe] Reservation not saved: ' . $e->getMessage() );
                $reservation = null;
            }

            // create() gives null or an id-less model when the insert fails.
            if ( ! $reservation || (int) $reservation->id <= 0 ) {
                do_action( 'wpcafe_reservation_create_failed', $request );
                return $this->error( __( 'The reservation could not be saved. Please try again.', 'wp-cafe' ), 500 );
            }
        } finally {
            Reservation_Model::unlock_booking_slot( $slot_lock );
        }

        /**
         * The booking row exists. Fires before anything else in this request can
         * fail, so an integration can tie what it did in the save filter to it.
         *
         * @param Reservation_Model $reservation
         * @param \WP_REST_Request  $request
         */
        do_action( 'wpcafe_reservation_inserted', $reservation, $request );

        if ( $this->food_menu_is_visible_in_reservation_form() ) {
            $food_items = $this->create_food_items_from_woocart( $reservation->id );
        }

        if ( ! empty( $food_items ) ) {
            $reservation->update( [ 'food_order' => 'yes' ] );

            /*
             * Paying at the restaurant: the food is now recorded on the booking
             * and never goes through checkout, so empty the cart. Leaving it
             * would mean the same food is counted again on the customer's next
             * booking (the whole cart counts as that booking's food) and could
             * be ordered a second time. Online payment keeps the cart — checkout
             * is about to charge it.
             */
            if ( 'wc' !== ( $data['payment_method'] ?? '' ) && function_exists( 'WC' ) && WC()->cart ) {
                WC()->cart->empty_cart();
            }
        }
        $this->set_reservation_data_in_woocommerce_session( $reservation );

        $payment_token = $this->issue_reservation_payment_token( $reservation->id );

        $response = new Reservation_Resource( $reservation );

        /**
         * The booking is saved and the original request is still at hand, for
         * integrations that store extra form choices (e.g. a points code).
         *
         * @param Reservation_Model $reservation
         * @param \WP_REST_Request  $request
         */
        do_action( 'wpcafe_reservation_request_saved', $reservation, $request );

        do_action( 'wpcafe_after_reservation_create', $reservation );

        $payload = array_merge( $response->to_array(), [ 'payment_token' => $payment_token ] );

        /**
         * What the booking form gets back after a booking is created.
         *
         * @param array             $payload     Booking data and payment token.
         * @param Reservation_Model $reservation Booking.
         * @param \WP_REST_Request  $request     Create request.
         */
        $payload = apply_filters( 'wpcafe_reservation_create_response', $payload, $reservation, $request );

        return $this->response( $payload, __( 'Reservation created successfully.', 'wp-cafe' ) );
    }

    /**
	 * Work out the deposit split on the server and save it on the booking.
	 *
	 * The form's figures are ignored. A deposit applies only to WooCommerce
	 * payment with the WP Cafe toggle and a Deposet rule on, and is a share of
	 * booking + food. total_price stays the full booking amount.
	 *
     * @param array $data          Reservation data, changed in place.
	 * @param float $food_subtotal Food already in the cart for this booking.
     * @return void
     */
    private function apply_partial_payment( array &$data, float $food_subtotal = 0.0 ): void {
		$is_wc     = ( $data['payment_method'] ?? '' ) === 'wc';
		$toggle_on = ! empty( wpc_get_option( 'reservation_partial_payment' ) );
		$rule      = $toggle_on ? wpc_deposet_rule() : null;

		// A forced deposit leaves no "pay in full" choice, so ignore what the form sent.
		$wants_deposit = $rule && ( $rule['force'] || ( $data['payment_amount_type'] ?? $rule['default'] ) === 'deposit' );

		if ( $is_wc && $wants_deposit ) {
            // The deposit is a share of everything the customer pays online: the
            // booking amount plus any food added in the form. Checkout works this
            // out again from the cart it is about to charge, in case the customer
            // changes the cart after booking.
            $total = (float) ( $data['total_price'] ?? 0 ) + $food_subtotal;
            $split = wpc_reservation_deposit_split( $total );

            if ( $split ) {
                $data['is_partial_payment'] = 'yes';
                $data['deposit_value']      = $split['deposit'];
                $data['remaining_amount']   = $split['remaining'];
                return;
            }
        }

        // Not a deposit booking — save it as a normal full payment and clear any
        // deposit values the form may have sent.
        $data['is_partial_payment'] = 'no';
        $data['deposit_value']      = 0;
        $data['remaining_amount']   = 0;
    }

	/**
	 * The table for a new booking when the system or staff pick it, not the customer.
	 *
	 * The seats the browser sent are ignored then. The area the customer asked for
	 * is kept when the site lets customers choose one.
	 *
	 * @param array $data Booking data.
	 * @return array{seats: string[], area_id: string, assigned_by: string}|null|\WP_Error
	 *         Null when the customer or staff picked the seats, or no tables are used.
	 *         `no_table_free` (HTTP 409) when no table fits the group at that time.
	 */
	private function pick_table( array $data ) {
		$branch_id = $data['branch_id'] ?? '';

		if ( ! Reservation_Model::tables_in_use( $branch_id ) ) {
			return null;
		}

		// With "Select table" off nobody picks on a map, so "customer" gives no table.
		$mode = wpc_get_table_pick_mode( $branch_id );

		if ( 'customer' === $mode || ( $this->can_manage_reservations() && ! empty( $data['seats'] ) ) ) {
			return null;
		}

		$guests  = max( 1, (int) ( $data['total_guest'] ?? 1 ) );
		$area_id = wpc_guest_picks_table_area() ? sanitize_text_field( (string) ( $data['area_id'] ?? '' ) ) : '';

		/**
		 * Best free table for the group, from WP Cafe Pro.
		 *
		 * @param array|null $best Null when nothing can pick. Else the table, with its `seats`, or [] when none fits.
		 * @param array      $args date, start_time, end_time, guests, branch_id and area_id ('' for any area).
		 */
		$best = apply_filters(
			'wpcafe_reservation_pick_table',
			null,
			array(
				'date'       => (string) ( $data['date'] ?? '' ),
				'start_time' => $data['start_time'] ?? '',
				'end_time'   => $data['end_time'] ?? '',
				'guests'     => $guests,
				'branch_id'  => $branch_id,
				'area_id'    => $area_id,
			)
		);

		if ( ! is_array( $best ) ) {
			return null;
		}

		if ( empty( $best['seats'] ) ) {
			return new WP_Error(
				'no_table_free',
				sprintf(
					/* translators: %d: number of guests */
					_n( 'No table is free for %d guest at this time. Please pick another time.', 'No table is free for %d guests at this time. Please pick another time.', $guests, 'wp-cafe' ),
					$guests
				),
				array( 'status' => 409 )
			);
		}

		$is_system = 'system' === $mode;

		return array(
			'seats'       => $is_system ? array_map( 'strval', (array) $best['seats'] ) : array(),
			'area_id'     => '' !== $area_id ? $area_id : ( $is_system ? (string) ( $best['area_id'] ?? '' ) : '' ),
			'assigned_by' => $is_system ? 'system' : '',
		);
	}

    /**
     * Work out the reservation price on the server and overwrite any figure the
     * client sent.
     *
     * @param array $data Reservation data, changed in place.
     * @return void
     */
    private function set_server_calculated_total( array &$data ): void {
        $branch_id   = ! empty( $data['branch_id'] ) ? absint( $data['branch_id'] ) : null;
        $total_guest = max( 1, (int) ( $data['total_guest'] ?? 1 ) );

        $config = wpc_get_reservation_booking_config( $branch_id );
        $amount = (float) $config['amount'];

        $data['booking_amount'] = $amount;
		$data['total_price']    = $config['multiply']
			? $amount * $total_guest
			: $amount;
    }

    /**
     * Build a hard-to-guess invoice reference for a reservation.
     *
     * @return string Invoice reference, e.g. "WPC3F9A2B7C1D4".
     */
    private function generate_invoice_number(): string {
        return 'WPC' . strtoupper( bin2hex( random_bytes( 6 ) ) );
    }

    /**
     * Issue a one-time, short-lived payment token for the reservation.
     *
     * Returns the raw token to the caller and stores only its SHA-256 hash
     * plus expiry as post meta so the payment endpoint can verify ownership
     * for guest flows without relying on WC session cookie continuity.
     *
     * @param int $reservation_id Reservation post ID.
     * @return string Raw token (64 hex chars).
     */
    private function issue_reservation_payment_token( int $reservation_id ): string {
        $token = bin2hex( random_bytes( 32 ) );

        update_post_meta( $reservation_id, '_wpc_payment_token', hash( 'sha256', $token ) );
        update_post_meta( $reservation_id, '_wpc_payment_token_expires', time() + ( 2 * HOUR_IN_SECONDS ) );

        return $token;
    }

    /**
     * Stores reservation data in WC session
     *
     * Stores reservation data in WC session to be used later
     * in the checkout process.
     *
     * @param Reservation_Model $reservation Reservation data
     */
    private function set_reservation_data_in_woocommerce_session( $reservation ) {
        if ( function_exists( 'WC' ) ) {
            // Initialize WooCommerce session if not already available
            if ( ! WC()->session ) {
                WC()->session = new \WC_Session_Handler();
                WC()->session->init();
            }

            /*
             * Checkout reads this to add the booking fee and link the order, so
             * only an online-paid booking may sit here. Otherwise the customer's
             * next food order gets this booking's fee and its order id.
             */
            if ( WC()->session && 'wc' !== $reservation->payment_method ) {
                WC()->session->__unset( 'wpc_reservation_data' );
                return;
            }

            if ( WC()->session ) {
                $session_data = [
                    'reservation_id' => $reservation->id,
                    'reservation_date' => $reservation->date ?? '',
                    'start_time'      => $reservation->start_time ?? '',
                    'end_time'        => $reservation->end_time ?? '',
                    'name'            => $reservation->name ?? '',
                    'email'           => $reservation->email ?? '',
                    'phone'           => $reservation->phone ?? '',
                    'total_guest'     => $reservation->total_guest ?? '',
                    'notes'           => $reservation->notes ?? '',
                    'branch_name'     => $reservation->branch_name ?? ''
                ];

                $custom_fields = $reservation->custom_fields ?? [];
                if ( ! empty( $custom_fields ) ) {
                    $session_data['custom_fields'] = $custom_fields;
                }

                WC()->session->set( 'wpc_reservation_data', $session_data );
            }
        }
    }

    /**
     * Checks if food menu is visible in reservation form
     *
     * @return bool
     */
    private function food_menu_is_visible_in_reservation_form() : bool {
        $reservation_form_customization = wpc_get_option('reservation_form_customization');

        if ( ! is_array($reservation_form_customization ) ) {
            return false;
        }

        foreach ( $reservation_form_customization as $reservation_step ) {
            if ( ! isset( $reservation_step['fields'] ) || ! is_array( $reservation_step['fields'] ) ) {
                continue;
            }

            foreach ( $reservation_step['fields'] as $field ) {
                if ( 'food_menu' === $field['type']  && $field['visible'] == true ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Permission check for creating a reservation.
     *
     * The booking form is public, so this stays open to logged-out visitors —
     * the nonce is CSRF protection only, and the guest `wp_rest` nonce is
     * printed on every page carrying the form. What the caller may actually set
     * is decided in create_item(); this callback only rejects forged and
     * flooded requests.
     *
     * Deny paths must return WP_Error or false: WordPress treats any other
     * return value as "granted".
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function create_item_permissions_check($request) {
        /**
         * Bookings allowed per IP before new attempts are refused.
         *
         * @param int $limit Number of bookings per 10 minutes.
         */
        $limit = (int) apply_filters( 'wpcafe_reservation_create_rate_limit', 10 );

        if ( $this->rate_limit_exceeded( $limit, 'create' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }

        if ( ! $this->verify_rest_nonce( $request ) ) {
            return new \WP_Error( 'wpcafe_invalid_nonce', __( 'Invalid security token.', 'wp-cafe' ), [ 'status' => 403 ] );
        }

        return true;
    }

    /**
     * Get a list of reservation items
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function get_items( $request ) {
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 10;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $search   = ! empty( $request['search'] ) ? sanitize_text_field( $request['search'] ) : '';
        $status   = ! empty( $request['status'] ) ? sanitize_text_field( $request['status'] ) : 'any';

        $filter = [];

        if ( isset( $request['status'] ) ) {
            $filter['status'] = sanitize_text_field( $request['status'] );
        }

        if ( isset( $request['branch'] ) ) {
            $filter['branch'] = sanitize_text_field( $request['branch'] );
        }

        if ( isset( $request['food_order'] ) ) {
            $filter['food_order'] = sanitize_text_field( $request['food_order'] );
        }

        if ( isset( $request['date_range'] ) && is_array( $request['date_range'] ) && count( $request['date_range'] ) === 2 ) {
            $start_date = sanitize_text_field( $request['date_range'][0] );
            $end_date = sanitize_text_field( $request['date_range'][1] );

            if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
                $filter['date_range'] = [ $start_date, $end_date ];
            }
        } elseif ( isset( $request['date_range[0]'] ) && isset( $request['date_range[1]'] ) ) {
            // Fallback for non-parsed array notation
            $start_date = sanitize_text_field( $request['date_range[0]'] );
            $end_date = sanitize_text_field( $request['date_range[1]'] );

            if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
                $filter['date_range'] = [ $start_date, $end_date ];
            }
        }

        $args = [
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
        ];

		$own_only = $this->view_own_reservations_only();

		if ( $own_only ) {
			$user_id = get_current_user_id();

			// WP_Query treats author 0 as "any author", so never let it through.
			if ( $user_id <= 0 ) {
				return $this->error( __( 'No reservations found', 'wp-cafe' ), 404 );
			}

			// By author, not email, minus links not yet confirmed: see current_user_owns_reservation().
			$args['author'] = $user_id;
			$pending        = Booking_Account::pending_link_ids( $user_id );

			if ( $pending ) {
				$args['post__not_in'] = $pending;
			}

			// A booking made this account from a typed email: other bookings with that email wait for a password.
			$only = Booking_Account::unverified_booking( $user_id );

			if ( $only ) {
				$args['post__in'] = array( $only );
			}
		}

        if ( ! empty( $search ) ) {
            $args['search'] = $search;
        }

        if ( ! empty( $filter ) ) {
            $args['filters'] = $filter;
        }

        $data = Reservation_Model::paginate( $args );

        if ( ! $data ) {
            return $this->error( __( 'No reservations found', 'wp-cafe' ), 404 );
        }

        $response = Reservation_Resource::collection( $data['items'] );

		if ( $own_only ) {
			$response = array_map( array( $this, 'own_reservation_data' ), $response );
		}

        $data['items'] = $response;

        return $this->response( $data );
    }

    /**
     * Permission check for reading reservations
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function get_items_permissions_check($request): bool {
        return $this->can_view_reservations();
    }

	/**
	 * Per-day reservation and guest counts for the admin calendar.
	 *
	 * Uses the same scoping as get_items(): staff see every booking, a
	 * customer with the "own" cap sees only bookings under their email.
	 *
	 * @param \WP_REST_Request $request Needs `start_date` and `end_date` (`Y-m-d`); `branch`, `status` and `search` are optional.
	 * @return WP_HTTP_Response
	 */
	public function get_calendar( $request ) {
		// A month view needs at most six weeks; the cap keeps one request cheap.
		$range = $this->parse_date_range( $request, 63 );

		if ( ! is_array( $range ) ) {
			return $range;
		}

		list( $start, $end ) = $range;

		$args = array();

		if ( ! empty( $request['branch'] ) ) {
			$args['branch'] = $request['branch'];
		}

		// The list's search box, so the calendar shows the bookings the list would.
		if ( ! empty( $request['search'] ) ) {
			$args['search'] = (string) $request['search'];
		}

		/*
		 * Same rule as the list's status filter: one exact post status, so both
		 * views agree. Picking one replaces the default set, cancelled included.
		 */
		if ( ! empty( $request['status'] ) ) {
			$status = (string) $request['status'];

			if ( ! in_array( $status, self::CALENDAR_FILTER_STATUSES, true ) ) {
				return $this->error( __( 'Invalid reservation status.', 'wp-cafe' ), 400 );
			}

			$args['statuses'] = array( $status );
		}

		if ( $this->view_own_reservations_only() ) {
			$args['email'] = (string) wp_get_current_user()->user_email;

			$only = Booking_Account::unverified_booking( get_current_user_id() );

			if ( $only ) {
				$args['post__in'] = array( $only );
			}

			// A blank email would skip the email match and count everyone's bookings.
			if ( '' === $args['email'] ) {
				$args['post__in'] = array( 0 );
			}
		}

		$start_date = $start->format( 'Y-m-d' );
		$end_date   = $end->format( 'Y-m-d' );

		/*
		 * A picked location with its own schedule decides which days are open;
		 * otherwise the global schedule does. Read global explicitly: with no ID,
		 * wpc_get_reservation_schedule() falls back to the admin's session location.
		 */
		$location_schedule = ! empty( $args['branch'] ) ? wpc_get_location_reservation_schedule( absint( $args['branch'] ) ) : null;
		$schedule          = $location_schedule ?? wpc_get_global_reservation_schedule();
		$closed_days       = $schedule
			? ( new Scheduler( $schedule, $start_date, $end_date, 0, $args['branch'] ?? null ) )->closed_days()
			: array();

		return $this->response(
			array(
				// "Today" in the site's time zone, so the highlight matches the restaurant, not the browser.
				'today'           => wp_date( 'Y-m-d' ),
				'start_date'      => $start_date,
				'end_date'        => $end_date,
				'statuses'        => $args['statuses'] ?? Reservation_Model::calendar_counted_statuses(),
				// No schedule set at all leaves every day open rather than blocking the admin.
				'closed_days'     => $closed_days,
				'schedule_source' => null !== $location_schedule ? 'location' : 'global',
				// An object, so an empty month is `{}` in JSON rather than `[]`.
				'days'            => (object) Reservation_Model::count_by_date( $start_date, $end_date, $args ),
			)
		);
	}

	/**
	 * Read `start_date` and `end_date` from a request and check them.
	 *
	 * Both must be real `Y-m-d` dates, in order, and the range may cover at
	 * most `$max_days` days (both ends counted).
	 *
	 * @param \WP_REST_Request $request  Current request.
	 * @param int              $max_days Most days the range may cover.
	 * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}|WP_HTTP_Response The two dates, or a 400 response.
	 */
	private function parse_date_range( $request, int $max_days ) {
		$start_input = (string) $request['start_date'];
		$end_input   = (string) $request['end_date'];
		$start       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_input );
		$end         = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_input );

		// createFromFormat() rolls Feb 31 over to Mar 3; a round trip catches it.
		if (
			! $start || ! $end || $start > $end
			|| $start->format( 'Y-m-d' ) !== $start_input
			|| $end->format( 'Y-m-d' ) !== $end_input
		) {
			return $this->error( __( 'Invalid date range.', 'wp-cafe' ), 400 );
		}

		if ( $start->diff( $end )->days + 1 > $max_days ) {
			return $this->error( __( 'Date range is too long.', 'wp-cafe' ), 400 );
		}

		return array( $start, $end );
	}

	/**
	 * Permission check for the calendar counts. Same audience as the list, plus a nonce.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function get_calendar_permissions_check( $request ) {
		if ( ! $this->can_view_reservations() ) {
			return new WP_Error( 'wpcafe_forbidden', __( 'You do not have permission to view reservations.', 'wp-cafe' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! $this->verify_rest_nonce( $request ) ) {
			return new WP_Error( 'wpcafe_invalid_nonce', __( 'Invalid nonce.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Totals, averages and weekday × hour counts for the Reservations Overview page.
	 *
	 * Also returns the same numbers for the period of the same length just
	 * before, for the "vs last" change on each card.
	 *
	 * @param \WP_REST_Request $request Needs `start_date` and `end_date` (`Y-m-d`); `branch` is optional.
	 * @return WP_HTTP_Response
	 */
	public function get_overview( $request ) {
		// One year keeps the query cheap.
		$range = $this->parse_date_range( $request, 366 );

		if ( ! is_array( $range ) ) {
			return $range;
		}

		list( $start, $end ) = $range;

		$length = $start->diff( $end )->days + 1;

		// The route takes an integer, so the schedule and the SQL use the same ID.
		$branch_id = absint( $request['branch'] ?? 0 );
		$branch    = $branch_id ? (string) $branch_id : '';

		// Same schedule rule as the calendar: a location's own schedule wins, else the global one.
		$location_schedule = $branch_id ? wpc_get_location_reservation_schedule( $branch_id ) : null;
		$schedule          = $location_schedule ?? wpc_get_global_reservation_schedule();

		$previous_end   = $start->modify( '-1 day' );
		$previous_start = $previous_end->modify( '-' . ( $length - 1 ) . ' days' );

		$rows = Reservation_Model::overview_rows( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $branch );

		// The previous period only needs totals, so count per day in SQL.
		$previous_days = Reservation_Model::count_by_date(
			$previous_start->format( 'Y-m-d' ),
			$previous_end->format( 'Y-m-d' ),
			$branch_id ? array( 'branch' => $branch ) : array()
		);

		$current  = $this->overview_period(
			$start->format( 'Y-m-d' ),
			$end->format( 'Y-m-d' ),
			(int) array_sum( array_column( $rows, 'count' ) ),
			(int) array_sum( array_column( $rows, 'guests' ) ),
			$branch,
			$schedule
		);
		$previous = $this->overview_period(
			$previous_start->format( 'Y-m-d' ),
			$previous_end->format( 'Y-m-d' ),
			(int) array_sum( array_column( $previous_days, 'total' ) ),
			(int) array_sum( array_column( $previous_days, 'guests' ) ),
			$branch,
			$schedule
		);

		$cells = array();
		$hours = array();

		foreach ( $rows as $row ) {
			$hour = $this->overview_booking_hour( $row['date'], $row['start_time'] );

			if ( null === $hour ) {
				continue;
			}

			$weekday = (int) gmdate( 'N', strtotime( $row['date'] . ' 00:00:00 UTC' ) );
			$key     = $weekday . '-' . $hour;

			$cells[ $key ]  = ( $cells[ $key ] ?? 0 ) + $row['count'];
			$hours[ $hour ] = true;
		}

		// Opening hours first; a booking outside them still gets its own column.
		if ( null !== $current['first_hour'] ) {
			foreach ( range( $current['first_hour'], $current['last_hour'] ) as $hour ) {
				$hours[ $hour ] = true;
			}
		}

		ksort( $hours );

		$cell_list = array();

		foreach ( $cells as $key => $total ) {
			list( $weekday, $hour ) = array_map( 'intval', explode( '-', $key ) );

			$cell_list[] = array(
				'day'   => $weekday,
				'hour'  => $hour,
				'total' => $total,
			);
		}

		unset( $current['first_hour'], $current['last_hour'], $previous['first_hour'], $previous['last_hour'] );

		return $this->response(
			array(
				'start_date'          => $start->format( 'Y-m-d' ),
				'end_date'            => $end->format( 'Y-m-d' ),
				'previous_start_date' => $previous_start->format( 'Y-m-d' ),
				'previous_end_date'   => $previous_end->format( 'Y-m-d' ),
				'current'             => $current,
				'previous'            => $previous,
				// Where open days and hours come from. With no location picked this is
				// the global schedule, even though bookings from every location count.
				'schedule_source'     => null !== $location_schedule ? 'location' : ( $schedule ? 'global' : 'none' ),
				'hours'               => array_keys( $hours ),
				// `day` is 1 (Monday) to 7 (Sunday); `hour` is 0-23. Empty cells are left out.
				'cells'               => $cell_list,
			)
		);
	}

	/**
	 * Totals and averages for one period of the Overview page.
	 *
	 * With no schedule saved at all, every day counts as open (same as the
	 * calendar) and the per-hour average is null, since hours are unknown.
	 *
	 * @param string     $start_date First day, `Y-m-d`.
	 * @param string     $end_date   Last day, `Y-m-d`.
	 * @param int        $total      Reservations in the period.
	 * @param int        $guests     Guests in those reservations.
	 * @param string     $branch     Location ID, or '' for all.
	 * @param array|null $schedule   Weekly schedule to read open days and hours from.
	 * @return array
	 */
	private function overview_period( string $start_date, string $end_date, int $total, int $guests, string $branch, $schedule ): array {

		if ( $schedule ) {
			$opening = ( new Scheduler( $schedule, $start_date, $end_date, 0, '' !== $branch ? $branch : null ) )->opening_summary();
		} else {
			$days    = ( new \DateTime( $start_date ) )->diff( new \DateTime( $end_date ) )->days + 1;
			$opening = array(
				'open_days'  => $days,
				'open_hours' => 0.0,
				'first_hour' => null,
				'last_hour'  => null,
			);
		}

		return array(
			'reservations' => $total,
			'guests'       => $guests,
			'open_days'    => (int) $opening['open_days'],
			'open_hours'   => round( (float) $opening['open_hours'], 2 ),
			'avg_per_day'  => $opening['open_days'] > 0 ? round( $total / $opening['open_days'], 1 ) : null,
			'avg_per_hour' => $opening['open_hours'] > 0 ? round( $total / $opening['open_hours'], 2 ) : null,
			'first_hour'   => $opening['first_hour'],
			'last_hour'    => $opening['last_hour'],
		);
	}

	/**
	 * Hour (0-23) a booking starts, read the same way the list shows it.
	 *
	 * The list prints a stored timestamp with gmdate(), so this does too. Older
	 * bookings may hold a clock time like "7:00 PM" instead.
	 *
	 * @param string $date       Booking date, `Y-m-d`.
	 * @param string $start_time Stored start time.
	 * @return int|null Null when the time cannot be read.
	 */
	private function overview_booking_hour( string $date, string $start_time ) {
		if ( '' === $start_time ) {
			return null;
		}

		$timestamp = ( ctype_digit( $start_time ) && strlen( $start_time ) >= 10 )
			? (int) $start_time
			: strtotime( $date . ' ' . $start_time . ' UTC' );

		return false === $timestamp ? null : (int) gmdate( 'G', $timestamp );
	}

	/**
	 * Permission check for the Overview page. Staff only, plus a nonce.
	 *
	 * Not open to the "own reservations" cap: every customer has it, and these
	 * numbers cover all bookings.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function get_overview_permissions_check( $request ) {
		if ( ! $this->can_view_all_reservations() ) {
			return new WP_Error( 'wpcafe_forbidden', __( 'You do not have permission to view reservations.', 'wp-cafe' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! $this->verify_rest_nonce( $request ) ) {
			return new WP_Error( 'wpcafe_invalid_nonce', __( 'Invalid nonce.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		return true;
	}

    /**
     * Get a single reservation item
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response|WP_Error
     */
    public function get_item($request) {
        $id = intval( $request['id'] );

        $reservation = Reservation_Model::find($id);

        if ( ! $reservation ) {
            return $this->error(__('Reservation not found', 'wp-cafe'), 404);
        }

		$response = new Reservation_Resource( $reservation );

		if ( $this->view_own_reservations_only() ) {
			if ( ! $this->current_user_owns_reservation( $id ) ) {
                return $this->error( __( 'You do not have permission to view this reservation.', 'wp-cafe' ), 403 );
            }

			return $this->response( $this->own_reservation_data( $response->resolve() ) );
		}

        return $this->response( $response );
    }

    /**
     * Permission check for getting a single reservation
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function get_item_permissions_check($request): bool {
        return $this->can_view_reservations();
    }

    /**
     * Update reservation.
     */
    public function update_item($request) {
        $id = intval($request['id']);
        $reservation = Reservation_Model::find($id);

        if ( ! $reservation ) {
            return $this->error( __('Reservation not found.', 'wp-cafe'), 404 );
        }

        $data = $this->prepare_item_for_database($request);

        if ( is_wp_error( $data ) ) {
            return $this->error($data->get_error_message());
        }

        $data = Member_Discount::reprice( $reservation, $data );

		// Only the server writes these. Kept as booked unless the seats or location change.
		unset( $data['area_id'], $data['table_ids'], $data['table_snapshot'], $data['table_assigned_by'] );

        /*
         * Same rule as create: a status only ever comes from our own list. An
         * unknown value is refused rather than defaulted, so a bad request
         * cannot quietly move a confirmed booking back to pending.
         */
        if ( isset( $data['status'] ) ) {
            $clean_status = Reservation_Model::sanitize_status( $data['status'], '' );

            if ( '' === $clean_status ) {
                return $this->error( __( 'Invalid reservation status.', 'wp-cafe' ), 400 );
            }

            $data['status'] = $clean_status;
        }

        // Same lock as create: the slot check and the save must not interleave.
        $slot_lock = Reservation_Model::lock_booking_slot( $data['branch_id'] ?? $reservation->branch_id );

        if ( is_wp_error( $slot_lock ) ) {
            return $this->error( $slot_lock->get_error_message(), 503, $slot_lock->get_error_code() );
        }

        $slot_check = $this->validate_edited_slot( $reservation, $data );

        if ( is_wp_error( $slot_check ) ) {
            Reservation_Model::unlock_booking_slot( $slot_lock );
            $status = (int) ( $slot_check->get_error_data()['status'] ?? 422 );
            return $this->error( $slot_check->get_error_message(), $status, $slot_check->get_error_code(), $slot_check->get_error_data() );
        }

		$seats_before = is_array( $reservation->seats ) ? $reservation->seats : array();
		$seats_after  = isset( $data['seats'] ) && is_array( $data['seats'] ) ? $data['seats'] : $seats_before;
		$branch_after = $data['branch_id'] ?? $reservation->branch_id;
		sort( $seats_before );
		sort( $seats_after );

		if ( $seats_after !== $seats_before || (int) $branch_after !== (int) $reservation->branch_id ) {
			$data = array_merge( $data, Reservation_Model::table_snapshot_for_seats( $seats_after, $branch_after ) );
			// Only staff edit bookings, so a new table is one they gave; none means it waits again.
			$data['table_assigned_by'] = $seats_after ? 'staff' : '';
		}

        $old_status = $reservation->status;
        $old_reservation_data = [
            'name'          => $reservation->name,
            'email'         => $reservation->email,
            'phone'         => $reservation->phone,
            'date'          => $reservation->date,
            'start_time'    => $reservation->start_time,
            'end_time'      => $reservation->end_time,
            'total_guest'   => $reservation->total_guest,
            'table_name'    => $reservation->table_name,
            'branch_id'     => $reservation->branch_id,
            'branch_name'   => $reservation->branch_name,
            'status'        => $reservation->status,
            'notes'         => $reservation->notes,
            'booking_amount'=> $reservation->booking_amount,
            'total_price'   => $reservation->total_price,
            'currency'      => $reservation->currency,
            'payment_method'=> $reservation->payment_method,
            'food_order'    => $reservation->food_order,
            'invoice'       => $reservation->invoice,
        ];
        $updated = $reservation->update($data);
        Reservation_Model::unlock_booking_slot( $slot_lock );

        if ( ! $updated ) {
            return $this->error( __('Failed to update reservation.', 'wp-cafe'), 500 );
        }

        // Trigger cancellation hook if status changed to 'cancelled'
        if ( 'cancelled' !== $old_status  &&  'cancelled' === $reservation->status ) {
            do_action( 'wpcafe_after_reservation_cancelled', $reservation );
        }

        // Trigger status change hook for non-cancelled status transitions
        if ( isset( $data['status'] ) && $old_status !== $reservation->status && 'cancelled' !== $reservation->status ) {
            do_action( 'wpcafe_after_reservation_status_changed', $reservation, $old_status );
        }

        // Trigger updated hook only when non-status fields have actually changed
        if ( $this->has_reservation_field_changes( $old_reservation_data, $data ) ) {
            do_action( 'wpcafe_after_reservation_update', $reservation, $old_reservation_data );
        }

        $response = new Reservation_Resource( $reservation );

        return $this->response( $response, __('Reservation updated successfully.', 'wp-cafe') );
    }

    /**
     * Run the create-time capacity and seat checks again for an edit that needs them.
     *
     * Only a booking that holds its time (status in "Statuses that block the time
     * slots") is checked, and only when the edit moves it (date, time, guests,
     * seats, location) or turns it into a holding status. A note-only edit is never
     * refused, so old bookings stay editable. Capacity is checked only for a move.
     *
     * @param Reservation_Model $reservation Booking before the edit.
     * @param array             $data        Prepared edit data.
     * @return true|WP_Error
     */
    private function validate_edited_slot( Reservation_Model $reservation, array $data ) {
        $after = [];
        foreach ( [ 'date', 'start_time', 'end_time', 'total_guest', 'branch_id', 'seats', 'status' ] as $key ) {
            $after[ $key ] = array_key_exists( $key, $data ) ? $data[ $key ] : $reservation->$key;
        }

        $blocking = Reservation_Model::seat_blocking_statuses();
        if ( ! in_array( $after['status'], $blocking, true ) ) {
            return true;
        }

        $seats_before = is_array( $reservation->seats ) ? $reservation->seats : [];
        $seats_after  = is_array( $after['seats'] ) ? $after['seats'] : [];
        sort( $seats_before );
        sort( $seats_after );

        $moved = (string) $after['date'] !== (string) $reservation->date
            || (int) $after['start_time'] !== (int) $reservation->start_time
            || (int) $after['end_time'] !== (int) $reservation->end_time
            || (int) $after['total_guest'] !== (int) $reservation->total_guest
            || (int) $after['branch_id'] !== (int) $reservation->branch_id
            || $seats_after !== $seats_before;

        $starts_holding = ! in_array( $reservation->status, $blocking, true );

        if ( ! $moved && ! $starts_holding ) {
            return true;
        }

		// Only when these change, so an old booking can still be moved to another day.
		if ( (int) $after['total_guest'] !== (int) $reservation->total_guest
			|| (int) $after['branch_id'] !== (int) $reservation->branch_id
			|| $seats_after !== $seats_before ) {
			$selection = Reservation_Model::validate_seat_selection( $seats_after, $after['total_guest'], $after['branch_id'], $this->can_manage_reservations(), $after['date'] );

			if ( is_wp_error( $selection ) ) {
				return $selection;
			}
		}

        if ( $moved ) {
            $capacity = Reservation_Model::validate_guest_capacity( $after['total_guest'], $after['date'], $after['start_time'], $after['end_time'], $after['branch_id'], (int) $reservation->id );

            if ( is_wp_error( $capacity ) ) {
                return $capacity;
            }
        }

        return Reservation_Model::validate_seat_availability( $seats_after, $after['date'], $after['start_time'], $after['end_time'], $after['branch_id'], (int) $reservation->id );
    }

    /**
     * Permission check for updating a reservation
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function update_item_permissions_check($request): bool {
        return $this->can_manage_reservations();
    }

    /**
     * Delete reservation.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function delete_item($request) {
        if ( ! $this->verify_rest_nonce( $request ) ) {
            return $this->error( __('Invalid security token', 'wp-cafe'), 403, 'invalid_nonce' );
        }

        $id = intval( $request['id'] );
        $reservation = Reservation_Model::find($id);

        if ( ! $reservation ) {
            return $this->error( __('Reservation not found.', 'wp-cafe'), 404 );
        }

        $deleted = $reservation->delete();

        if ( ! $deleted ) {
            return $this->error( __('Failed to delete reservation.', 'wp-cafe'), 500 );
        }

        do_action('wpcafe_after_reservation_delete', $deleted );

        return $this->response( ['deleted' => true], __('Reservation deleted.', 'wp-cafe') );
    }

    /**
     * Permission check for deleting a reservation
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function delete_item_permissions_check( $request ): bool {
        return $this->can_manage_reservations();
    }

    /**
     * Bulk delete reservations.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response
     */
    public function bulk_delete_item($request) {
        if ( ! $this->verify_rest_nonce( $request ) ) {
            return $this->error( __('Invalid security token', 'wp-cafe'), 403, 'invalid_nonce' );
        }

        $ids = $request->get_param('ids');

        if ( ! is_array( $ids ) || empty( $ids )) {
            return $this->error(__('Invalid or empty reservation IDs.', 'wp-cafe'), 400);
        }

        $deleted = [];

        foreach ( $ids as $id ) {
            $id = intval($id);
            $reservation = Reservation_Model::find( $id );

            if ( $reservation ) {
                $deleted_item = $reservation->delete(); // Skip if reservation not found

                do_action('wpcafe_after_reservation_delete', $deleted_item);

                $deleted[] = $deleted_item;
            }

        }

        return $this->response( ['deleted' => $deleted], __( 'Selected reservations deleted.', 'wp-cafe' ) );
    }

    /**
     * Prepare item for database storage
     *
     * @param \WP_REST_Request $request
     * @return array|WP_Error
     */
    protected function prepare_item_for_database( $request ) {
        $body = $request->get_body();
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return [];
        }

        $has_date       = ! empty( $data['date'] );
        $has_start_time = ! empty( $data['start_time'] );
        $has_end_time   = ! empty( $data['end_time'] );

        if ( $has_date && ( $has_start_time || $has_end_time ) ) {
            $date = $data['date'];

            if ( $has_start_time ) {
                $data['start_time'] = $this->parse_time_to_timestamp( $date, $data['start_time'] );
            }
            if ( $has_end_time ) {
                $data['end_time'] = $this->parse_time_to_timestamp( $date, $data['end_time'] );
            }
			if ( $has_start_time && $has_end_time ) {
				$data['end_time'] = Reservation_Model::end_time_after_start( $data['start_time'], $data['end_time'] );
			}
        }

        if ( ! $has_date ) {
            unset( $data['date'] );
        }
        if ( ! $has_start_time ) {
            unset( $data['start_time'] );
        }
        if ( ! $has_end_time ) {
            unset( $data['end_time'] );
        }

        if ( empty( $data['table_name'] ) ) {
            $table_id_from_session = wpc_get_table_id_from_session();
            if ( ! empty( $table_id_from_session ) ) {
                $data['table_name'] = $table_id_from_session;
            }
        }

        // Sanitize user-supplied fields before validation and storage.
        if ( isset( $data['name'] ) ) {
            $data['name'] = sanitize_text_field( $data['name'] );
        }
        if ( isset( $data['phone'] ) ) {
            $data['phone'] = sanitize_text_field( $data['phone'] );
        }
        if ( isset( $data['email'] ) ) {
            $data['email'] = sanitize_email( $data['email'] );
        }
        if ( isset( $data['notes'] ) ) {
            $data['notes'] = sanitize_textarea_field( $data['notes'] );
        }
        if ( isset( $data['table_name'] ) ) {
            $data['table_name'] = sanitize_text_field( $data['table_name'] );
        }
        if ( isset( $data['date'] ) ) {
            $data['date'] = sanitize_text_field( $data['date'] );
        }
        if ( isset( $data['branch_name'] ) ) {
            $data['branch_name'] = sanitize_text_field( $data['branch_name'] );
        }
        if ( isset( $data['status'] ) ) {
            $data['status'] = sanitize_text_field( $data['status'] );
        }
        if ( isset( $data['total_guest'] ) ) {
            $data['total_guest'] = intval( $data['total_guest'] );
        }
        if ( isset( $data['branch_id'] ) ) {
            $data['branch_id'] = absint( $data['branch_id'] );
        }
        if ( isset( $data['booking_amount'] ) ) {
            $data['booking_amount'] = floatval( $data['booking_amount'] );
        }
        if ( isset( $data['total_price'] ) ) {
            $data['total_price'] = floatval( $data['total_price'] );
        }
        /*
         * Clean up the deposit fields the form sends. We do not rely on these
         * values — apply_partial_payment() works them out again — but we still
         * sanitize them so anything stored is a clean number, yes/no, or a
         * known payment amount type.
         */
        if ( isset( $data['deposit_value'] ) ) {
            $data['deposit_value'] = floatval( $data['deposit_value'] );
        }
        if ( isset( $data['remaining_amount'] ) ) {
            $data['remaining_amount'] = floatval( $data['remaining_amount'] );
        }
        if ( isset( $data['is_partial_payment'] ) ) {
            $data['is_partial_payment'] = $data['is_partial_payment'] ? 'yes' : 'no';
        }
        if ( isset( $data['payment_amount_type'] ) ) {
            $data['payment_amount_type'] = in_array( $data['payment_amount_type'], [ 'full', 'deposit' ], true ) ? $data['payment_amount_type'] : 'deposit';
        }
        if ( isset( $data['seats'] ) && is_array( $data['seats'] ) ) {
            $data['seats'] = array_values( array_filter( array_map(
                'sanitize_text_field',
                array_map( 'wp_unslash', $data['seats'] )
            ) ) );
        }

        $validate = wpcafe_validate( $data , [
            'name' => [
                'required',
                'string',
            ],
            'email' => [
                'required',
                'email',
            ],
        ]);



        if ( is_wp_error( $validate ) ) {
            return $validate;
        }

        $data = $this->separate_custom_fields_from_data( $data );

        return $data;
    }

    /**
     * Separates custom fields from reservation data.
     *
     * Extracts fields that are defined in form customization settings
     * but are not part of the Reservation_Model into a separate
     * custom_fields array.
     *
     * @param array $data The reservation data from request.
     *
     * @return array Modified data with custom_fields key added.
     */
    private function separate_custom_fields_from_data( array $data ): array {
        $fillable_keys = $this->get_fillable_keys(); // Get fillable keys from Reservation_Model
        $custom_field_types = $this->get_custom_field_types(); // Get custom field ID => type map

        $custom_fields = [];

        foreach ( array_keys( $data ) as $original_key ) {
            $key = (string) $original_key;

            if ( in_array( $key, $fillable_keys, true ) ) {
                continue;
            }

            if ( isset( $custom_field_types[ $key ] ) ) {
                $field_type = $custom_field_types[ $key ];
                $custom_fields[ $key ] = $this->sanitize_custom_field_value( $data[ $original_key ], $field_type );
            }

            unset( $data[ $original_key ] );
        }

        if ( ! empty( $custom_fields ) ) {
            $data['custom_fields'] = $custom_fields;
        }

        return $data;
    }


    /**
     * Checks if a key represents a custom field.
     *
     * A field is considered custom if it's not a core fillable field
     * but exists in the form customization settings.
     *
     * @param string $key              The field key to check.
     * @param array  $fillable_keys    Core model fillable keys.
     * @param array  $custom_field_ids Custom field IDs from settings.
     *
     * @return bool True if the key is a custom field.
     */
    private function is_custom_field( string $key, array $fillable_keys, array $custom_field_ids ): bool {
        // If it's a core fillable field, it's not a custom field
        if ( in_array( $key, $fillable_keys, true ) ) {
            return false;
        }

        // Check if it exists in custom field definitions
        return in_array( $key, $custom_field_ids, true );
    }

    /**
     * Sanitizes a custom field value based on its type.
     *
     * @param mixed  $value The field value to sanitize.
     * @param string $type  The field type (text, select, textarea, radio, checkbox).
     *
     * @return mixed Sanitized value.
     */
    private function sanitize_custom_field_value( $value, string $type ) {
        switch ( $type ) {
            case 'textarea':
                return is_string( $value ) ? sanitize_textarea_field( $value ) : '';

            case 'checkbox':
                return is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : [];

            case 'text':
            case 'select':
            case 'radio':
            default:
                return is_string( $value ) ? sanitize_text_field( $value ) : sanitize_text_field( (string) $value );
        }
    }

    /**
     * Gets fillable keys from the Reservation Model.
     *
     * @return array Array of fillable key names.
     */
    private function get_fillable_keys(): array {
        $model = new Reservation_Model();
        return $model->get_fillable_keys();
    }

    /**
     * Gets custom field IDs from customization settings.
     *
     * @return array Array of custom field IDs.
     */
    private function get_custom_field_ids(): array {
        return array_keys( $this->get_custom_field_types() );
    }

    /**
     * Gets custom field ID-to-type map from customization settings.
     *
     * Extracts all field IDs and their types defined in the reservation
     * form customization settings across all steps.
     *
     * @return array Associative array of field ID => field type.
     */
    private function get_custom_field_types(): array {
        $custom_field_types = [];
        $customization_settings = wpc_get_option( 'reservation_form_customization', [] );

        if ( empty( $customization_settings ) ) {
            return $custom_field_types;
        }

        foreach ( $customization_settings as $step ) {
            if ( empty( $step['fields'] ) ) {
                continue;
            }

            foreach ( $step['fields'] as $field ) {
                if ( ! empty( $field['id'] ) ) {
                    $custom_field_types[ $field['id'] ] = $field['type'] ?? 'text';
                }
            }
        }

        return $custom_field_types;
    }

    /**
     * Return the WooCommerce cart items that count towards this reservation.
     *
     * When the reservation form shows a food menu, the whole cart is treated as
     * food for the booking — we do not try to separate items the customer added
     * while shopping from items they picked in the form. They check out in one
     * go anyway, so splitting them only created cases to handle (merged lines,
     * items added in another tab, carts built on an earlier visit).
     *
     * @return array Map of cart_item_key => WC cart item.
     */
    private function get_reservation_food_cart_items(): array {
        // Bail before touching WC(): on sites without WooCommerce active the
        // WC() function is undefined and calling it fatals the whole request.
        if ( ! function_exists( 'WC' ) || ! class_exists( 'WooCommerce' ) ) {
            return [];
        }

        if ( function_exists( 'wc_load_cart' ) && is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return [];
        }

        return WC()->cart->get_cart();
    }

    /**
     * Sum the reservation food currently in the cart.
     *
     * Uses the addon-inclusive line total so the figure matches what checkout
     * actually charges (Optiontics adds its addon prices during calculate_totals,
     * so we recalculate first, then read each line's `line_subtotal`). Falling
     * back to base price * quantity only if a line has no computed subtotal.
     *
     * @return float Line total of the reservation food items.
     */
    private function get_reservation_food_cart_subtotal(): float {
        if ( ! function_exists( 'WC' ) || ! class_exists( 'WooCommerce' ) ) {
            return 0.0;
        }

        // Make sure addon prices are baked into each line before reading it.
        if ( WC()->cart ) {
            WC()->cart->calculate_totals();
        }

        $subtotal = 0.0;

        foreach ( $this->get_reservation_food_cart_items() as $cart_item ) {
            if ( isset( $cart_item['line_subtotal'] ) ) {
                $subtotal += (float) $cart_item['line_subtotal'];
                continue;
            }

            $product = $cart_item['data'] ?? null;
            if ( $product instanceof \WC_Product ) {
                $subtotal += (float) $product->get_price() * (int) $cart_item['quantity'];
            }
        }

        return $subtotal;
    }

    /**
     * REST: live food total, used by the booking form to show a running
     * "reservation + food" total and the deposit that follows from it.
     *
     * Reports the whole cart — see get_reservation_food_cart_items().
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function get_reservation_cart_total( $request ) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'cart' );

        return $this->response( [
            'food_subtotal' => $this->get_reservation_food_cart_subtotal(),
            'food_count'    => count( $this->get_reservation_food_cart_items() ),
        ] );
    }

    /**
     * Create food items from woocommerce cart items
     *
     * @param int $reservation_id
     *
     * @return array Array of Reservation_Item_Model instances
     */
    public function create_food_items_from_woocart( $reservation_id ) {
        $reservation_items = [];

        foreach ( $this->get_reservation_food_cart_items() as $cart_item ) {
            $product = $cart_item['data'];

            if ( ! ( $product instanceof \WC_Product ) ) {
                continue;
            }

            // WPML/WCML: store the original-language product ID so reservation
            // records and reports stay consistent no matter which language the
            // customer ordered in. No-op when WPML is inactive.
            $reservation_product_id = $cart_item['product_id'];
            if ( class_exists( '\WpCafePro\Wpml\Wpc_Pro_Wpml' ) ) {
                $reservation_product_id = \WpCafePro\Wpml\Wpc_Pro_Wpml::original_product_id( $reservation_product_id );
            }

            // Create reservation item data
            $item = [
                'reservation_id' => $reservation_id,
                'product_id'     => $reservation_product_id,
                'product_name'   => $product->get_name(),
                'quantity'       => $cart_item['quantity'],
                'price'          => $product->get_price(),
            ];

            $reservation_item = Reservation_Item_Model::create( $item );

            if ( ! is_wp_error( $reservation_item ) ) {
                $reservation_items[] = $reservation_item;
            }
        }

        return $reservation_items;
    }

    /**
     * Get time slots
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function get_slots($request) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'slots' );

        $start_date     = $request->get_param('start_date');
        $end_date       = $request->get_param('end_date');
        $location_id    = $request->get_param('location_id') ?? null;
        $schedules      = wpc_get_reservation_schedule( $location_id );
        $total_capacity = wpc_get_reservation_capacity( $location_id );

        if ( ! $start_date ) {
            return $this->error( __( 'Please start date', 'wp-cafe' ) );
        }

        if ( ! $end_date ) {
            return $this->error( __( 'Please end date', 'wp-cafe' ) );
        }

        if ( ! $schedules ) {
            return $this->error(__('Schedules did not set', 'wp-cafe'), 409);
        }

        // Get reservation settings for date range validation
        $reservation_advanced = wpc_get_reservation_advanced( $location_id );
        $early_booking_time  = wpc_get_reservation_early_booking_time( $location_id );

        // Validate and adjust date range based on settings
        $date_range = $this->validate_and_adjust_date_range(
            $start_date,
            $end_date,
            $reservation_advanced,
            $early_booking_time
        );

        // If no valid dates in range, return empty response
        if ( empty( $date_range ) ) {
            return $this->response( [] );
        }

        $scheduler = new Scheduler(
            $schedules,
            $date_range['start_date'],
            $date_range['end_date'],
            $total_capacity,
            $location_id
        );

		$slots = $scheduler->generate();

        return $this->response($slots);
    }

    /**
     * Validate and adjust date range based on reservation settings
     *
     * @param string $start_date Requested start date (Y-m-d)
     * @param string $end_date Requested end date (Y-m-d)
     * @param array $reservation_advanced Minimum lead time setting
     * @param string|array $early_booking_time Maximum booking horizon setting
     * @return array Adjusted date range or empty array if no valid dates
     */
    private function validate_and_adjust_date_range( $start_date, $end_date, $reservation_advanced, $early_booking_time ) {
        $timezone = wp_timezone();
        $now = new \DateTime( 'now', $timezone );
        $now->setTime( 0, 0, 0 );

        $start = new \DateTime( $start_date, $timezone );
        $end   = new \DateTime( $end_date, $timezone );

        // Calculate minimum allowed datetime based on reservation_advanced
        $minimum_datetime = $this->calculate_minimum_booking_datetime( $reservation_advanced, $now );

        // Adjust start_date if before minimum allowed
        if ( $start < $minimum_datetime ) {
            $start = clone $minimum_datetime;
        }

        // Calculate maximum allowed date based on early_booking_time
        $maximum_date = $this->calculate_maximum_booking_date( $early_booking_time, $now );

        // Adjust end_date if after maximum allowed
        if ( $end > $maximum_date ) {
            $end = clone $maximum_date;
        }

        // Ensure start is before end after adjustments
        if ( $start >= $end ) {
            return [];
        }

        return [
            'start_date' => $start->format( 'Y-m-d' ),
            'end_date'   => $end->format( 'Y-m-d' ),
        ];
    }

    /**
     * Calculate minimum booking datetime from advance reservation setting
     *
     * @param array $reservation_advanced Advance reservation setting
     * @param DateTime $now Current datetime (start of day in WP timezone)
     * @return DateTime Minimum allowed booking datetime
     */
    private function calculate_minimum_booking_datetime( $reservation_advanced, $now ) {
        $minimum = clone $now;

        if ( empty( $reservation_advanced ) || ! is_array( $reservation_advanced ) ) {
            return $minimum;
        }

        $value = intval( $reservation_advanced['value'] ?? 0 );
        $unit  = $reservation_advanced['unit'] ?? 'minutes';

        switch ( $unit ) {
            case 'minutes':
                $minimum->add( new \DateInterval( "PT{$value}M" ) );
                break;
            case 'hours':
                $minimum->add( new \DateInterval( "PT{$value}H" ) );
                break;
            case 'days':
                $minimum->add( new \DateInterval( "P{$value}D" ) );
                break;
        }

        return $minimum;
    }

    /**
     * Calculate maximum booking date from early booking time limit
     *
     * @param string|array $early_booking_time Early booking time setting
     * @param DateTime $now Current datetime (start of day in WP timezone)
     * @return DateTime Maximum allowed booking date
     */
    private function calculate_maximum_booking_date( $early_booking_time, $now ) {
        $maximum = clone $now;

        // "any_time" means no restriction - use 1 year ahead as practical limit
        if ( $early_booking_time === 'any_time' || empty( $early_booking_time ) ) {
            $maximum->add( new \DateInterval( "P1Y" ) );
            return $maximum;
        }

        if ( ! is_array( $early_booking_time ) ) {
            return $maximum;
        }

        $value = intval( $early_booking_time['value'] ?? 0 );
        $unit  = $early_booking_time['unit'] ?? 'days';

        switch ( $unit ) {
            case 'days':
                $maximum->add( new \DateInterval( "P{$value}D" ) );
                break;
            case 'weeks':
                $maximum->add( new \DateInterval( "P" . ( $value * 7 ) . "D" ) );
                break;
            case 'months':
                $maximum->add( new \DateInterval( "P{$value}M" ) );
                break;
        }

        return $maximum;
    }

    /**
     * Object-cache group for the rate-limit counters.
     */
    private const RATE_GROUP = 'wpcafe_rate';

    /**
     * Storage key holding this caller's request count.
     *
     * Each endpoint passes its own bucket so one route can't drain another's
     * allowance. The booking form fires several read routes back-to-back while
     * the guest fills it in (slots, capacity, food list, cart total); a shared
     * counter would let that normal traffic trip a 429 on a legitimate user.
     *
     * @param string $bucket Counter name — the endpoint's own bucket.
     * @return string
     */
    private function rate_limit_key( string $bucket = '' ): string {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        return 'wpc_rate_' . ( '' !== $bucket ? $bucket . '_' : '' ) . md5( $ip );
    }

    /**
     * Current request count for this caller's bucket.
     *
     * Reads from whichever store record_rate_limit_hit() writes to, so the two
     * always agree: the persistent object cache when one is present, the options
     * table (transient) otherwise.
     *
     * @param string $bucket Counter name, see rate_limit_key().
     * @return int
     */
    private function rate_limit_count( string $bucket = '' ): int {
        $key = $this->rate_limit_key( $bucket );

        if ( wp_using_ext_object_cache() ) {
            $count = wp_cache_get( $key, self::RATE_GROUP );
            return false === $count ? 0 : (int) $count;
        }

        return (int) get_transient( $key );
    }

    /**
     * Has this caller used up its allowance?
     *
     * Read-only on purpose. WordPress runs a permission callback twice per
     * request — once to authorize it, then again from rest_send_allow_header()
     * to build the Allow header — so counting here would spend two of the
     * caller's requests for every one they make. The route handler records the
     * hit instead, once it is actually going to do the work.
     *
     * @param int    $limit  Max requests allowed within the window.
     * @param string $bucket Counter name, see rate_limit_key().
     * @return bool
     */
    private function rate_limit_exceeded( int $limit = 30, string $bucket = '' ): bool {
        return $this->rate_limit_count( $bucket ) >= $limit;
    }

    /**
     * Count one request against this caller's allowance.
     *
     * With a persistent object cache (Redis/Memcached) the increment is atomic,
     * so two requests arriving together can't both read N and both write N+1 and
     * lose a hit. Without one we fall back to a read-modify-write transient,
     * which is not atomic — a concurrent burst can slip a few extra requests
     * past the cap. That is an accepted limit of a DB-backed throttle: it stops
     * naive floods, it is not the authorization boundary. Reads are harmless and
     * every write route is still cap-gated on top of this.
     *
     * @param int    $window Time window in seconds.
     * @param string $bucket Counter name, see rate_limit_key().
     * @return int The count after this hit.
     */
    private function record_rate_limit_hit( int $window = 60, string $bucket = '' ): int {
        $key = $this->rate_limit_key( $bucket );

        if ( wp_using_ext_object_cache() ) {
            // Seed the key without clobbering an existing count, then bump it
            // atomically. wp_cache_incr() returns false only on a missing key,
            // which the add() above rules out on the normal path.
            wp_cache_add( $key, 0, self::RATE_GROUP, $window );
            $count = wp_cache_incr( $key, 1, self::RATE_GROUP );
            if ( false !== $count ) {
                return (int) $count;
            }
        }

        $count = (int) get_transient( $key ) + 1;
        set_transient( $key, $count, $window );
        return $count;
    }

    /**
     * Permission check for getting time slots
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function get_slots_permissions_check($request) {
        if ( $this->rate_limit_exceeded( 60, 'slots' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }
        return true;
    }

    /**
     * Future bookings on the given seats, so the table editor can warn before they are removed.
     *
     * @param \WP_REST_Request $request Takes `seats` (comma list) and `branch_id`.
     * @return WP_HTTP_Response
     */
    public function get_seat_bookings( $request ) {
        $seats = array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $request->get_param( 'seats' ) ) ) );

        return $this->response(
            [
                'bookings' => Reservation_Model::future_bookings_for_seats( $seats, absint( $request->get_param( 'branch_id' ) ) ),
            ]
        );
    }

    /**
     * Get reservation capacity
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function get_reservation_capacity($request) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'capacity' );

        $date       = $request->get_param('date');
        $start_time = $request->get_param('start_time');
        $end_time   = $request->get_param('end_time');
        $branch_id  = $request->get_param('branch_id');

        if ( $branch_id === "undefined" || $branch_id === "null" ) {
            $branch_id = null;
        }

        if ( empty( $date ) ) {
            return $this->error( __( 'Please enter date', 'wp-cafe' ) );
        }

        if ( empty( $start_time ) ) {
            return $this->error( __( 'Please enter start time', 'wp-cafe' ) );
        }

        if ( empty( $end_time ) ) {
            return $this->error( __( 'Please enter end time', 'wp-cafe' ) );
        }

        $booked_capacity = Reservation_Model::get_total_guest_by_date_time($date, $start_time, $end_time, $branch_id );

        $total_capacity = wpc_get_reservation_capacity( $branch_id );

        $available_capacity = $total_capacity - $booked_capacity;

        // Get booked seat IDs for seat-plan integration
        $booked_seat_ids = $this->get_booked_seats( $date, $start_time, $end_time, $branch_id );

        return $this->response([
            'available_capacity' => $available_capacity,
            'booked_capacity'    => $booked_capacity,
            'total_capacity'     => $total_capacity,
            'booked_seat_ids'    => $booked_seat_ids,
        ]);
    }

    /**
     * Permission check for getting reservation capacity
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error
     */
    public function get_reservation_capacity_permissions_check($request) {
        if ( $this->rate_limit_exceeded( 60, 'capacity' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }
        return true;
    }

	/**
	 * The current pause, for the dashboard.
	 *
	 * @return WP_HTTP_Response
	 */
	public function get_pause() {
		return $this->response( Reservation_Pause::admin_state() );
	}

	/**
	 * Staff who can see reservations can see whether they are paused.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function get_pause_permissions_check( $request ) {
		if ( ! $this->can_view_all_reservations() ) {
			return new WP_Error( 'wpcafe_forbidden', __( 'You do not have permission to view reservation availability.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		if ( ! $this->verify_rest_nonce( $request ) ) {
			return new WP_Error( 'wpcafe_invalid_nonce', __( 'Invalid nonce.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Stop taking new bookings, replacing any pause set before. Existing
	 * bookings are not touched.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return WP_HTTP_Response|WP_Error
	 */
	public function pause_reservations( $request ) {
		$type      = (string) $request->get_param( 'type' );
		$starts_at = 0;
		$ends_at   = 0;

		if ( Reservation_Pause::TYPE_TEMPORARY === $type ) {
			$start_input = sanitize_text_field( (string) $request->get_param( 'starts_at' ) );
			$starts_at   = '' === $start_input ? 0 : Reservation_Pause::parse_site_time( $start_input );
			$ends_at     = Reservation_Pause::parse_site_time( sanitize_text_field( (string) $request->get_param( 'ends_at' ) ) );

			if ( false === $starts_at || false === $ends_at ) {
				return new WP_Error( 'wpcafe_invalid_pause_time', __( 'Choose a valid start and end date and time.', 'wp-cafe' ), array( 'status' => 422 ) );
			}
		}

		$entry = Reservation_Pause::replace( (array) $request->get_param( 'location_ids' ), $type, $starts_at, $ends_at );

		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		$response            = Reservation_Pause::admin_state();
		$response['message'] = __( 'Reservations paused.', 'wp-cafe' );

		return $this->response( $response );
	}

	/**
	 * Start taking bookings again everywhere. Reopening when nothing is
	 * paused still succeeds, so a double click or a stale screen is harmless.
	 *
	 * @return WP_HTTP_Response
	 */
	public function resume_reservations() {
		Reservation_Pause::clear();

		$response            = Reservation_Pause::admin_state();
		$response['message'] = __( 'Reservations reopened.', 'wp-cafe' );

		return $this->response( $response );
	}

	/**
	 * Only staff who manage reservations may pause or reopen them.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function manage_pause_permissions_check( $request ) {
		// Cast: a non-WP_Error object returned by a filter would otherwise read as "granted".
		$can = (bool) apply_filters( 'wpcafe_reservation_pause_permission', $this->can_manage_reservations(), $request );

		if ( ! $can ) {
			return new WP_Error( 'wpcafe_forbidden', __( 'You do not have permission to change reservation availability.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		if ( ! $this->verify_rest_nonce( $request ) ) {
			return new WP_Error( 'wpcafe_invalid_nonce', __( 'Invalid nonce.', 'wp-cafe' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Whether the booking form may take bookings for a location. Public: the
	 * form calls it for logged-out visitors.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return WP_HTTP_Response
	 */
	public function get_pause_status( $request ) {
		// Counted here, not in the permission callback — see rate_limit_exceeded().
		$this->record_rate_limit_hit( 60, 'pause_status' );

		return $this->response( Reservation_Pause::public_status( $request->get_param( 'location_id' ) ) );
	}

	/**
	 * Rate limit for the public pause status route.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function get_pause_status_permissions_check( $request ) {
		if ( $this->rate_limit_exceeded( 60, 'pause_status' ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), array( 'status' => 429 ) );
		}
		return true;
	}
    /**
     * Helper method to find reservation by invoice and email
     *
     * @param string $invoice
     * @param string $email
     * @return Reservation_Model|null
     */
    private function find_reservation_by_invoice_and_email($invoice, $email) {
        if (empty($invoice) || empty($email)) {
            return null;
        }

        $cache_key = $invoice . '|' . $email;
        if ( array_key_exists( $cache_key, $this->reservation_lookup_cache ) ) {
            return $this->reservation_lookup_cache[ $cache_key ];
        }

        $args = [
            'post_type' => 'wpc_reservation',
			'post_status' => array( 'confirmed', 'pending', 'cancelled', 'completed', 'refunded' ),
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- required for report/filter functionality
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'invoice',
                    'value' => $invoice,
                    'compare' => '=',
                ],
            ]
        ];

        $posts = get_posts($args);

        $reservation = null;

        if ( ! empty( $posts ) ) {
            $found = new Reservation_Model( $posts[0] );

            // Only a match when the email also lines up.
            if ( $found->email === $email ) {
                $reservation = $found;
            }
        }

        $this->reservation_lookup_cache[ $cache_key ] = $reservation;

        return $reservation;
    }

    /**
     * Cancel reservation
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function cancel_reservation($request) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'cancel' );

        if ( ! $this->verify_rest_nonce( $request ) ) {
            return $this->error( __( 'Security check failed. Please try again.', 'wp-cafe' ), 403 );
        }

        $invoice = sanitize_text_field( $request->get_param('invoice') ?? '' );
        $email   = sanitize_email( $request->get_param('email') ?? '' );
        $notes   = sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' );
        $phone   = sanitize_text_field( $request->get_param('phone') ?? '' );

        if ( empty( $invoice ) ) {
            return $this->error( __( 'Please enter invoice', 'wp-cafe' ) );
        }

        if ( empty( $email ) ) {
            return $this->error( __( 'Please enter email', 'wp-cafe' ) );
        }

        $reservation = $this->find_reservation_by_invoice_and_email($invoice, $email);

        if ( ! $reservation ) {
            return $this->error( __( 'Reservation not found', 'wp-cafe' ) );
        }

        $status = get_post_status( $reservation->id );

        if ( 'cancelled' === $status ) {
            return $this->error( __( 'Reservation already cancelled', 'wp-cafe' ) );
        }

		// A finished or refunded booking is closed; the customer cannot cancel it.
		if ( in_array( $status, array( 'completed', 'refunded' ), true ) ) {
			return $this->error( __( 'This reservation can no longer be cancelled', 'wp-cafe' ) );
		}

		$update = array( 'status' => 'cancelled' );

		// There is one note per booking, so the cancel reason replaces it.
		if ( '' !== $notes ) {
			$update['notes'] = $notes;
		}

        $reservation->update( $update );

        do_action( 'wpcafe_after_reservation_cancelled', $reservation );

        return $this->response( __( 'Reservation cancelled successfully', 'wp-cafe' ) );
    }

    /**
     * Permission check for canceling a reservation.
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error True when the caller owns the booking, WP_Error/false otherwise.
     */
    public function cancel_reservation_permissions_check($request) {
        // Write action — tighter cap than the read lookups.
        if ( $this->rate_limit_exceeded( 20, 'cancel' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }
        if ( ! $this->verify_rest_nonce( $request ) ) {
            return false;
        }
        $invoice     = sanitize_text_field( $request->get_param( 'invoice' ) ?? '' );
        $email       = sanitize_email( $request->get_param( 'email' ) ?? '' );
        $reservation = $this->find_reservation_by_invoice_and_email( $invoice, $email );
        return (bool) $reservation;
    }

    /**
     * Get food list
     *
     * @param \WP_REST_Request $request
     * @return WP_HTTP_Response
     */
    public function get_food_list($request) {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'food' );

        $content = "";

        $branch_id = $request->get_param('branch_id');

        // JS serializes undefined/null as the literal strings "undefined"/"null".
        if ( $branch_id === 'undefined' || $branch_id === 'null' ) {
            $branch_id = null;
        }

        // The food-menu shortcode resolves its location from the session, so set
        // it before rendering — passing a location attribute would be ignored.
        if ( ! empty($branch_id) ) {
            Session::set( 'selected_location', intval($branch_id) );
        }

        if ( wpc_is_module_enable('food_ordering') ) {
            $reservation_date = $this->sanitize_reservation_date( $request->get_param( 'date' ) );

            // Thread the customer's selected reservation date into the timed-product
            // rule evaluators for the duration of this shortcode render, then
            // always reset (even on exception) so the value never leaks.
            if ( class_exists( '\\WpCafePro\\FoodOrder\\TimedProduct\\Timed_Products_Conditions' ) ) {
                \WpCafePro\FoodOrder\TimedProduct\Timed_Products_Conditions::set_reference_date( $reservation_date );
                try {
                    $content = do_shortcode( $this->build_food_menu_shortcode() );
                } finally {
                    \WpCafePro\FoodOrder\TimedProduct\Timed_Products_Conditions::reset_reference_date();
                }
            } else {
                $content = do_shortcode( $this->build_food_menu_shortcode() );
            }
        }

        // No card markup means nothing to show; hand back "" so the React form
        // hides the food-menu field instead of rendering an empty box.
        if ( ! empty($content) && strpos($content, 'wpc-food-menu-item') === false ) {
            $content = "";
        }

        return $this->response($content);
    }

    /**
     * Sanitize and validate a date string received from a REST request param.
     *
     * Returns the value unchanged when it matches Y-m-d, null otherwise.
     * Callers can safely pass the return value to set_reference_date() — that
     * method also validates format, so the double-check is defense-in-depth.
     *
     * @param mixed $date Raw request param value.
     * @return string|null Sanitized Y-m-d string, or null if invalid/empty.
     */
    private function sanitize_reservation_date( $date ): ?string {
        if ( empty( $date ) ) {
            return null;
        }
        $clean = sanitize_text_field( wp_unslash( (string) $date ) );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ? $clean : null;
    }

    /**
     * Get food menu attributes from reservation form customization settings
     *
     * The saved food_menu field stores a `template` (the shortcode tag) plus a few
     * display options. We render that shortcode directly instead of the old
     * `wpc_reservation_with_food` wrapper, which has been removed.
     *
     * @return string Shortcode string, e.g. `[wpc_food_menu_tab style="style-2" ...]`.
     */
    private function build_food_menu_shortcode(): string {
        $fields = $this->get_reservation_food_menu_fields();

        return wpc_food_menu_shortcode( $fields['template'] ?? 'wpc_food_menu_list', [
            'style'               => $fields['style'] ?? 'style-1',
            'wpc_food_categories' => $fields['wpc_food_categories'] ?? '',
            'wpc_show_desc'       => $fields['wpc_show_desc'] ?? 'yes',
            'show_thumbnail'      => $fields['show_thumbnail'] ?? 'yes',
            'wpc_cart_button'     => $fields['wpc_cart_button'] ?? 'yes',
            'no_of_product'       => isset($fields['no_of_product']) ? (int) $fields['no_of_product'] : -1,
        ] );
    }

    /**
     * Pull the saved food_menu field config from the reservation form settings.
     *
     * @return array The `food_menu_fields` array, or [] when no food_menu field is configured.
     */
    private function get_reservation_food_menu_fields(): array {
        return wpc_get_reservation_food_menu_fields();
    }

    /**
     * Permission check for getting food list
     *
     * @return bool|\WP_Error
     */
    public function get_food_list_permissions_check() {
        if ( $this->rate_limit_exceeded( 60, 'food' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }
        return true;
    }

    /**
     * Get booked seat IDs for a specific time slot
     *
     * @param string $date
     * @param string $start_time
     * @param string $end_time
     * @param int $branch_id
     * @return array Array of booked seat IDs
     */
    protected function get_booked_seats( $date, $start_time, $end_time, $branch_id ) {
        return Reservation_Model::get_booked_seats_for_time_slot( $date, $start_time, $end_time, $branch_id );
    }

    /**
     * Check if WooCommerce cart has items
     *
     * @return WP_HTTP_Response
     */
    public function check_cart_has_items() {
        // Counted here, not in the permission callback — see rate_limit_exceeded().
        $this->record_rate_limit_hit( 60, 'cart' );

        // Check if WooCommerce is available
        if ( ! class_exists( 'WooCommerce' ) ) {
            return $this->response( [ 'has_items' => false ] );
        }

        if ( function_exists( 'wc_load_cart' ) && is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( ! WC()->cart ) {
            return $this->response( [ 'has_items' => false ] );
        }

        $has_items = ! WC()->cart->is_empty();

        return $this->response( [ 'has_items' => (bool) $has_items ] );
    }

    /**
     * Permission check for checking cart items
     *
     * @return bool|\WP_Error
     */
    public function check_cart_has_items_permissions_check() {
        // Shared by check_cart_has_items and get_reservation_cart_total.
        if ( $this->rate_limit_exceeded( 60, 'cart' ) ) {
            return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'wp-cafe' ), [ 'status' => 429 ] );
        }
        return true;
    }

    /**
     * Parse time string to timestamp, handling WordPress and standard formats
     *
     * @param string $date Date in various formats (Y-m-d, d/m/Y, m/d/Y, etc.)
     * @param string $time_string Time string in various formats
     * @return int|false Timestamp or false on failure
     */
    private function parse_time_to_timestamp( $date, $time_string ) {
        $normalized_date = $this->normalize_date( $date );
        if ( ! $normalized_date ) {
            return false;
        }

        $wp_time_format = get_option( 'time_format' );
        if ( $wp_time_format ) {
            $datetime = \DateTime::createFromFormat( 'Y-m-d ' . $wp_time_format, $normalized_date . ' ' . $time_string );
            if ( $datetime !== false ) {
                return $datetime->getTimestamp();
            }
        }

        $timestamp = strtotime( $normalized_date . ' ' . $time_string );
        return $timestamp !== false ? $timestamp : false;
    }

    /**
     * Normalize date string to Y-m-d format
     *
     * @param string $date_string Date in various formats
     * @return string|false Normalized date in Y-m-d format or false on failure
     */
    private function normalize_date( $date_string ) {
        $wp_date_format = get_option( 'date_format' );
        if ( $wp_date_format ) {
            $datetime = \DateTime::createFromFormat( $wp_date_format, $date_string );
            if ( $datetime !== false ) {
                return $datetime->format( 'Y-m-d' );
            }
        }

        $timestamp = strtotime( $date_string );
        if ( $timestamp !== false ) {
            return gmdate( 'Y-m-d', $timestamp );
        }

        return false;
    }

    /**
     * Check if any non-status fields have actually changed
     *
     * @param array $old_data Old reservation data
     * @param array $new_data New data being updated
     * @return bool True if any field value is different
     */
    private function has_reservation_field_changes( $old_data, $new_data ) {
        $fields_to_check = ['name', 'email', 'phone', 'date', 'start_time', 'end_time',
            'total_guest', 'table_name', 'branch_id', 'branch_name',
            'notes', 'booking_amount', 'total_price', 'currency',
            'payment_method', 'food_order', 'invoice'];

        foreach ( $fields_to_check as $field ) {
            if ( isset( $new_data[ $field ] ) ) {
                $old_value = $old_data[ $field ] ?? '';
                $new_value = $new_data[ $field ];

                if ( is_array( $new_value ) ) {
                    if ( json_encode( $old_value ) !== json_encode( $new_value ) ) {
                        return true;
                    }
                } elseif ( (string) $old_value !== (string) $new_value ) {
                    return true;
                }
            }
        }

        return false;
    }
}
