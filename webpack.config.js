const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  ...defaultConfig,
  module: {
    ...defaultConfig.module,
    rules: [
      ...defaultConfig.module.rules.filter(
        (rule) => rule.test && !rule.test.toString().includes('css')
      ),
      {
        test: /\.css$/,
        use: [
          defaultConfig.module.rules.find((r) => r.test && r.test.toString().includes('css'))
            ?.use?.[0] || 'style-loader',
          'css-loader',
          'postcss-loader',
        ],
      },
    ],
  },
};
