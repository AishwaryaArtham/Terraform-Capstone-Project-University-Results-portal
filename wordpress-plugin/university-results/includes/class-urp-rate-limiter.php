<?php
/**
 * Limits failed result lookups to slow down guessing of access codes.
 *
 * Counters are WordPress transients, which live in the database (wp_options)
 * unless an object cache is installed, so they are shared by every web server
 * that uses the same RDS database — this keeps working behind an Auto Scaling group.
 */

defined( 'ABSPATH' ) || exit;

final class URP_Rate_Limiter {

	const WINDOW         = 15 * MINUTE_IN_SECONDS;
	const MAX_PER_REG_NO = 5;
	const MAX_PER_IP     = 20;

	public static function is_blocked( string $reg_no, string $ip ): bool {
		return self::count( 'reg', $reg_no ) >= self::MAX_PER_REG_NO
			|| self::count( 'ip', $ip ) >= self::MAX_PER_IP;
	}

	public static function record_failure( string $reg_no, string $ip ): void {
		self::increment( 'reg', $reg_no );
		self::increment( 'ip', $ip );
	}

	/** Called after a successful lookup so a student's own typos don't keep counting. */
	public static function clear( string $reg_no ): void {
		delete_transient( self::key( 'reg', $reg_no ) );
	}

	/**
	 * The visitor's IP address.
	 *
	 * Behind the AWS Application Load Balancer, REMOTE_ADDR is the load balancer's
	 * own address. The ALB appends the real client address as the LAST entry of
	 * X-Forwarded-For (earlier entries can be faked by the client). Only trust that
	 * header when wp-config.php defines URP_TRUST_PROXY_HEADER = true, which is
	 * safe only when the web server accepts traffic exclusively from the ALB.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( defined( 'URP_TRUST_PROXY_HEADER' ) && true === URP_TRUST_PROXY_HEADER && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$last  = trim( (string) end( $parts ) );
			if ( filter_var( $last, FILTER_VALIDATE_IP ) ) {
				$ip = $last;
			}
		}

		return (string) apply_filters( 'urp_client_ip', $ip );
	}

	private static function count( string $scope, string $value ): int {
		return (int) get_transient( self::key( $scope, $value ) );
	}

	private static function increment( string $scope, string $value ): void {
		set_transient( self::key( $scope, $value ), self::count( $scope, $value ) + 1, self::WINDOW );
	}

	private static function key( string $scope, string $value ): string {
		return 'urp_rl_' . $scope . '_' . md5( strtolower( $value ) );
	}
}
