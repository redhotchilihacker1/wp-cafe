<?php
namespace WpCafe\Integrations\Pointics;

if ( ! defined( 'ABSPATH' ) ) exit;

use WpCafe\CustomerAccounts\Booking_Account;

/**
 * Finds the account a booking's points belong to.
 *
 * Never by the booking's email alone: a customer can set their account email
 * to a guest's address without proving it, and would collect that guest's points.
 *
 * @package WpCafe/Integrations/Pointics
 */
class Reservation_Account_Resolver {
    /**
     * Account id or 0.
     *
     * WP Cafe's Customer Accounts setting links a guest's booking to an
     * account as its author, so no account is made here.
     *
     * @param \WpCafe\Models\Reservation_Model $reservation Booking.
     * @return int
     */
    public function resolve( $reservation ): int {
        $email  = strtolower( trim( (string) $reservation->email ) );
        $author = get_userdata( (int) get_post_field( 'post_author', $reservation->id ) );

		// A booking linked by email waits until its owner confirms it.
		$pending = Booking_Account::is_link_pending( (int) $reservation->id );

		if ( $author && ! $pending && ( ! Booking_Account::is_staff( $author ) || strtolower( $author->user_email ) === $email ) ) {
            return (int) $author->ID;
        }

        // Read into a local first: empty() on the model's magic property is always true.
        $order_id = (int) $reservation->woo_order_id;
        $order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		return $order ? (int) $order->get_customer_id() : 0;
    }
}
