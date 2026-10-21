<?php
/**
 * Import screen: upload form, then a row-by-row preview to confirm.
 *
 * @var array|null $preview Report from URP_CSV_Importer::prepare() plus 'type' and 'token'.
 */

defined( 'ABSPATH' ) || exit;

$examples = array(
	'students' => "reg_no,full_name,programme\nUNI2026001,Asha Rao,B.Tech Mechanical Engineering",
	'results'  => "reg_no,term,subject_code,subject_name,marks,max_marks\nUNI2026001,2026-S1,MA101,Engineering Mathematics I,88,100",
);
?>
<div class="wrap urp-admin">
	<h1>Import CSV</h1>

	<?php include __DIR__ . '/admin-notices.php'; ?>

	<?php if ( $preview ) : ?>
		<div class="urp-card">
			<h2>Preview: <?php echo esc_html( ucfirst( $preview['type'] ) ); ?></h2>
			<p>
				<strong><?php echo esc_html( (string) $preview['valid'] ); ?></strong> row(s) ready to import.
				<?php if ( $preview['invalid'] ) : ?>
					<strong><?php echo esc_html( (string) $preview['invalid'] ); ?></strong> row(s) have errors and will be <strong>skipped</strong>
					— fix them in the file and import again, or add them by hand.
				<?php endif; ?>
				Existing records with the same key are updated.
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Line</th>
						<?php foreach ( URP_CSV_Importer::COLUMNS[ $preview['type'] ] as $column ) : ?>
							<th><?php echo esc_html( $column ); ?></th>
						<?php endforeach; ?>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $preview['rows'] as $row ) : ?>
						<tr class="<?php echo $row['errors'] ? 'urp-preview-error' : ''; ?>">
							<td><?php echo esc_html( (string) $row['line'] ); ?></td>
							<?php foreach ( $row['data'] as $value ) : ?>
								<td><?php echo esc_html( $value ); ?></td>
							<?php endforeach; ?>
							<td><?php echo $row['errors'] ? esc_html( implode( ' ', $row['errors'] ) ) : 'OK'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" class="urp-confirm">
				<?php wp_nonce_field( 'urp_confirm_import' ); ?>
				<input type="hidden" name="urp_action" value="confirm_import">
				<input type="hidden" name="token" value="<?php echo esc_attr( $preview['token'] ); ?>">
				<p>
					<?php if ( $preview['valid'] ) : ?>
						<?php submit_button( sprintf( 'Import %d valid row(s)', $preview['valid'] ), 'primary', 'submit', false ); ?>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=urp-import' ) ); ?>">Cancel</a>
				</p>
			</form>
		</div>
	<?php else : ?>
		<div class="urp-card">
			<h2>Upload a CSV file</h2>
			<p>Import <strong>students first</strong>, then their results. Nothing is saved until you confirm the preview.
				Maximum <?php echo esc_html( (string) URP_CSV_Importer::MAX_ROWS ); ?> rows / 1 MB per file.</p>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'urp_preview_import' ); ?>
				<input type="hidden" name="urp_action" value="preview_import">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">What are you importing?</th>
						<td>
							<label><input type="radio" name="type" value="students" checked> Students</label><br>
							<label><input type="radio" name="type" value="results"> Results (marks)</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="urp-csv-file">CSV file</label></th>
						<td><input type="file" id="urp-csv-file" name="csv_file" accept=".csv,text/csv" required></td>
					</tr>
				</table>
				<?php submit_button( 'Preview import', 'primary', 'submit', false ); ?>
			</form>
		</div>

		<div class="urp-card">
			<h2>File format</h2>
			<p>The first row must be the header. Column order doesn't matter. <code>programme</code> and <code>max_marks</code> (default 100) are optional.</p>
			<?php foreach ( $examples as $type => $example ) : ?>
				<h3><?php echo esc_html( ucfirst( $type ) ); ?></h3>
				<pre><?php echo esc_html( $example ); ?></pre>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
