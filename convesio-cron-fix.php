<?php
/**
 * Plugin Name: Convesio - In-Process Cron Fix
 * Description: Bypasses the wp-cron.php HTTP loopback (which is intercepted/bypassed at the
 *              edge and never reaches PHP-FPM on this stack) by running due cron events
 *              directly in-process on normal page loads instead. This restores reliable
 *              scheduled post publishing and other cron-dependent functionality without
 *              requiring changes to edge/WAF routing.
 * Author: Convesio
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Don't do anything if WP-Cron has been explicitly disabled on purpose.
if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
    return;
}

// Avoid running on every single request (admin-ajax, REST, cron itself, CLI) —
// only hook into normal front-end page loads, and rate-limit via a transient.
add_action( 'init', 'convesio_maybe_run_due_cron_inprocess', 20 );

function convesio_maybe_run_due_cron_inprocess() {

    // Never run inside the real wp-cron.php request, WP-CLI, admin-ajax, or REST — avoid recursion/duplication.
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return;
    }
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return;
    }
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return;
    }
    if ( is_admin() ) {
        // Skip heavy wp-admin screens to avoid slowing down editor UI; still allow front-end.
        return;
    }

    // Rate-limit: only check/run due events once every 60 seconds, not on every single visitor.
    $lock_key = 'convesio_inprocess_cron_lock';
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, 1, 60 );

    error_log( '[convesio-cron-fix] init fired, checking for due events at ' . date( 'Y-m-d H:i:s' ) . ' on URL ' . ( $_SERVER['REQUEST_URI'] ?? 'unknown' ) );

    $crons = _get_cron_array();
    if ( empty( $crons ) || ! is_array( $crons ) ) {
        error_log( '[convesio-cron-fix] no cron array found, exiting' );
        return;
    }

    $now         = time();
    $events_run  = 0;
    $max_events  = 10; // safety cap per page load so we never block a visitor's request for too long

    foreach ( $crons as $timestamp => $hooks ) {

        if ( ! is_numeric( $timestamp ) ) {
            continue; // skip the 'version' key WP stores at the end of the cron array
        }

        if ( $timestamp > $now ) {
            break; // cron array is time-sorted; nothing further is due yet
        }

        if ( ! is_array( $hooks ) ) {
            continue;
        }

        foreach ( $hooks as $hook => $events ) {

            foreach ( $events as $key => $event ) {

                if ( $events_run >= $max_events ) {
                    error_log( '[convesio-cron-fix] hit max_events cap, stopping early' );
                    return;
                }

                error_log( "[convesio-cron-fix] found due event: {$hook} scheduled for " . date( 'Y-m-d H:i:s', $timestamp ) );

                $args = $event['args'];

                // Reschedule recurring events before running, same as core's spawn_cron() does,
                // so a slow/failed callback doesn't cause it to be skipped forever.
                if ( ! empty( $event['schedule'] ) ) {
                    $new_args = array( $timestamp, $event['schedule'], $hook, $args );
                    call_user_func_array( 'wp_reschedule_event', $new_args );
                }

                wp_unschedule_event( $timestamp, $hook, $args );

                // Run the actual hook callbacks (this is what publishes scheduled posts,
                // sends scheduled emails, runs plugin maintenance jobs, etc).
                do_action_ref_array( $hook, $args );

                error_log( "[convesio-cron-fix] ran event: {$hook}" );

                $events_run++;
            }
        }
    }

    error_log( "[convesio-cron-fix] finished, ran {$events_run} event(s)" );
}
