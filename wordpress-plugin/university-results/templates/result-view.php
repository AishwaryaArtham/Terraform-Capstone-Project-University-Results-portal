<?php
/**
 * A student's published results, grouped by term.
 *
 * @var array                 $student
 * @var array<string,array[]> $terms   term => result rows
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="urp-portal">
	<div class="urp-student">
		<h2><?php echo esc_html( $student['full_name'] ); ?></h2>
		<p>
			<span>Registration number: <strong><?php echo esc_html( $student['reg_no'] ); ?></strong></span>
			<?php if ( '' !== $student['programme'] ) : ?>
				<span>Programme: <strong><?php echo esc_html( $student['programme'] ); ?></strong></span>
			<?php endif; ?>
		</p>
	</div>

	<?php if ( ! $terms ) : ?>
		<p class="urp-message">No results have been published for you yet.</p>
	<?php endif; ?>

	<?php foreach ( $terms as $term => $rows ) : ?>
		<?php
		$total_marks = array_sum( array_map( 'floatval', array_column( $rows, 'marks' ) ) );
		$total_max   = array_sum( array_map( 'floatval', array_column( $rows, 'max_marks' ) ) );
		$overall     = URP_Validator::percentage( $total_marks, $total_max );
		$failed      = 0;
		?>
		<section class="urp-term">
			<h3>Term <?php echo esc_html( $term ); ?></h3>
			<div class="urp-table-wrap">
				<table>
					<thead>
						<tr>
							<th scope="col">Code</th>
							<th scope="col">Subject</th>
							<th scope="col">Marks</th>
							<th scope="col">%</th>
							<th scope="col">Grade</th>
							<th scope="col">Result</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$pct  = URP_Validator::percentage( (float) $row['marks'], (float) $row['max_marks'] );
							$pass = URP_Validator::is_pass( $pct );
							$failed += $pass ? 0 : 1;
							?>
							<tr>
								<td><?php echo esc_html( $row['subject_code'] ); ?></td>
								<td><?php echo esc_html( $row['subject_name'] ); ?></td>
								<td><?php echo esc_html( (float) $row['marks'] . ' / ' . (float) $row['max_marks'] ); ?></td>
								<td><?php echo esc_html( number_format( $pct, 2 ) ); ?></td>
								<td><?php echo esc_html( URP_Validator::grade( $pct ) ); ?></td>
								<td class="<?php echo $pass ? 'urp-pass' : 'urp-fail'; ?>"><?php echo $pass ? 'Pass' : 'Fail'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="urp-summary">
				Overall: <strong><?php echo esc_html( number_format( $overall, 2 ) ); ?>%</strong>
				(grade <?php echo esc_html( URP_Validator::grade( $overall ) ); ?>) ·
				<?php echo $failed ? esc_html( sprintf( '%d subject(s) not passed', $failed ) ) : 'All subjects passed'; ?>
			</p>
		</section>
	<?php endforeach; ?>

	<p><a href="<?php echo esc_url( get_permalink() ); ?>">Check another result</a></p>
</div>
