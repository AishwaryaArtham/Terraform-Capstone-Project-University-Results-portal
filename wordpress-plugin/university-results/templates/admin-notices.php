<?php
/**
 * Success notice and validation errors shared by all admin screens.
 *
 * @var string   $notice
 * @var string[] $errors
 */

defined( 'ABSPATH' ) || exit;

if ( $notice ) : ?>
	<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
<?php endif; ?>

<?php if ( $errors ) : ?>
	<div class="notice notice-error">
		<p><strong>Please fix the following:</strong></p>
		<ul class="urp-error-list">
			<?php foreach ( $errors as $error ) : ?>
				<li><?php echo esc_html( $error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>
