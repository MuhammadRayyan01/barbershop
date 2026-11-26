/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './admin/**/*.{php,html}',
    './user/**/*.{php,html}',
    './includes/**/*.{php,html}',
    './*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
