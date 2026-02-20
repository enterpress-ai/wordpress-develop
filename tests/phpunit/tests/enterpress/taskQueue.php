<?php

/**
 * EnterPress: Integration tests for the Task Queue API.
 *
 * Tests cross-system interactions between WordPress cron functions
 * and the {prefix}task_queue database table that replaces the
 * wp_options-based cron storage.
 *
 * @group enterpress
 * @group cron
 */
class Tests_EnterPress_TaskQueue extends WP_UnitTestCase {

	/**
	 * @var string Full table name with prefix.
	 */
	private $table;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$this->table = $wpdb->prefix . 'task_queue';

		// The framework's _create_temporary_tables filter converts
		// CREATE TABLE to CREATE TEMPORARY TABLE automatically.
		$wpdb->query(
			"CREATE TABLE {$this->table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				hook VARCHAR(255) NOT NULL,
				args TEXT,
				args_hash VARCHAR(32) NOT NULL DEFAULT '',
				schedule VARCHAR(255) DEFAULT NULL,
				interval_seconds INT UNSIGNED DEFAULT NULL,
				next_run DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				claimed_by VARCHAR(255) DEFAULT NULL,
				claimed_at DATETIME DEFAULT NULL,
				completed_at DATETIME DEFAULT NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_task_queue_hook (hook),
				INDEX idx_task_queue_next_run_status (next_run, status)
			) ENGINE=InnoDB"
		);
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$this->table}" );
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Full lifecycle: Schedule -> Retrieve -> Unschedule
	// -------------------------------------------------------------------

	/**
	 * Schedule a single event, verify raw DB row, retrieve via API,
	 * check wp_next_scheduled, unschedule, verify row deleted.
	 *
	 * @covers ::wp_schedule_single_event
	 * @covers ::wp_get_scheduled_event
	 * @covers ::wp_unschedule_event
	 * @covers ::wp_next_scheduled
	 */
	public function test_single_event_full_lifecycle_through_task_queue() {
		global $wpdb;

		$hook      = 'ep_test_single_lifecycle';
		$timestamp = strtotime( '+1 hour' );
		$args      = array( 'user_id' => 42 );

		// 1. Schedule.
		$result = wp_schedule_single_event( $timestamp, $hook, $args );
		$this->assertTrue( $result, 'wp_schedule_single_event should return true.' );

		// 2. Verify raw DB row.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE hook = %s",
				$hook
			)
		);
		$this->assertNotNull( $row, 'Row should exist in task_queue table.' );
		$this->assertSame( $hook, $row->hook );
		$this->assertSame( serialize( $args ), $row->args );
		$this->assertSame( md5( serialize( $args ) ), $row->args_hash );
		$this->assertNull( $row->schedule, 'Single events have null schedule.' );
		$this->assertNull( $row->interval_seconds, 'Single events have null interval.' );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $timestamp ), $row->next_run );
		$this->assertSame( 'pending', $row->status );

		// 3. Retrieve via API.
		$event = wp_get_scheduled_event( $hook, $args, $timestamp );
		$this->assertIsObject( $event );
		$this->assertSame( $hook, $event->hook );
		$this->assertSame( $timestamp, $event->timestamp );
		$this->assertFalse( $event->schedule, 'Single events report schedule as false.' );
		$this->assertSame( $args, $event->args );
		$this->assertObjectNotHasProperty( 'interval', $event );

		// 4. wp_next_scheduled returns correct timestamp.
		$this->assertSame( $timestamp, wp_next_scheduled( $hook, $args ) );

		// 5. Unschedule.
		$unscheduled = wp_unschedule_event( $timestamp, $hook, $args );
		$this->assertTrue( $unscheduled );

		// 6. Verify row is gone.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE hook = %s",
				$hook
			)
		);
		$this->assertSame( '0', $count, 'Row should be deleted from task_queue.' );
		$this->assertFalse( wp_next_scheduled( $hook, $args ) );
	}

	/**
	 * Schedule a recurring event, verify DB row has schedule and interval,
	 * retrieve it, confirm event object includes interval property.
	 *
	 * @covers ::wp_schedule_event
	 * @covers ::wp_get_scheduled_event
	 * @covers ::wp_get_schedule
	 */
	public function test_recurring_event_full_lifecycle_through_task_queue() {
		global $wpdb;

		$hook      = 'ep_test_recurring_lifecycle';
		$timestamp = strtotime( '+1 hour' );
		$recur     = 'daily';
		$args      = array( 'task' => 'cleanup' );

		$result = wp_schedule_event( $timestamp, $recur, $hook, $args );
		$this->assertTrue( $result );

		// Verify raw row.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE hook = %s",
				$hook
			)
		);
		$this->assertSame( 'daily', $row->schedule );
		$this->assertSame( (string) DAY_IN_SECONDS, $row->interval_seconds );

		// Verify event object.
		$event = wp_get_scheduled_event( $hook, $args, $timestamp );
		$this->assertSame( 'daily', $event->schedule );
		$this->assertSame( DAY_IN_SECONDS, $event->interval );
		$this->assertSame( $timestamp, $event->timestamp );

		// Verify wp_get_schedule.
		$this->assertSame( 'daily', wp_get_schedule( $hook, $args ) );
	}

	// -------------------------------------------------------------------
	// Duplicate detection (10-minute window)
	// -------------------------------------------------------------------

	/**
	 * Events within 10 minutes with same hook+args should be blocked.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_duplicate_detection_within_10_minute_window() {
		$hook = 'ep_test_dup';
		$args = array( 'key' => 'value' );
		$ts1  = strtotime( '+15 minutes' );

		$this->assertTrue( wp_schedule_single_event( $ts1, $hook, $args ) );

		// Within 10 minutes of ts1 -- should be blocked.
		$ts2 = $ts1 + ( 5 * MINUTE_IN_SECONDS );
		$this->assertFalse( wp_schedule_single_event( $ts2, $hook, $args ) );

		// With wp_error=true for the error code.
		$result = wp_schedule_single_event( $ts2, $hook, $args, true );
		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_event', $result->get_error_code() );
	}

	/**
	 * Events more than 10 minutes apart should NOT be duplicates.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_no_duplicate_detection_outside_10_minute_window() {
		$hook = 'ep_test_no_dup';
		$args = array( 'key' => 'value' );
		$ts1  = strtotime( '+15 minutes' );

		$this->assertTrue( wp_schedule_single_event( $ts1, $hook, $args ) );

		$ts2 = $ts1 + ( 11 * MINUTE_IN_SECONDS );
		$this->assertTrue( wp_schedule_single_event( $ts2, $hook, $args ) );
	}

	/**
	 * Different args produce different args_hash, so not duplicates.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_different_args_are_not_duplicates() {
		$hook = 'ep_test_diff_args';
		$ts   = strtotime( '+5 minutes' );

		$this->assertTrue( wp_schedule_single_event( $ts, $hook, array( 'a' ) ) );
		$this->assertTrue( wp_schedule_single_event( $ts, $hook, array( 'b' ) ) );
	}

	/**
	 * Events with no args vs with args have different hashes.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_args_vs_no_args_are_not_duplicates() {
		$hook = 'ep_test_args_noargs';
		$ts   = strtotime( '+5 minutes' );

		$this->assertTrue( wp_schedule_single_event( $ts, $hook ) );
		$this->assertTrue( wp_schedule_single_event( $ts, $hook, array( 'x' ) ) );
	}

	/**
	 * Past events participate in duplicate detection for near-future events.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_duplicate_detection_with_past_event() {
		$hook = 'ep_test_past_dup';
		$args = array( 'a' );
		$ts1  = strtotime( '-5 minutes' );

		$this->assertTrue( wp_schedule_single_event( $ts1, $hook, $args ) );

		// A future timestamp within 10 min of now should collide.
		$ts2 = strtotime( '+3 minutes' );
		$this->assertFalse( wp_schedule_single_event( $ts2, $hook, $args ) );
	}

	// -------------------------------------------------------------------
	// wp_reschedule_event through task_queue layer
	// -------------------------------------------------------------------

	/**
	 * wp_reschedule_event reads existing event from task_queue, then
	 * calls wp_schedule_event. Verify the full chain.
	 *
	 * @covers ::wp_reschedule_event
	 * @covers ::wp_get_scheduled_event
	 * @covers ::wp_schedule_event
	 */
	public function test_reschedule_event_creates_new_row_in_task_queue() {
		global $wpdb;

		$hook = 'ep_test_reschedule';
		$ts   = time();
		$args = array( 1, 2, 3 );

		$this->assertTrue( wp_schedule_event( $ts, 'daily', $hook, $args ) );

		$result = wp_reschedule_event( $ts, 'daily', $hook, $args );
		$this->assertTrue( $result );

		// UPSERT behavior: the existing row is updated (not duplicated).
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE hook = %s",
				$hook
			)
		);
		$this->assertSame( '1', $count, 'Rescheduling should update the existing row, not create a duplicate.' );

		// The rescheduled event should have a future timestamp.
		$new_event = wp_get_scheduled_event( $hook, $args );
		$this->assertGreaterThan( $ts, $new_event->timestamp );
	}

	/**
	 * wp_reschedule_event falls back to stored interval_seconds
	 * when the schedule name is removed from wp_get_schedules.
	 *
	 * @covers ::wp_reschedule_event
	 */
	public function test_reschedule_event_uses_stored_interval_when_schedule_gone() {
		$hook = 'ep_test_reschedule_fallback';
		$ts   = time();

		// Add a custom schedule.
		$add_schedule = function ( $schedules ) {
			$schedules['every_five_min'] = array(
				'interval' => 300,
				'display'  => 'Every 5 Minutes',
			);
			return $schedules;
		};
		add_filter( 'cron_schedules', $add_schedule );

		$this->assertTrue( wp_schedule_event( $ts, 'every_five_min', $hook ) );

		// Remove the custom schedule.
		remove_filter( 'cron_schedules', $add_schedule );

		// Reschedule -- should read interval from stored row and update in-place.
		$result = wp_reschedule_event( $ts, 'every_five_min', $hook );
		$this->assertTrue( $result );

		// The existing row was updated, so wp_next_scheduled returns the new time.
		$next_ts = wp_next_scheduled( $hook );
		$this->assertGreaterThanOrEqual( $ts + 300, $next_ts );
	}

	// -------------------------------------------------------------------
	// wp_clear_scheduled_hook and wp_unschedule_hook
	// -------------------------------------------------------------------

	/**
	 * wp_clear_scheduled_hook deletes only events matching specific args.
	 *
	 * @covers ::wp_clear_scheduled_hook
	 */
	public function test_clear_scheduled_hook_respects_args() {
		$hook   = 'ep_test_clear_args';
		$args_a = array( 'a' );
		$args_b = array( 'b' );

		wp_schedule_single_event( strtotime( '+1 hour' ), $hook, $args_a );
		wp_schedule_single_event( strtotime( '+2 hours' ), $hook, $args_a );
		wp_schedule_single_event( strtotime( '+3 hours' ), $hook, $args_b );

		$cleared = wp_clear_scheduled_hook( $hook, $args_a );
		$this->assertSame( 2, $cleared );

		// args_b events should remain.
		$this->assertGreaterThan( 0, wp_next_scheduled( $hook, $args_b ) );
		$this->assertFalse( wp_next_scheduled( $hook, $args_a ) );
	}

	/**
	 * wp_clear_scheduled_hook with empty args clears only empty-args events.
	 *
	 * @covers ::wp_clear_scheduled_hook
	 */
	public function test_clear_scheduled_hook_empty_args_only_clears_empty_args() {
		$hook = 'ep_test_clear_empty';

		wp_schedule_single_event( strtotime( '+1 hour' ), $hook );
		wp_schedule_single_event( strtotime( '+2 hours' ), $hook );
		wp_schedule_single_event( strtotime( '+3 hours' ), $hook, array( 'x' ) );

		$cleared = wp_clear_scheduled_hook( $hook );
		$this->assertSame( 2, $cleared );
		$this->assertGreaterThan( 0, wp_next_scheduled( $hook, array( 'x' ) ) );
	}

	/**
	 * Backward-compat: non-array args calling convention.
	 *
	 * @expectedDeprecated wp_clear_scheduled_hook
	 * @covers ::wp_clear_scheduled_hook
	 */
	public function test_clear_scheduled_hook_deprecated_non_array_args() {
		$hook = 'ep_test_clear_deprecated';

		wp_schedule_single_event( strtotime( '+1 hour' ), $hook, array( 1, 2, 3 ) );

		$cleared = wp_clear_scheduled_hook( $hook, 1, 2, 3 );
		$this->assertSame( 1, $cleared );
	}

	/**
	 * wp_unschedule_hook deletes ALL events for a hook regardless of args.
	 *
	 * @covers ::wp_unschedule_hook
	 */
	public function test_unschedule_hook_removes_all_events() {
		$hook = 'ep_test_unsched_hook';

		wp_schedule_single_event( strtotime( '+1 hour' ), $hook );
		wp_schedule_single_event( strtotime( '+2 hours' ), $hook, array( 'foo' ) );
		wp_schedule_event( strtotime( '+3 hours' ), 'hourly', $hook, array( 'bar' ) );

		$removed = wp_unschedule_hook( $hook );
		$this->assertSame( 3, $removed );
		$this->assertFalse( wp_next_scheduled( $hook ) );
		$this->assertFalse( wp_next_scheduled( $hook, array( 'foo' ) ) );
		$this->assertFalse( wp_next_scheduled( $hook, array( 'bar' ) ) );
	}

	/**
	 * wp_unschedule_hook on a hook with no events returns 0.
	 *
	 * @covers ::wp_unschedule_hook
	 */
	public function test_unschedule_hook_returns_zero_for_nonexistent() {
		$this->assertSame( 0, wp_unschedule_hook( 'nonexistent_hook' ) );
	}

	// -------------------------------------------------------------------
	// wp_get_ready_cron_jobs
	// -------------------------------------------------------------------

	/**
	 * wp_get_ready_cron_jobs returns legacy nested array format from
	 * task_queue rows where next_run <= now.
	 *
	 * @covers ::wp_get_ready_cron_jobs
	 */
	public function test_get_ready_cron_jobs_returns_legacy_format() {
		$hook1     = 'ep_test_ready_single';
		$hook2     = 'ep_test_ready_recurring';
		$past_ts   = strtotime( '-5 minutes' );
		$future_ts = strtotime( '+1 hour' );
		$args      = array( 'x' );

		wp_schedule_single_event( $past_ts, $hook1, $args );
		wp_schedule_event( $past_ts, 'hourly', $hook2 );
		wp_schedule_single_event( $future_ts, $hook1, $args );

		$ready = wp_get_ready_cron_jobs();

		// Past events should be present.
		$past_key = strtotime( gmdate( 'Y-m-d H:i:s', $past_ts ) );
		$this->assertArrayHasKey( $past_key, $ready );
		$this->assertArrayHasKey( $hook1, $ready[ $past_key ] );
		$this->assertArrayHasKey( $hook2, $ready[ $past_key ] );

		// Future events should NOT be present.
		$future_key = strtotime( gmdate( 'Y-m-d H:i:s', $future_ts ) );
		$this->assertArrayNotHasKey( $future_key, $ready );

		// Check recurring event entry structure.
		$hash2 = md5( serialize( array() ) );
		$this->assertArrayHasKey( $hash2, $ready[ $past_key ][ $hook2 ] );
		$entry = $ready[ $past_key ][ $hook2 ][ $hash2 ];
		$this->assertSame( 'hourly', $entry['schedule'] );
		$this->assertSame( HOUR_IN_SECONDS, $entry['interval'] );
		$this->assertSame( array(), $entry['args'] );

		// Check single event entry structure.
		$hash1       = md5( serialize( $args ) );
		$single_entry = $ready[ $past_key ][ $hook1 ][ $hash1 ];
		$this->assertFalse( $single_entry['schedule'] );
		$this->assertArrayNotHasKey( 'interval', $single_entry );
	}

	/**
	 * @covers ::wp_get_ready_cron_jobs
	 */
	public function test_get_ready_cron_jobs_empty_table() {
		$this->assertSame( array(), wp_get_ready_cron_jobs() );
	}

	/**
	 * wp_get_ready_cron_jobs ignores non-pending rows.
	 *
	 * @covers ::wp_get_ready_cron_jobs
	 */
	public function test_get_ready_cron_jobs_ignores_non_pending_status() {
		global $wpdb;

		$hook = 'ep_test_ready_status';
		$ts   = strtotime( '-5 minutes' );

		wp_schedule_single_event( $ts, $hook );

		$wpdb->update(
			$this->table,
			array( 'status' => 'claimed' ),
			array( 'hook' => $hook )
		);

		$this->assertSame( array(), wp_get_ready_cron_jobs() );
	}

	/**
	 * pre_get_ready_cron_jobs filter short-circuits.
	 *
	 * @covers ::wp_get_ready_cron_jobs
	 */
	public function test_get_ready_cron_jobs_pre_filter() {
		$override = array( 'custom' => 'data' );
		add_filter(
			'pre_get_ready_cron_jobs',
			function () use ( $override ) {
				return $override;
			}
		);

		$this->assertSame( $override, wp_get_ready_cron_jobs() );
	}

	// -------------------------------------------------------------------
	// wp_get_scheduled_event edge cases
	// -------------------------------------------------------------------

	/**
	 * Without timestamp, returns the soonest event.
	 *
	 * @covers ::wp_get_scheduled_event
	 */
	public function test_get_scheduled_event_returns_soonest_when_no_timestamp() {
		$hook    = 'ep_test_soonest';
		$args    = array( 'test' );
		$ts_late = strtotime( '+2 hours' );
		$ts_soon = strtotime( '+30 minutes' );

		wp_schedule_single_event( $ts_late, $hook, $args );
		wp_schedule_single_event( $ts_soon, $hook, $args );

		$event = wp_get_scheduled_event( $hook, $args );
		$this->assertSame( $ts_soon, $event->timestamp );
	}

	/**
	 * @covers ::wp_get_scheduled_event
	 */
	public function test_get_scheduled_event_invalid_timestamp() {
		$this->assertFalse( wp_get_scheduled_event( 'any_hook', array(), 'not_a_number' ) );
	}

	/**
	 * @covers ::wp_get_scheduled_event
	 */
	public function test_get_scheduled_event_returns_false_for_missing() {
		$this->assertFalse( wp_get_scheduled_event( 'nonexistent_hook' ) );
		$this->assertFalse( wp_get_scheduled_event( 'nonexistent_hook', array(), time() ) );
	}

	// -------------------------------------------------------------------
	// wp_error parameter behavior
	// -------------------------------------------------------------------

	/**
	 * Invalid timestamps return WP_Error when wp_error=true.
	 *
	 * @covers ::wp_schedule_single_event
	 * @covers ::wp_schedule_event
	 * @covers ::wp_reschedule_event
	 * @covers ::wp_unschedule_event
	 */
	public function test_invalid_timestamp_returns_wp_error() {
		$single = wp_schedule_single_event( -1, 'hook', array(), true );
		$this->assertWPError( $single );
		$this->assertSame( 'invalid_timestamp', $single->get_error_code() );

		$recurring = wp_schedule_event( -1, 'daily', 'hook', array(), true );
		$this->assertWPError( $recurring );
		$this->assertSame( 'invalid_timestamp', $recurring->get_error_code() );

		$resched = wp_reschedule_event( -1, 'daily', 'hook', array(), true );
		$this->assertWPError( $resched );
		$this->assertSame( 'invalid_timestamp', $resched->get_error_code() );

		$unsched = wp_unschedule_event( -1, 'hook', array(), true );
		$this->assertWPError( $unsched );
		$this->assertSame( 'invalid_timestamp', $unsched->get_error_code() );
	}

	/**
	 * Invalid timestamps return false by default.
	 *
	 * @covers ::wp_schedule_single_event
	 * @covers ::wp_schedule_event
	 */
	public function test_invalid_timestamp_returns_false_by_default() {
		$this->assertFalse( wp_schedule_single_event( -1, 'hook' ) );
		$this->assertFalse( wp_schedule_event( -1, 'daily', 'hook' ) );
	}

	/**
	 * Invalid recurrence returns WP_Error.
	 *
	 * @covers ::wp_schedule_event
	 * @covers ::wp_reschedule_event
	 */
	public function test_invalid_recurrence_error() {
		$event = wp_schedule_event( time(), 'nonexistent_schedule', 'hook', array(), true );
		$this->assertWPError( $event );
		$this->assertSame( 'invalid_schedule', $event->get_error_code() );

		$resched = wp_reschedule_event( time(), 'nonexistent_schedule', 'hook', array(), true );
		$this->assertWPError( $resched );
		$this->assertSame( 'invalid_schedule', $resched->get_error_code() );
	}

	/**
	 * schedule_event filter returning false results in error.
	 *
	 * @covers ::wp_schedule_single_event
	 * @covers ::wp_schedule_event
	 */
	public function test_schedule_event_filter_false_returns_error() {
		add_filter( 'schedule_event', '__return_false' );

		$single = wp_schedule_single_event( strtotime( '+1 hour' ), 'hook', array(), true );
		$this->assertWPError( $single );
		$this->assertSame( 'schedule_event_false', $single->get_error_code() );

		$recurring = wp_schedule_event( strtotime( '+1 hour' ), 'daily', 'hook', array(), true );
		$this->assertWPError( $recurring );
		$this->assertSame( 'schedule_event_false', $recurring->get_error_code() );
	}

	// -------------------------------------------------------------------
	// Filter hooks preserved (backward compat)
	// -------------------------------------------------------------------

	/**
	 * pre_schedule_event filter can short-circuit scheduling.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_pre_schedule_event_filter_fires() {
		global $wpdb;

		$fired = false;
		add_filter(
			'pre_schedule_event',
			function ( $pre, $event ) use ( &$fired ) {
				$fired = true;
				return true; // Short-circuit.
			},
			10,
			2
		);

		wp_schedule_single_event( strtotime( '+1 hour' ), 'ep_test_pre_filter' );
		$this->assertTrue( $fired, 'pre_schedule_event filter should fire.' );

		// Since we short-circuited, no row should be in the DB.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE hook = 'ep_test_pre_filter'"
		);
		$this->assertSame( '0', $count );
	}

	/**
	 * schedule_event filter can modify the event object before insert.
	 *
	 * @covers ::wp_schedule_single_event
	 */
	public function test_schedule_event_filter_modifies_event() {
		global $wpdb;

		add_filter(
			'schedule_event',
			function ( $event ) {
				$event->hook = 'modified_hook';
				return $event;
			}
		);

		wp_schedule_single_event( strtotime( '+1 hour' ), 'original_hook' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$this->table} WHERE hook = 'modified_hook'"
		);
		$this->assertNotNull( $row, 'Event should be stored with modified hook.' );
	}

	/**
	 * pre_unschedule_event filter can short-circuit unscheduling.
	 *
	 * @covers ::wp_unschedule_event
	 */
	public function test_pre_unschedule_event_filter_fires() {
		$hook = 'ep_test_pre_unsched';
		$ts   = strtotime( '+1 hour' );

		wp_schedule_single_event( $ts, $hook );

		add_filter( 'pre_unschedule_event', '__return_true' );
		wp_unschedule_event( $ts, $hook );

		// Event should still exist because filter short-circuited.
		$this->assertSame( $ts, wp_next_scheduled( $hook ) );
	}

	/**
	 * pre_get_scheduled_event filter can return a custom event.
	 *
	 * @covers ::wp_get_scheduled_event
	 */
	public function test_pre_get_scheduled_event_filter_fires() {
		$custom = (object) array(
			'hook'      => 'custom_hook',
			'timestamp' => 12345,
			'schedule'  => false,
			'args'      => array(),
		);

		add_filter(
			'pre_get_scheduled_event',
			function () use ( $custom ) {
				return $custom;
			}
		);

		$event = wp_get_scheduled_event( 'any_hook' );
		$this->assertEquals( $custom, $event );
	}

	/**
	 * pre_clear_scheduled_hook filter can short-circuit clearing.
	 *
	 * @covers ::wp_clear_scheduled_hook
	 */
	public function test_pre_clear_scheduled_hook_filter() {
		add_filter( 'pre_clear_scheduled_hook', '__return_true' );

		$hook = 'ep_test_pre_clear';
		wp_schedule_single_event( strtotime( '+1 hour' ), $hook );

		$result = wp_clear_scheduled_hook( $hook );
		$this->assertTrue( $result );
		// Event should still exist.
		$this->assertGreaterThan( 0, wp_next_scheduled( $hook ) );
	}

	/**
	 * pre_unschedule_hook filter can short-circuit.
	 *
	 * @covers ::wp_unschedule_hook
	 */
	public function test_pre_unschedule_hook_filter() {
		add_filter( 'pre_unschedule_hook', '__return_zero' );

		$hook = 'ep_test_pre_unsched_hook';
		wp_schedule_single_event( strtotime( '+1 hour' ), $hook );

		$result = wp_unschedule_hook( $hook );
		$this->assertSame( 0, $result );
		// Event should still exist.
		$this->assertGreaterThan( 0, wp_next_scheduled( $hook ) );
	}

	// -------------------------------------------------------------------
	// Multiple events coexistence
	// -------------------------------------------------------------------

	/**
	 * Multiple events with same hook but different args coexist.
	 *
	 * @covers ::wp_schedule_single_event
	 * @covers ::wp_next_scheduled
	 */
	public function test_multiple_events_different_args_coexist() {
		$hook   = 'ep_test_multi_args';
		$ts_a   = strtotime( '+1 hour' );
		$ts_b   = strtotime( '+2 hours' );
		$args_a = array( 'user' => 1 );
		$args_b = array( 'user' => 2 );

		wp_schedule_single_event( $ts_a, $hook, $args_a );
		wp_schedule_single_event( $ts_b, $hook, $args_b );

		$this->assertSame( $ts_a, wp_next_scheduled( $hook, $args_a ) );
		$this->assertSame( $ts_b, wp_next_scheduled( $hook, $args_b ) );
		// No-args query returns false because neither event has empty args.
		$this->assertFalse( wp_next_scheduled( $hook ) );
	}
}
