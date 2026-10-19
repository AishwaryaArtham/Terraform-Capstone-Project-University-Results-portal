<?php
/**
 * Students screen: add/edit form, search and list.
 *
 * @var array|null $editing  Student being edited, or null.
 * @var array|null $new_code Newly issued access code to show once.
 * @var array      $form     Values for the form fields.
 * @var string[]   $programmes Programmes already in use, for the dropdown.
 * @var string     $search
 * @var array[]    $students
 * @var int        $total
 * @var int        $page
 */

defined( 'ABSPATH' ) || exit;

$list_url = admin_url( 'admin.php?page=urp-students' );
?>
<div class="wrap urp-admin">
	<h1 class="wp-heading-inline">Students</h1>
	<hr class="wp-header-end">

	<?php include __DIR__ . '/admin-notices.php'; ?>

	<?php if ( $new_code ) : ?>
		<div class="notice notice-warning">
			<p>New access code for <strong><?php echo esc_html( $new_code['reg_no'] . ' — ' . $new_code['full_name'] ); ?></strong>:</p>
			<p class="urp-codes"><strong><?php echo esc_html( $new_code['code'] ); ?></strong></p>
			<p>Copy it now and give it to the student privately. <strong>It will not be shown again</strong> — only a secure hash is stored.</p>
		</div>
	<?php endif; ?>

	<div class="urp-card">
		<?php if ( $editing ) : ?>
			<h2>Edit student <?php echo esc_html( $editing['reg_no'] ); ?></h2>
		<?php else : ?>
			<h2>Add a student</h2>
		<?php endif; ?>

		<form method="post">
			<?php $action = $editing ? 'update_student' : 'add_student'; ?>
			<?php wp_nonce_field( 'urp_' . $action ); ?>
			<input type="hidden" name="urp_action" value="<?php echo esc_attr( $action ); ?>">
			<?php if ( $editing ) : ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ); ?>">
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="urp-reg-no">Registration number</label></th>
					<td>
						<?php if ( $editing ) : ?>
							<code><?php echo esc_html( $editing['reg_no'] ); ?></code>
							<p class="description">Registration numbers can't be changed. Delete and re-add the student instead.</p>
						<?php else : ?>
							<input type="text" id="urp-reg-no" name="reg_no" class="regular-text" maxlength="32" required
								value="<?php echo esc_attr( $form['reg_no'] ?? '' ); ?>" placeholder="UNI2026001">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="urp-full-name">Full name</label></th>
					<td><input type="text" id="urp-full-name" name="full_name" class="regular-text" maxlength="191" required
						value="<?php echo esc_attr( $form['full_name'] ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="urp-programme">Programme</label></th>
					<td><?php URP_Admin::choice_field( 'programme', 'urp-programme', $programmes, $form['programme'] ?? '', '— Choose a programme —', 'New programme…', 191 ); ?></td>
				</tr>
			</table>

			<?php submit_button( $editing ? 'Save changes' : 'Add student', 'primary', 'submit', false ); ?>
			<?php if ( $editing ) : ?>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>">Cancel</a>
			<?php endif; ?>
		</form>
	</div>

	<form method="get" class="urp-search">
		<input type="hidden" name="page" value="urp-students">
		<label class="screen-reader-text" for="urp-search">Search students</label>
		<input type="search" id="urp-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Reg. no or name">
		<?php submit_button( 'Search', '', '', false ); ?>
		<span class="urp-count"><?php echo esc_html( sprintf( '%d student(s)', $total ) ); ?></span>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>Reg. no</th>
				<th>Full name</th>
				<th>Programme</th>
				<th>Results</th>
				<th>Access code</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $students ) : ?>
				<tr><td colspan="6">No students found.</td></tr>
			<?php endif; ?>
			<?php foreach ( $students as $student ) : ?>
				<tr>
					<td><code><?php echo esc_html( $student['reg_no'] ); ?></code></td>
					<td><?php echo esc_html( $student['full_name'] ); ?></td>
					<td><?php echo esc_html( $student['programme'] ); ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'urp-results', 'reg_no' => $student['reg_no'] ), admin_url( 'admin.php' ) ) ); ?>">
							<?php echo esc_html( $student['result_count'] ); ?>
						</a>
					</td>
					<td><?php echo $student['access_code_hash'] ? 'Set' : '<em>Not set</em>'; ?></td>
					<td class="urp-actions">
						<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'edit', 'id' => $student['id'] ), $list_url ) ); ?>">Edit</a>
						<?php URP_Admin::action_button( 'reset_code', (int) $student['id'], 'New access code', 'Issue a new access code for ' . $student['reg_no'] . '? Any previous code stops working.' ); ?>
						<?php URP_Admin::action_button( 'delete_student', (int) $student['id'], 'Delete', 'Delete ' . $student['reg_no'] . ' and all of their results?', 'button-link urp-delete' ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( URP_Admin::pagination( $total, $page ) ); ?></div></div>
</div>
