# Import map polyfill (for fralenuvole)

Fixes the '@wordpress/interactivity' module error in older browsers (100% of the
crypto-license page errors: VivoBrowser on Chrome 87, iOS 16.2-16.3, Chrome 80).

- `importmap-polyfill.php`: prints a ~250 byte inline loader at `wp_head` priority 1.
  It runs `HTMLScriptElement.supports('importmap')`; modern browsers stop there.
- `es-module-shims.js`: es-module-shims 2.8.4 (MIT), 82 KB raw / 23 KB gzip,
  downloaded only by browsers without import map support. In polyfill mode it
  re-runs the module graph that failed natively, so the menu works.

Install: put both files in one folder inside the plugin (for example
`modules/importmap-polyfill/`) and `require_once` the PHP file.

Note: the native error may still be logged once before the polyfill runs, so the
beacon count may not drop to zero; the menu should work for those visitors.
