<?php
namespace WpCafe\CustomerAccounts;

if ( ! defined( 'ABSPATH' ) ) exit;

use WpCafe\Contracts\Hookable_Service_Contract;
use WpCafe\Models\Reservation_Model;

/**
 * Gives each booking customer a WordPress account, when the admin turns it on.
 *
 * A new email makes an account; a known one is reused. Either way the booking's
 * author becomes that customer, so "my bookings" and integrations find it.
 * A new account made from the public booking form can also be signed in.
 *
 * @package WpCafe/CustomerAccounts
 */
class Booking_Account implements Hookable_Service_Contract {
    const SETTING_CREATE     = 'reservation_create_customer_account';
    const SETTING_AUTO_LOGIN = 'reservation_customer_auto_login';

    /** User meta: the booking that made the account, until its owner sets a password or logs in with one. */
    const META_UNVERIFIED = '_wpcafe_unverified';

	/** Booking meta: hash of the confirm code, while a booking linked to an existing account waits for its owner. */
	const META_LINK_PENDING = '_wpcafe_link_pending';

	/** Query arg of the confirm link sent to the booking email. */
	const CONFIRM_ARG = 'wpcafe_link_booking';

    /** Capabilities of people who book for others; they are never the customer. */
    const STAFF_CAPS = [ 'manage_options', 'manage_woocommerce', 'wpcafe_manage_reservations', 'wpcafe_view_all_reservations' ];

    /**
     * What this request did, by booking id: `created` or `existing`, and whether it signed in.
     * The response says only `linked`, or `created` when it signed in.
     *
     * @var array<int, array{status: string, signed_in: bool}>
     */
    private static $results = [];

    /**
     * Bookings that came through the create route from a signed-out browser.
     *
     * @var int[]
     */
    private static $from_form = [];

    /**
     * The logged-in cookie set during this request; see sign_in().
     *
     * @var string
     */
    private static $logged_in_cookie = '';

    /**
     * @return void
     */
    public function register() {
        add_action( 'wpcafe_reservation_request_saved', [ $this, 'mark_form_booking' ] );
        // Before the booking email and integrations, so they see the linked account.
        add_action( 'wpcafe_after_reservation_create', [ $this, 'link_account' ], 1 );
        add_filter( 'wpcafe_reservation_create_response', [ $this, 'add_to_response' ], 10, 2 );
        add_action( 'after_password_reset', [ $this, 'verify' ] );
        // WooCommerce before 10.9 skips after_password_reset on its own reset form.
        add_action( 'woocommerce_customer_reset_password', [ $this, 'verify' ] );
        // Only a password login fires wp_login; sign_in() below does not.
        add_action( 'wp_login', [ $this, 'verify_on_login' ], 10, 2 );
		// A reset link goes to the account email, so it proves that email; a login does not.
		add_action( 'after_password_reset', array( $this, 'confirm_links_after_reset' ) );
		add_action( 'woocommerce_customer_reset_password', array( $this, 'confirm_links_after_reset' ) );
		add_action( 'template_redirect', array( $this, 'handle_confirm_link' ) );
    }

	/**
	 * Whether this booking is linked to an existing account whose owner has
	 * not confirmed it yet. Until then it is hidden from that account and earns it nothing.
	 *
	 * @param int $reservation_id Booking.
	 * @return bool
	 */
	public static function is_link_pending( int $reservation_id ): bool {
		return $reservation_id > 0 && '' !== (string) get_post_meta( $reservation_id, self::META_LINK_PENDING, true );
	}

	/**
	 * Ids of this account's bookings that still wait for confirmation.
	 *
	 * @param int $user_id Account.
	 * @return int[]
	 */
	public static function pending_link_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'   => 'wpc_reservation',
				'post_status' => 'any',
				'author'      => $user_id,
				'fields'      => 'ids',
				'numberposts' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one account's bookings only.
				'meta_key'    => self::META_LINK_PENDING,
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Anyone can set their account email to a guest's address, so a guest booking
	 * matched to an existing account stays hidden until that owner proves the email.
	 *
	 * @param Reservation_Model $reservation Booking.
	 * @param \WP_User          $account     Account it was linked to.
	 * @return void
	 */
	private function hold_link( $reservation, \WP_User $account ) {
		$reservation_id = (int) $reservation->id;

		try {
			$code = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			$code = '';
		}

		// Without a code the owner can still confirm with a password reset.
		update_post_meta( $reservation_id, self::META_LINK_PENDING, '' !== $code ? hash( 'sha256', $code ) : 'reset-only' );

		if ( '' === $code ) {
			return;
		}

		$url = add_query_arg(
			array(
				self::CONFIRM_ARG => $reservation_id,
				'code'            => $code,
			),
			home_url( '/' )
		);

		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Add your booking to your account', 'wp-cafe' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$message = sprintf(
			/* translators: 1: account username, 2: confirm link. */
			__( "A table booking with this email was added to the account \"%1\$s\".\n\nIf this is your account, log in and open this link to see the booking in My bookings:\n%2\$s\n\nIf it is not your account, ignore this email. Your booking is still valid.", 'wp-cafe' ),
			$account->user_login,
			$url
		);

		wp_mail( (string) $reservation->email, $subject, $message );
	}

	/**
	 * Confirm link from the booking email. The visitor must be logged in as the
	 * linked account: the code proves the email, the login proves the account.
	 *
	 * @return void Redirects or stops with a message.
	 */
	public function handle_confirm_link() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the emailed code is the proof.
		if ( ! isset( $_GET[ self::CONFIRM_ARG ] ) ) {
			return;
		}

		$reservation_id = absint( wp_unslash( $_GET[ self::CONFIRM_ARG ] ) );
		$code           = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_user_logged_in() ) {
			$back = add_query_arg(
				array(
					self::CONFIRM_ARG => $reservation_id,
					'code'            => $code,
				),
				home_url( '/' )
			);
			wp_safe_redirect( wp_login_url( $back ) );
			exit;
		}

		// One message for every failure, so the link does not reveal which part was wrong.
		if ( ! $this->confirm_with_code( $reservation_id, $code, get_current_user_id() ) ) {
			wp_die( esc_html__( 'This link is not valid for your account.', 'wp-cafe' ), '', array( 'response' => 403 ) );
		}

		$target = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Confirm a pending booking when the emailed code matches and the user is its linked account.
	 *
	 * @param int    $reservation_id Booking.
	 * @param string $code           Code from the email link.
	 * @param int    $user_id        Logged-in user.
	 * @return bool Whether it was confirmed.
	 */
	public function confirm_with_code( int $reservation_id, string $code, int $user_id ): bool {
		$stored = (string) get_post_meta( $reservation_id, self::META_LINK_PENDING, true );

		if ( '' === $code || '' === $stored || $user_id <= 0 || (int) get_post_field( 'post_author', $reservation_id ) !== $user_id ) {
			return false;
		}

		if ( ! hash_equals( $stored, hash( 'sha256', $code ) ) ) {
			return false;
		}

		$this->confirm_link( $reservation_id, $user_id );

		return true;
	}

	/**
	 * A password reset proves the account's current email, so it confirms the
	 * pending bookings made with that email. Bookings with another email stay hidden.
	 *
	 * @param \WP_User $user Account.
	 * @return void
	 */
	public function confirm_links_after_reset( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		foreach ( self::pending_link_ids( (int) $user->ID ) as $reservation_id ) {
			if ( 0 === strcasecmp( (string) get_post_meta( $reservation_id, 'email', true ), (string) $user->user_email ) ) {
				$this->confirm_link( $reservation_id, (int) $user->ID );
			}
		}
	}

	/**
	 * Show the booking to its account and tell integrations it is confirmed.
	 *
	 * @param int $reservation_id Booking.
	 * @param int $user_id        Account that proved it.
	 * @return void
	 */
	private function confirm_link( int $reservation_id, int $user_id ) {
		delete_post_meta( $reservation_id, self::META_LINK_PENDING );

		/**
		 * The owner of an existing account proved a guest booking is theirs.
		 *
		 * @param int $reservation_id Booking.
		 * @param int $user_id        Account.
		 */
		do_action( 'wpcafe_booking_link_confirmed', $reservation_id, $user_id );
	}

    /**
     * The booking that made this account while its owner has not proved the
     * email is theirs, else 0. Until then "My bookings" shows only that booking.
     *
     * @param int $user_id Account.
     * @return int
     */
    public static function unverified_booking( int $user_id ): int {
        return $user_id > 0 ? (int) get_user_meta( $user_id, self::META_UNVERIFIED, true ) : 0;
    }

    /**
     * Whether a booking made this account and its owner has not set a password yet.
     *
     * @param int $user_id Account.
     * @return bool
     */
    public static function is_unverified( int $user_id ): bool {
        return self::unverified_booking( $user_id ) > 0;
    }

    /**
     * Setting a password proves the email belongs to this person.
     *
     * @param \WP_User $user Account.
     * @return void
     */
    public function verify( $user ) {
        $user_id        = $user instanceof \WP_User ? (int) $user->ID : 0;
        $reservation_id = self::unverified_booking( $user_id );

        if ( ! $reservation_id ) {
            return;
        }

        delete_user_meta( $user_id, self::META_UNVERIFIED );

        /**
         * A customer whose account a booking made has set a password, so the email is theirs.
         *
         * @param int $user_id        Account.
         * @param int $reservation_id Booking that made the account.
         */
        do_action( 'wpcafe_customer_account_verified', $user_id, $reservation_id );
    }

    /**
     * @param string   $user_login Login name.
     * @param \WP_User $user       Account.
     * @return void
     */
    public function verify_on_login( $user_login, $user ) {
        $this->verify( $user );
    }

    /**
     * Whether the admin turned accounts on.
     *
     * @return bool
     */
    public static function enabled() {
        return wpc_is_option_on( self::SETTING_CREATE, false );
    }

    /**
     * Only the create route fires this, so a booking seen here came from a
     * browser. Waitlist and other paths skip it and are never signed in.
     *
     * @param Reservation_Model $reservation Booking.
     * @return void
     */
    public function mark_form_booking( $reservation ) {
        if ( 0 === get_current_user_id() ) {
            self::$from_form[] = (int) $reservation->id;
        }
    }

    /**
     * Find or make the customer's account and make them the booking's author.
     *
     * @param Reservation_Model $reservation Booking.
     * @return void
     */
    public function link_account( $reservation ) {
        if ( ! self::enabled() || ! $reservation instanceof Reservation_Model ) {
            return;
        }

        $current = wp_get_current_user();

        // A signed-in customer booking for themselves is already the author.
        if ( $current->exists() && ! self::is_staff( $current ) ) {
            return;
        }

        $email = sanitize_email( (string) $reservation->email );

        if ( ! is_email( $email ) ) {
            return;
        }

        $existing = get_user_by( 'email', $email );

        /**
         * Whether a booking may create a WordPress account for a new email. Runs
         * even when "Anyone can register" is off in Settings > General.
         *
         * @param bool              $create      Default true.
         * @param string            $email       Customer email.
         * @param Reservation_Model $reservation Booking.
         */
        if ( ! $existing && ! apply_filters( 'wpcafe_create_customer_account', true, $email, $reservation ) ) {
            return;
        }

        $user_id = $existing ? (int) $existing->ID : $this->create_user( $email, (string) $reservation->name, (int) $reservation->id );

        if ( $user_id <= 0 ) {
            return;
        }

        $this->set_author( (int) $reservation->id, $user_id );

		// Staff booking their own table typed their own email; anyone else may not own it.
		if ( $existing && (int) $existing->ID !== (int) $current->ID ) {
			$this->hold_link( $reservation, $existing );
		}

        $signed_in = false;

        if ( ! $existing ) {
            /**
             * A booking just made this customer's account.
             *
             * @param int               $user_id     New account.
             * @param Reservation_Model $reservation Booking.
             */
            do_action( 'wpcafe_customer_account_created', $user_id, $reservation );

            // Never into an account that already existed: anyone could type its email.
            $signed_in = $this->should_sign_in( (int) $reservation->id, $email ) && $this->sign_in( $user_id );
        }

        self::$results[ (int) $reservation->id ] = [
            'status'    => $existing ? 'existing' : 'created',
            'signed_in' => $signed_in,
        ];
    }

    /**
     * Tell the booking form what happened, and hand a signed-in browser the
     * REST nonce for its new session: the page's nonce belongs to a guest.
     *
     * @param array             $payload     Create response data.
     * @param Reservation_Model $reservation Booking.
     * @return array
     */
    public function add_to_response( $payload, $reservation ) {
        $result = self::$results[ (int) $reservation->id ] ?? null;

        /*
         * With auto login off, new and known emails get the same answer. With it on,
         * a signed-in answer shows the email had no account; a known one never signs in.
         */
        $linked = $result && ! $result['signed_in'];

        $payload['account'] = [
            'status'    => $result ? ( $linked ? 'linked' : 'created' ) : '',
            'signed_in' => $result ? $result['signed_in'] : false,
            'login_url' => $linked ? wp_login_url() : '',
        ];

        if ( $result && $result['signed_in'] ) {
            $payload['nonce'] = wp_create_nonce( 'wp_rest' );
        }

        return $payload;
    }

    /**
     * A customer account with a random password; the email carries the link to set one.
     *
     * Unverified until a password is set: anyone could have typed the email.
     *
     * @param string $email          Customer email.
     * @param string $name           Name on the booking.
     * @param int    $reservation_id Booking that makes it.
     * @return int Account id or 0.
     */
    private function create_user( string $email, string $name, int $reservation_id ) {
        $name    = sanitize_text_field( $name );
        $user_id = wp_insert_user(
            [
                'user_login'   => $this->username_for( $email ),
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 24 ),
                'first_name'   => $name,
                'display_name' => '' !== $name ? $name : $email,
                // Never the site's default role: an altered default must not hand out more rights.
                'role'         => get_role( 'customer' ) ? 'customer' : 'subscriber',
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WPCafe] Booking account not created: ' . $user_id->get_error_message() );
            return 0;
        }

        update_user_meta( (int) $user_id, self::META_UNVERIFIED, $reservation_id );

        return (int) $user_id;
    }

    /**
     * "rahim" from rahim@mail.com, then rahim1, rahim2 while taken.
     *
     * @param string $email Customer email.
     * @return string
     */
    private function username_for( string $email ) {
        $base = sanitize_user( current( explode( '@', $email ) ), true );
        $base = '' !== $base ? $base : 'customer';
        $name = $base;

        for ( $i = 1; username_exists( $name ); $i++ ) {
            $name = $base . $i;
        }

        return $name;
    }

    /**
     * Auto login is on, the booking came from a signed-out browser on the form,
     * and the email has no earlier bookings.
     *
     * @param int    $reservation_id Booking.
     * @param string $email          Customer email.
     * @return bool
     */
    private function should_sign_in( int $reservation_id, string $email ) {
        return wpc_is_option_on( self::SETTING_AUTO_LOGIN, false )
            && in_array( $reservation_id, self::$from_form, true )
            && 0 === get_current_user_id()
            // "My bookings" matches by email, so a session would show that email's
            // earlier guest bookings to whoever typed it. Those owners set a password first.
            && ! self::has_earlier_bookings( $email, $reservation_id );
    }

    /**
     * Whether this email booked before, as a guest, ahead of this booking.
     *
     * @param string $email          Customer email.
     * @param int    $reservation_id This booking.
     * @return bool
     */
    private static function has_earlier_bookings( string $email, int $reservation_id ) {
        $query = new \WP_Query(
            [
                'post_type'      => 'wpc_reservation',
                'post_status'    => 'any',
                'posts_per_page' => 2,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one key, only when a new account would be signed in.
                'meta_query'     => [ [ 'key' => 'email', 'value' => $email ] ],
            ]
        );

        // Two matches are enough to find another booking without an SQL exclusion.
        return ! empty( array_diff( array_map( 'intval', $query->posts ), [ $reservation_id ] ) );
    }

    /**
     * Sign the new customer in for this and later requests.
     *
     * @param int $user_id New account.
     * @return bool
     */
    private function sign_in( int $user_id ) {
        /*
         * wp_create_nonce() reads the session token from the logged-in cookie,
         * which the browser only sends on the next request. Put it in $_COOKIE
         * now, or the nonce returned to the form is invalid.
         */
        $capture = static function ( $cookie ) {
            self::$logged_in_cookie = (string) $cookie;
        };
        add_action( 'set_logged_in_cookie', $capture );

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, false, is_ssl() );

        remove_action( 'set_logged_in_cookie', $capture );

        if ( '' === self::$logged_in_cookie ) {
            return false;
        }

        $_COOKIE[ LOGGED_IN_COOKIE ] = self::$logged_in_cookie;

        return true;
    }

    /**
     * Only the author column changes. wp_update_post() would re-fire the save
     * hooks for a booking created a moment ago, and warns because the booking
     * post type is not registered.
     *
     * @param int $reservation_id Booking.
     * @param int $user_id        Customer.
     * @return void
     */
    private function set_author( int $reservation_id, int $user_id ) {
        global $wpdb;

        $wpdb->update( $wpdb->posts, [ 'post_author' => $user_id ], [ 'ID' => $reservation_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        clean_post_cache( $reservation_id );
    }

    /**
     * Someone who books for other people, so never the booking's customer.
     *
     * @param \WP_User $user User.
     * @return bool
     */
    public static function is_staff( $user ) {
        foreach ( self::STAFF_CAPS as $cap ) {
            if ( user_can( $user, $cap ) ) {
                return true;
            }
        }

        return false;
    }
}
