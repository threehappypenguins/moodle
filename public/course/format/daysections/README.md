# Day sections (format_daysections)

Moodle course format that shows the course as numbered day sections (Day 1, Day 2, Day 3, …), based on Topics format.

## Requirements

- Moodle 5.2–5.3 (see `version.php`)
- `format_topics`

## Install

1. Place this plugin in `course/format/daysections`
2. Visit **Site administration → Notifications** to finish install
3. Set a course’s format to **Day sections**
4. If you have several courses and you want to convert them all to `daysections`:
   ```bash
   # list course formats
   sudo mariadb moodle -e "SELECT id, shortname, format FROM mdl_course WHERE id != 1 ORDER BY id;"
   ```

   *Note*: If you have hidden courses, you will need to unhide and hide them again in order for them to be hidden correctly.

   ```bash
   # convert all courses to `daysections`
   sudo mariadb moodle -e "UPDATE mdl_course SET format='daysections' WHERE id != 1;"
   ```

## Features

- Default section names use a configurable prefix (site-wide and per-course), e.g. `Day 1`
- Section 0 remains **General**
- Same editing and navigation behaviour as Topics (indentation, linear nav, etc.)

## License

GNU GPL v3 or later