<?php
/**
 * Results screen: add/edit form, filters and list.
 *
 * @var array|null $editing    Result being edited, or null.
 * @var array      $form
 * @var string     $reg_no     Filter.
 * @var string     $term       Filter.
 * @var string[]   $terms      All terms that have results.
 * @var string[]   $published  Published terms.
 * @var array[]    $results
 * @var int        $total
 * @var int        $page
 */

defined( 'ABSPATH' ) || exit;

$list_url = admin_url( 'admin.php?page=urp-results' );
?>
<div class="wrap urp-admin">
	<h1 class="wp-heading-inline">Results</h1>
	<hr class="wp-header-end">

	<?php include __DIR__ . '/admin-notices.php'; ?>

	<div class="urp-card">
		<?php if ( $editing ) : ?>
			<h2>Edit result: <?php echo esc_html( $editing['reg_no'] . ' · ' . $editing['term'] . ' · ' . $editing['subject_code'] ); ?></h2>
		<?php else : ?>
			<h2>Add or update a result</h2>
			<p class="description">If the student already has this subject in this term, the marks are updated.</p>
		<?php endif; ?>

		<form method="post">
			<?php $action = $editing ? 'update_result' : 'add_result'; ?>
			<?php wp_nonce_field( 'urp_' . $action ); ?>
			<input type="hidden" name="urp_action" value="<?php echo esc_attr( $action ); ?>">
			<?php if ( $editing ) : ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<?php if ( ! $editing ) : ?>
					<tr>
						<th scope="row"><label for="urp-reg-no">Registration number</label></th>
						<td>
							<input type="text" id="urp-reg-no" name="reg_no" class="regular-text" maxlength="32" required autocomplete="off"
								value="<?php echo esc_attr( $form['reg_no'] ?? $reg_no ); ?>" placeholder="UNI2026001">
							<p class="urp-student-status" id="urp-student-status" aria-live="polite"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="urp-term">Term</label></th>
						<td>
							<?php URP_Admin::choice_field( 'term', 'urp-term', $terms, $form['term'] ?? $term, '— Choose a term —', 'New term…', 32 ); ?>
							<p class="description">Filled in automatically with the latest term once a registration number is found. You can change it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="urp-subject-code">Subject</label></th>
						<td>
							<?php // Options are filled in by assets/js/admin.js for the selected term. ?>
							<select id="urp-subject-code" name="subject_code" data-new-input="urp-subject-code-new" aria-label="Subject code"
								data-selected="<?php echo esc_attr( $form['subject_code'] ?? '' ); ?>"></select>
							<select id="urp-subject-name" name="subject_name" data-new-input="urp-subject-name-new" aria-label="Subject name"
								data-selected="<?php echo esc_attr( $form['subject_name'] ?? '' ); ?>"></select>
							<div class="urp-new-subject">
								<input type="text" id="urp-subject-code-new" name="subject_code_new" class="urp-new-input" maxlength="32"
									placeholder="New code, e.g. MA103" aria-label="New subject code" value="<?php echo esc_attr( $form['subject_code'] ?? '' ); ?>">
								<input type="text" id="urp-subject-name-new" name="subject_name_new" class="regular-text urp-new-input" maxlength="191"
									placeholder="New subject name" aria-label="New subject name" value="<?php echo esc_attr( $form['subject_name'] ?? '' ); ?>">
							</div>
							<p class="description">Choosing a code selects its name and vice versa. Only subjects already used in the chosen term are listed.</p>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<th scope="row"><label for="urp-subject-name-edit">Subject name</label></th>
						<td><input type="text" id="urp-subject-name-edit" name="subject_name" class="regular-text" maxlength="191" required
							value="<?php echo esc_attr( $form['subject_name'] ?? '' ); ?>"></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="urp-marks">Marks</label></th>
					<td>
						<input type="number" id="urp-marks" name="marks" min="0" max="1000" step="0.01" required class="small-text"
							value="<?php echo esc_attr( $form['marks'] ?? '' ); ?>">
						<label for="urp-max-marks"> out of </label>
						<input type="number" id="urp-max-marks" name="max_marks" min="0.01" max="1000" step="0.01" class="small-text"
							value="<?php echo esc_attr( $form['max_marks'] ?? '100' ); ?>">
					</td>
				</tr>
			</table>

			<?php submit_button( $editing ? 'Save changes' : 'Save result', 'primary', 'submit', false ); ?>
			<?php if ( $editing ) : ?>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>">Cancel</a>
			<?php endif; ?>
		</form>
	</div>

	<form method="get" class="urp-search">
		<input type="hidden" name="page" value="urp-results">
		<label for="urp-filter-reg">Reg. no</label>
		<input type="search" id="urp-filter-reg" name="reg_no" value="<?php echo esc_attr( $reg_no ); ?>">
		<label for="urp-filter-term">Term</label>
		<select id="urp-filter-term" name="term">
			<option value="">All terms</option>
			<?php foreach ( $terms as $t ) : ?>
				<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $term, $t ); ?>><?php echo esc_html( $t ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( 'Filter', '', '', false ); ?>
		<span class="urp-count"><?php echo esc_html( sprintf( '%d result(s)', $total ) ); ?></span>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>Reg. no</th>
				<th>Student</th>
				<th>Term</th>
				<th>Subject</th>
				<th>Marks</th>
				<th>%</th>
				<th>Grade</th>
				<th>Visible to student</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $results ) : ?>
				<tr><td colspan="9">No results found.</td></tr>
			<?php endif; ?>
			<?php foreach ( $results as $row ) : ?>
				<?php $pct = URP_Validator::percentage( (float) $row['marks'], (float) $row['max_marks'] ); ?>
				<tr>
					<td><code><?php echo esc_html( $row['reg_no'] ); ?></code></td>
					<td><?php echo esc_html( $row['full_name'] ); ?></td>
					<td><?php echo esc_html( $row['term'] ); ?></td>
					<td><?php echo esc_html( $row['subject_code'] . ' — ' . $row['subject_name'] ); ?></td>
					<td><?php echo esc_html( (float) $row['marks'] . ' / ' . (float) $row['max_marks'] ); ?></td>
					<td><?php echo esc_html( number_format( $pct, 2 ) ); ?></td>
					<td><?php echo esc_html( URP_Validator::grade( $pct ) ); ?></td>
					<td><?php echo in_array( $row['term'], $published, true ) ? 'Yes' : 'No (term not published)'; ?></td>
					<td class="urp-actions">
						<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'edit', 'id' => $row['id'] ), $list_url ) ); ?>">Edit</a>
						<?php URP_Admin::action_button( 'delete_result', (int) $row['id'], 'Delete', 'Delete this result?', 'button-link urp-delete' ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( URP_Admin::pagination( $total, $page ) ); ?></div></div>
</div>
