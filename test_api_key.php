<?php
// WordPress環境を読み込み
require_once '../../../wp-config.php';

// オプションを取得
$options       = get_option( 'fe_search_ai_options' );
$encrypted_key = $options['provider']['openai_key'] ?? '';

// 復号
if ( class_exists( 'FE_Search_AI_Encryption_Helper' ) ) {
	$api_key = FE_Search_AI_Encryption_Helper::decrypt( $encrypted_key );
} else {
	$api_key = $encrypted_key;
}
$api_key = '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';
echo 'API Key: ' . substr( $api_key, 0, 10 ) . '...' . PHP_EOL;

// curlテスト
$ch = curl_init();
curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions' );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_POST, true );
curl_setopt(
	$ch,
	CURLOPT_POSTFIELDS,
	json_encode(
		[
			'model'    => 'gpt-4o-mini',
			'messages' => [
				[ 'role' => 'user', 'content' => 'お久しぶり' ],
			],
			'stream'   => false,
		]
	)
);
curl_setopt(
	$ch,
	CURLOPT_HTTPHEADER,
	[
		'Content-Type: application/json',
		'Authorization: Bearer ' . $api_key,
	]
);

$response  = curl_exec( $ch );
$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

echo 'HTTP Status: ' . $http_code . PHP_EOL;
echo 'Response: ' . $response . PHP_EOL;
