<?php
namespace WpCafe\Payments\Gateways\WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WpCafe\Contracts\Hookable_Service_Contract;
use WpCafe\Models\Reservation_Model;
use WpCafe\Reservation\Member_Discount;

/**
 * Records on the booking the member discount checkout took off its order.
 *
 * Checkout applies the level's discount in its own cart, so without this the
 * booking, its emails and the revenue report would still show the full price.
 *
 * @package WpCafe\Payments\Gateways\WC
 */
class Order_Member_Discount implements Hookable_Service_Contract {

	const FILTER = 'wpcafe_reservation_order_member_discount';

	/**
	 * Hook both checkouts.
	 *
	 * @return void
	 */
	public function register() {
		// Both fire once the order has its coupon lines; one for each checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record' ), 20 );
	}

	/**
	 * Save on the booking the member discount its checkout order took.
	 *
	 * @param \WC_Order|int $order The order, or its id from the classic checkout.
	 * @return void
	 */
	public function record( $order ) {
		$order          = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		$reservation_id = $order ? (int) $order->get_meta( 'reservation_id' ) : 0;

		// Most orders are not bookings.
		if ( $reservation_id <= 0 ) {
			return;
		}

		$reservation = Reservation_Model::find( $reservation_id );

		// A reused checkout draft can still name an older booking, so the booking must point back at this order.
		if ( ! $reservation || (int) $reservation->woo_order_id !== (int) $order->get_id() ) {
			return;
		}

		$discount = $this->order_discount( $order );

		// A retried checkout processes the same order again; a booking save runs every save_post hook.
		if ( (float) $reservation->member_discount === $discount['amount'] && (string) $reservation->member_discount_label === $discount['label'] ) {
			return;
		}

		$fields = array(
			'member_discount'       => $discount['amount'],
			'member_discount_label' => $discount['label'],
		);

		/*
		 * The restaurant still collects the saved balance; the discount came off
		 * what was paid online. So the deposit is whatever is left once the
		 * balance is taken from the discounted total.
		 */
		if ( 'yes' === $reservation->is_partial_payment && (float) $reservation->remaining_amount > 0 ) {
			$total                   = $reservation->get_total_price() - (float) $reservation->points_discount - $discount['amount'];
			$fields['deposit_value'] = round( max( 0.0, $total - (float) $reservation->remaining_amount ), wpc_price_decimals() );
		}

		$reservation->update( $fields );

		/**
		 * The booking's price changed after it was saved; points earned on it are now stale.
		 *
		 * @param int $reservation_id Booking id.
		 */
		do_action( 'wpcafe_reservation_member_discount_recorded', $reservation_id );
	}

	/**
	 * The level's discount over every line of the order: the booking and its food.
	 *
	 * The order belongs to the booking (checkout is built from the booking's own
	 * cart), so each line's share counts. Listeners price one line at a time.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{amount: float, label: string}
	 */
	private function order_discount( $order ): array {
		$amount = 0.0;
		$label  = '';

		foreach ( $order->get_items( 'line_item' ) as $line ) {
			/**
			 * Filters the member discount checkout took off one line of a booking's order.
			 *
			 * @param array                  $discount { amount: float, label: string }.
			 * @param \WC_Order              $order    The order paying for the booking.
			 * @param \WC_Order_Item_Product $line     One line of that order.
			 */
			$share = Member_Discount::clean(
				apply_filters( self::FILTER, Member_Discount::none(), $order, $line ),
				(float) $line->get_subtotal()
			);

			$amount += $share['amount'];
			$label   = '' !== $label ? $label : $share['label'];
		}

		// Round the sum again: per-line floats can add up to 49.999999.
		return Member_Discount::clean(
			array(
				'amount' => $amount,
				'label'  => $label,
			),
			$amount
		);
	}
}
