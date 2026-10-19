<?php

use PHPUnit\Framework\TestCase;

final class AccessCodesTest extends TestCase {

	public function test_generated_code_format(): void {
		$code = URP_Access_Codes::generate();
		$this->assertMatchesRegularExpression( '/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{5}-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{5}$/', $code );
	}

	public function test_generated_codes_are_not_repeated(): void {
		$codes = array_map( static fn() => URP_Access_Codes::generate(), range( 1, 200 ) );
		$this->assertCount( 200, array_unique( $codes ) );
	}

	public function test_hash_is_not_the_code(): void {
		$code = URP_Access_Codes::generate();
		$hash = URP_Access_Codes::hash( $code );
		$this->assertStringNotContainsString( URP_Access_Codes::normalize( $code ), $hash );
		$this->assertStringStartsWith( '$2y$11$', $hash );
	}

	public function test_verify_accepts_the_code_however_it_is_typed(): void {
		$hash = URP_Access_Codes::hash( 'K7QM2-XH9TP' );
		$this->assertTrue( URP_Access_Codes::verify( 'K7QM2-XH9TP', $hash ) );
		$this->assertTrue( URP_Access_Codes::verify( ' k7qm2 xh9tp ', $hash ) );
		$this->assertTrue( URP_Access_Codes::verify( 'K7QM2XH9TP', $hash ) );
	}

	public function test_verify_rejects_wrong_missing_or_partial_codes(): void {
		$hash = URP_Access_Codes::hash( 'K7QM2-XH9TP' );
		$this->assertFalse( URP_Access_Codes::verify( 'K7QM2-XH9TQ', $hash ) );
		$this->assertFalse( URP_Access_Codes::verify( '', $hash ) );
		$this->assertFalse( URP_Access_Codes::verify( 'K7QM2-XH9TP', null ) );
		$this->assertFalse( URP_Access_Codes::verify( 'K7QM2-XH9TP', '' ) );
	}

	public function test_dummy_hash_never_matches_and_uses_same_cost(): void {
		$this->assertFalse( URP_Access_Codes::verify( 'urp-dummy-code-never-valid', null ) );
		$this->assertSame( 11, password_get_info( URP_Access_Codes::DUMMY_HASH )['options']['cost'] );
	}
}
