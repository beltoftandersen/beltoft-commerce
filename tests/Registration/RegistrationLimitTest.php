<?php

use BeltoftCommerce\Pricing\Roles;
use BeltoftCommerce\Registration\FormAction;
use BeltoftCommerce\Registration\RegistrationLimit;

/**
 * The application form had no limit: a script could create b2b_pending
 * users and make the store send its branded "received" email - carrying
 * text the script chose - to any address, as fast as it liked, and probe
 * which emails already have an account.
 */
class RegistrationLimitTest extends WP_UnitTestCase {

	private static int $octet = 10;

	public function setUp(): void {
		parent::setUp();
		Roles::install();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( ++self::$octet );
	}

	public function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_a_few_attempts_are_fine() {
		for ( $i = 0; $i < 9; $i++ ) {
			RegistrationLimit::record_attempt();
		}

		$this->assertTrue( RegistrationLimit::allows() );
	}

	public function test_too_many_attempts_from_one_address_are_refused() {
		for ( $i = 0; $i < 10; $i++ ) {
			RegistrationLimit::record_attempt();
		}

		$this->assertFalse( RegistrationLimit::allows() );
	}

	public function test_too_many_applications_from_one_address_are_refused() {
		for ( $i = 0; $i < 3; $i++ ) {
			RegistrationLimit::record_application();
		}

		$this->assertFalse( RegistrationLimit::allows() );
	}

	public function test_another_address_is_not_affected() {
		for ( $i = 0; $i < 10; $i++ ) {
			RegistrationLimit::record_attempt();
		}

		$_SERVER['REMOTE_ADDR'] = '198.51.100.250';

		$this->assertTrue( RegistrationLimit::allows() );
	}

	/** A Bricks form instance, reduced to what FormAction reads and writes. */
	private function form( array $fields ): object {
		return new class( $fields ) {
			public array $result = array();

			public function __construct( private array $fields ) {}

			public function get_fields(): array {
				$out = array();

				foreach ( $this->fields as $name => $value ) {
					$out[ 'form-field-' . $name ] = $value;
				}

				return $out;
			}

			public function get_settings(): array {
				return array( 'beltoftB2bFieldMap' => array_combine( array_keys( $this->fields ), array_keys( $this->fields ) ) );
			}

			public function set_result( array $result ): void {
				$this->result = $result;
			}
		};
	}

	/** A complete, valid application: only the limit can refuse it. */
	private function application( string $email ): array {
		return array(
			'email'        => $email,
			'company'      => 'Loja Teste Lda',
			'nif'          => 'PT501964843',
			'first_name'   => 'Ana',
			'last_name'    => 'Silva',
			'address'      => 'Rua A 1',
			'postcode'     => '1000-001',
			'city'         => 'Lisboa',
			'country'      => 'PT',
			'state'        => 'LS',
			'phone'        => '912345678',
			'company_info' => 'Loja de produtos sustentáveis.',
			'shop_name'    => 'Loja Teste',
			'website'      => 'https://example.com',
			'password'     => 'Uma-palavra-passe-forte-1',
		);
	}

	public function test_the_form_refuses_once_the_limit_is_reached_without_creating_anyone() {
		// The same application goes through while under the limit...
		$control = $this->form( $this->application( 'controlo@example.com' ) );
		FormAction::handle( $control );
		$this->assertNotFalse( email_exists( 'controlo@example.com' ), 'fixture: a valid application' );

		for ( $i = 0; $i < 10; $i++ ) {
			RegistrationLimit::record_attempt();
		}

		// ...and only the limit refuses it now.
		$form = $this->form( $this->application( 'novo-parceiro@example.com' ) );
		FormAction::handle( $form );

		$this->assertSame( 'error', $form->result['type'] );
		$this->assertFalse( email_exists( 'novo-parceiro@example.com' ) );
	}

	public function test_every_submission_counts_as_an_attempt() {
		for ( $i = 0; $i < 10; $i++ ) {
			FormAction::handle( $this->form( array( 'email' => 'not-an-email' ) ) );
		}

		$this->assertFalse( RegistrationLimit::allows() );
	}
}
