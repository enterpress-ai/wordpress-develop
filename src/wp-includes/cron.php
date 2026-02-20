<?php
/**
 * WordPress Cron API
 *
 * @package WordPress
 */

/**
 * Schedules an event to run only once.
 *
 * Schedules a hook which will be triggered by WordPress at the specified UTC time.
 * The action will trigger when someone visits your WordPress site if the scheduled
 * time has passed.
 *
 * Note that scheduling an event to occur within 10 minutes of an existing event
 * with the same action hook will be ignored unless you pass unique `$args` values
 * for each scheduled event.
 *
 * Use wp_next_scheduled() to prevent duplicate events.
 *
 * Use wp_schedule_event() to schedule a recurring event.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to boolean indicating success or failure,
 *              {@see 'pre_schedule_event'} filter added to short-circuit the function.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @link https://developer.wordpress.org/reference/functions/wp_schedule_single_event/
 *
 * @param int    $timestamp  Unix timestamp (UTC) for when to next run the event.
 * @param string $hook       Action hook to execute when the event is run.
 * @param array  $args       Optional. Array containing arguments to pass to the
 *                           hook's callback function. Each value in the array
 *                           is passed to the callback as an individual parameter.
 *                           The array keys are ignored. Default empty array.
 *
 *                           These arguments are used to uniquely identify the
 *                           scheduled event and must match those used when the
 *                           event was originally scheduled. If the arguments
 *                           do not match exactly, WordPress will treat the
 *                           event as different, which can lead to duplicate
 *                           cron events being scheduled unintentionally,
 *                           excessive growth of the 'cron' option, and
 *                           database performance issues.
 * @param bool   $wp_error   Optional. Whether to return a WP_Error on failure. Default false.
 * @return bool|WP_Error True if event successfully scheduled. False or WP_Error on failure.
 */
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	global $wpdb;

	// Make sure timestamp is a positive integer.
	if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_timestamp',
				__( 'Event timestamp must be a valid Unix timestamp.' )
			);
		}

		return false;
	}

	$event = (object) array(
		'hook'      => $hook,
		'timestamp' => $timestamp,
		'schedule'  => false,
		'args'      => $args,
	);

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_schedule_event', null, $event, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_schedule_event_false',
				__( 'A plugin prevented the event from being scheduled.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	/*
	 * EnterPress: Duplicate check via task_queue table.
	 */
	$args_hash = md5( serialize( $event->args ) );

	if ( $event->timestamp < time() + 10 * MINUTE_IN_SECONDS ) {
		$min_timestamp = 0;
	} else {
		$min_timestamp = $event->timestamp - 10 * MINUTE_IN_SECONDS;
	}

	if ( $event->timestamp < time() ) {
		$max_timestamp = time() + 10 * MINUTE_IN_SECONDS;
	} else {
		$max_timestamp = $event->timestamp + 10 * MINUTE_IN_SECONDS;
	}

	$table     = $wpdb->prefix . 'task_queue';
	$min_dt    = gmdate( 'Y-m-d H:i:s', $min_timestamp );
	$max_dt    = gmdate( 'Y-m-d H:i:s', $max_timestamp );
	$duplicate = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND args_hash = %s AND next_run BETWEEN %s AND %s AND status IN ('pending','claimed')",
			$event->hook,
			$args_hash,
			$min_dt,
			$max_dt
		)
	);

	if ( $duplicate ) {
		if ( $wp_error ) {
			return new WP_Error(
				'duplicate_event',
				__( 'A duplicate event already exists.' )
			);
		}

		return false;
	}

	/** This filter is documented in wp-includes/cron.php */
	$event = apply_filters( 'schedule_event', $event );

	// A plugin disallowed this event.
	if ( ! $event ) {
		if ( $wp_error ) {
			return new WP_Error(
				'schedule_event_false',
				__( 'A plugin disallowed this event.' )
			);
		}

		return false;
	}

	$result = $wpdb->insert(
		$table,
		array(
			'hook'             => $event->hook,
			'args'             => serialize( $event->args ),
			'args_hash'        => $args_hash,
			'schedule'         => null,
			'interval_seconds' => null,
			'next_run'         => gmdate( 'Y-m-d H:i:s', $event->timestamp ),
			'status'           => 'pending',
		),
		array( '%s', '%s', '%s', null, null, '%s', '%s' )
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron event could not be saved.' )
			);
		}

		return false;
	}

	return true;
}

/**
 * Schedules a recurring event.
 *
 * Schedules a hook which will be triggered by WordPress at the specified interval.
 * The action will trigger when someone visits your WordPress site if the scheduled
 * time has passed.
 *
 * Valid values for the recurrence are 'hourly', 'twicedaily', 'daily', and 'weekly'.
 * These can be extended using the {@see 'cron_schedules'} filter in wp_get_schedules().
 *
 * Use wp_next_scheduled() to prevent duplicate events.
 *
 * Use wp_schedule_single_event() to schedule a non-recurring event.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to boolean indicating success or failure,
 *              {@see 'pre_schedule_event'} filter added to short-circuit the function.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @link https://developer.wordpress.org/reference/functions/wp_schedule_event/
 *
 * @param int    $timestamp  Unix timestamp (UTC) for when to next run the event.
 * @param string $recurrence How often the event should subsequently recur.
 *                           See wp_get_schedules() for accepted values.
 * @param string $hook       Action hook to execute when the event is run.
 * @param array  $args       Optional. Array containing arguments to pass to the
 *                           hook's callback function. Each value in the array
 *                           is passed to the callback as an individual parameter.
 *                           The array keys are ignored. Default empty array.
 *
 *                           These arguments are used to uniquely identify the
 *                           scheduled event and must match those used when the
 *                           event was originally scheduled. If the arguments
 *                           do not match exactly, WordPress will treat the
 *                           event as different, which can lead to duplicate
 *                           cron events being scheduled unintentionally,
 *                           excessive growth of the 'cron' option, and
 *                           database performance issues.
 * @param bool   $wp_error   Optional. Whether to return a WP_Error on failure. Default false.
 * @return bool|WP_Error True if event successfully scheduled. False or WP_Error on failure.
 */
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	global $wpdb;

	// Make sure timestamp is a positive integer.
	if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_timestamp',
				__( 'Event timestamp must be a valid Unix timestamp.' )
			);
		}

		return false;
	}

	$schedules = wp_get_schedules();

	if ( ! isset( $schedules[ $recurrence ] ) ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_schedule',
				__( 'Event schedule does not exist.' )
			);
		}

		return false;
	}

	$event = (object) array(
		'hook'      => $hook,
		'timestamp' => $timestamp,
		'schedule'  => $recurrence,
		'args'      => $args,
		'interval'  => $schedules[ $recurrence ]['interval'],
	);

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_schedule_event', null, $event, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_schedule_event_false',
				__( 'A plugin prevented the event from being scheduled.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	/** This filter is documented in wp-includes/cron.php */
	$event = apply_filters( 'schedule_event', $event );

	// A plugin disallowed this event.
	if ( ! $event ) {
		if ( $wp_error ) {
			return new WP_Error(
				'schedule_event_false',
				__( 'A plugin disallowed this event.' )
			);
		}

		return false;
	}

	$args_hash = md5( serialize( $event->args ) );
	$table     = $wpdb->prefix . 'task_queue';

	$result = $wpdb->insert(
		$table,
		array(
			'hook'             => $event->hook,
			'args'             => serialize( $event->args ),
			'args_hash'        => $args_hash,
			'schedule'         => $event->schedule,
			'interval_seconds' => $event->interval,
			'next_run'         => gmdate( 'Y-m-d H:i:s', $event->timestamp ),
			'status'           => 'pending',
		),
		array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron event could not be saved.' )
			);
		}

		return false;
	}

	return true;
}

/**
 * Reschedules a recurring event.
 *
 * Mainly for internal use, this takes the Unix timestamp (UTC) of a previously run
 * recurring event and reschedules it for its next run.
 *
 * To change upcoming scheduled events, use wp_schedule_event() to
 * change the recurrence frequency.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to boolean indicating success or failure,
 *              {@see 'pre_reschedule_event'} filter added to short-circuit the function.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @param int    $timestamp  Unix timestamp (UTC) for when the event was scheduled.
 * @param string $recurrence How often the event should subsequently recur.
 *                           See wp_get_schedules() for accepted values.
 * @param string $hook       Action hook to execute when the event is run.
 * @param array  $args       Optional. Array containing arguments to pass to the
 *                           hook's callback function. Each value in the array
 *                           is passed to the callback as an individual parameter.
 *                           The array keys are ignored. Default empty array.
 *
 *                           These arguments are used to uniquely identify the
 *                           scheduled event and must match those used when the
 *                           event was originally scheduled. If the arguments
 *                           do not match exactly, WordPress will treat the
 *                           event as different, which can lead to duplicate
 *                           cron events being scheduled unintentionally,
 *                           excessive growth of the 'cron' option, and
 *                           database performance issues.
 * @param bool   $wp_error   Optional. Whether to return a WP_Error on failure. Default false.
 * @return bool|WP_Error True if event successfully rescheduled. False or WP_Error on failure.
 */
function wp_reschedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	// Make sure timestamp is a positive integer.
	if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_timestamp',
				__( 'Event timestamp must be a valid Unix timestamp.' )
			);
		}

		return false;
	}

	$schedules = wp_get_schedules();
	$interval  = 0;

	// First we try to get the interval from the schedule.
	if ( isset( $schedules[ $recurrence ] ) ) {
		$interval = $schedules[ $recurrence ]['interval'];
	}

	// Now we try to get it from the saved interval in case the schedule disappears.
	if ( 0 === $interval ) {
		$scheduled_event = wp_get_scheduled_event( $hook, $args, $timestamp );

		if ( $scheduled_event && isset( $scheduled_event->interval ) ) {
			$interval = $scheduled_event->interval;
		}
	}

	$event = (object) array(
		'hook'      => $hook,
		'timestamp' => $timestamp,
		'schedule'  => $recurrence,
		'args'      => $args,
		'interval'  => $interval,
	);

	/**
	 * Filter to override rescheduling of a recurring event.
	 *
	 * Returning a non-null value will short-circuit the normal rescheduling
	 * process, causing the function to return the filtered value instead.
	 *
	 * For plugins replacing wp-cron, return true if the event was successfully
	 * rescheduled, false or a WP_Error if not.
	 *
	 * @since 5.1.0
	 * @since 5.7.0 The `$wp_error` parameter was added, and a WP_Error object can now be returned.
	 *
	 * @param null|bool|WP_Error $pre      Value to return instead. Default null to continue adding the event.
	 * @param object             $event    {
	 *     An object containing an event's data.
	 *
	 *     @type string $hook      Action hook to execute when the event is run.
	 *     @type int    $timestamp Unix timestamp (UTC) for when to next run the event.
	 *     @type string $schedule  How often the event should subsequently recur.
	 *     @type array  $args      Array containing each separate argument to pass to the hook's callback function.
	 *     @type int    $interval  The interval time in seconds for the schedule.
	 * }
	 * @param bool               $wp_error Whether to return a WP_Error on failure.
	 */
	$pre = apply_filters( 'pre_reschedule_event', null, $event, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_reschedule_event_false',
				__( 'A plugin prevented the event from being rescheduled.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	// Now we assume something is wrong and fail to schedule.
	if ( 0 === $interval ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_schedule',
				__( 'Event schedule does not exist.' )
			);
		}

		return false;
	}

	$now = time();

	if ( $timestamp >= $now ) {
		$timestamp = $now + $interval;
	} else {
		$timestamp = $now + ( $interval - ( ( $now - $timestamp ) % $interval ) );
	}

	// If the schedule still exists, delegate to wp_schedule_event.
	$schedules = wp_get_schedules();
	if ( isset( $schedules[ $recurrence ] ) ) {
		return wp_schedule_event( $timestamp, $recurrence, $hook, $args, $wp_error );
	}

	// EnterPress: Schedule is gone but we have a stored interval.
	// Insert directly into the task_queue to avoid the schedule validation
	// in wp_schedule_event(). This keeps recurring events alive even when
	// the originating plugin / schedule is temporarily unavailable.
	global $wpdb;

	$args_hash = md5( serialize( $args ) );
	$table     = $wpdb->prefix . 'task_queue';

	$result = $wpdb->insert(
		$table,
		array(
			'hook'             => $hook,
			'args'             => serialize( $args ),
			'args_hash'        => $args_hash,
			'schedule'         => $recurrence,
			'interval_seconds' => $interval,
			'next_run'         => gmdate( 'Y-m-d H:i:s', $timestamp ),
			'status'           => 'pending',
		),
		array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron event could not be saved.' )
			);
		}

		return false;
	}

	return true;
}

/**
 * Unschedules a previously scheduled event.
 *
 * The `$timestamp` and `$hook` parameters are required so that the event can be
 * identified.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to boolean indicating success or failure,
 *              {@see 'pre_unschedule_event'} filter added to short-circuit the function.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @param int    $timestamp Unix timestamp (UTC) of the event.
 * @param string $hook      Action hook of the event.
 * @param array  $args      Optional. Array containing each separate argument to pass to the hook's callback function.
 *                          Although not passed to a callback, these arguments are used to uniquely identify the
 *                          event, so they must match those used when originally scheduling the event. If the
 *                          arguments do not match exactly, the event will not be found. Default empty array.
 * @param bool   $wp_error  Optional. Whether to return a WP_Error on failure. Default false.
 * @return bool|WP_Error True if event successfully unscheduled. False or WP_Error on failure.
 */
function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	global $wpdb;

	// Make sure timestamp is a positive integer.
	if ( ! is_numeric( $timestamp ) || $timestamp <= 0 ) {
		if ( $wp_error ) {
			return new WP_Error(
				'invalid_timestamp',
				__( 'Event timestamp must be a valid Unix timestamp.' )
			);
		}

		return false;
	}

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_unschedule_event', null, $timestamp, $hook, $args, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_unschedule_event_false',
				__( 'A plugin prevented the event from being unscheduled.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	$table     = $wpdb->prefix . 'task_queue';
	$args_hash = md5( serialize( $args ) );
	$next_run  = gmdate( 'Y-m-d H:i:s', $timestamp );

	$result = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE hook = %s AND args_hash = %s AND next_run = %s",
			$hook,
			$args_hash,
			$next_run
		)
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron event could not be deleted.' )
			);
		}

		return false;
	}

	return true;
}

/**
 * Unschedules all events attached to the hook with the specified arguments.
 *
 * Warning: This function may return boolean false, but may also return a non-boolean
 * value which evaluates to false. For information about casting to booleans see the
 * {@link https://www.php.net/manual/en/language.types.boolean.php PHP documentation}. Use
 * the `===` operator for testing the return value of this function.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to indicate success or failure,
 *              {@see 'pre_clear_scheduled_hook'} filter added to short-circuit the function.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @param string $hook     Action hook, the execution of which will be unscheduled.
 * @param array  $args     Optional. Array containing each separate argument to pass to the hook's callback function.
 *                         Although not passed to a callback, these arguments are used to uniquely identify the
 *                         event, so they must match those used when originally scheduling the event. If the
 *                         arguments do not match exactly, the event will not be found. Default empty array.
 * @param bool   $wp_error Optional. Whether to return a WP_Error on failure. Default false.
 * @return int|false|WP_Error On success an integer indicating number of events unscheduled (0 indicates no
 *                            events were registered with the hook and arguments combination), false or WP_Error
 *                            if unscheduling one or more events fail.
 */
function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) {
	global $wpdb;

	/*
	 * Backward compatibility.
	 * Previously, this function took the arguments as discrete vars rather than an array like the rest of the API.
	 */
	if ( ! is_array( $args ) ) {
		_deprecated_argument(
			__FUNCTION__,
			'3.0.0',
			__( 'This argument has changed to an array to match the behavior of the other cron functions.' )
		);

		$args     = array_slice( func_get_args(), 1 ); // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.NeedsInspection
		$wp_error = false;
	}

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_clear_scheduled_hook', null, $hook, $args, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_clear_scheduled_hook_false',
				__( 'A plugin prevented the hook from being cleared.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	$table     = $wpdb->prefix . 'task_queue';
	$args_hash = md5( serialize( $args ) );

	$result = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE hook = %s AND args_hash = %s",
			$hook,
			$args_hash
		)
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron events could not be deleted.' )
			);
		}

		return false;
	}

	return (int) $result;
}

/**
 * Unschedules all events attached to the hook.
 *
 * Can be useful for plugins when deactivating to clean up the cron queue.
 *
 * Warning: This function may return boolean false, but may also return a non-boolean
 * value which evaluates to false. For information about casting to booleans see the
 * {@link https://www.php.net/manual/en/language.types.boolean.php PHP documentation}. Use
 * the `===` operator for testing the return value of this function.
 *
 * @since 4.9.0
 * @since 5.1.0 Return value added to indicate success or failure.
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @param string $hook     Action hook, the execution of which will be unscheduled.
 * @param bool   $wp_error Optional. Whether to return a WP_Error on failure. Default false.
 * @return int|false|WP_Error On success an integer indicating number of events unscheduled (0 indicates no
 *                            events were registered on the hook), false or WP_Error if unscheduling fails.
 */
function wp_unschedule_hook( $hook, $wp_error = false ) {
	global $wpdb;

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_unschedule_hook', null, $hook, $wp_error );

	if ( null !== $pre ) {
		if ( $wp_error && false === $pre ) {
			return new WP_Error(
				'pre_unschedule_hook_false',
				__( 'A plugin prevented the hook from being cleared.' )
			);
		}

		if ( ! $wp_error && is_wp_error( $pre ) ) {
			return false;
		}

		return $pre;
	}

	$table  = $wpdb->prefix . 'task_queue';
	$result = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE hook = %s",
			$hook
		)
	);

	if ( false === $result ) {
		if ( $wp_error ) {
			return new WP_Error(
				'could_not_set',
				__( 'The cron events could not be deleted.' )
			);
		}

		return false;
	}

	return (int) $result;
}

/**
 * Retrieves a scheduled event.
 *
 * Retrieves the full event object for a given event, if no timestamp is specified the next
 * scheduled event is returned.
 *
 * @since 5.1.0
 *
 * @param string   $hook      Action hook of the event.
 * @param array    $args      Optional. Array containing each separate argument to pass to the hook's callback function.
 *                            Although not passed to a callback, these arguments are used to uniquely identify the
 *                            event, so they should be the same as those used when originally scheduling the event.
 *                            Default empty array.
 * @param int|null $timestamp Optional. Unix timestamp (UTC) of the event. If not specified, the next scheduled event
 *                            is returned. Default null.
 * @return object|false {
 *     The event object. False if the event does not exist.
 *
 *     @type string       $hook      Action hook to execute when the event is run.
 *     @type int          $timestamp Unix timestamp (UTC) for when to next run the event.
 *     @type string|false $schedule  How often the event should subsequently recur.
 *     @type array        $args      Array containing each separate argument to pass to the hook's callback function.
 *     @type int          $interval  Optional. The interval time in seconds for the schedule. Only present for recurring events.
 * }
 */
function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) {
	global $wpdb;

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_get_scheduled_event', null, $hook, $args, $timestamp );

	if ( null !== $pre ) {
		return $pre;
	}

	if ( null !== $timestamp && ! is_numeric( $timestamp ) ) {
		return false;
	}

	$table     = $wpdb->prefix . 'task_queue';
	$args_hash = md5( serialize( $args ) );

	if ( null !== $timestamp ) {
		$next_run = gmdate( 'Y-m-d H:i:s', $timestamp );
		$row      = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE hook = %s AND args_hash = %s AND next_run = %s AND status IN ('pending','claimed') LIMIT 1",
				$hook,
				$args_hash,
				$next_run
			)
		);
	} else {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE hook = %s AND args_hash = %s AND status IN ('pending','claimed') ORDER BY next_run ASC LIMIT 1",
				$hook,
				$args_hash
			)
		);
	}

	if ( ! $row ) {
		return false;
	}

	$event = (object) array(
		'hook'      => $row->hook,
		'timestamp' => strtotime( $row->next_run ),
		'schedule'  => $row->schedule ? $row->schedule : false,
		'args'      => $args,
	);

	if ( $row->interval_seconds ) {
		$event->interval = (int) $row->interval_seconds;
	}

	return $event;
}

/**
 * Retrieves the timestamp of the next scheduled event for the given hook.
 *
 * @since 2.1.0
 *
 * @param string $hook Action hook of the event.
 * @param array  $args Optional. Array containing each separate argument to pass to the hook's callback function.
 *                     Although not passed to a callback, these arguments are used to uniquely identify the
 *                     event, so they must match those used when originally scheduling the event. If the
 *                     arguments do not match exactly, the event will not be found. Default empty array.
 * @return int|false The Unix timestamp (UTC) of the next time the event will occur. False if the event doesn't exist.
 */
function wp_next_scheduled( $hook, $args = array() ) {
	$next_event = wp_get_scheduled_event( $hook, $args );

	if ( ! $next_event ) {
		return false;
	}

	/**
	 * Filters the timestamp of the next scheduled event for the given hook.
	 *
	 * @since 6.8.0
	 *
	 * @param int    $timestamp  Unix timestamp (UTC) for when to next run the event.
	 * @param object $next_event {
	 *     An object containing an event's data.
	 *
	 *     @type string $hook      Action hook of the event.
	 *     @type int    $timestamp Unix timestamp (UTC) for when to next run the event.
	 *     @type string $schedule  How often the event should subsequently recur.
	 *     @type array  $args      Array containing each separate argument to pass to the hook
	 *                             callback function.
	 *     @type int    $interval  Optional. The interval time in seconds for the schedule. Only
	 *                             present for recurring events.
	 * }
	 * @param string $hook       Action hook of the event.
	 * @param array  $args       Array containing each separate argument to pass to the hook
	 *                           callback function.
	 */
	return apply_filters( 'wp_next_scheduled', $next_event->timestamp, $next_event, $hook, $args );
}

/**
 * Sends a request to run cron through HTTP request that doesn't halt page loading.
 *
 * @since 2.1.0
 * @since 5.1.0 Return values added.
 *
 * @param int $gmt_time Optional. Unix timestamp (UTC). Default 0 (current time is used).
 * @return bool True if spawned, false if no events spawned.
 */
function spawn_cron( $gmt_time = 0 ) {
	// EnterPress: Inline cron removed. Use external cron runner (sidecar).
	return false;
}

/**
 * Registers _wp_cron() to run on the {@see 'shutdown'} action.
 *
 * The spawn_cron() function attempts to make a non-blocking loopback request to `wp-cron.php` (when alternative
 * cron is not being used). However, the wp_remote_post() function does not always respect the `timeout` and
 * `blocking` parameters. A timeout of `0.01` may end up taking 1 second. When this runs at the {@see 'wp_loaded'}
 * action, it increases the Time To First Byte (TTFB) since the HTML cannot be sent while waiting for the cron request
 * to initiate. Moving the spawning of cron to the {@see 'shutdown'} hook allows for the server to flush the HTML document to
 * the browser while waiting for the request.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value added to indicate success or failure.
 * @since 5.7.0 Functionality moved to _wp_cron() to which this becomes a wrapper.
 * @since 6.9.0 The _wp_cron() callback is moved from {@see 'wp_loaded'} to the {@see 'shutdown'} action,
 *              unless `ALTERNATE_WP_CRON` is enabled; the function now always returns void.
 */
function wp_cron(): void {
	// EnterPress: Inline cron removed. Use external cron runner (sidecar).
	return;
}

/**
 * Runs scheduled callbacks or spawns cron for all scheduled events.
 *
 * Warning: This function may return Boolean FALSE, but may also return a non-Boolean
 * value which evaluates to FALSE. For information about casting to booleans see the
 * {@link https://www.php.net/manual/en/language.types.boolean.php PHP documentation}. Use
 * the `===` operator for testing the return value of this function.
 *
 * @since 5.7.0
 * @access private
 *
 * @return int|false On success an integer indicating number of events spawned (0 indicates no
 *                   events needed to be spawned), false if spawning fails for one or more events.
 */
function _wp_cron() {
	// EnterPress: Inline cron removed. Use external cron runner (sidecar).
	return 0;
}

/**
 * Retrieves supported event recurrence schedules.
 *
 * The default supported recurrences are 'hourly', 'twicedaily', 'daily', and 'weekly'.
 * A plugin may add more by hooking into the {@see 'cron_schedules'} filter.
 * The filter accepts an array of arrays. The outer array has a key that is the name
 * of the schedule, for example 'monthly'. The value is an array with two keys,
 * one is 'interval' and the other is 'display'.
 *
 * The 'interval' is a number in seconds of when the cron job should run.
 * So for 'hourly' the time is `HOUR_IN_SECONDS` (`60 * 60` or `3600`). For 'monthly',
 * the value would be `MONTH_IN_SECONDS` (`30 * 24 * 60 * 60` or `2592000`).
 *
 * The 'display' is the description. For the 'monthly' key, the 'display'
 * would be `__( 'Once Monthly' )`.
 *
 * For your plugin, you will be passed an array. You can add your
 * schedule by doing the following:
 *
 *     // Filter parameter variable name is 'array'.
 *     $array['monthly'] = array(
 *         'interval' => MONTH_IN_SECONDS,
 *         'display'  => __( 'Once Monthly' )
 *     );
 *
 * @since 2.1.0
 * @since 5.4.0 The 'weekly' schedule was added.
 *
 * @return array {
 *     The array of cron schedules keyed by the schedule name.
 *
 *     @type array ...$0 {
 *         Cron schedule information.
 *
 *         @type int    $interval The schedule interval in seconds.
 *         @type string $display  The schedule display name.
 *     }
 * }
 */
function wp_get_schedules() {
	$schedules = array(
		'hourly'     => array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Once Hourly' ),
		),
		'twicedaily' => array(
			'interval' => 12 * HOUR_IN_SECONDS,
			'display'  => __( 'Twice Daily' ),
		),
		'daily'      => array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily' ),
		),
		'weekly'     => array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly' ),
		),
	);

	/**
	 * Filters the non-default cron schedules.
	 *
	 * @since 2.1.0
	 *
	 * @param array $new_schedules {
	 *     An array of non-default cron schedules keyed by the schedule name. Default empty array.
	 *
	 *     @type array ...$0 {
	 *         Cron schedule information.
	 *
	 *         @type int    $interval The schedule interval in seconds.
	 *         @type string $display  The schedule display name.
	 *     }
	 * }
	 */
	return array_merge( apply_filters( 'cron_schedules', array() ), $schedules );
}

/**
 * Retrieves the name of the recurrence schedule for an event.
 *
 * @see wp_get_schedules() for available schedules.
 *
 * @since 2.1.0
 * @since 5.1.0 {@see 'get_schedule'} filter added.
 *
 * @param string $hook Action hook to identify the event.
 * @param array  $args Optional. Arguments passed to the event's callback function.
 *                     Default empty array.
 * @return string|false Schedule name on success, false if no schedule.
 */
function wp_get_schedule( $hook, $args = array() ) {
	$schedule = false;
	$event    = wp_get_scheduled_event( $hook, $args );

	if ( $event ) {
		$schedule = $event->schedule;
	}

	/**
	 * Filters the schedule name for a hook.
	 *
	 * @since 5.1.0
	 *
	 * @param string|false $schedule Schedule for the hook. False if not found.
	 * @param string       $hook     Action hook to execute when cron is run.
	 * @param array        $args     Arguments to pass to the hook's callback function.
	 */
	return apply_filters( 'get_schedule', $schedule, $hook, $args );
}

/**
 * Retrieves cron jobs ready to be run.
 *
 * Returns the results of _get_cron_array() limited to events ready to be run,
 * ie, with a timestamp in the past.
 *
 * @since 5.1.0
 *
 * @return array[] Array of cron job arrays ready to be run.
 */
function wp_get_ready_cron_jobs() {
	global $wpdb;

	/** This filter is documented in wp-includes/cron.php */
	$pre = apply_filters( 'pre_get_ready_cron_jobs', null );

	if ( null !== $pre ) {
		return $pre;
	}

	$table   = $wpdb->prefix . 'task_queue';
	$now     = gmdate( 'Y-m-d H:i:s' );
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE next_run <= %s AND status = 'pending' ORDER BY next_run ASC",
			$now
		)
	);
	$results = array();

	if ( $rows ) {
		foreach ( $rows as $row ) {
			$timestamp = strtotime( $row->next_run );
			$args      = maybe_unserialize( $row->args );
			$args_hash = $row->args_hash;
			$entry     = array(
				'schedule' => $row->schedule ? $row->schedule : false,
				'args'     => $args,
			);
			if ( $row->interval_seconds ) {
				$entry['interval'] = (int) $row->interval_seconds;
			}
			$results[ $timestamp ][ $row->hook ][ $args_hash ] = $entry;
		}
	}

	return $results;
}

//
// Private functions.
//

/**
 * Retrieves cron info array option.
 *
 * @since 2.1.0
 * @since 6.1.0 Return type modified to consistently return an array.
 * @access private
 *
 * @return array[] Array of cron events.
 */
function _get_cron_array() {
	// EnterPress: Cron data now stored in task_queue table.
	return array();
}

/**
 * Updates the cron option with the new cron array.
 *
 * @since 2.1.0
 * @since 5.1.0 Return value modified to outcome of update_option().
 * @since 5.7.0 The `$wp_error` parameter was added.
 *
 * @access private
 *
 * @param array[] $cron     Array of cron info arrays from _get_cron_array().
 * @param bool    $wp_error Optional. Whether to return a WP_Error on failure. Default false.
 * @return bool|WP_Error True if cron array updated. False or WP_Error on failure.
 */
function _set_cron_array( $cron, $wp_error = false ) {
	// EnterPress: Cron data now stored in task_queue table.
	return true;
}

/**
 * Upgrades a cron info array.
 *
 * This function upgrades the cron info array to version 2.
 *
 * @since 2.1.0
 * @access private
 *
 * @param array $cron Cron info array from _get_cron_array().
 * @return array An upgraded cron info array.
 */
function _upgrade_cron_array( $cron ) {
	// EnterPress: Cron data now stored in task_queue table.
	return array();
}
