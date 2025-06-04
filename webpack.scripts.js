const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry,
		'replace-media': path.resolve(__dirname, 'src/replace-media.js'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
		filename: '[name].js',
	},
};
