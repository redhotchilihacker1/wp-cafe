<?php
namespace WpCafe\Payments\Gateways\WC;

if ( ! defined( 'ABSPATH' ) ) exit;

use WpCafe\Contracts\Hookable_Service_Contract;
use WpCafe\Models\Reservation_Model;
/**
 * WC Checkout Process
 *
 * @package WpCafe/Payments
 */
class Checkout_Process implements Hookable_Service_Contract {
    /**
     * Register hooks
     *
     * @return  void
     */
    public function register() {
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'transfer_cart_item_data_to_order' ], 10, 4 );

        add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'modify_cart_item_price' ], 10, 2 );

        // Charge the booking fee on a reservation-with-food cart. That flow goes
        // straight to checkout with only the food in the cart (it never runs the
        // generic-product payment path), so without this the booking is free.
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_reservation_booking_fee' ] );

        add_action( 'woocommerce_payment_complete', [ $this, 'handle_payment_complete' ], 10, 1 );

        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 3 );

        add_filter( 'woocommerce_checkout_fields', [ $this, 'prefill_checkout_fields' ] );

        /*
         * Our reservation product is already priced to the deposit, so the Deposet
         * plugin must not apply its own deposit again. We turn Deposet off on two
         * occasions: when the cart total is calculated (this also runs when the
         * customer clicks "Place order", which is where the wrong price slipped in
         * before), and when the checkout page is drawn so its deposit box is gone.
         */
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'suppress_deposet_checkout_ui' ], 1 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'suppress_deposet_checkout_ui' ], 5 );

        /*
         * The block checkout gets its deposit box from the Store API instead of
         * the hooks above, and that route cannot be unhooked, so strip the data
         * out of the response as well. See strip_deposet_store_api_data().
         */
        add_filter( 'rest_request_after_callbacks', [ $this, 'strip_deposet_store_api_data' ], 10, 3 );

        /*
         * The block checkout does not fetch its first cart over HTTP. WooCommerce
         * builds that response while rendering the page and prints it into the
         * markup, skipping the REST stack and the filter above — which is why the
         * deposit box flashed up on load before the first real request replaced it.
         * This is the same filter for that server-side path.
         */
        add_filter( 'woocommerce_hydration_request_after_callbacks', [ $this, 'strip_deposet_store_api_data' ], 10, 3 );

        /*
         * Show the booking the customer made on the order-received and "my
         * account" order pages. At checkout the card comes from the session, but
         * that is cleared once the order exists, so here we rebuild it read-only
         * from the reservation saved on the order. Priority 5 keeps it above the
         * payment split below.
         */
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_reservation_summary_on_order' ], 5, 1 );

        /*
         * Show how the reservation payment splits up (full price, paid now, owed
         * later) on the order-received and "my account" order pages, so a partial
         * payment never looks like the customer underpaid.
         */
        add_action( 'woocommerce_order_details_after_order_table', [ $this, 'display_partial_payment_summary' ], 10, 1 );

        // Show the same split in the checkout totals so the customer sees the
        // full price and the balance owed before they pay.
        add_action( 'woocommerce_review_order_after_order_total', [ $this, 'display_checkout_partial_rows' ], 10 );

		// Staff and customer emails need the same split, or the order looks underpaid.
		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'display_admin_order_split' ), 10, 1 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'display_email_split' ), 10, 3 );
    }

    /**
     * Modify the cart item price
     *
     * @param array $cart_item
     * @param array $session_data
     * @return array
     */
    public function modify_cart_item_price( $cart_item, $session_data ) {
        if ( isset( $session_data['reservation_id'] ) ) {
            $cart_item['reservation_id'] = $session_data['reservation_id'];

            $reservation = new Reservation_Model( $session_data['reservation_id'] );
            /*
			 * Full booking price, never the deposit: a deposit comes off the whole
			 * order as one "Balance due" credit (add_reservation_booking_fee), so
			 * the order shows the real price. Food is its own lines, so not here.
             */
			$cart_item['data']->set_price( (float) $reservation->total_price );
        }

        return $cart_item;
    }

    /**
	 * Add the booking fee and, for a deposit booking, the balance credit.
     *
	 * Two carts reach checkout. Paying online without food, Wc_Payment adds a
	 * "Reservation" product line that already carries the booking. With food,
	 * the form sends the customer straight to checkout with only the food in
	 * the cart, so the booking is added here as a fee or it would be free.
     *
	 * Every line is at full price. A deposit is one "Balance due" credit off the
	 * whole order (booking + food), so the order shows the real price, the
	 * credit, and the deposit as its total.
     *
     * @param \WC_Cart $cart The cart being calculated.
     * @return void
     */
    public function add_reservation_booking_fee( $cart ) {
		$reservation = $this->session_reservation();
		if ( ! $reservation ) {
            return;
        }

		$booking = (float) $reservation->total_price;

		if ( $booking > 0 && ! $this->cart_has_reservation_line( $cart ) ) {
			$cart->add_fee( __( 'Reservation', 'wp-cafe' ), $booking, false );
        }

		$split = $this->cart_deposit_split( $reservation, $cart );

		if ( $split && $split['remaining'] > 0 ) {
			$cart->add_fee( __( 'Balance due at the restaurant', 'wp-cafe' ), -$split['remaining'], false );
		}
	}

	/**
	 * Work out the deposit split from the cart as it stands right now.
	 *
	 * Not from the figures saved on the booking: the customer can still change
	 * the cart from the mini-cart or cart page, and a stale balance either
	 * overcharges them or credits back more than the order.
	 *
	 * Call it only once line totals exist (fee hook, checkout, review order).
	 *
	 * @param Reservation_Model $reservation Booking being paid for.
	 * @param \WC_Cart|null     $cart        Cart to read.
	 * @return array|null ['deposit', 'remaining', 'total'], or null when nothing is deferred.
	 */
	private function cart_deposit_split( Reservation_Model $reservation, $cart ): ?array {
		if ( 'yes' !== $reservation->is_partial_payment || ! $cart instanceof \WC_Cart ) {
			return null;
		}

		$booking = null;
		$food    = 0.0;

        foreach ( $cart->get_cart() as $cart_item ) {
			// Before tax: WooCommerce taxes a negative fee in proportion, so the credit's tax share follows.
			$line_total = (float) ( $cart_item['line_total'] ?? 0 );

			// The reservation line's own total, so a coupon on it counts.
            if ( ! empty( $cart_item['reservation_id'] ) ) {
				$booking = $line_total;
			} else {
				$food += $line_total;
            }
        }

		$total = ( null === $booking ? (float) $reservation->total_price : $booking ) + $food;
		$split = wpc_reservation_deposit_split( $total );

		return $split ? $split + array( 'total' => $total ) : null;
	}

	/**
	 * The split as the customer pays it, tax included, for the rows and the order record.
	 *
	 * The credit fee is before tax and WooCommerce takes its tax share off on top,
	 * so the balance is the fee plus that tax. Call only after totals are worked out.
	 *
	 * @param Reservation_Model $reservation Booking being paid for.
	 * @param \WC_Cart|null     $cart        Calculated cart.
	 * @return array|null ['deposit', 'remaining', 'total'], or null when nothing is deferred.
	 */
	private function cart_paid_split( Reservation_Model $reservation, $cart ): ?array {
		$split = $this->cart_deposit_split( $reservation, $cart );

		if ( ! $split || ! wc_tax_enabled() ) {
			return $split;
		}

		$credit_name = __( 'Balance due at the restaurant', 'wp-cafe' );
		$credit_tax  = 0.0;
		foreach ( $cart->get_fees() as $fee ) {
			if ( $credit_name === $fee->name ) {
				$credit_tax = abs( (float) ( $fee->tax ?? 0 ) );
			}
		}

		$total     = $split['total'] + (float) $cart->get_cart_contents_tax() + (float) $cart->get_fee_tax() + $credit_tax;
		$remaining = $split['remaining'] + $credit_tax;

		return array(
			'deposit'   => round( $total - $remaining, wc_get_price_decimals() ),
			'remaining' => round( $remaining, wc_get_price_decimals() ),
			'total'     => round( $total, wc_get_price_decimals() ),
		);
	}

	/**
	 * Whether the cart holds the "Reservation" product line from Wc_Payment.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return bool
	 */
	private function cart_has_reservation_line( $cart ): bool {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['reservation_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The booking this visitor is checking out, from the WC session.
	 *
	 * @return Reservation_Model|null
	 */
	private function session_reservation(): ?Reservation_Model {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
        }

		$session_data = WC()->session->get( 'wpc_reservation_data' );

		return empty( $session_data['reservation_id'] ) ? null : new Reservation_Model( $session_data['reservation_id'] );
    }

    /**
     * Handle actions after WooCommerce payment is complete.
     *
     * Hooked to 'woocommerce_payment_complete'. Confirms the booking the order
     * pays for, if any.
     *
     * @param int $order_id The ID of the WooCommerce order.
     * @return void
     */
    public function handle_payment_complete( $order_id ) {
        $reservation_id = $this->reservation_id_for_order( $order_id );

        // Every paid WooCommerce order passes through here, not only bookings.
        if ( ! $reservation_id ) {
            return;
        }

        $this->sync_reservation_status( $reservation_id, 'confirmed', 'payment_complete' );
    }

    /**
     * Transfer custom cart item data to the WooCommerce order item.
     *
     * This function is hooked to 'woocommerce_checkout_create_order_line_item' and is responsible
     * for transferring any custom data from the cart item to the order item during the checkout process.
     * For example, it can be used to add reservation or intent keys as order item meta.
     *
     * @param WC_Order_Item_Product $item         The order item to which meta data will be added.
     * @param string                $cart_item_key The cart item key.
     * @param array                 $cart_item     The cart item data array.
     * @param WC_Order              $order         The WooCommerce order object.
     * @return void
     */
    public function transfer_cart_item_data_to_order( $item, $cart_item_key, $cart_item, $order ) {
        if ( $order->get_meta( 'reservation_id' ) ) {
            return;
        }
        // Check if we have any custom cart item data
        $reservation_id = $cart_item['reservation_id'] ?? null;

        if ( empty( $reservation_id ) && function_exists( 'WC' ) && WC()->session ) {
            $session_data = WC()->session->get( 'wpc_reservation_data' );
            if ( ! empty( $session_data['reservation_id'] ) ) {
                $reservation_id = $session_data['reservation_id'];
            }
        }

        if ( ! empty( $reservation_id ) ) {
            $order->add_meta_data( 'reservation_id', $reservation_id );
            $order->save();

            $reservation = new Reservation_Model( $reservation_id );
            $reservation->update( [ 'woo_order_id' => $order->get_id() ] );

			/*
			 * One note on both sides. The form note goes to the order; a note typed
			 * at checkout is the customer's latest word, so it wins over the form's.
			 */
			$order_note = $order->get_customer_note();

			if ( '' === $order_note && '' !== (string) $reservation->notes ) {
				$order->set_customer_note( $reservation->notes );
				$order->save();
			} elseif ( '' !== $order_note && $order_note !== (string) $reservation->notes ) {
				$reservation->update( array( 'notes' => $order_note ) );
			}

            /*
			 * Record the split staff read, from the same cart the balance credit
			 * was taken off. A display record only: nobody chases the balance.
             */
			if ( 'yes' === $reservation->is_partial_payment ) {
				$cart  = function_exists( 'WC' ) ? WC()->cart : null;
				$split = $this->cart_deposit_split( $reservation, $cart );

                if ( $split ) {
					// The booking keeps pre-tax figures like its other prices; the order shows what was paid.
                    $reservation->update( [
						'deposit_value'    => $split['deposit'],
						'remaining_amount' => $split['remaining'],
                    ] );

					$paid = $this->cart_paid_split( $reservation, $cart ) ?? $split;
					$order->update_meta_data( '_wpc_reservation_total', $paid['total'] );
					$order->update_meta_data( '_wpc_reservation_deposit', $paid['deposit'] );
					$order->update_meta_data( '_wpc_reservation_remaining', $paid['remaining'] );
					$order->save();
				} else {
					// No credit was taken (e.g. Deposet switched off since), so this order pays in full.
					$reservation->update(
						array(
							'is_partial_payment' => 'no',
							'deposit_value'      => 0,
							'remaining_amount'   => 0,
						)
					);
				}
            }
        }
    }

    /**
     * Handle order status changes and update reservation status accordingly.
     *
     * This function is triggered when the WooCommerce order status changes.
	 * It moves the linked reservation to the matching status: processing to
	 * confirmed, completed to completed, refunded to refunded.
     *
     * @param int    $order_id   The ID of the WooCommerce order.
     * @param string $old_status The previous status of the order.
     * @param string $new_status The new status of the order.
     * @return void
     */
    public function handle_order_status_changed( $order_id, $old_status, $new_status ) {
        $reservation_id = $this->reservation_id_for_order( $order_id );

        if ( ! $reservation_id ) {
            return;
        }

        $status_map = [
            'pending'    => 'pending',
            'on-hold'    => 'pending',
			'processing' => 'confirmed',
			'completed'  => 'completed',
            'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
            'failed'     => 'cancelled',
        ];

        if ( isset( $status_map[ $new_status ] ) ) {
            $this->sync_reservation_status( $reservation_id, $status_map[ $new_status ], 'order_status' );
        }

        if ( 'cancelled' === $new_status ) {
            do_action( 'wpcafe_order_cancelled', $order_id );
        }
    }

    /**
     * Prefill checkout fields
     *
     * @param array $fields
     * @return array
     */
    public function prefill_checkout_fields( $fields ) {
        if ( WC()->cart && ! empty( WC()->cart->get_cart() ) ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                if ( ! empty( $cart_item['reservation_id'] ) ) {
                    $reservation = new Reservation_Model( $cart_item['reservation_id'] );

                    $fields['billing']['billing_first_name']['default'] = $reservation->name;
                    $fields['billing']['billing_last_name']['default']  = $reservation->name;
                    $fields['billing']['billing_email']['default']      = $reservation->email;
                    $fields['billing']['billing_phone']['default']      = $reservation->phone;
                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * Render a read-only reservation summary on the order pages.
     *
     * The checkout card is driven by the WC session, which is wiped once the
     * order is created. Here we rebuild the same card from the reservation
     * linked to the order so the customer can still see what they booked.
     * `$reservation_context = 'order'` tells the template to drop the Discard
     * button — a placed booking can't be discarded from the receipt.
     *
     * @param \WC_Order $order The order being viewed.
     * @return void
     */
    public function display_reservation_summary_on_order( $order ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        $reservation_id = $order->get_meta( 'reservation_id' );
        if ( empty( $reservation_id ) ) {
            return;
        }

        $reservation = new Reservation_Model( $reservation_id );

        // The model exposes fields through a magic __get without __isset, so
        // empty()/isset() on $reservation->name always reads false. Pull the
        // values into locals first, then test and map them.
        $name       = $reservation->name;
        $date       = $reservation->date;
        $start_time = $reservation->start_time;

        // No usable booking on the model means nothing worth rendering.
        if ( empty( $name ) && empty( $date ) && empty( $start_time ) ) {
            return;
        }

        // The shared template keys off `reservation_date`; the model stores it
        // as `date`. Map across so the same card renders on the receipt.
        $custom_fields    = $reservation->custom_fields;
        $reservation_data = [
            'name'             => $name,
            'email'            => $reservation->email,
            'phone'            => $reservation->phone,
            'reservation_date' => $date,
            'total_guest'      => $reservation->total_guest,
            'start_time'       => $start_time,
            'end_time'         => $reservation->end_time,
            'notes'            => $reservation->notes,
            'branch_name'      => $reservation->branch_name,
			'table_name'       => $reservation->table_name ? $reservation->table_name : ( $reservation->guest_sees_table() ? $reservation->table_names() : '' ),
            'area_name'        => $reservation->area_name(),
            'custom_fields'    => is_array( $custom_fields ) ? $custom_fields : [],
        ];
        $reservation_context = 'order';

        $template_path = wpcafe()->template_directory . '/reservation/reservation-view.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        }
    }

    /**
     * Print the reservation payment breakdown under the order table.
     *
     * The amount the customer paid is only the deposit, so on its own the order
     * total looks like an underpayment. These rows spell out the full price, what
     * was paid today and what is left to pay, using the figures we saved on the
     * order at checkout.
     *
     * @param \WC_Order $order The order being viewed.
     * @return void
     */
    public function display_partial_payment_summary( $order ) {
		$split = $this->order_split( $order );

		if ( ! $split ) {
            return;
        }

        $rows = [
			array( __( 'Reservation total', 'wp-cafe' ), $split['total'], false ),
			array( $this->deposit_label( $order ), $split['deposit'], true ),
			array( __( 'Balance due at the restaurant', 'wp-cafe' ), $split['remaining'], false ),
        ];

        $html  = '<section class="wpc-reservation-payment">';
        $html .= '<h2 class="woocommerce-column__title">' . esc_html__( 'Your reservation payment', 'wp-cafe' ) . '</h2>';
        $html .= '<table class="woocommerce-table shop_table">';
        foreach ( $rows as [ $label, $amount, $emphasize ] ) {
            $th = $emphasize ? '<th><strong>' . esc_html( $label ) . '</strong></th>' : '<th>' . esc_html( $label ) . '</th>';
            $td = $emphasize ? '<td><strong>' . wp_kses_post( wc_price( $amount ) ) . '</strong></td>' : '<td>' . wp_kses_post( wc_price( $amount ) ) . '</td>';
            $html .= '<tr>' . $th . $td . '</tr>';
        }
        $html .= '</table>';
		$html .= '<p class="wpc-reservation-payment__note">' . esc_html( $this->deposit_note( $order ) ) . '</p>';
        $html .= '</section>';

        echo wp_kses_post( $html );
    }

    /**
	 * Add the reservation split under the totals on the admin order screen.
     *
	 * The order total there is only the deposit, so staff need the full price and
	 * the balance to collect at the table. Rows match WooCommerce's own totals
	 * markup (label, spacer cell, amount).
     *
	 * @param int $order_id Order being edited.
     * @return void
     */
	public function display_admin_order_split( $order_id ) {
		$order = wc_get_order( $order_id );
		$split = $this->order_split( $order );

		if ( ! $split ) {
            return;
        }

		$rows = array(
			__( 'Reservation total:', 'wp-cafe' )             => $split['total'],
			$this->deposit_label( $order ) . ':'              => $split['deposit'],
			__( 'Balance due at the restaurant:', 'wp-cafe' ) => $split['remaining'],
		);

		$html = '';
		foreach ( $rows as $label => $amount ) {
			$html .= '<tr class="wpc-reservation-split"><td class="label">' . esc_html( $label ) . '</td><td width="1%"></td><td class="total">'
				. wp_kses_post( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
		}

		echo wp_kses_post( $html );
	}

	/**
	 * Add the reservation split to WooCommerce order emails.
	 *
	 * @param \WC_Order $order         Order the email is about.
	 * @param bool      $sent_to_admin Unused.
	 * @param bool      $plain_text    Plain-text email.
	 * @return void
	 */
	public function display_email_split( $order, $sent_to_admin = false, $plain_text = false ) {
		$split = $this->order_split( $order );

		if ( ! $split ) {
            return;
        }

		$rows = array(
			__( 'Reservation total', 'wp-cafe' )             => $split['total'],
			$this->deposit_label( $order )                   => $split['deposit'],
			__( 'Balance due at the restaurant', 'wp-cafe' ) => $split['remaining'],
		);
		$args = array( 'currency' => $order->get_currency() );

		if ( $plain_text ) {
			echo "\n" . esc_html( wc_strtoupper( __( 'Your reservation payment', 'wp-cafe' ) ) ) . "\n\n";
			foreach ( $rows as $label => $amount ) {
				echo esc_html( $label . ': ' . wp_strip_all_tags( wc_price( $amount, $args ) ) ) . "\n";
			}
			echo "\n" . esc_html( $this->deposit_note( $order ) ) . "\n\n";
            return;
        }

		$cell = 'text-align:left;border:1px solid #e5e5e5;padding:12px;';
		$html = '<h2>' . esc_html__( 'Your reservation payment', 'wp-cafe' ) . '</h2>'
			. '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
		foreach ( $rows as $label => $amount ) {
			$html .= '<tr><th scope="row" style="' . $cell . '">' . esc_html( $label ) . '</th><td style="' . $cell . '">'
				. wp_kses_post( wc_price( $amount, $args ) ) . '</td></tr>';
		}
		$html .= '</table><p style="margin:0 0 24px;">' . esc_html( $this->deposit_note( $order ) ) . '</p>';

		echo wp_kses_post( $html );
	}

	/**
	 * The deposit split saved on a reservation order at checkout.
	 *
	 * @param \WC_Order|mixed $order Order.
	 * @return array|null ['total', 'deposit', 'remaining'], or null when the order took no deposit.
	 */
	private function order_split( $order ): ?array {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// Only deposit orders have this saved.
		$remaining = $order->get_meta( '_wpc_reservation_remaining' );

		if ( '' === $remaining || null === $remaining ) {
			return null;
		}

		return array(
			'total'     => (float) $order->get_meta( '_wpc_reservation_total' ),
			'deposit'   => (float) $order->get_meta( '_wpc_reservation_deposit' ),
			'remaining' => (float) $remaining,
		);
	}

	/**
	 * "Deposit paid" once the money is in; before that (bank transfer, a failed
	 * card) saying "paid" would be wrong.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function deposit_label( $order ): string {
		return $order->is_paid() ? __( 'Deposit paid', 'wp-cafe' ) : __( 'Deposit (awaiting payment)', 'wp-cafe' );
	}

	/**
	 * The line under the split telling the customer what happens next.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function deposit_note( $order ): string {
		return $order->is_paid()
			? __( 'Your deposit is paid and your booking is held. The remaining balance is collected at the restaurant.', 'wp-cafe' )
			: __( 'Your booking is held once the deposit is paid. The remaining balance is collected at the restaurant.', 'wp-cafe' );
	}

	/**
	 * Add the full-price and balance rows to the checkout totals table.
	 *
	 * Read from the live cart, tax included, so the rows always match the total
	 * above them. Output sits inside WooCommerce's totals table, so each row
	 * must be a <tr>.
	 *
	 * @return void
	 */
	public function display_checkout_partial_rows() {
		$reservation = $this->session_reservation();
		$split       = $reservation ? $this->cart_paid_split( $reservation, WC()->cart ) : null;

		if ( ! $split ) {
			return;
		}

        $rows = [
			__( 'Reservation order total', 'wp-cafe' )       => $split['total'],
			__( 'Deposit to pay now', 'wp-cafe' )            => $split['deposit'],
			__( 'Balance due at the restaurant', 'wp-cafe' ) => $split['remaining'],
        ];

        $html = '';
        foreach ( $rows as $label => $amount ) {
            $html .= '<tr class="wpc-reservation-split"><th>' . esc_html( $label ) . '</th><td>' . wp_kses_post( wc_price( $amount ) ) . '</td></tr>';
        }

        echo wp_kses_post( $html );
    }

    /**
     * Turn the Deposet plugin off for reservation checkouts.
     *
     * Our reservation product is already priced to the deposit. If Deposet runs
     * as well it takes its cut a second time — for example charging 50% of an
     * already-halved $5 and collecting only $2.50 — and shows its own deposit box
     * on top of ours. So on any cart that holds a reservation we remove Deposet's
     * checkout hooks.
     *
     * Deposet attaches these as methods on its own objects. remove_action() needs
     * that exact object to undo them, which we do not have, so we walk the hook
     * list and drop every callback that belongs to a Deposet\Frontend class.
     *
     * @return void
     */
    public function suppress_deposet_checkout_ui() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        // Nothing to do unless this cart is for a reservation.
        $session_data = WC()->session->get( 'wpc_reservation_data' );
        if ( empty( $session_data['reservation_id'] ) ) {
            return;
        }

        // The hooks where Deposet changes the total or prints its deposit UI.
        // wp_enqueue_scripts is included so Deposet's classic- and block-checkout
        // assets do not load for reservation carts; the block checkout otherwise
        // renders its own Pay Deposit/Pay Full Amount selector using the cart
        // subtotal only and ignores the reservation fee, giving wrong figures.
        $hooks = [
            'woocommerce_calculated_total',
            'woocommerce_review_order_before_payment',
            'woocommerce_review_order_after_order_total',
            'woocommerce_checkout_update_order_meta',
            'woocommerce_cart_calculate_fees',
            'wp_enqueue_scripts',
            'woocommerce_store_api_checkout_order_processed',
        ];

        global $wp_filter;
        foreach ( $hooks as $hook ) {
            if ( empty( $wp_filter[ $hook ] ) ) {
                continue;
            }
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $id => $cb ) {
                    if ( is_array( $cb['function'] )
                        && is_object( $cb['function'][0] )
                        && 0 === strpos( get_class( $cb['function'][0] ), 'Deposet\\Frontend\\' ) ) {
                        unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $id ] );
                    }
                }
            }
        }
    }

    /**
     * Remove Deposet's deposit data from the block cart/checkout response.
     *
     * The block checkout does not use the hooks stripped above. Deposet feeds it
     * through the Store API instead, registered with
     * woocommerce_store_api_register_endpoint_data(), which is not stored as a
     * WordPress hook and so cannot be unhooked. Dropping its data from the
     * response is the reliable way to hide the box: the block only draws the
     * deposit choice when this data says it is available.
     *
     * Only reservation carts are touched, so a plain food order still gets
     * Deposet's normal deposit choice.
     *
     * @param \WP_REST_Response|mixed $response Result of the request.
     * @param array                   $handler  Route handler used for the request.
     * @param \WP_REST_Request        $request  The request.
     * @return \WP_REST_Response|mixed
     */
    public function strip_deposet_store_api_data( $response, $handler, $request ) {
        if ( ! $response instanceof \WP_REST_Response ) {
            return $response;
        }

        // Cart and checkout both carry the deposit data; match either.
        if ( 0 !== strpos( (string) $request->get_route(), '/wc/store/' ) ) {
            return $response;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return $response;
        }

        $session_data = WC()->session->get( 'wpc_reservation_data' );
        if ( empty( $session_data['reservation_id'] ) ) {
            return $response;
        }

        $data = $response->get_data();
        if ( ! is_array( $data ) && ! is_object( $data ) ) {
            return $response;
        }

        $this->remove_deposet_extension( $data );
        $response->set_data( $data );

        return $response;
    }

    /**
     * Drop Deposet's data from every `extensions` bag inside a Store API response.
     *
     * There is more than one place to look. The cart route carries `extensions` at
     * the top, but the checkout route asked with `__experimental_calc_totals=true`
     * also nests a whole cart response under `__experimentalCart`, with its own
     * copy. Clearing only the top one left that copy to put the deposit box back
     * as soon as the customer changed payment method.
     *
     * The nested key is named "experimental" by WooCommerce and can move, so walk
     * the response rather than reaching for a fixed path. Depth is capped because
     * this runs on every Store API call.
     *
     * @param array|object $node  Response data, edited in place.
     * @param int          $depth Current recursion depth.
     * @return void
     */
    private function remove_deposet_extension( &$node, int $depth = 0 ) {
        if ( $depth > 6 ) {
            return;
        }

        $keys = is_object( $node ) ? array_keys( get_object_vars( $node ) ) : ( is_array( $node ) ? array_keys( $node ) : [] );

        foreach ( $keys as $key ) {
            $value = is_object( $node ) ? $node->$key : $node[ $key ];

            if ( 'extensions' === $key ) {
                /*
                 * The Store API hands `extensions` back as an object, not an array,
                 * so it still encodes as {} when empty. Handle both shapes — reading
                 * an object with array syntax is a fatal, not a warning.
                 */
                if ( is_object( $value ) ) {
                    unset( $value->deposet );
                } elseif ( is_array( $value ) ) {
                    unset( $value['deposet'] );
                }
            } elseif ( is_array( $value ) || is_object( $value ) ) {
                $this->remove_deposet_extension( $value, $depth + 1 );
            } else {
                continue;
            }

            if ( is_object( $node ) ) {
                $node->$key = $value;
            } else {
                $node[ $key ] = $value;
            }
        }
    }

    /**
     * The booking an order pays for, or 0.
     *
     * @param int $order_id WooCommerce order id.
     * @return int
     */
    private function reservation_id_for_order( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        if ( ! $order ) {
            return 0;
        }

        $reservation_id = absint( $order->get_meta( 'reservation_id' ) );

        return $reservation_id && 'wpc_reservation' === get_post_type( $reservation_id ) ? $reservation_id : 0;
    }

    /**
     * Move a booking to a status and tell listeners, only when it really changed.
     *
     * @param int    $reservation_id Reservation id.
     * @param string $new_status     Target status.
     * @param string $source         payment_complete or order_status.
     * @return void
     */
    private function sync_reservation_status( $reservation_id, $new_status, $source ) {
        $old_status = (string) get_post_status( $reservation_id );

        if ( $old_status === $new_status ) {
            return;
        }

		/*
		 * The guest has eaten (completed) or the money is back (refunded), so a
		 * later order change must not reopen the booking. A completed one can
		 * still be refunded; a refunded one is final.
		 */
		if ( 'refunded' === $old_status || ( 'completed' === $old_status && 'refunded' !== $new_status ) ) {
			return;
		}

        ( new Reservation_Model( $reservation_id ) )->update( [ 'status' => $new_status ] );

        /**
         * A booking's status changed because its WooCommerce order moved.
         *
         * Listeners must be idempotent: the check above is not atomic, so a payment
         * webhook and an order status change landing together can both fire it.
         *
         * @param int    $reservation_id
         * @param string $old_status
         * @param string $new_status
         * @param string $source payment_complete or order_status.
         */
        do_action( 'wpcafe_reservation_status_synced', $reservation_id, $old_status, $new_status, $source );
    }
}
