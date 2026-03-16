const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const CopyPlugin = require( 'copy-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		'foldsnap-admin': path.resolve( __dirname, 'template/js/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js' ),
	},
	plugins: [
		...( defaultConfig.plugins || [] ),
		new CopyPlugin( {
			patterns: [
				{
					from: path.resolve(
						__dirname,
						'template/js/foldsnap-dragdrop.js'
					),
					to: path.resolve(
						__dirname,
						'assets/js/foldsnap-dragdrop.js'
					),
				},
			],
		} ),
	],
};
