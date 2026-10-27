Description of import into Moodle:
1. Download from https://requirejs.org/docs/download.html
2. Put the require.js and require.min.js and LICENSE file in this folder.
3. Update lib/thirdpartylibs.xml with the latest version information.
4. Check that core_privacy\local\request\moodle_content_writer::write_html_data() does not need to be updated.

Note: requirejs.org's download page can lag behind the latest tagged release in
https://github.com/requirejs/requirejs (e.g. it may still serve the minified
build for the previous version). If so:
* Take require.js and LICENSE directly from the git tag for the target version.
* Build require.min.js yourself using the same command the upstream project's
  own release script (dist/dist-build.sh) uses:
    uglifyjs require.js -mc --comments -o require.min.js
