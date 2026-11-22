<?php
namespace WpCafe\Reservation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WpCafe\Models\Reservation_Model;

/**
 * A loyalty plugin's member discount on a booking paid at the restaurant.
 *
 * The form's quote and the save both price it through subtotal() and resolve(),
 * so they agree whenever they read the same booking and food.
 *
 * @package WpCafe\Reservation
 */
class Member_Discount {

	const FILTER = 'wpcafe_reservation_member_discount';

	/**
	 * No discount.
	 *
	 * @return array{amount: float, label: string}
	 */
	public static function none(): array {
		return array(
			'amount' => 0.0,
			'label'  => '',
		);
	}

	/**
	 * What the member discount is a share of: booking and food, less points.
	 *
	 * @param float $booking Booking price.
	 * @param float $food    Food with it.
	 * @param float $points  Points money off.
	 * @return float
	 */
	public static function subtotal( float $booking, float $food, float $points ): float {
		return max( 0.0, $booking + $food - $points );
	}

	/**
	 * The member's discount on what is left after points.
	 *
	 * @param float $subtotal Booking and food, less any points discount.
	 * @param int   $user_id  The member whose level counts; 0 gets nothing.
	 * @return array{amount: float, label: string}
	 */
	public static function resolve( float $subtotal, int $user_id ): array {
		if ( $user_id <= 0 || $subtotal <= 0 ) {
			return self::none();
		}

		/**
		 * Filters the member discount on a booking paid at the restaurant.
		 *
		 * @param array $discount { amount: float, label: string }.
		 * @param float $subtotal Booking and food, less any points discount.
		 * @param int   $user_id  The member whose level counts.
		 */
		return self::clean( apply_filters( self::FILTER, self::none(), $subtotal, $user_id ), $subtotal );
	}

	/**
	 * A discount a listener returned, held to what it may take.
	 *
	 * @param mixed $discount What the listener returned.
	 * @param float $max      The most it may take off.
	 * @return array{amount: float, label: string}
	 */
	public static function clean( $discount, float $max ): array {
		if ( ! is_array( $discount ) || ! isset( $discount['amount'] ) || ! is_numeric( $discount['amount'] ) ) {
			return self::none();
		}

		$amount = min( round( (float) $discount['amount'], wpc_price_decimals() ), $max );

		if ( $amount <= 0 ) {
			return self::none();
		}

		return array(
			'amount' => $amount,
			'label'  => isset( $discount['label'] ) && is_scalar( $discount['label'] ) ? sanitize_text_field( (string) $discount['label'] ) : '',
		);
	}

	/**
	 * Whether the signed-in user books for other people, and so is matched by email.
	 *
	 * The save and the form's quote both ask this, so they always pick the same customer.
	 *
	 * @return bool
	 */
	public static function books_for_others(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'wpcafe_manage_reservations' );
	}

	/**
	 * Whose level a new booking takes.
	 *
	 * Only staff book for someone else, so only staff are matched by email;
	 * anyone else typing a member's email would borrow that member's level.
	 *
	 * @param array $data Booking data.
	 * @return int
	 */
	public static function customer_id( array $data ): int {
		if ( ! self::books_for_others() ) {
			return get_current_user_id();
		}

		$user = get_user_by( 'email', sanitize_email( (string) ( $data['email'] ?? '' ) ) );

		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Price the member discount into a booking about to be saved.
	 *
	 * The customer is stored with the booking, so an edit prices the same
	 * person even after roles or the booking's author change.
	 *
	 * @param array $data          Booking data, priced on the server.
	 * @param float $food_subtotal Food in the cart for this booking.
	 * @return array
	 */
	public static function apply( array $data, float $food_subtotal ): array {
		$data['member_customer_id'] = self::customer_id( $data );

		return self::priced( $data, $food_subtotal, (float) ( $data['points_discount'] ?? 0 ), $data['member_customer_id'] );
	}

	/**
	 * Re-price the member discount when an edit changes a booking's price or payment method.
	 *
	 * Any other edit keeps the discount the booking was saved at, so a later level
	 * change never moves an old booking. A checkout booking that stays on checkout
	 * keeps what its order recorded.
	 *
	 * @param Reservation_Model $reservation The booking before the edit.
	 * @param array             $data        Edited fields.
	 * @return array
	 */
	public static function reprice( Reservation_Model $reservation, array $data ): array {
		unset( $data['member_discount'], $data['member_discount_label'], $data['member_customer_id'] );

		$method         = (string) ( $data['payment_method'] ?? $reservation->payment_method );
		$was_local      = 'local' === $reservation->payment_method;
		$price_changed  = isset( $data['total_price'] ) && (float) $data['total_price'] !== (float) $reservation->total_price;
		$method_changed = $method !== (string) $reservation->payment_method;

		// Moving to checkout drops the restaurant discount: the checkout coupon takes it instead.
		if ( ! ( $price_changed || $method_changed ) || ( 'local' !== $method && ! $was_local ) ) {
			return $data;
		}

		$data['payment_method'] = $method;
		$food                   = $reservation->get_total_price() - (float) $reservation->total_price;

		return self::priced(
			array_merge( $data, array( 'total_price' => $data['total_price'] ?? $reservation->total_price ) ),
			$food,
			(float) $reservation->points_discount,
			(int) $reservation->member_customer_id
		);
	}

	/**
	 * Set the member discount fields on booking data; none unless it is paid at the restaurant.
	 *
	 * @param array $data    Booking data with total_price and payment_method.
	 * @param float $food    Food with the booking.
	 * @param float $points  Points money off.
	 * @param int   $user_id The booking's customer.
	 * @return array
	 */
	private static function priced( array $data, float $food, float $points, int $user_id ): array {
		// Only a booking paid at the restaurant; checkout takes the member discount in its own cart.
		$discount = 'local' !== ( $data['payment_method'] ?? '' )
			? self::none()
			: self::resolve( self::subtotal( (float) ( $data['total_price'] ?? 0 ), $food, $points ), $user_id );

		$data['member_discount']       = $discount['amount'];
		$data['member_discount_label'] = $discount['label'];

		return $data;
	}
}
