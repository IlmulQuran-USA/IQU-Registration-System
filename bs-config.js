module.exports = {
  proxy: "localhost/wordpress",
  files: [
    "C:/xampp/htdocs/wordpress/wp-content/plugins/iqu-registration/**/*.php",
    "C:/xampp/htdocs/wordpress/wp-content/plugins/iqu-registration/**/*.css",
    "C:/xampp/htdocs/wordpress/wp-content/plugins/iqu-registration/**/*.js"
  ],
  watchOptions: {
    usePolling: true,
    interval: 500
  }
};