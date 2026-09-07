/**
 * External dependencies
 */
const { resolve } = require('path');
const path = require('path');
const CopyPlugin = require('copy-webpack-plugin');
const WebpackBar = require('webpackbar');
const NODE_ENV = 'production';

const webpackConfig = {
	mode: NODE_ENV,
	entry: {
		dashboard: resolve(process.cwd(), './src/dashboard/index.js'),
		blocks: resolve(process.cwd(), './src/blocks/index.js'),
		divibuilder: resolve(process.cwd(), './src/divibuilder/index.jsx'),
		templates: resolve(process.cwd(), './src/templates/index.tsx'),
		rolesandpermission: resolve(
			process.cwd(),
			'./src/rolesandpermission/index.js',
		),
		dashboardHeader: resolve(
			process.cwd(),
			'./src/dashboard/header-standalone.js',
		),
		builderAI: resolve(process.cwd(), './src/builder-ai/index.tsx'),
		styleCustomizerV2: resolve(
			process.cwd(),
			'./src/style-customizer-v2/index.tsx',
		),
	},
	output: {
		path: resolve(process.cwd(), 'dist'),
		filename: '[name].min.js',
		libraryTarget: 'this',
	},
	devtool: NODE_ENV ? false : 'source-map',
	module: {
		rules: [
			{
				test: /\.(js|jsx|ts|tsx)$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							'@babel/preset-env',
							'@babel/preset-react',
							'@babel/preset-typescript',
						],
					},
				},
			},
			{
				test: /.scss$/,
				use: [
					'style-loader',
					'css-loader',
					{
						loader: 'sass-loader',
						options: {
							// Dart Sass (since 1.71) prepends a BOM/@charset to its output
							// whenever a stylesheet has non-ASCII characters (e.g. em dashes
							// in comments). style-loader injects that output verbatim into a
							// runtime <style> tag, and the leading BOM breaks the CSS parser's
							// recognition of the very first rule. Disable it at the source.
							sassOptions: {
								charset: false,
							},
						},
					},
				],
			},
			{
				test: /\.(png|svg|jpg|jpeg|gif|webp)$/i,
				use: [
					{
						loader: 'file-loader',
					},
				],
			},
		],
	},
	plugins: [
		new WebpackBar(),
		new CopyPlugin({
			patterns: [
				{
					from: './src/blocks/**/block.json',
					to({ absoluteFilename }) {
						return path.resolve(
							__dirname,
							'dist',
							path.basename(path.dirname(absoluteFilename)),
							'block.json',
						);
					},
				},
			],
		}),
	],
	externals: {
		'@wordpress/blocks': ['wp', 'blocks'],
		'@wordpress/components': ['wp', 'components'],
		'@wordpress/block-editor': ['wp', 'blockEditor'],
		'@wordpress/server-side-render': ['wp', 'serverSideRender'],
		'@wordpress/hooks': ['wp', 'hooks'],
		react: ['React'],
	},
	resolve: {
		extensions: ['.js', '.jsx', '.ts', '.tsx'],
	},
	performance: {
		hints: false, // Suppress performance warnings
	},
};

if (webpackConfig.mode !== 'production') {
	webpackConfig.devtool = process.env.SOURCEMAP || 'source-map';
}

module.exports = webpackConfig;
