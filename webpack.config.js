const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );

module.exports = {
	...defaultConfig,
	entry: {
		'unwan-address-picker': './src/unwan-address-picker.js',
		'blocks/editor': './src/blocks/editor.js',
		'blocks/frontend': './src/blocks/frontend.js',
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
		new CopyWebpackPlugin( {
			patterns: [
				{
					from: 'src/blocks/billing/block.json',
					to: 'blocks/billing/block.json',
				},
				{
					from: 'src/blocks/shipping/block.json',
					to: 'blocks/shipping/block.json',
				},
			],
		} ),
	],
};
