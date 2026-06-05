<?php
/**
 * Basic unit tests for FE Search AI plugin
 *
 * @package FE_Search_AI\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

/**
 * Basic functionality tests
 *
 * @since 1.0.0
 */
class BasicTest extends TestCase {

	/**
	 * Test plugin constants are defined
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'FE_SEARCH_AI_VERSION' ), 'FE_SEARCH_AI_VERSION constant should be defined' );
		$this->assertTrue( defined( 'FE_SEARCH_AI_PLUGIN_DIR' ), 'FE_SEARCH_AI_PLUGIN_DIR constant should be defined' );
		$this->assertTrue( defined( 'FE_SEARCH_AI_PLUGIN_FILE' ), 'FE_SEARCH_AI_PLUGIN_FILE constant should be defined' );
	}

	/**
	 * Test plugin main class exists
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_main_class_exists() {
		$this->assertTrue( class_exists( 'FESearchAI\Core\FE_Search_AI_Activator' ), 'FE_Search_AI_Activator class should exist' );
	}

	/**
	 * Test plugin initialization
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_initialization() {
		$this->assertTrue( function_exists( 'fe_search_ai' ), 'fe_search_ai() template tag should be loaded' );
	}

	/**
	 * Test plugin version format
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_version_format() {
		$version = FE_SEARCH_AI_VERSION;
		$this->assertMatchesRegularExpression( '/^\d+(?:\.\d+){2,}$/', $version, 'Version should be in x.y.z or longer numeric dot-separated format' );
	}

	/**
	 * Test plugin directory structure
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_plugin_directory_structure() {
		$this->assertTrue( is_dir( FE_SEARCH_AI_PLUGIN_DIR . 'includes' ), 'Includes directory should exist' );
		$this->assertTrue( is_dir( FE_SEARCH_AI_PLUGIN_DIR . 'languages' ), 'Languages directory should exist' );
		$this->assertTrue( file_exists( FE_SEARCH_AI_PLUGIN_DIR . 'fe-search-ai.php' ), 'Main plugin file should exist' );
	}
}
