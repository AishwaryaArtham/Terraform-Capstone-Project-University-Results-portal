<?php
/**
 * Publishing (per term) and bulk access-code generation.
 *
 * @var array[]  $terms          term, result_count, student_count
 * @var string[] $published
 * @var int      $missing_codes  Students without an access code.
 * @var int      $total_students
 */

defined( 'ABSPATH' ) || exit;

$lookup_pages = get_posts(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		's'           => '[urp_result_lookup]',
		'numberposts' => 1,
	)
);
?>
<div class="wrap urp-admin">
	<h1>Publish results &amp; access codes</h1>

	<?php include __DIR__ . '/admin-notices.php'; ?>

	<div class="urp-card">
		<h2>1. Student results page</h2>
		<?php if ( $lookup_pages ) : ?>
			<p>Students check results at <a href="<?php echo esc_url( get_permalink( $lookup_pages[0] ) ); ?>" target="_blank"><?php echo esc_html( get_permalink( $lookup_pages[0] ) ); ?></a>.</p>
		<?php else : ?>
			<p>No published page contains the shortcode yet. Create a page and add <code>[urp_result_lookup]</code> to its content.</p>
		<?php endif; ?>
	</div>

	<div class="urp-card">
		<h2>2. Access codes</h2>
		<p>
			<?php echo esc_html( sprintf( '%d of %d student(s) have no access code yet.', $missing_codes, $total_students ) ); ?>
			Codes are downloaded as a CSV <strong>once</strong>; only a secure hash is stored, so they can't be shown again.
			Store the file safely, give each student only their own code, then delete the file.
		</p>
		<form method="post" class="urp-inline-form">
			<?php wp_nonce_field( 'urp_generate_codes' ); ?>
			<input type="hidden" name="urp_action" value="generate_codes">
			<input type="hidden" name="scope" value="missing">
			<?php submit_button( sprintf( 'Generate codes for %d student(s) without one', $missing_codes ), 'primary', 'submit', false, $missing_codes ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<form method="post" class="urp-inline-form" onsubmit="return confirm('Issue NEW codes for ALL students? Every existing code will stop working.');">
			<?php wp_nonce_field( 'urp_generate_codes' ); ?>
			<input type="hidden" name="urp_action" value="generate_codes">
			<input type="hidden" name="scope" value="all">
			<?php submit_button( 'Regenerate codes for all students', 'secondary', 'submit', false ); ?>
		</form>
		<p class="description">To reset one student's code, use “New access code” on the Students page.</p>
	</div>

	<div class="urp-card">
		<h2>3. Publish by term</h2>
		<p>Students only see results for <strong>published</strong> terms. Unpublished terms can be prepared and checked first.</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Term</th>
					<th>Students</th>
					<th>Results</th>
					<th>Status</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $terms ) : ?>
					<tr><td colspan="5">No results yet. Add or import results first.</td></tr>
				<?php endif; ?>
				<?php foreach ( $terms as $row ) : ?>
					<?php $is_published = in_array( $row['term'], $published, true ); ?>
					<tr>
						<td><strong><?php echo esc_html( $row['term'] ); ?></strong></td>
						<td><?php echo esc_html( $row['student_count'] ); ?></td>
						<td><?php echo esc_html( $row['result_count'] ); ?></td>
						<td><?php echo $is_published ? '<strong>Published</strong>' : 'Not published'; ?></td>
						<td>
							<?php $action = $is_published ? 'unpublish_term' : 'publish_term'; ?>
							<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( ( $is_published ? 'Hide' : 'Publish' ) . ' results for term ' . $row['term'] . '?' ) ); ?>);">
								<?php wp_nonce_field( 'urp_' . $action ); ?>
								<input type="hidden" name="urp_action" value="<?php echo esc_attr( $action ); ?>">
								<input type="hidden" name="term" value="<?php echo esc_attr( $row['term'] ); ?>">
								<?php submit_button( $is_published ? 'Unpublish' : 'Publish', $is_published ? 'secondary' : 'primary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
