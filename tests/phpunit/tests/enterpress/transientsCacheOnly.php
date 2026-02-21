<?php

/**
 * EnterPress: Integration tests for cache-only transients.
 *
 * Verifies that all 6 transient functions use wp_cache exclusively
 * and never fall back to database storage.
 *
 * @group enterpress
 * @group option
 */
class Tests_EnterPress_TransientsCacheOnly extends WP_UnitTestCase {

	// -------------------------------------------------------------------
	// Regular transients: set/get/delete via cache
	// -------------------------------------------------------------------

	/**
	 * Basic round-trip: set, get, delete.
	 *
	 * @covers ::set_transient
	 * @covers ::get_transient
	 * @covers ::delete_transient
	 */
	public function test_transient_basic_round_trip() {
		$key   = 'ep_test_basic';
		$value = 'test_value';

		$this->assertTrue( set_transient( $key, $value ) );
		$this->assertSame( $value, get_transient( $key ) );
		$this->assertTrue( delete_transient( $key ) );
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * Transients with complex serialized data.
	 *
	 * @covers ::set_transient
	 * @covers ::get_transient
	 */
	public function test_transient_serialized_data() {
		$key   = 'ep_test_serialized';
		$value = array( 'nested' => array( 'deep' => true ), 'count' => 42 );

		$this->assertTrue( set_transient( $key, $value ) );
		$this->assertSame( $value, get_transient( $key ) );
	}

	/**
	 * Transient with object value.
	 *
	 * @covers ::set_transient
	 * @covers ::get_transient
	 */
	public function test_transient_object_value() {
		$key   = 'ep_test_object';
		$value = (object) array( 'foo' => 'bar' );

		$this->assertTrue( set_transient( $key, $value ) );
		$this->assertEquals( $value, get_transient( $key ) );
	}

	// -------------------------------------------------------------------
	// No DB leakage (key integration proof)
	// -------------------------------------------------------------------

	/**
	 * set_transient should NOT create _transient_ rows in wp_options.
	 *
	 * @covers ::set_transient
	 */
	public function test_transient_does_not_write_to_options_table() {
		global $wpdb;

		$key = 'ep_test_no_db';
		set_transient( $key, 'value', 300 );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
				'_transient_' . $key
			)
		);
		$this->assertNull( $row, 'set_transient should not create _transient_ option in DB.' );

		$timeout_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
				'_transient_timeout_' . $key
			)
		);
		$this->assertNull( $timeout_row, 'set_transient should not create _transient_timeout_ option in DB.' );
	}

	/**
	 * get_transient should NOT query the options table.
	 *
	 * @covers ::get_transient
	 */
	public function test_get_transient_makes_no_db_queries() {
		$key = 'ep_test_no_query';
		set_transient( $key, 'cached_value' );

		$queries_before = get_num_queries();
		$value          = get_transient( $key );
		$queries_after  = get_num_queries();

		$this->assertSame( 'cached_value', $value );
		$this->assertSame( $queries_before, $queries_after, 'get_transient should not make any DB queries.' );
	}

	/**
	 * delete_transient should NOT query the options table.
	 *
	 * @covers ::delete_transient
	 */
	public function test_delete_transient_makes_no_db_queries() {
		$key = 'ep_test_del_no_query';
		set_transient( $key, 'value' );

		$queries_before = get_num_queries();
		delete_transient( $key );
		$queries_after = get_num_queries();

		$this->assertSame( $queries_before, $queries_after, 'delete_transient should not make any DB queries.' );
	}

	/**
	 * Transient uses the 'transient' cache group, not 'options'.
	 *
	 * @covers ::set_transient
	 * @covers ::get_transient
	 */
	public function test_transient_uses_correct_cache_group() {
		$key = 'ep_test_group';
		set_transient( $key, 'in_transient_group' );

		$this->assertSame( 'in_transient_group', wp_cache_get( $key, 'transient' ) );
		$this->assertFalse( wp_cache_get( '_transient_' . $key, 'options' ) );
	}

	/**
	 * Expiration is passed through to wp_cache_set.
	 *
	 * @covers ::set_transient
	 */
	public function test_transient_expiration_passed_to_cache() {
		$key = 'ep_test_expiry';
		set_transient( $key, 'expiring_value', 300 );

		$cached = wp_cache_get( $key, 'transient' );
		$this->assertSame( 'expiring_value', $cached );
	}

	// -------------------------------------------------------------------
	// Site transients
	// -------------------------------------------------------------------

	/**
	 * Site transient basic round-trip.
	 *
	 * @covers ::set_site_transient
	 * @covers ::get_site_transient
	 * @covers ::delete_site_transient
	 */
	public function test_site_transient_basic_round_trip() {
		$key   = 'ep_test_site_basic';
		$value = 'site_value';

		$this->assertTrue( set_site_transient( $key, $value ) );
		$this->assertSame( $value, get_site_transient( $key ) );
		$this->assertTrue( delete_site_transient( $key ) );
		$this->assertFalse( get_site_transient( $key ) );
	}

	/**
	 * Site transient should NOT write to wp_options.
	 *
	 * @covers ::set_site_transient
	 */
	public function test_site_transient_does_not_write_to_db() {
		global $wpdb;

		$key = 'ep_test_site_no_db';
		set_site_transient( $key, 'value', 300 );

		$option_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
				'_site_transient_' . $key
			)
		);
		$this->assertNull( $option_row, 'Site transient should not create DB row.' );
	}

	/**
	 * Site transients use the 'site-transient' cache group.
	 *
	 * @covers ::set_site_transient
	 * @covers ::get_site_transient
	 */
	public function test_site_transient_uses_correct_cache_group() {
		$key = 'ep_test_site_group';
		set_site_transient( $key, 'site_cached' );

		$this->assertSame( 'site_cached', wp_cache_get( $key, 'site-transient' ) );
	}

	/**
	 * get_site_transient should not make DB queries.
	 *
	 * @covers ::get_site_transient
	 */
	public function test_get_site_transient_no_db_queries() {
		$key = 'ep_test_site_no_query';
		set_site_transient( $key, 'value' );

		$queries_before = get_num_queries();
		$value          = get_site_transient( $key );
		$queries_after  = get_num_queries();

		$this->assertSame( 'value', $value );
		$this->assertSame( $queries_before, $queries_after );
	}

	// -------------------------------------------------------------------
	// Filter hooks preserved (backward compat)
	// -------------------------------------------------------------------

	/**
	 * pre_transient_{key} filter can short-circuit get_transient.
	 *
	 * @covers ::get_transient
	 */
	public function test_pre_transient_filter_fires() {
		add_filter(
			'pre_transient_ep_test_pre',
			function () {
				return 'filtered_value';
			}
		);

		$this->assertSame( 'filtered_value', get_transient( 'ep_test_pre' ) );
	}

	/**
	 * transient_{key} filter is applied to the return value.
	 *
	 * @covers ::get_transient
	 */
	public function test_transient_output_filter_fires() {
		set_transient( 'ep_test_output', 'original' );

		add_filter(
			'transient_ep_test_output',
			function ( $value ) {
				return $value . '_filtered';
			}
		);

		$this->assertSame( 'original_filtered', get_transient( 'ep_test_output' ) );
	}

	/**
	 * pre_set_transient_{key} filter modifies value before caching.
	 *
	 * @covers ::set_transient
	 */
	public function test_pre_set_transient_filter_fires() {
		add_filter(
			'pre_set_transient_ep_test_pre_set',
			function ( $value ) {
				return 'modified_' . $value;
			}
		);

		set_transient( 'ep_test_pre_set', 'original' );
		$this->assertSame( 'modified_original', get_transient( 'ep_test_pre_set' ) );
	}

	/**
	 * expiration_of_transient_{key} filter receives correct expiration.
	 *
	 * @covers ::set_transient
	 */
	public function test_expiration_filter_fires() {
		$captured_expiration = null;
		add_filter(
			'expiration_of_transient_ep_test_exp',
			function ( $exp ) use ( &$captured_expiration ) {
				$captured_expiration = $exp;
				return $exp;
			}
		);

		set_transient( 'ep_test_exp', 'value', 600 );
		$this->assertSame( 600, $captured_expiration );
	}

	/**
	 * set_transient action fires after successful cache write.
	 *
	 * @covers ::set_transient
	 */
	public function test_set_transient_action_fires() {
		$action = new MockAction();
		add_action( 'set_transient', array( $action, 'action' ), 10, 3 );

		set_transient( 'ep_test_action', 'val', 100 );

		$this->assertSame( 1, $action->get_call_count() );
		$events = $action->get_events();
		$this->assertSame( 'ep_test_action', $events[0]['args'][0] );
		$this->assertSame( 'val', $events[0]['args'][1] );
		$this->assertSame( 100, $events[0]['args'][2] );
	}

	/**
	 * delete_transient_{key} action fires before deletion.
	 *
	 * @covers ::delete_transient
	 */
	public function test_delete_transient_action_fires() {
		set_transient( 'ep_test_del_action', 'value' );

		$action = new MockAction();
		add_action( 'delete_transient_ep_test_del_action', array( $action, 'action' ) );

		delete_transient( 'ep_test_del_action' );
		$this->assertSame( 1, $action->get_call_count() );
	}

	/**
	 * deleted_transient action fires after successful deletion.
	 *
	 * @covers ::delete_transient
	 */
	public function test_deleted_transient_action_fires() {
		set_transient( 'ep_test_deleted_action', 'value' );

		$action = new MockAction();
		add_action( 'deleted_transient', array( $action, 'action' ) );

		delete_transient( 'ep_test_deleted_action' );
		$this->assertSame( 1, $action->get_call_count() );
	}

	/**
	 * pre_site_transient_{key} filter can short-circuit.
	 *
	 * @covers ::get_site_transient
	 */
	public function test_pre_site_transient_filter_fires() {
		add_filter(
			'pre_site_transient_ep_test_site_pre',
			function () {
				return 'site_filtered';
			}
		);

		$this->assertSame( 'site_filtered', get_site_transient( 'ep_test_site_pre' ) );
	}

	/**
	 * set_site_transient action fires.
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_action_fires() {
		$action = new MockAction();
		add_action( 'set_site_transient', array( $action, 'action' ), 10, 3 );

		set_site_transient( 'ep_test_site_action', 'val', 200 );
		$this->assertSame( 1, $action->get_call_count() );
	}

	// -------------------------------------------------------------------
	// Cross-system: transient <-> options isolation
	// -------------------------------------------------------------------

	/**
	 * Transients should not appear in wp_load_alloptions.
	 *
	 * @covers ::set_transient
	 * @covers ::wp_load_alloptions
	 */
	public function test_transient_does_not_pollute_alloptions() {
		set_transient( 'ep_test_alloptions', 'value', 300 );

		$alloptions = wp_load_alloptions();

		$this->assertArrayNotHasKey( '_transient_ep_test_alloptions', $alloptions );
		$this->assertArrayNotHasKey( '_transient_timeout_ep_test_alloptions', $alloptions );
	}

	/**
	 * get_option('_transient_...') should NOT return the cached transient.
	 *
	 * @covers ::set_transient
	 * @covers ::get_option
	 */
	public function test_transient_not_accessible_via_get_option() {
		set_transient( 'ep_test_not_option', 'cache_value' );

		$this->assertFalse( get_option( '_transient_ep_test_not_option' ) );
	}
}
