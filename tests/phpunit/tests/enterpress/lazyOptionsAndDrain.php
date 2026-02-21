<?php

/**
 * EnterPress: Integration tests for lazy options loading and instance drain.
 *
 * Tests that wp_load_alloptions returns empty (options loaded lazily via
 * get_option fallback) and that wp_handle_instance_drain fires the
 * wp_instance_draining action under correct conditions.
 *
 * @group enterpress
 * @group option
 */
class Tests_EnterPress_LazyOptionsAndDrain extends WP_UnitTestCase {

	// -------------------------------------------------------------------
	// Lazy options loading
	// -------------------------------------------------------------------

	/**
	 * wp_load_alloptions() returns an empty array in EnterPress.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_load_alloptions_returns_empty_array() {
		// Clear cache to ensure we hit the actual function logic.
		wp_cache_delete( 'alloptions', 'options' );

		$alloptions = wp_load_alloptions();

		$this->assertIsArray( $alloptions );
		$this->assertEmpty( $alloptions, 'wp_load_alloptions should return empty array.' );
	}

	/**
	 * get_option still works via the individual DB query fallback path.
	 *
	 * @covers ::get_option
	 * @covers ::wp_load_alloptions
	 */
	public function test_get_option_works_with_empty_alloptions() {
		// blogname is always set during WP install.
		$blogname = get_option( 'blogname' );

		$this->assertNotFalse( $blogname, 'get_option(blogname) should return a value.' );
		$this->assertIsString( $blogname );
	}

	/**
	 * get_option fetches individually, NOT via bulk autoload query.
	 *
	 * @covers ::get_option
	 */
	public function test_get_option_fetches_individually() {
		// Clear all caches to force a DB hit.
		wp_cache_flush();

		$queries = array();
		add_filter(
			'query',
			function ( $query ) use ( &$queries ) {
				$queries[] = $query;
				return $query;
			}
		);

		get_option( 'blogname' );

		// Should NOT contain the bulk autoload query.
		$bulk_query_found = false;
		foreach ( $queries as $q ) {
			if ( strpos( $q, 'autoload' ) !== false && strpos( $q, 'SELECT' ) !== false ) {
				$bulk_query_found = true;
				break;
			}
		}
		$this->assertFalse( $bulk_query_found, 'No bulk autoload query should fire with lazy options.' );

		// Should contain an individual option query.
		$individual_found = false;
		foreach ( $queries as $q ) {
			if ( strpos( $q, 'option_name' ) !== false || strpos( $q, 'blogname' ) !== false ) {
				$individual_found = true;
				break;
			}
		}
		$this->assertTrue( $individual_found, 'Individual option query should fire for blogname.' );
	}

	/**
	 * After first fetch, subsequent calls are served from cache.
	 *
	 * @covers ::get_option
	 */
	public function test_get_option_cached_on_second_call() {
		// First call populates cache.
		get_option( 'siteurl' );

		$queries_before = get_num_queries();
		get_option( 'siteurl' );
		$queries_after = get_num_queries();

		$this->assertSame( $queries_before, $queries_after, 'Second get_option call should use cache.' );
	}

	/**
	 * get_option returns the default for non-existent options.
	 *
	 * @covers ::get_option
	 */
	public function test_get_option_default_for_nonexistent() {
		$this->assertFalse( get_option( 'ep_nonexistent_option_xyz' ) );
		$this->assertSame( 'custom_default', get_option( 'ep_nonexistent_option_xyz', 'custom_default' ) );
	}

	// -------------------------------------------------------------------
	// Alloptions filter hooks
	// -------------------------------------------------------------------

	/**
	 * pre_wp_load_alloptions filter can override the return value.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_pre_wp_load_alloptions_filter() {
		$custom = array( 'option1' => 'value1' );
		add_filter(
			'pre_wp_load_alloptions',
			function () use ( $custom ) {
				return $custom;
			}
		);

		$result = wp_load_alloptions();
		$this->assertSame( $custom, $result );
	}

	/**
	 * The alloptions filter still fires on the empty return value.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_alloptions_filter_fires() {
		wp_cache_delete( 'alloptions', 'options' );

		$fired = false;
		add_filter(
			'alloptions',
			function ( $opts ) use ( &$fired ) {
				$fired = true;
				return $opts;
			}
		);

		wp_load_alloptions();
		$this->assertTrue( $fired );
	}

	// -------------------------------------------------------------------
	// Option filter backward compat
	// -------------------------------------------------------------------

	/**
	 * pre_option_{name} filter still short-circuits get_option.
	 *
	 * @covers ::get_option
	 */
	public function test_pre_option_filter_still_works() {
		add_filter(
			'pre_option_ep_test_pre',
			function () {
				return 'intercepted';
			}
		);

		$this->assertSame( 'intercepted', get_option( 'ep_test_pre' ) );
	}

	/**
	 * option_{name} filter still fires on retrieved values.
	 *
	 * @covers ::get_option
	 */
	public function test_option_output_filter_still_works() {
		update_option( 'ep_test_filter', 'raw_value' );

		add_filter(
			'option_ep_test_filter',
			function ( $value ) {
				return $value . '_filtered';
			}
		);

		$this->assertSame( 'raw_value_filtered', get_option( 'ep_test_filter' ) );
	}

	// -------------------------------------------------------------------
	// Instance drain shutdown hook
	// -------------------------------------------------------------------

	/**
	 * Without WP_INSTANCE_DRAINING, the action does NOT fire.
	 *
	 * @covers ::wp_handle_instance_drain
	 */
	public function test_instance_drain_noop_without_constant() {
		$action = new MockAction();
		add_action( 'wp_instance_draining', array( $action, 'action' ) );

		wp_handle_instance_drain();

		$this->assertSame( 0, $action->get_call_count(), 'Action should not fire when WP_INSTANCE_DRAINING is not defined.' );
	}

	/**
	 * wp_handle_instance_drain function exists and is callable.
	 *
	 * @covers ::wp_handle_instance_drain
	 */
	public function test_instance_drain_function_exists() {
		$this->assertTrue( function_exists( 'wp_handle_instance_drain' ) );
		$this->assertTrue( is_callable( 'wp_handle_instance_drain' ) );
	}

	/**
	 * Callbacks can be registered on the wp_instance_draining hook.
	 *
	 * @covers ::wp_handle_instance_drain
	 */
	public function test_instance_drain_hook_is_registrable() {
		add_action(
			'wp_instance_draining',
			function () {
				// Intentionally empty.
			}
		);

		$this->assertGreaterThan(
			0,
			has_action( 'wp_instance_draining' ),
			'Should be able to register callbacks on wp_instance_draining.'
		);
	}

	/**
	 * wp_handle_instance_drain was loaded by wp-settings.php bootstrap.
	 *
	 * @covers ::wp_handle_instance_drain
	 */
	public function test_instance_drain_registered_in_settings() {
		$this->assertTrue( is_callable( 'wp_handle_instance_drain' ) );
	}
}
