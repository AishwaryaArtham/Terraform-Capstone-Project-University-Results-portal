<?php
/**
 * Public lookup form.
 *
 * @var string $error  Message to show, or ''.
 * @var string $reg_no Previously typed registration number.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="urp-portal">
	<form method="post" class="urp-lookup-form" autocomplete="off">
		<?php if ( $error ) : ?>
			<p class="urp-message urp-message-error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<p>
			<label for="urp-reg-no">Registration number</label>
			<input type="text" id="urp-reg-no" name="reg_no" maxlength="32" required
				value="<?php echo esc_attr( $reg_no ); ?>" placeholder="e.g. UNI2026001" autocapitalize="characters" spellcheck="false">
		</p>
		<p>
			<label for="urp-access-code">Access code</label>
			<input type="password" id="urp-access-code" name="access_code" maxlength="20" required
				placeholder="e.g. K7QM2-XH9TP" autocapitalize="characters" spellcheck="false">
			<small>Your access code was issued by the examinations office.</small>
		</p>

		<?php wp_nonce_field( 'urp_lookup', '_urp_nonce', false ); ?>
		<input type="hidden" name="urp_lookup" value="1">
		<p><button type="submit">View my results</button></p>
	</form>
</div>
