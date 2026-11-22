<?php
namespace WpCafe\Resources;
use WpCafe\Abstract\Resource;

/**
 * Reservation Resource
 *
 * Handles reservation data and interactions.
 *
 * @package WpCafe/Resources
 */
class Reservation_Resource extends Resource {
    /**
     * Transform the resource into an array.
     *
     * @return array
     */
    public function to_array() {
        $status = get_post_status( $this->data->id );
		$order          = $this->woo_order();
		$payment_method = $this->get_payment_method( $order );
		$deposit_paid   = $this->data->is_deposit_paid( $order );
        $start_time = $this->data->start_time;
        $end_time   = $this->data->end_time;
        $items      = $this->data->get_items();
		// Staff always see the table; the customer only when they may (see guest_sees_table()).
		$shows_table = $this->data->guest_sees_table() || wpc_current_user_can_view_reservations();

        $reservation_data = [
            'id'                => $this->data->id,
            'name'              => $this->data->name,
            'email'             => $this->data->email,
            'phone'             => $this->data->phone,
            'date'              => $this->data->date,
            'start_time'        => ! empty( $start_time ) && is_numeric( $start_time ) ? gmdate('h:i A', $start_time) : '',
            'end_time'          => ! empty( $end_time ) && is_numeric( $end_time ) ? gmdate('h:i A', $end_time) : '',
            'total_guest'       => $this->data->total_guest,
            'status'            => $status,
            'branch_id'         => $this->data->branch_id,
            'branch_name'       => $this->data->branch_name,
            'notes'             => $this->data->notes,
            'invoice'           => $this->data->invoice,
            'booking_amount'    => $this->data->booking_amount,
            'total_price'       => $this->data->total_price,
            'points_discount'   => (float) $this->data->points_discount,
            'member_discount'   => (float) $this->data->member_discount,
            'member_discount_label' => (string) $this->data->member_discount_label,
			'amount_due'        => $this->data->get_amount_due( $items, $deposit_paid ),
            'deposit_value'     => $this->data->deposit_value,
            'remaining_amount'  => $this->data->remaining_amount,
            'is_partial_payment'=> $this->data->is_partial_payment,
			'deposit_paid'      => $deposit_paid,
            'currency'          => $this->data->currency,
            'payment_method'    => $payment_method,
            'woo_order_id'      => $this->data->woo_order_id,
            'table_name'        => $this->data->table_name,
            'custom_fields'     => $this->data->custom_fields,
            'seats'             => $this->data->seats,
			'seat_groups'       => $shows_table ? $this->data->seat_groups() : array(),
            'area_name'         => $this->data->area_name(),
			'table_names'       => $shows_table ? $this->data->table_names() : '',
            'food_items'        => Reservation_Item_Resource::collection( $items ),
        ];

        /*
         * Send the customer to checkout only when they chose to pay online. A
         * booking with food paid for at the restaurant must NOT be pushed to
         * checkout — the food is recorded on the reservation and settled there,
         * so the form shows its thank-you screen instead.
         *
         * (The && chain used to sit inside empty(), which happened to give the
         * right answer but read as one test instead of three.)
         */
        if ( ! empty( $reservation_data['food_items'] )
            && 'wc' === $payment_method
            && class_exists( 'WooCommerce' )
            && function_exists( 'wc_get_checkout_url' ) ) {
            $reservation_data['redirect_url'] = wc_get_checkout_url();
        }

        return $reservation_data;
    }

	/**
	 * The booking's WooCommerce order, loaded once for every field that needs it.
	 *
	 * @return \WC_Order|null
	 */
	private function woo_order() {
		$woo_order_id = $this->data->woo_order_id;

		if ( empty( $woo_order_id ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		try {
			$order = wc_get_order( $woo_order_id );
		} catch ( \Exception $e ) {
			return null;
		}

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Payment method from the WooCommerce order, else the one saved on the booking.
	 *
	 * @param \WC_Order|null $order The booking's order.
	 * @return string
	 */
	private function get_payment_method( $order ) {
		$payment_method = $order ? $order->get_payment_method() : '';

		if ( empty( $payment_method ) ) {
			$payment_method = $this->data->payment_method;
		}

		return ! empty( $payment_method ) ? $payment_method : '';
	}
}
