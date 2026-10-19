<?php
/**
 * wp-admin screens: "Results Portal" menu with Students and Results pages.
 *
 * Pattern used by every screen:
 *   - Form submissions are handled on the page's "load-{hook}" action, before any
 *     output, after checking the capability and a nonce.
 *   - Success redirects back with ?urp_notice=... (Post/Redirect/Get) so a browser
 *     refresh never repeats an action.
 *   - Validation failure re-renders the form with the errors and the typed values.
 */

defined( 'ABSPATH' ) || exit;

class URP_Admin {

	const PER_PAGE = 25;

	/** @var string[] */
	protected array $errors = array();
	/** @var array<string,string> Values to re-display after a validation failure. */
	protected array $form = array();
	/** @var array|null CSV import preview waiting for confirmation. */
	protected ?array $preview = null;
	/** @var array{reg_no:string,full_name:string,code:string}|null Newly issued code, shown once. */
	protected ?array $new_code = null;

	/** Value of a dropdown's "New…" option; the typed value is then posted in "<field>_new". */
	const NEW_CHOICE = '__new__';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_urp_student', array( $this, 'ajax_student' ) );
	}

	public function add_menu(): void {
		$cap   = URP_Installer::CAPABILITY;
		$pages = array(
			'students' => add_menu_page( 'Results Portal', 'Results Portal', $cap, 'urp-students', array( $this, 'render_students' ), 'dashicons-welcome-learn-more', 30 ),
		);
		add_submenu_page( 'urp-students', 'Students', 'Students', $cap, 'urp-students', array( $this, 'render_students' ) );
		$pages['results'] = add_submenu_page( 'urp-students', 'Results', 'Results', $cap, 'urp-results', array( $this, 'render_results' ) );
		$pages['import']  = add_submenu_page( 'urp-students', 'Import CSV', 'Import CSV', $cap, 'urp-import', array( $this, 'render_import' ) );
		$pages['publish'] = add_submenu_page( 'urp-students', 'Publish & access codes', 'Publish & codes', $cap, 'urp-publish', array( $this, 'render_publish' ) );

		foreach ( $pages as $key => $hook ) {
			add_action( "load-{$hook}", array( $this, "handle_{$key}" ) );
		}
	}

	/* ---------------------------------------------------------------- Students */

	public function handle_students(): void {
		switch ( $this->posted_action() ) {
			case 'add_student':
				$this->form = array(
					'reg_no'    => URP_Validator::normalize_reg_no( $this->post( 'reg_no' ) ),
					'full_name' => $this->post( 'full_name' ),
					'programme' => $this->choice( 'programme' ),
				);
				$this->errors = URP_Validator::validate_student( $this->form );
				if ( ! $this->errors && URP_Repository::find_student_by_reg_no( $this->form['reg_no'] ) ) {
					$this->errors[] = 'A student with this registration number already exists. Use Edit instead.';
				}
				if ( ! $this->errors ) {
					URP_Repository::save_student( $this->form['reg_no'], $this->form['full_name'], $this->form['programme'] );
					$this->redirect( 'urp-students', 'student_created' );
				}
				break;

			case 'update_student':
				$student = URP_Repository::find_student( $this->post_int( 'id' ) );
				if ( ! $student ) {
					$this->redirect( 'urp-students', 'not_found' );
				}
				$this->form = array(
					'id'        => (string) $student['id'],
					'reg_no'    => $student['reg_no'],
					'full_name' => $this->post( 'full_name' ),
					'programme' => $this->choice( 'programme' ),
				);
				$this->errors = URP_Validator::validate_student( $this->form );
				if ( ! $this->errors ) {
					URP_Repository::update_student( (int) $student['id'], $this->form['full_name'], $this->form['programme'] );
					$this->redirect( 'urp-students', 'student_updated' );
				}
				break;

			case 'delete_student':
				URP_Repository::delete_student( $this->post_int( 'id' ) );
				$this->redirect( 'urp-students', 'student_deleted' );
				break;

			case 'reset_code':
				// Not redirected: the new code is rendered once in this response and never stored in plain text.
				$student = URP_Repository::find_student( $this->post_int( 'id' ) );
				if ( ! $student ) {
					$this->redirect( 'urp-students', 'not_found' );
				}
				$code = URP_Access_Codes::generate();
				URP_Repository::set_access_code_hash( (int) $student['id'], URP_Access_Codes::hash( $code ) );
				nocache_headers();
				$this->new_code = array(
					'reg_no'    => $student['reg_no'],
					'full_name' => $student['full_name'],
					'code'      => $code,
				);
		}
	}

	public function render_students(): void {
		$editing = null;
		if ( 'edit' === $this->get( 'view' ) ) {
			$editing = URP_Repository::find_student( absint( $_GET['id'] ?? 0 ) );
		}
		if ( $editing && ! $this->form ) {
			$this->form = $editing;
		}

		$search                 = $this->get( 's' );
		$page                   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		list( $students, $total ) = URP_Repository::search_students( $search, $page, self::PER_PAGE );
		$this->enqueue_script( array( 'newValue' => self::NEW_CHOICE ) );

		$this->template(
			'admin-students',
			array(
				'editing'  => $editing,
				'new_code' => $this->new_code,
				'search'   => $search,
				'programmes' => URP_Repository::programmes(),
				'students' => $students,
				'total'    => $total,
				'page'     => $page,
			)
		);
	}

	/* ----------------------------------------------------------------- Results */

	public function handle_results(): void {
		switch ( $this->posted_action() ) {
			case 'add_result':
				$this->form = array(
					'reg_no'       => URP_Validator::normalize_reg_no( $this->post( 'reg_no' ) ),
					'term'         => $this->choice( 'term' ),
					'subject_code' => URP_Validator::normalize_subject_code( $this->choice( 'subject_code' ) ),
					'subject_name' => $this->choice( 'subject_name' ),
					'marks'        => $this->post( 'marks' ),
					'max_marks'    => $this->post( 'max_marks' ) ?: '100',
				);
				$this->errors = URP_Validator::validate_result( $this->form );
				$student      = $this->errors ? null : URP_Repository::find_student_by_reg_no( $this->form['reg_no'] );
				if ( ! $this->errors && ! $student ) {
					$this->errors[] = 'No student has this registration number. Add the student first.';
				}
				if ( ! $this->errors ) {
					$outcome = URP_Repository::save_result( (int) $student['id'], $this->form['term'], $this->form['subject_code'], $this->form['subject_name'], $this->form['marks'], $this->form['max_marks'] );
					$this->redirect( 'urp-results', 'error' === $outcome ? 'db_error' : 'result_saved', array( 'reg_no' => $this->form['reg_no'] ) );
				}
				break;

			case 'update_result':
				$result = URP_Repository::find_result( $this->post_int( 'id' ) );
				if ( ! $result ) {
					$this->redirect( 'urp-results', 'not_found' );
				}
				$this->form   = array_merge(
					$result,
					array(
						'subject_name' => $this->post( 'subject_name' ),
						'marks'        => $this->post( 'marks' ),
						'max_marks'    => $this->post( 'max_marks' ) ?: '100',
					)
				);
				$this->errors = URP_Validator::validate_result( $this->form );
				if ( ! $this->errors ) {
					URP_Repository::update_result( (int) $result['id'], $this->form['subject_name'], $this->form['marks'], $this->form['max_marks'] );
					$this->redirect( 'urp-results', 'result_saved', array( 'reg_no' => $result['reg_no'] ) );
				}
				break;

			case 'delete_result':
				URP_Repository::delete_result( $this->post_int( 'id' ) );
				$this->redirect( 'urp-results', 'result_deleted' );
		}
	}

	public function render_results(): void {
		$editing = null;
		if ( 'edit' === $this->get( 'view' ) ) {
			$editing = URP_Repository::find_result( absint( $_GET['id'] ?? 0 ) );
		}
		if ( $editing && ! $this->form ) {
			$this->form = $editing;
		}

		$reg_no                  = URP_Validator::normalize_reg_no( $this->get( 'reg_no' ) );
		$term                    = $this->get( 'term' );
		$page                    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		list( $results, $total ) = URP_Repository::list_results( $reg_no, $term, $page, self::PER_PAGE );

		$this->enqueue_script(
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'urp_student_lookup' ),
				'terms'    => array_column( URP_Repository::terms(), 'term' ), // newest first
				'subjects' => URP_Repository::subjects_by_term(),
				'newValue' => self::NEW_CHOICE,
			)
		);

		$this->template(
			'admin-results',
			array(
				'editing'   => $editing,
				'reg_no'    => $reg_no,
				'term'      => $term,
				'terms'     => array_column( URP_Repository::terms(), 'term' ),
				'published' => URP_Repository::published_terms(),
				'results'   => $results,
				'total'     => $total,
				'page'      => $page,
			)
		);
	}

	/**
	 * AJAX for the Results form: who is this registration number?
	 * Admin-only (capability + nonce), returns only what the admin can already see.
	 */
	public function ajax_student(): void {
		check_ajax_referer( 'urp_student_lookup' );
		if ( ! current_user_can( URP_Installer::CAPABILITY ) ) {
			wp_send_json_error( null, 403 );
		}

		$student = URP_Repository::find_student_by_reg_no( URP_Validator::normalize_reg_no( $this->post( 'reg_no' ) ) );
		if ( ! $student ) {
			wp_send_json_success( array( 'found' => false ) );
		}

		list( $rows ) = URP_Repository::list_results( $student['reg_no'], '', 1, 1000 );
		wp_send_json_success(
			array(
				'found'     => true,
				'full_name' => $student['full_name'],
				'programme' => $student['programme'],
				'terms'     => array_count_values( array_column( $rows, 'term' ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ Import */

	public function handle_import(): void {
		switch ( $this->posted_action() ) {
			case 'preview_import':
				$type = $this->post( 'type' );
				$file = $_FILES['csv_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only tmp_name/error/size are read.

				if ( ! isset( URP_CSV_Importer::COLUMNS[ $type ] ) ) {
					$this->errors[] = 'Choose what you are importing.';
				} elseif ( ! $file || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
					$this->errors[] = 'Choose a CSV file to upload.';
				} elseif ( 'csv' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
					$this->errors[] = 'The file must have a .csv extension.';
				}
				if ( $this->errors ) {
					return;
				}

				$report = URP_CSV_Importer::prepare(
					$file['tmp_name'],
					$type,
					static fn( array $reg_nos ) => array_keys( URP_Repository::student_ids_by_reg_no( $reg_nos ) )
				);
				$this->errors = $report['file_errors'];
				if ( $this->errors ) {
					return;
				}

				// Keep only the valid rows server-side until the admin confirms; the file itself is not stored.
				$token = wp_generate_password( 20, false );
				set_transient(
					$this->import_key(),
					array(
						'token' => $token,
						'type'  => $type,
						'rows'  => array_values( array_column( array_filter( $report['rows'], static fn( $r ) => ! $r['errors'] ), 'data' ) ),
					),
					15 * MINUTE_IN_SECONDS
				);
				$this->preview = array_merge( $report, array( 'type' => $type, 'token' => $token ) );
				break;

			case 'confirm_import':
				$pending = get_transient( $this->import_key() );
				delete_transient( $this->import_key() );
				if ( ! is_array( $pending ) || ! hash_equals( $pending['token'], $this->post( 'token' ) ) ) {
					$this->redirect( 'urp-import', 'import_expired' );
				}

				$counts = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0 );
				if ( 'students' === $pending['type'] ) {
					foreach ( $pending['rows'] as $row ) {
						++$counts[ URP_Repository::save_student( $row['reg_no'], $row['full_name'], $row['programme'] ) ];
					}
				} else {
					$ids = URP_Repository::student_ids_by_reg_no( array_column( $pending['rows'], 'reg_no' ) );
					foreach ( $pending['rows'] as $row ) {
						if ( ! isset( $ids[ $row['reg_no'] ] ) ) {
							++$counts['error']; // student deleted between preview and confirm
							continue;
						}
						++$counts[ URP_Repository::save_result( $ids[ $row['reg_no'] ], $row['term'], $row['subject_code'], $row['subject_name'], $row['marks'], $row['max_marks'] ) ];
					}
				}
				$this->redirect(
					'urp-import',
					'import_done',
					array( 'c' => $counts['created'], 'u' => $counts['updated'], 'n' => $counts['unchanged'], 'e' => $counts['error'] )
				);
		}
	}

	public function render_import(): void {
		$this->template( 'admin-import', array( 'preview' => $this->preview ) );
	}

	/* ------------------------------------------------- Publishing and codes */

	public function handle_publish(): void {
		$action = $this->posted_action();
		switch ( $action ) {
			case 'publish_term':
			case 'unpublish_term':
				$term = $this->post( 'term' );
				if ( URP_Validator::is_valid_term( $term ) ) {
					$publish = 'publish_term' === $action;
					URP_Repository::set_term_published( $term, $publish );
					$this->redirect( 'urp-publish', $publish ? 'term_published' : 'term_unpublished' );
				}
				$this->redirect( 'urp-publish', 'not_found' );
				break;

			case 'generate_codes':
				$this->download_codes( URP_Repository::students_for_codes( 'all' !== $this->post( 'scope' ) ) );
		}
	}

	public function render_publish(): void {
		$this->template(
			'admin-publish',
			array(
				'terms'          => URP_Repository::terms(),
				'published'      => URP_Repository::published_terms(),
				'missing_codes'  => count( URP_Repository::students_for_codes( true ) ),
				'total_students' => URP_Repository::count_students(),
			)
		);
	}

	/**
	 * Issues new codes and sends them straight to the browser as a CSV download.
	 * Plain-text codes exist only in this response; the database keeps hashes.
	 *
	 * @return never
	 */
	private function download_codes( array $students ): void {
		if ( ! $students ) {
			$this->redirect( 'urp-publish', 'no_codes_needed' );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="urp-access-codes-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'reg_no', 'full_name', 'access_code' ), ',', '"', '' );
		foreach ( $students as $student ) {
			$code = URP_Access_Codes::generate();
			URP_Repository::set_access_code_hash( (int) $student['id'], URP_Access_Codes::hash( $code ) );
			fputcsv( $out, array( URP_CSV_Importer::safe_cell( $student['reg_no'] ), URP_CSV_Importer::safe_cell( $student['full_name'] ), $code ), ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	private function import_key(): string {
		return 'urp_import_' . get_current_user_id();
	}

	/* ----------------------------------------------------------------- Helpers */

	/**
	 * Returns the submitted urp_action after verifying the capability and the
	 * nonce for that action, or '' when nothing was submitted.
	 */
	protected function posted_action(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['urp_action'] ) ) {
			return '';
		}
		$action = sanitize_key( wp_unslash( $_POST['urp_action'] ) );
		if ( ! current_user_can( URP_Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage results.', 'university-results' ), 403 );
		}
		check_admin_referer( 'urp_' . $action );
		return $action;
	}

	protected function post( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in posted_action().
	}

	/** A dropdown value, or the typed text when its "New…" option was chosen. */
	protected function choice( string $key ): string {
		$value = $this->post( $key );
		return self::NEW_CHOICE === $value ? $this->post( $key . '_new' ) : $value;
	}

	protected function post_int( string $key ): int {
		return absint( $_POST[ $key ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in posted_action().
	}

	protected function get( string $key ): string {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/** @return never */
	protected function redirect( string $page, string $notice, array $args = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page, 'urp_notice' => $notice ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	protected function template( string $name, array $vars = array() ): void {
		$errors = $this->errors;
		$form   = $this->form;
		$notice = $this->notice_message();
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- template variables, keys are fixed above.
		include URP_PLUGIN_DIR . "templates/{$name}.php";
	}

	/** Maps the ?urp_notice= code to a fixed message, so no text from the URL is ever shown. */
	protected function notice_message(): string {
		$messages = array(
			'student_created'  => 'Student added.',
			'student_updated'  => 'Student updated.',
			'student_deleted'  => 'Student and their results deleted.',
			'result_saved'     => 'Result saved.',
			'result_deleted'   => 'Result deleted.',
			'not_found'        => 'That record no longer exists.',
			'db_error'         => 'The database rejected the change. Please try again.',
			'import_expired'   => 'That import preview expired or was already used. Please upload the file again.',
			'term_published'   => 'Term published. Students can now see these results.',
			'term_unpublished' => 'Term unpublished. Students can no longer see these results.',
			'no_codes_needed'  => 'Every student already has an access code.',
		);
		if ( 'import_done' === $this->get( 'urp_notice' ) ) {
			return sprintf(
				'Import finished: %d created, %d updated, %d unchanged, %d failed.',
				absint( $_GET['c'] ?? 0 ),
				absint( $_GET['u'] ?? 0 ),
				absint( $_GET['n'] ?? 0 ),
				absint( $_GET['e'] ?? 0 )
			);
		}
		return $messages[ $this->get( 'urp_notice' ) ] ?? '';
	}

	/** Loads assets/js/admin.js (in the footer) with the page's data as window.urpAdmin. */
	protected function enqueue_script( array $data ): void {
		wp_enqueue_script( 'urp-admin', plugins_url( 'assets/js/admin.js', URP_PLUGIN_FILE ), array(), URP_VERSION, true );
		wp_add_inline_script( 'urp-admin', 'window.urpAdmin = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Prints a dropdown of known values plus a "New…" option that reveals a text box.
	 * A current value that isn't in the list selects "New…" with the value typed in.
	 *
	 * @param string[] $options
	 */
	public static function choice_field( string $name, string $id, array $options, string $current, string $placeholder, string $new_label, int $maxlength ): void {
		$is_new = '' !== $current && ! in_array( $current, $options, true );
		$new_id = $id . '-new';
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" data-new-input="<?php echo esc_attr( $new_id ); ?>">
			<option value=""><?php echo esc_html( $placeholder ); ?></option>
			<?php foreach ( $options as $option ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $current, $option ); ?>><?php echo esc_html( $option ); ?></option>
			<?php endforeach; ?>
			<option value="<?php echo esc_attr( self::NEW_CHOICE ); ?>" <?php selected( $is_new ); ?>><?php echo esc_html( $new_label ); ?></option>
		</select>
		<input type="text" id="<?php echo esc_attr( $new_id ); ?>" name="<?php echo esc_attr( $name . '_new' ); ?>" class="regular-text urp-new-input"
			maxlength="<?php echo esc_attr( (string) $maxlength ); ?>" value="<?php echo esc_attr( $is_new ? $current : '' ); ?>" aria-label="<?php echo esc_attr( $new_label ); ?>">
		<?php
	}

	/** Small helper used by templates to print pagination links. */
	public static function pagination( int $total, int $page ): string {
		return (string) paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $page,
				'total'   => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/** Prints a one-button POST form for row actions such as Delete. */
	public static function action_button( string $action, int $id, string $label, string $confirm = '', string $class = 'button-link' ): void {
		?>
		<form method="post" class="urp-inline-form"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
			<?php wp_nonce_field( 'urp_' . $action ); ?>
			<input type="hidden" name="urp_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}
