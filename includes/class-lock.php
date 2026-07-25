<?php
/**
 * Per-order lock that prevents two concurrent processes from creating an
 * invoice for the same order.
 *
 * Why this exists: the "already invoiced" guard reads order meta that is only
 * written AFTER the API call returns. Two requests arriving within that
 * window — an Action Scheduler job and a manual button click, say, or a
 * payment webhook firing twice — would both see "no invoice yet" and both
 * create a document. With finalized invoices that is serious, because they
 * cannot be deleted (GoBD), only reversed with a credit note.
 *
 * Implementation: add_option() with autoload=off. MySQL enforces a unique
 * index on option_name, so of two concurrent inserts exactly one succeeds —
 * that is the atomic "test and set" this lock needs. (get/update_option is
 * NOT atomic and would race.) A timestamp is stored as the value so that a
 * lock left behind by a crashed process expires instead of blocking the
 * order forever.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Lock {

	/**
	 * A lock older than this is considered abandoned and may be taken over.
	 * Generous on purpose: an invoice run does a handful of API calls, each
	 * with up to three retries and backoff, so a healthy run can take a
	 * couple of minutes on a bad day.
	 */
	const TTL_SECONDS = 300;

	/**
	 * Try to acquire the lock for one order.
	 *
	 * @param int $order_id Order id.
	 * @return bool True when this process now holds the lock.
	 */
	public static function acquire( $order_id ) {
		$name = self::option_name( $order_id );
		$now  = time();

		// Atomic: relies on the unique index on wp_options.option_name.
		if ( add_option( $name, (string) $now, '', false ) ) {
			return true;
		}

		// Somebody holds it. If they are long gone, take over — but do the
		// takeover through delete+add so it stays atomic too: if two
		// processes both delete an expired lock, only one add_option() wins.
		$held_since = (int) get_option( $name, 0 );

		if ( $held_since > 0 && ( $now - $held_since ) < self::TTL_SECONDS ) {
			return false;
		}

		delete_option( $name );

		return (bool) add_option( $name, (string) $now, '', false );
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function release( $order_id ) {
		delete_option( self::option_name( $order_id ) );
	}

	/**
	 * Option name for an order's lock.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function option_name( $order_id ) {
		return 'nota_inv_lock_' . absint( $order_id );
	}
}
