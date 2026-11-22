<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- this plugin names class files without a class- prefix.
/**
 * Keeps a booking and its WooCommerce order in step.
 *
 * @package WpCafe
 */

namespace WpCafe\Payments\Gateways\WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WpCafe\Contracts\Hookable_Service_Contract;
use WpCafe\Models\Reservation_Model;

/**
 * Keeps a booking and its WooCommerce order in step.
 *
 * Order to booking lives in Checkout_Process. This is the other way: staff
 * change the booking, the order follows. A booking paid at the restaurant has
 * no order, so every method returns early for it.
 *
 * @package WpCafe\Payments\Gateways\WC
 */
class Reservation_Order_Sync implements Hookable_Service_Contract {

	/**
	 * True while this class is writing, so its own save does not echo back.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hook the booking changes and the order save.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wpcafe_after_reservation_cancelled', array( $this, 'push' ), 20 );
		add_action( 'wpcafe_after_reservation_status_changed', array( $this, 'push' ), 20 );
		add_action( 'wpcafe_after_reservation_update', array( $this, 'push' ), 20 );
		add_action( 'woocommerce_update_order', array( $this, 'pull_note' ), 20, 2 );
	}

	/**
	 * Move the linked order to match the booking: status first, then the note.
	 *
	 * Only completed and cancelled reach the order. Confirmed is a promise to
	 * the guest, not a payment fact, and a refund must be a real refund made in
	 * WooCommerce, so neither of those touches the order.
	 *
	 * @param Reservation_Model $reservation Booking that just changed.
	 * @return void
	 */
	public function push( $reservation ) {
		if ( self::$busy || ! $reservation instanceof Reservation_Model ) {
			return;
		}

		$order = $this->order_for( $reservation );

		if ( ! $order ) {
			return;
		}

		self::$busy = true;

		try {
			$this->push_status( $reservation, $order );
			$this->push_note( $reservation, $order );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Copy a note typed on the order (WooCommerce edit screen) to the booking.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order being saved.
	 * @return void
	 */
	public function pull_note( $order_id, $order = null ) {
		if ( self::$busy ) {
			return;
		}

		$order          = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		$reservation_id = $order ? absint( $order->get_meta( 'reservation_id' ) ) : 0;

		// Most orders are not bookings.
		if ( ! $reservation_id ) {
			return;
		}

		$reservation = Reservation_Model::find( $reservation_id );

		// A reused checkout draft can still name an older booking.
		if ( ! $reservation || (int) $reservation->woo_order_id !== (int) $order->get_id() ) {
			return;
		}

		$note = $order->get_customer_note();

		// An empty order note never wipes a booking note: orders made before the sync have none.
		if ( '' === $note || (string) $reservation->notes === $note ) {
			return;
		}

		self::$busy = true;

		try {
			$reservation->update( array( 'notes' => $note ) );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Completed finishes a processing order; cancelled closes an unpaid one.
	 *
	 * A paid order is never cancelled here: that would leave money taken with
	 * no refund, so staff refund it in WooCommerce and the order sync brings
	 * the booking to Refunded.
	 *
	 * @param Reservation_Model $reservation Booking.
	 * @param \WC_Order         $order       Linked order.
	 * @return void
	 */
	private function push_status( Reservation_Model $reservation, \WC_Order $order ) {
		$status = (string) get_post_status( $reservation->id );

		if ( 'completed' === $status && $order->has_status( 'processing' ) ) {
			$order->update_status( 'completed' );
		}

		if ( 'cancelled' === $status && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			$order->update_status( 'cancelled' );
		}
	}

	/**
	 * One note on both sides: the booking's note replaces the order's.
	 *
	 * @param Reservation_Model $reservation Booking.
	 * @param \WC_Order         $order       Linked order.
	 * @return void
	 */
	private function push_note( Reservation_Model $reservation, \WC_Order $order ) {
		$note = (string) $reservation->notes;

		if ( $order->get_customer_note() === $note ) {
			return;
		}

		$order->set_customer_note( $note );
		$order->save();
	}

	/**
	 * The order a booking was paid with, or null for a pay-at-restaurant booking.
	 *
	 * @param Reservation_Model $reservation Booking.
	 * @return \WC_Order|null
	 */
	private function order_for( Reservation_Model $reservation ) {
		$order_id = absint( $reservation->woo_order_id );

		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		// The order must point back, as a reused draft can name an older booking.
		if ( ! $order instanceof \WC_Order || absint( $order->get_meta( 'reservation_id' ) ) !== (int) $reservation->id ) {
			return null;
		}

		return $order;
	}
}
