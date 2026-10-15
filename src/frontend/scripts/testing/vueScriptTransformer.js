const babelJest = require("babel-jest").createTransformer({
  configFile: false,
  babelrc: false,
  presets: [["@babel/preset-env", { targets: { node: "current" } }]],
})

module.exports = {
  process(source, filename, configuration, options) {
    const script = source.match(/<script>([\s\S]*?)<\/script>/)
    if (!script) throw new Error(`Missing Vue script in ${filename}`)
    return babelJest.process(
      script[1],
      filename + ".js",
      configuration,
      options,
    )
  },
}
