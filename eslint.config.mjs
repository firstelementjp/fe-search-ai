import wordpress from '@wordpress/eslint-plugin';
import prettierConfig from 'eslint-config-prettier';
import prettierPlugin from 'eslint-plugin-prettier';
import globals from 'globals';

export default [
	{
		ignores: [
			'node_modules/',
			'vendor/',
			'_deprecated/',
			'**/*.min.js',
			'**/*.min.css',
			'assets/css/*.min.css',
			'assets/js/*.min.js',
		],
	},
	...wordpress.configs.recommended,
	{
		plugins: {
			prettier: prettierPlugin,
		},
		languageOptions: {
			globals: {
				...globals.browser,
				jQuery: 'readonly',
				wp: 'readonly',
			},
		},
		rules: {
			'prettier/prettier': 'error',
			indent: 'off',
			semi: 'off',
			'no-unused-vars': ['error', { caughtErrors: 'none' }],
			'linebreak-style': ['error', 'unix'],
			'comma-dangle': ['error', 'only-multiline'],
			'object-curly-spacing': ['error', 'always'],
			'array-bracket-spacing': ['error', 'never'],
			'keyword-spacing': ['error', { before: true, after: true }],
			camelcase: 'off',
			'@wordpress/no-unused-vars-before-return': ['error', { excludePattern: '^_' }],
			'@wordpress/no-global-active-element': 'warn',
			'@wordpress/no-global-get-selection': 'warn',
		},
	},
	prettierConfig,
];
