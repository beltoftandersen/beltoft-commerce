<?php

use BeltoftCommerce\Bricks\HostCondition;
use BeltoftCommerce\Support\Hosts;

class HostConditionTest extends WP_UnitTestCase {

	use TradeHosts;

	public function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		$this->forget_trade_hosts();
		parent::tearDown();
	}

	public function test_b2b_condition_matches_on_the_b2b_host() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( HostCondition::evaluate( array( 'value' => 'b2b' ) ) );
	}

	public function test_b2b_condition_does_not_match_on_retail() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertFalse( HostCondition::evaluate( array( 'value' => 'b2b' ) ) );
	}

	public function test_retail_condition_matches_on_retail() {
		$_SERVER['HTTP_HOST'] = Hosts::retail();

		$this->assertTrue( HostCondition::evaluate( array( 'value' => 'retail' ) ) );
	}

	public function test_retail_condition_does_not_match_on_b2b() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( HostCondition::evaluate( array( 'value' => 'retail' ) ) );
	}

	public function test_an_unknown_value_never_matches() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertFalse( HostCondition::evaluate( array( 'value' => 'nonsense' ) ) );
	}

	public function test_it_registers_its_option() {
		$options = HostCondition::register_option( array() );

		$this->assertNotEmpty( wp_list_filter( $options, array( 'key' => 'beltoft_host' ) ) );
	}

	/** The callback Bricks really calls, both comparisons. */
	public function test_result_answers_for_its_own_key_and_inverts_on_not_equal() {
		$_SERVER['HTTP_HOST'] = Hosts::b2b();

		$this->assertTrue( HostCondition::result( false, 'beltoft_host', array( 'value' => 'b2b', 'compare' => '==' ) ) );
		$this->assertFalse( HostCondition::result( true, 'beltoft_host', array( 'value' => 'b2b', 'compare' => '!=' ) ) );
		$this->assertSame( 'untouched', HostCondition::result( 'untouched', 'some_other_condition', array() ) );
	}

	/** Another host the install answers on is neither retail nor trade. */
	public function test_neither_condition_matches_on_another_host() {
		$_SERVER['HTTP_HOST'] = 'other.example.invalid';

		$this->assertFalse( HostCondition::evaluate( array( 'value' => 'retail' ) ) );
		$this->assertFalse( HostCondition::evaluate( array( 'value' => 'b2b' ) ) );
	}

	/** "Retail" is every retail host - the site address and each language domain - and no trade host. */
	public function test_the_retail_condition_matches_every_retail_language_domain() {
		$this->use_trade_hosts();

		foreach ( array( Hosts::retail(), self::$en_retail, self::$de_retail ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertTrue( HostCondition::evaluate( array( 'value' => 'retail' ) ), $host );
			$this->assertFalse( HostCondition::evaluate( array( 'value' => 'b2b' ) ), $host );
		}

		foreach ( Hosts::all_b2b_hosts() as $host ) {
			$_SERVER['HTTP_HOST'] = $host;
			$this->assertFalse( HostCondition::evaluate( array( 'value' => 'retail' ) ), $host );
			$this->assertTrue( HostCondition::evaluate( array( 'value' => 'b2b' ) ), $host );
		}
	}
}
