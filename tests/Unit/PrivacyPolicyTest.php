<?php
/**
 * Unit tests for the FE Search AI privacy policy guide content.
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Privacy policy content tests.
 *
 * @since 1.2.0
 */
class PrivacyPolicyTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// wp_add_privacy_policy_content() lives in wp-admin; load it for tests.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		// wp_add_privacy_policy_content() requires is_admin() and admin_init to have run.
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'dashboard' );
		}
		// Bumping the counter avoids firing the full hook (which sends headers).
		if ( ! did_action( 'admin_init' ) ) {
			$GLOBALS['wp_actions']['admin_init'] = 1;
		}

		$this->reset_policy_content();
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		$this->reset_policy_content();
		delete_option( 'fe_search_ai_settings' );
		delete_option( 'fe_search_ai_pro_settings' );
	}

	/**
	 * Reset the collected suggested policy content between tests.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	private function reset_policy_content() {
		if ( ! class_exists( 'WP_Privacy_Policy_Content' ) ) {
			return;
		}
		$property = new \ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Read the collected suggested policy content via reflection.
	 *
	 * @since 1.2.0
	 * @return array Collected policy content entries.
	 */
	private function get_policy_content() {
		$property = new \ReflectionProperty( 'WP_Privacy_Policy_Content', 'policy_content' );
		$property->setAccessible( true );
		return (array) $property->getValue();
	}

	/**
	 * Test that add_policy_content registers suggested text naming the active providers.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_policy_content_lists_active_recipients() {
		update_option(
			'fe_search_ai_settings',
			[
				'provider' => [
					'chat'      => 'openai',
					'embedding' => 'openai',
				],
				'rerank'   => [ 'enabled' => true ],
			]
		);

		\FESearchAI\Admin\FE_Search_AI_Privacy_Policy::add_policy_content();

		$entries = $this->get_policy_content();
		$this->assertNotEmpty( $entries, 'Policy content should be registered' );

		$text = implode( "\n", array_map(
			static function ( $entry ) {
				return (string) ( $entry['policy_text'] ?? '' );
			},
			$entries
		) );

		$this->assertStringContainsString( 'OpenAI', $text, 'Policy should name OpenAI' );
		$this->assertStringContainsString( 'Cohere Rerank', $text, 'Policy should name Cohere Rerank' );
		$this->assertStringContainsString( 'does not store your questions', $text, 'Policy should state that content is not stored by default' );
	}
}
