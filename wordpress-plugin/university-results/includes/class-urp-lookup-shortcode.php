<?php
/**
 * [urp_result_lookup] — the public page where a student checks their results.
 *
 * The form is processed on template_redirect (before any page output) so the
 * response can be marked no-cache; the shortcode then renders the outcome.
 * Every failure shows the same message, so the page never reveals whether a
 * registration number exists. There is deliberately no way to list students
 * or fetch results without a valid access code.
 */

defined( 'ABSPATH' ) || exit;

final class URP_Lookup_Shortcode {

	const GENERIC_ERROR = 'We couldn’t find results for that registration number and access code. Check both and try again.';
	const BLOCKED_ERROR = 'Too many unsuccessful attempts. Please wait 15 minutes and try again, or contact the examinations office.';

	/** @var array{status:string,message?:string,reg_no?:string,student?:array,terms?:array}|null */
	private static ?array $outcome = null;

	public static function register(): void {
		add_shortcode( 'urp_result_lookup', array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'handle_submission' ) );
		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				wp_register_style( 'urp-portal', plugins_url( 'assets/css/portal.css', URP_PLUGIN_FILE ), array(), URP_VERSION );
			}
		);
	}

	public static function handle_submission(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['urp_lookup'] ) ) {
			return;
		}
		nocache_headers();

		$reg_no = URP_Validator::normalize_reg_no( sanitize_text_field( wp_unslash( $_POST['reg_no'] ?? '' ) ) );
		$code   = sanitize_text_field( wp_unslash( $_POST['access_code'] ?? '' ) );

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_urp_nonce'] ?? '' ) ), 'urp_lookup' ) ) {
			self::$outcome = array(
				'status'  => 'error',
				'message' => 'This form expired. Please try again.',
				'reg_no'  => $reg_no,
			);
			return;
		}

		$ip = URP_Rate_Limiter::client_ip();
		if ( URP_Rate_Limiter::is_blocked( $reg_no, $ip ) ) {
			self::$outcome = array(
				'status'  => 'error',
				'message' => self::BLOCKED_ERROR,
				'reg_no'  => $reg_no,
			);
			return;
		}

		$student = URP_Validator::is_valid_reg_no( $reg_no ) ? URP_Repository::find_student_by_reg_no( $reg_no ) : null;
		if ( ! URP_Access_Codes::verify( $code, $student['access_code_hash'] ?? null ) ) {
			URP_Rate_Limiter::record_failure( $reg_no, $ip );
			self::$outcome = array(
				'status'  => 'error',
				'message' => self::GENERIC_ERROR,
				'reg_no'  => $reg_no,
			);
			return;
		}

		URP_Rate_Limiter::clear( $reg_no );

		$terms = array();
		foreach ( URP_Repository::results_for_student( (int) $student['id'], URP_Repository::published_terms() ) as $row ) {
			$terms[ $row['term'] ][] = $row;
		}

		self::$outcome = array(
			'status'  => 'ok',
			'student' => $student,
			'terms'   => $terms,
		);
	}

	public static function render(): string {
		wp_enqueue_style( 'urp-portal' );
		$outcome = self::$outcome;

		ob_start();
		if ( $outcome && 'ok' === $outcome['status'] ) {
			$student = $outcome['student'];
			$terms   = $outcome['terms'];
			include URP_PLUGIN_DIR . 'templates/result-view.php';
		} else {
			$error  = $outcome['message'] ?? '';
			$reg_no = $outcome['reg_no'] ?? '';
			include URP_PLUGIN_DIR . 'templates/lookup-form.php';
		}
		return (string) ob_get_clean();
	}
}
