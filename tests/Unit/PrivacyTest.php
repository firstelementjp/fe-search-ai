<?php
/**
 * Unit tests for FE Search AI privacy configuration.
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Privacy configuration tests.
 *
 * @since 1.2.0
 */
class PrivacyTest extends TestCase {

	/**
	 * Removes filters after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_all_filters( 'fe_search_ai_privacy_provider_registry' );
		remove_all_filters( 'fe_search_ai_active_privacy_recipients' );
		remove_all_filters( 'fe_search_ai_privacy_config' );
		remove_all_filters( 'fe_search_ai_enable_github_updates' );
		parent::tearDown();
	}

	/**
	 * Test active provider resolution.
	 *
	 * @return void
	 */
	public function test_active_recipients_follow_settings() {
		$settings = [
			'provider'  => [
				'chat'      => 'google',
				'embedding' => 'openai',
			],
			'rerank'    => [ 'enabled' => true ],
			'tokenizer' => [ 'ja' => [ 'engine' => 'yahoo_ma' ] ],
			'vector'    => [ 'stores' => [ 'qdrant' => true ] ],
		];

		$recipients = \FESearchAI\Core\FE_Search_AI_Privacy::get_active_recipients( $settings );

		$this->assertArrayHasKey( 'google', $recipients );
		$this->assertArrayHasKey( 'openai', $recipients );
		$this->assertArrayHasKey( 'cohere', $recipients );
		$this->assertArrayHasKey( 'yahoo_ma', $recipients );
		$this->assertArrayHasKey( 'qdrant', $recipients );
		$this->assertArrayHasKey( 'github', $recipients, 'GitHub should be an active recipient by default' );
	}

	/**
	 * Test that every registry entry declares whether it receives user content.
	 *
	 * @return void
	 */
	public function test_registry_entries_have_user_content_flag() {
		$registry = \FESearchAI\Core\FE_Search_AI_Privacy::get_provider_registry();

		foreach ( $registry as $key => $entry ) {
			$this->assertArrayHasKey( 'user_content', $entry, "Registry entry {$key} should declare user_content" );
			$this->assertIsBool( $entry['user_content'], "Registry entry {$key} user_content should be a boolean" );
		}

		$this->assertFalse( $registry['github']['user_content'], 'GitHub should not receive user content' );
		$this->assertTrue( $registry['qdrant']['user_content'], 'Qdrant receives the query vector derived from the question' );
	}

	/**
	 * Test that GitHub is excluded from active recipients when updates are disabled.
	 *
	 * @return void
	 */
	public function test_github_recipient_follows_update_filter() {
		$settings = [ 'provider' => [ 'chat' => 'openai', 'embedding' => 'openai' ] ];

		$recipients = \FESearchAI\Core\FE_Search_AI_Privacy::get_active_recipients( $settings );
		$this->assertArrayHasKey( 'github', $recipients, 'GitHub should be listed when updates are enabled' );

		add_filter( 'fe_search_ai_enable_github_updates', '__return_false' );
		$recipients = \FESearchAI\Core\FE_Search_AI_Privacy::get_active_recipients( $settings );
		remove_filter( 'fe_search_ai_enable_github_updates', '__return_false' );

		$this->assertArrayNotHasKey( 'github', $recipients, 'GitHub should not be listed when updates are disabled' );
	}

	/**
	 * Test consent version changes with displayed purposes.
	 *
	 * @return void
	 */
	public function test_consent_version_changes_with_privacy_config() {
		$settings = [ 'provider' => [ 'chat' => 'openai', 'embedding' => 'openai' ] ];
		$first    = \FESearchAI\Core\FE_Search_AI_Privacy::get_frontend_config( $settings );

		add_filter(
			'fe_search_ai_privacy_config',
			static function ( $config ) {
				$config['analytics_available'] = true;
				return $config;
			}
		);
		$second = \FESearchAI\Core\FE_Search_AI_Privacy::get_frontend_config( $settings );

		$this->assertNotSame( $first['version'], $second['version'] );
	}

	/**
	 * Test provider registry can be extended by Pro or another add-on.
	 *
	 * @return void
	 */
	public function test_provider_registry_is_filterable() {
		add_filter(
			'fe_search_ai_privacy_provider_registry',
			static function ( $registry ) {
				$registry['custom'] = [
					'label'       => 'Custom Provider',
					'data'        => [ 'question' ],
					'purpose'     => 'chat',
					'is_external' => true,
				];
				return $registry;
			}
		);

		$recipients = \FESearchAI\Core\FE_Search_AI_Privacy::get_active_recipients(
			[ 'provider' => [ 'chat' => 'custom', 'embedding' => 'openai' ] ]
		);
		$this->assertArrayHasKey( 'custom', $recipients );
	}
}
