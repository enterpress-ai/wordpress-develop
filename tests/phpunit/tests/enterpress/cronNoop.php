<?php

/**
 * EnterPress: Tests that cron execution functions are no-ops.
 *
 * Verifies that wp_cron(), _wp_cron(), and spawn_cron() do nothing,
 * and that the cron system is not triggered on page load.
 *
 * @group enterpress
 * @group cron
 */
class Tests_EnterPress_CronNoop extends WP_UnitTestCase {

	// -------------------------------------------------------------------
	// Execution functions are no-ops
	// -------------------------------------------------------------------

	/**
	 * spawn_cron() should return false unconditionally.
	 *
	 * @covers ::spawn_cron
	 */
	public function test_spawn_cron_returns_false() {
		$this->assertFalse( spawn_cron() );
		$this->assertFalse( spawn_cron( time() ) );
		$this->assertFalse( spawn_cron( time() + 3600 ) );
	}

	/**
	 * wp_cron() should NOT register _wp_cron on shutdown.
	 *
	 * @covers ::wp_cron
	 */
	public function test_wp_cron_is_noop() {
		remove_all_actions( 'shutdown' );

		wp_cron();

		$this->assertFalse(
			has_action( 'shutdown', '_wp_cron' ),
			'wp_cron() should not register _wp_cron on shutdown in EnterPress.'
		);
	}

	/**
	 * _wp_cron() should return 0.
	 *
	 * @covers ::_wp_cron
	 */
	public function test_internal_wp_cron_returns_zero() {
		$this->assertSame( 0, _wp_cron() );
	}

	/**
	 * spawn_cron() should not make any HTTP requests.
	 *
	 * @covers ::spawn_cron
	 */
	public function test_spawn_cron_makes_no_http_request() {
		$request_made = false;
		add_filter(
			'pre_http_request',
			function () use ( &$request_made ) {
				$request_made = true;
				return new WP_Error( 'blocked', 'Should not reach here.' );
			}
		);

		spawn_cron();

		$this->assertFalse( $request_made, 'spawn_cron() should not make HTTP requests.' );
	}

	/**
	 * wp_cron should not be registered on wp_loaded or init.
	 *
	 * @covers ::wp_cron
	 */
	public function test_no_cron_action_on_init_or_wp_loaded() {
		$this->assertFalse(
			has_action( 'wp_loaded', 'wp_cron' ),
			'wp_cron should not be registered on wp_loaded.'
		);
		$this->assertFalse(
			has_action( 'init', 'wp_cron' ),
			'wp_cron should not be registered on init.'
		);
	}

	// -------------------------------------------------------------------
	// Backward-compat private no-op functions
	// -------------------------------------------------------------------

	/**
	 * _get_cron_array() should always return an empty array.
	 *
	 * @covers ::_get_cron_array
	 */
	public function test_get_cron_array_returns_empty_array() {
		$result = _get_cron_array();
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * _set_cron_array() should always return true.
	 *
	 * @covers ::_set_cron_array
	 */
	public function test_set_cron_array_returns_true() {
		$this->assertTrue( _set_cron_array( array( 'anything' ) ) );
		$this->assertTrue( _set_cron_array( array() ) );
	}

	/**
	 * _upgrade_cron_array() should always return an empty array.
	 *
	 * @covers ::_upgrade_cron_array
	 */
	public function test_upgrade_cron_array_returns_empty_array() {
		$result = _upgrade_cron_array( array( 'old_data' ) );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// -------------------------------------------------------------------
	// Schedule registry survives fork
	// -------------------------------------------------------------------

	/**
	 * Standard schedules are still available.
	 *
	 * @covers ::wp_get_schedules
	 */
	public function test_schedules_still_available() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( 'twicedaily', $schedules );
		$this->assertArrayHasKey( 'daily', $schedules );
		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertSame( HOUR_IN_SECONDS, $schedules['hourly']['interval'] );
		$this->assertSame( DAY_IN_SECONDS, $schedules['daily']['interval'] );
		$this->assertSame( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
	}

	/**
	 * The cron_schedules filter still works for custom schedules.
	 *
	 * @covers ::wp_get_schedules
	 */
	public function test_cron_schedules_filter_still_works() {
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				$schedules['every_minute'] = array(
					'interval' => 60,
					'display'  => 'Every Minute',
				);
				return $schedules;
			}
		);

		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'every_minute', $schedules );
		$this->assertSame( 60, $schedules['every_minute']['interval'] );
	}
}
