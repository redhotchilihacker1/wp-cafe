<?php
namespace WpCafe\Upgrades\Upgrade_3_0_0;

use WpCafe\Models\Reservation_Model;

/**
 * Class Upgrade_Reservation
 *
 * Handles the upgrade process for version 3.0.0.
 */
class Upgrade_Reservation {
    
    /**
     * Constructor for the Upgrade_Reservation class.
     *
     * Automatically triggers the upgrade process when an instance is created.
     */
    public function __construct() {
        $this->migrate_reservation();
        $this->migrate_reservation_schedule();
		$this->migrate_exception_schedules();
    }

    /**
     * Upgrade the reservation.
     *
     * @return void
     */
    public function migrate_reservation() {

        $reservations = get_posts( [
            'post_type'      => 'wpc_reservation',
            'posts_per_page' => -1,
        ] );

        if ( $reservations ) {
            foreach ( $reservations as $reservation ) {
                $reservation_data = get_post_meta( $reservation->ID );
                $id = $reservation->ID;
                $term = get_term_by('name', get_post_meta( $id, 'wpc_branch', true ), 'wpc_branch');

                if ( $term ) {
                    $branch_id = $term->term_id;
                } else {
                    $branch_id = '';
                }

                $date           = get_post_meta( $id, 'wpc_booking_date', true );
                $start_time_str = get_post_meta( $id, 'wpc_from_time', true );
                $end_time_str   = get_post_meta( $id, 'wpc_to_time', true );

                // Convert time strings to timestamps
                $start_time = '';
                $end_time   = '';

                if ( ! empty( $date ) && ! empty( $start_time_str ) ) {
                    $start_time = strtotime( $date . ' ' . $start_time_str );
                }

                if ( ! empty( $date ) && ! empty( $end_time_str ) ) {
                    $end_time = strtotime( $date . ' ' . $end_time_str );
                }

                $data = [
                    'name'          => get_post_meta( $id, 'wpc_name', true ),
                    'email'         => get_post_meta( $id, 'wpc_email', true ),
                    'phone'         => get_post_meta( $id, 'wpc_phone', true ),
                    'date'          => $date,
                    'start_time'    => $start_time,
                    'end_time'      => $end_time,
                    'total_guest'   => get_post_meta( $id, 'wpc_total_guest', true ),
                    'table_name'    => get_post_meta( $id, '_table_name', true ),
					'status'        => Reservation_Model::from_legacy_status( get_post_meta( $id, 'wpc_reservation_state', true ) ),
                    'branch_id'     => $branch_id,
                    'branch_name'   => get_post_meta( $id, 'wpc_branch', true ),
                    'notes'         => get_post_meta( $id, 'wpc_booking_note', true ),
                    'invoice'       => get_post_meta( $id, 'wpc_reservation_invoice', true ),
                    'total_price'  => '',
                ];

                $reservation_model = new Reservation_Model( $id );
                $reservation_model->update( $data );
            }
        }
    }

    /**
     * Migrate reservation schedule
     *
     * @return  void
     */
    private function migrate_reservation_schedule() {
        $multi_slot = wpc_get_option('reser_multi_schedule', false);

        if ( 'on' === $multi_slot ) {
            $this->migrate_multi_slot_daily_schedule();
            $this->migrate_multi_slot_weekly_schedule();
        } else {
            $this->migrate_weekly_reservation_schedule();
            $this->migrate_daily_schedule();
        }
    }

    /**
     * Migrate weekly reservation schedule
     *
     * @return  void
     */
    public function migrate_weekly_reservation_schedule() {
        $reservation_schedule = wpc_get_option('wpc_weekly_schedule', []);
        $reservation_schedule_start_time = wpc_get_option('wpc_weekly_schedule_start_time', []);
        $reservation_schedule_end_time = wpc_get_option('wpc_weekly_schedule_end_time', []);

        $schedule_data = $this->convert_weekly_schedule_data($reservation_schedule, $reservation_schedule_start_time, $reservation_schedule_end_time);

        if ( $schedule_data ) {
			$this->save_schedule( $schedule_data );
        }
    }

    /**
     * Migrate daily reservation schedule
     *
     * @return  void
     */
    private function migrate_daily_schedule() {
        $start_time = wpc_get_option('wpc_all_day_start_time', '');
        $end_time   = wpc_get_option('wpc_all_day_end_time', '');

        $days = [
            "Mon" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Tue" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Wed" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Thu" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Fri" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Sat" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
            "Sun" => ["status" => "on", "slots" => [
                [
                    'start' => $start_time,
                    'end'   => $end_time,
                ]
            ]],
        ];

        if ( $start_time && $end_time ) {
			$this->save_schedule( $days );
        }
    }

    /**
     * Convert schedule data
     *
     * @return  array
     */
    private function convert_weekly_schedule_data($schedule_days, $schedule_start_time, $schedule_end_time) {
        
        if ( empty( $schedule_days ) || empty( $schedule_start_time ) || empty( $schedule_end_time ) ) {
            return [];
        }

        // Initialize all days
        $days = [
            "Mon" => ["status" => "off", "slots" => []],
            "Tue" => ["status" => "off", "slots" => []],
            "Wed" => ["status" => "off", "slots" => []],
            "Thu" => ["status" => "off", "slots" => []],
            "Fri" => ["status" => "off", "slots" => []],
            "Sat" => ["status" => "off", "slots" => []],
            "Sun" => ["status" => "off", "slots" => []],
        ];
        
        
        // Merge logic
        foreach ($schedule_days as $index => $dayRow) {
            foreach ($dayRow as $day => $status) {
        
                // Set status to ON
                $days[$day]['status'] = $status;
        
                // Add slot for that day
                $days[$day]['slots'][] = [
                    'start' => $schedule_start_time[$index],
                    'end'   => $schedule_end_time[$index],
                ];
            }
        }

        return $days;
    }

    /**
     * Migrate multi slot daily schedule
     *
     * @return  array
     */
    private function migrate_multi_slot_daily_schedule() {
        $start_time_entries = wpc_get_option('multi_start_time', []);
        $end_time_entries   = wpc_get_option('multi_end_time', []);

        if ( empty( $start_time_entries ) || empty( $end_time_entries ) ) {
            return [];
        }

		$slots = array();

		// By index, not array_combine(): two rows may share a start time, and a row may lack an end time.
		foreach ( (array) $start_time_entries as $index => $start_time ) {
			if ( $start_time && ! empty( $end_time_entries[ $index ] ) ) {
				$slots[] = array(
					'start' => $start_time,
					'end'   => $end_time_entries[ $index ],
				);
			}
        }

        if ( $slots ) {
            $schedule_data = [
                "Mon" => ["status" => "on", "slots" => $slots],
                "Tue" => ["status" => "on", "slots" => $slots],
                "Wed" => ["status" => "on", "slots" => $slots],
                "Thu" => ["status" => "on", "slots" => $slots],
                "Fri" => ["status" => "on", "slots" => $slots],
                "Sat" => ["status" => "on", "slots" => $slots],
                "Sun" => ["status" => "on", "slots" => $slots],
            ];

			$this->save_schedule( $schedule_data );
        }
    }

    /**
     * Migrate multi slot weekly schedule
     *
     * @return  void
     */
    private function migrate_multi_slot_weekly_schedule() {
		// The admin schedule form rejects a schedule that does not list all seven days.
		$result = array_fill_keys(
			array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ),
			array(
				'status' => 'off',
				'slots'  => array(),
			)
		);

        $weekly_multi_diff_times = wpc_get_option('weekly_multi_diff_times', []);

        if ( empty( $weekly_multi_diff_times ) ) {
            return [];
        }

        foreach ($weekly_multi_diff_times as $day => $items) {
            $result[$day] = [
                'status' => 'on',
                'slots'  => array_map(fn($t) => [
                    'start' => $t['start_time'],
                    'end'   => $t['end_time'],
                ], $items)
            ];
        }

		$this->save_schedule( $result );
	}

	/**
	 * Migrate the special opening hours of single dates.
	 *
	 * @return void
	 */
	private function migrate_exception_schedules() {
		$dates       = wpc_get_option( 'wpc_exception_date', array() );
		$start_times = wpc_get_option( 'wpc_exception_start_time', array() );
		$end_times   = wpc_get_option( 'wpc_exception_end_time', array() );
		$schedules   = array();

		foreach ( (array) $dates as $index => $date ) {
			$timestamp = strtotime( (string) $date );

			if ( ! $timestamp || empty( $start_times[ $index ] ) || empty( $end_times[ $index ] ) ) {
				continue;
			}

			$schedules[] = array(
				'date' => gmdate( 'Y-m-d', $timestamp ),
				'time' => array(
					'start' => $start_times[ $index ],
					'end'   => $end_times[ $index ],
				),
			);
		}

		if ( $schedules ) {
			wpc_update_option( 'enable_custom_schedule', true );
			wpc_update_option( 'custom_schedules', $schedules );
        }
    }

	/**
	 * Save migrated reservation hours where the admin can still edit them.
	 *
	 * The reservation override screen is Pro only. On a free site the hours would
	 * still run the booking form but stay hidden, so they become the Open Hours.
	 *
	 * @param array $schedule Weekly schedule keyed by day.
	 * @return void
	 */
	private function save_schedule( array $schedule ) {
		if ( $this->has_pro() ) {
			wpc_update_option( 'override_reservation_schedule', '1' );
			wpc_update_option( 'reservation_schedule', $schedule );
			return;
		}

		wpc_update_option( 'restaurant_schedule', $schedule );

		$interval = absint( wpc_get_option( 'reserv_time_interval' ) );

		if ( $interval ) {
			wpc_update_option( 'slot_interval', $interval );
		}
	}

	/**
	 * Whether WP Cafe Pro is active during this migration.
	 *
	 * @return bool
	 */
	protected function has_pro() {
		return function_exists( 'wpcafe_pro' );
	}
}
