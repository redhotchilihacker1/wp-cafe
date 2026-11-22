<?php

namespace WpCafe\Payments;

use WpCafe\Payments\Payment_Setup;
use WpCafe\Payments\Gateways\WC\Checkout_Process;
use WpCafe\Payments\Gateways\WC\Order_Member_Discount;
use WpCafe\Payments\Gateways\WC\Reservation_Order_Sync;
use WpCafe\Providers\Base_Service_Provider;
use WpCafe\Payments\Controllers\Payment_Controller;
/**
 * Payment_Service_Provider will responsible for all payment services
 *
 * @package WpCafe/Payments
 */
class Payment_Service_Provider extends Base_Service_Provider {
    /**
     * Store services
     *
     * @var array
     */
    protected $services = [
        Payment_Setup::class,
        Checkout_Process::class,
        Order_Member_Discount::class,
		Reservation_Order_Sync::class,
        Payment_Controller::class,
    ];

    /**
     * Register services
     *
     * @return  void
     */
    public function get_services() {
        return apply_filters( 'wpcafe_payment_services', $this->services );
    }
}

