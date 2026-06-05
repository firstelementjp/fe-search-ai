<?php
/**
 * Unit tests for FE Search AI Chat Handler
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Chat Handler functionality tests
 *
 * @since 1.0.0
 */
class ChatHandlerTest extends TestCase {

	/**
	 * Set up test environment before each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Clear logs after each test
		\FESearchAI\Core\FE_Search_AI_Logger::clear_logs();
	}

	/**
	 * Test that chat handler class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_chat_handler_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Ajax\FE_Search_AI_Chat_Handler' ), 'Chat Handler class should exist' );
	}

	/**
	 * Test that key methods exist
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_key_methods_exist() {
		// Create a mock sync handler for constructor
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$this->assertTrue( method_exists( $handler, 'register_endpoints' ), 'register_endpoints method should exist' );
		$this->assertTrue( method_exists( $handler, 'stream_handler' ), 'stream_handler method should exist' );
		$this->assertTrue( method_exists( $handler, 'build_prompt_messages' ), 'build_prompt_messages method should exist' );
		$this->assertTrue( method_exists( $handler, 'filter_basic_injection_phrases' ), 'filter_basic_injection_phrases method should exist' );
		$this->assertTrue( method_exists( $handler, 'filter_personal_data' ), 'filter_personal_data method should exist' );
	}

	/**
	 * Test filter_personal_data with email address
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_email() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'Contact me at test@example.com for more info';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertStringNotContainsString( 'test@example.com', $filtered, 'Email should be filtered' );
		$this->assertStringContainsString( '[REDACTED]', $filtered, 'Redacted placeholder should be present' );
	}

	/**
	 * Test filter_personal_data with phone number
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_phone() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'Call me at 123-456-7890';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertStringNotContainsString( '123-456-7890', $filtered, 'Phone number should be filtered' );
		$this->assertStringContainsString( '[REDACTED]', $filtered, 'Redacted placeholder should be present' );
	}

	/**
	 * Test filter_personal_data with international phone number
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_international_phone() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'Call +81 90-1234-5678';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertStringNotContainsString( '+81 90-1234-5678', $filtered, 'International phone should be filtered' );
		$this->assertStringContainsString( '[REDACTED]', $filtered, 'Redacted placeholder should be present' );
	}

	/**
	 * Test filter_personal_data with empty string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_empty_string() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = '';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertEquals( '', $filtered, 'Empty string should remain empty' );
	}

	/**
	 * Test filter_personal_data with no PII
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_no_pii() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'This is a normal text without personal data';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertEquals( $text, $filtered, 'Text without PII should remain unchanged' );
	}

	/**
	 * Test filter_personal_data with multiple PII
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_multiple_pii() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'Email: test@example.com, Phone: 123-456-7890';
		$filtered = $handler->filter_personal_data( $text );

		$this->assertStringNotContainsString( 'test@example.com', $filtered, 'Email should be filtered' );
		$this->assertStringNotContainsString( '123-456-7890', $filtered, 'Phone should be filtered' );
	}

	/**
	 * Test filter_basic_injection_phrases with empty string
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_basic_injection_phrases_empty() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = '';
		$filtered = $handler->filter_basic_injection_phrases( $text );

		$this->assertEquals( '', $filtered, 'Empty string should remain empty' );
	}

	/**
	 * Test filter_basic_injection_phrases with normal text
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_basic_injection_phrases_normal_text() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$text     = 'This is a normal question about the website';
		$filtered = $handler->filter_basic_injection_phrases( $text );

		$this->assertEquals( $text, $filtered, 'Normal text should remain unchanged' );
	}

	/**
	 * Test filter_basic_injection_phrases method exists and is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_basic_injection_phrases_callable() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$this->assertTrue( is_callable( [ $handler, 'filter_basic_injection_phrases' ] ), 'filter_basic_injection_phrases should be callable' );
	}

	/**
	 * Test filter_personal_data method exists and is callable
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_filter_personal_data_callable() {
		$sync_handler = $this->createMock( 'FESearchAI\Ajax\FE_Search_AI_Sync_Handler' );
		$handler      = new \FESearchAI\Ajax\FE_Search_AI_Chat_Handler( $sync_handler );

		$this->assertTrue( is_callable( [ $handler, 'filter_personal_data' ] ), 'filter_personal_data should be callable' );
	}
}
