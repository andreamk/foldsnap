module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		'no-unused-vars': 'error',
		'no-console': 'error',
	},
	overrides: [
		{
			files: [ '**/__tests__/**/*.[jt]s?(x)', '**/?(*.)test.[jt]s?(x)' ],
			env: {
				jest: true,
			},
		},
	],
};
