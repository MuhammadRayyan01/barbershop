const fs = require('node:fs');
const path = require('node:path');

const cssPath = path.join(__dirname, '../assets/css/tailwind.css');
const css = fs.readFileSync(cssPath, 'utf8')
  .replace(/\/\*[\s\S]*?\*\//g, '')
  .replace(/\n{3,}/g, '\n\n')
  .trimStart();

fs.writeFileSync(cssPath, css);
