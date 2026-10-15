module.exports = {
  testEnvironment: "node",
  testMatch: ["<rootDir>/src/__tests__/Battery*.test.js"],
  moduleNameMapper: { "^@/(.*)$": "<rootDir>/src/$1" },
  transform: {
    "^.+\\.vue$": "<rootDir>/scripts/testing/vueScriptTransformer.js",
    "^.+\\.jsx?$": [
      "babel-jest",
      {
        configFile: false,
        babelrc: false,
        presets: [["@babel/preset-env", { targets: { node: "current" } }]],
      },
    ],
  },
}
