<?php
/**
 * B2B application email body.
 *
 * Deliberately plain: heading, one paragraph, and the submitted company
 * details when they are worth repeating back. Anything more decorative would
 * have to be maintained in three places for three emails that each say one
 * thing.
 *
 * @package beltoft-commerce
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo wp_kses_post( nl2br( $body ) ); ?></p>

<?php if ( $user instanceof WP_User ) : ?>
	<?php
	$company   = (string) get_user_meta( $user->ID, 'billing_company', true );
	$shop_name = (string) get_user_meta( $user->ID, '_b2b_shop_name', true );
	$nif       = (string) get_user_meta( $user->ID, \BeltoftCommerce\Vat\Field::META_KEY, true );
	?>
	<?php if ( '' !== $company || '' !== $shop_name || '' !== $nif ) : ?>
		<h2><?php esc_html_e( 'Dados do registo', 'beltoft-commerce' ); ?></h2>
		<ul>
			<?php if ( '' !== $company ) : ?>
				<li><?php esc_html_e( 'Empresa', 'beltoft-commerce' ); ?>: <?php echo esc_html( $company ); ?></li>
			<?php endif; ?>
			<?php if ( '' !== $shop_name ) : ?>
				<li><?php esc_html_e( 'Loja', 'beltoft-commerce' ); ?>: <?php echo esc_html( $shop_name ); ?></li>
			<?php endif; ?>
			<?php if ( '' !== $nif ) : ?>
				<li><?php esc_html_e( 'NIF/NIPC', 'beltoft-commerce' ); ?>: <?php echo esc_html( $nif ); ?></li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
