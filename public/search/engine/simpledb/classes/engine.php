<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Simple moodle database engine.
 *
 * @package    search_simpledb
 * @copyright  2016 David Monllao {@link http://www.davidmonllao.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_simpledb;

defined('MOODLE_INTERNAL') || die();

/**
 * Simple moodle database engine.
 *
 * @package    search_simpledb
 * @copyright  2016 David Monllao {@link http://www.davidmonllao.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine extends \core_search\engine {

    /**
     * Total number of available results.
     *
     * @var null|int
     */
    protected $totalresults = null;

    /**
     * MySQL InnoDB minimum full-text token size.
     *
     * @var null|int
     */
    private ?int $mysqlmintokensize = null;

    /**
     * Cached lower-case fulltext-search stopwords for the current database, or null if not yet computed.
     *
     * @var string[]|null
     */
    private ?array $stopwords = null;

    /**
     * MySQL/MariaDB InnoDB's built-in default full-text stopword list, applied when
     * innodb_ft_server_stopword_table is not customised. This list is fixed by the server and not
     * user-configurable, so it is hardcoded here rather than read from
     * INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD, which requires the PROCESS privilege and is
     * therefore unavailable to the restricted database user a typical Moodle site connects with.
     *
     * @var string[]
     */
    private const MYSQL_DEFAULT_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how',
        'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what',
        'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    /**
     * Prepares a SQL query, applies filters and executes it returning its results.
     *
     * @throws \core_search\engine_exception
     * @param  stdClass     $filters Containing query and filters.
     * @param  array        $usercontexts Contexts where the user has access. True if the user can access all contexts.
     * @param  int          $limit The maximum number of results to return.
     * @return \core_search\document[] Results or false if no results
     */
    public function execute_query($filters, $usercontexts, $limit = 0) {
        global $DB, $USER;

        $serverstatus = $this->is_server_ready();
        if ($serverstatus !== true) {
            throw new \core_search\engine_exception('engineserverstatus', 'search');
        }

        if (empty($limit)) {
            $limit = \core_search\manager::MAX_RESULTS;
        }

        $params = array();

        // To store all conditions we will add to where.
        $ands = array();

        // Get results only available for the current user.
        $ands[] = '(owneruserid = ? OR owneruserid = ?)';
        $params = array_merge($params, array(\core_search\manager::NO_OWNER_ID, $USER->id));

        // Restrict it to the context where the user can access, we want this one cached.
        // If the user can access all contexts $usercontexts value is just true, we don't need to filter
        // in that case.
        if ($usercontexts && is_array($usercontexts)) {
            // Join all area contexts into a single array and implode.
            $allcontexts = array();
            foreach ($usercontexts as $areaid => $areacontexts) {
                if (!empty($filters->areaids) && !in_array($areaid, $filters->areaids)) {
                    // Skip unused areas.
                    continue;
                }
                foreach ($areacontexts as $contextid) {
                    // Ensure they are unique.
                    $allcontexts[$contextid] = $contextid;
                }
            }
            if (empty($allcontexts)) {
                // This means there are no valid contexts for them, so they get no results.
                return array();
            }

            list($contextsql, $contextparams) = $DB->get_in_or_equal($allcontexts);
            $ands[] = 'contextid ' . $contextsql;
            $params = array_merge($params, $contextparams);
        }

        // Course id filter.
        if (!empty($filters->courseids)) {
            list($conditionsql, $conditionparams) = $DB->get_in_or_equal($filters->courseids);
            $ands[] = 'courseid ' . $conditionsql;
            $params = array_merge($params, $conditionparams);
        }

        // Area id filter.
        if (!empty($filters->areaids)) {
            list($conditionsql, $conditionparams) = $DB->get_in_or_equal($filters->areaids);
            $ands[] = 'areaid ' . $conditionsql;
            $params = array_merge($params, $conditionparams);
        }

        if (!empty($filters->title)) {
            $ands[] = $DB->sql_like('title', '?', false, false);
            $params[] = $filters->title;
        }

        if (!empty($filters->timestart)) {
            $ands[] = 'modified >= ?';
            $params[] = $filters->timestart;
        }
        if (!empty($filters->timeend)) {
            $ands[] = 'modified <= ?';
            $params[] = $filters->timeend;
        }

        // And finally the main query after applying all AND filters.
        if (!empty($filters->q)) {
            switch ($DB->get_dbfamily()) {
                case 'postgres':
                    $postgresquery = $this->get_fulltext_query($filters->q);
                    if ($postgresquery === '') {
                        return [];
                    }

                    $ands[] = "(" .
                        "to_tsvector('simple', title) @@ to_tsquery('simple', ?) OR " .
                        "to_tsvector('simple', content) @@ to_tsquery('simple', ?) OR " .
                        "to_tsvector('simple', description1) @@ to_tsquery('simple', ?) OR " .
                        "to_tsvector('simple', description2) @@ to_tsquery('simple', ?))";

                    $params[] = $postgresquery;
                    $params[] = $postgresquery;
                    $params[] = $postgresquery;
                    $params[] = $postgresquery;
                    break;

                case 'mysql':
                    if ($DB->is_fulltext_search_supported()) {
                        $mysqlquery = $this->get_fulltext_query($filters->q);
                        if ($mysqlquery === '') {
                            return [];
                        }

                        $ands[] = "(MATCH (title, content, description1, description2) AGAINST (? IN BOOLEAN MODE))";
                        $params[] = $mysqlquery;
                    } else {
                        // Fallback when full-text is not supported.
                        [$simplequeryand, $simplequeryparams] = $this->get_simple_query($filters->q);
                        $ands[] = $simplequeryand;
                        $params = array_merge($params, $simplequeryparams);
                    }
                    break;

                case 'mssql':
                    if ($DB->is_fulltext_search_supported()) {
                        $mssqlquery = $this->get_fulltext_query($filters->q);
                        if ($mssqlquery === '') {
                            return [];
                        }

                        $ands[] = "CONTAINS ((title, content, description1, description2), ?)";
                        $params[] = $mssqlquery;
                    } else {
                        // Fallback when full-text is not supported.
                        [$simplequeryand, $simplequeryparams] = $this->get_simple_query($filters->q);
                        $ands[] = $simplequeryand;
                        $params = array_merge($params, $simplequeryparams);
                    }
                    break;

                default:
                    [$simplequeryand, $simplequeryparams] = $this->get_simple_query($filters->q);
                    $ands[] = $simplequeryand;
                    $params = array_merge($params, $simplequeryparams);
                    break;
            }
        }

        // It is limited to $limit, no need to use recordsets.
        $documents = $DB->get_records_select('search_simpledb_index', implode(' AND ', $ands), $params, 'docid', '*', 0, $limit);

        // Hopefully database cached results as this applies the same filters than above.
        $this->totalresults = $DB->count_records_select('search_simpledb_index', implode(' AND ', $ands), $params);

        $numgranted = 0;

        // Iterate through the results checking its availability and whether they are available for the user or not.
        $docs = array();
        foreach ($documents as $docdata) {
            if ($docdata->owneruserid != \core_search\manager::NO_OWNER_ID && $docdata->owneruserid != $USER->id) {
                // If owneruserid is set, no other user should be able to access this record.
                continue;
            }

            if (!$searcharea = $this->get_search_area($docdata->areaid)) {
                $this->totalresults--;
                continue;
            }

            // Switch id back to the document id.
            $docdata->id = $docdata->docid;
            unset($docdata->docid);

            $access = $searcharea->check_access($docdata->itemid);
            switch ($access) {
                case \core_search\manager::ACCESS_DELETED:
                    $this->delete_by_id($docdata->id);
                    $this->totalresults--;
                    break;
                case \core_search\manager::ACCESS_DENIED:
                    $this->totalresults--;
                    break;
                case \core_search\manager::ACCESS_GRANTED:
                    $numgranted++;
                    $docs[] = $this->to_document($searcharea, (array)$docdata);
                    break;
            }

            // This should never happen.
            if ($numgranted >= $limit) {
                $docs = array_slice($docs, 0, $limit, true);
                break;
            }
        }

        return $docs;
    }

    /**
     * Adds a document to the search engine.
     *
     * This does not commit to the search engine.
     *
     * @param \core_search\document $document
     * @param bool $fileindexing True if file indexing is to be used
     * @return bool False if the file was skipped or failed, true on success
     */
    public function add_document($document, $fileindexing = false) {
        global $DB;

        $doc = (object)$document->export_for_engine();

        // Moodle's ids using DML are always autoincremented.
        $doc->docid = $doc->id;
        unset($doc->id);

        $id = $DB->get_field('search_simpledb_index', 'id', array('docid' => $doc->docid));
        try {
            if ($id) {
                $doc->id = $id;
                $DB->update_record('search_simpledb_index', $doc);
            } else {
                $DB->insert_record('search_simpledb_index', $doc);
            }

        } catch (\dml_exception $ex) {
            debugging('dml error while trying to insert document with id ' . $doc->docid . ': ' . $ex->getMessage(),
                DEBUG_DEVELOPER);
            return false;
        }

        return true;
    }

    /**
     * Deletes the specified document.
     *
     * @param string $id The document id to delete
     * @return void
     */
    public function delete_by_id($id) {
        global $DB;
        $DB->delete_records('search_simpledb_index', array('docid' => $id));
    }

    /**
     * Delete all area's documents.
     *
     * @param string $areaid
     * @return void
     */
    public function delete($areaid = null) {
        global $DB;
        if ($areaid) {
            $DB->delete_records('search_simpledb_index', array('areaid' => $areaid));
        } else {
            $DB->delete_records('search_simpledb_index');
        }
    }

    /**
     * Checks that the required table was installed.
     *
     * @return true|string Returns true if all good or an error string.
     */
    public function is_server_ready() {
        global $DB;
        if (!$DB->get_manager()->table_exists('search_simpledb_index')) {
            return 'search_simpledb_index table does not exist';
        }

        return true;
    }

    /**
     * It is always installed.
     *
     * @return true
     */
    public function is_installed() {
        return true;
    }

    /**
     * Returns the total results.
     *
     * Including skipped results.
     *
     * @return int
     */
    public function get_query_total_count() {
        if (is_null($this->totalresults)) {
            // This is a just in case as we count total results in execute_query.
            return \core_search\manager::MAX_RESULTS;
        }

        return $this->totalresults;
    }

    /**
     * Returns the default query for db engines.
     *
     * @param string $q The query string
     * @return array SQL string and params list
     */
    protected function get_simple_query($q) {
        global $DB;

        $sql = '(' .
            $DB->sql_like('title', '?', false, false) . ' OR ' .
            $DB->sql_like('content', '?', false, false) . ' OR ' .
            $DB->sql_like('description1', '?', false, false) . ' OR ' .
            $DB->sql_like('description2', '?', false, false) .
            ')';

        // Remove single and double quotes from the query.
        $q = str_replace(['"', "'"], '', $q);
        $params = [
            '%' . $q . '%',
            '%' . $q . '%',
            '%' . $q . '%',
            '%' . $q . '%'
        ];

        return array($sql, $params);
    }

    /**
     * Get a full-text query according to the requirements of the current database.
     *
     * @param string $q The query string
     * @return string The full-text query
     */
    private function get_fulltext_query(string $q): string {
        global $DB;
        // Normalise typographic apostrophes to a plain apostrophe.
        $q = preg_replace('/[\x{2018}\x{2019}]/u', "'", $q);
        // Remove apostrophes and everything after them within each token.
        $q = preg_replace("/'[^\\s]*/u", '', $q);
        // Split the query into individual words on whitespace boundaries.
        $parts = preg_split('/\s+/u', trim($q));

        if (!$parts) {
            return '';
        }

        // For MySQL, tokens shorter than innodb_ft_min_token_size are never indexed, so
        // including them as required (+) boolean-mode terms returns zero rows.
        $dbfamily = $DB->get_dbfamily();
        if ($dbfamily === 'mysql' && $this->mysqlmintokensize === null) {
            try {
                $this->mysqlmintokensize = (int) $DB->get_field_sql('SELECT @@innodb_ft_min_token_size');
            } catch (\dml_exception $e) {
                $this->mysqlmintokensize = 3; // InnoDB default.
            }
        }

        // MySQL and MSSQL do not index fulltext stopwords, so including one as a required
        // (MySQL boolean-mode +term, MSSQL CONTAINS AND term) conjunct returns zero rows even
        // though the rest of the phrase matches. Drop them from the term list instead.
        $stopwords = in_array($dbfamily, ['mysql', 'mssql'], true) ? $this->get_stopwords() : [];

        $terms = [];
        foreach ($parts as $part) {
            // Strip any remaining non-letter, non-number characters from each word.
            $term = preg_replace('/[^\pL\pN_]+/u', '', $part);
            if ($term === '') {
                continue;
            }
            if ($stopwords && in_array(\core_text::strtolower($term), $stopwords, true)) {
                continue;
            }
            switch ($dbfamily) {
                case 'postgres':
                    $terms[] = $term . ':*';
                    break;
                case 'mysql':
                    // Skip tokens that are too short to be indexed.
                    if (\core_text::strlen($term) >= $this->mysqlmintokensize) {
                        $terms[] = '+' . $term . '*';
                    }
                    break;
                case 'mssql':
                    $terms[] = '"' . $term . '*"';
                    break;
            }
        }

        if (!$terms) {
            return '';
        }

        switch ($dbfamily) {
            case 'postgres':
                // Join terms with the tsquery AND operator so all terms must match.
                return implode(' & ', $terms);
            case 'mysql':
                // Join terms with spaces; the + prefix on each term enforces AND semantics.
                return implode(' ', $terms);
            case 'mssql':
                // Join terms with AND to enforce all-terms-must-match semantics.
                return implode(' AND ', $terms);
            default:
                return '';
        }
    }

    /**
     * Returns the fulltext-search stopwords configured for the current database, in lower case.
     *
     * Only MySQL and MSSQL are queried; other database families do not strip stopwords from the
     * fulltext index (PostgreSQL's 'simple' text search configuration used here does not either).
     *
     * @return string[] Lower-case stopwords, or an empty array if none apply or could be determined.
     */
    private function get_stopwords(): array {
        global $DB;

        if ($this->stopwords !== null) {
            return $this->stopwords;
        }

        try {
            switch ($DB->get_dbfamily()) {
                case 'mysql':
                    $this->stopwords = $this->get_mysql_stopwords();
                    break;
                case 'mssql':
                    $this->stopwords = $this->get_mssql_stopwords();
                    break;
                default:
                    $this->stopwords = [];
            }
        } catch (\dml_exception $e) {
            // If the stopword list cannot be determined, fall back to not filtering anything.
            $this->stopwords = [];
        }

        return $this->stopwords;
    }

    /**
     * Returns the InnoDB fulltext stopwords configured for the current MySQL/MariaDB server.
     *
     * @return string[] Lower-case stopwords.
     */
    private function get_mysql_stopwords(): array {
        global $DB;

        if (!(int) $DB->get_field_sql('SELECT @@innodb_ft_enable_stopword')) {
            // Stopword filtering is disabled server-side, so nothing is excluded from the index.
            return [];
        }

        if (trim((string) $DB->get_field_sql('SELECT @@innodb_ft_server_stopword_table')) !== '') {
            // A custom stopword table is configured server-side; its contents are not queried
            // here, so leave filtering to the pre-existing (unfiltered) behaviour for this case.
            return [];
        }

        return self::MYSQL_DEFAULT_STOPWORDS;
    }

    /**
     * Returns the fulltext stopwords configured for the stoplist attached to the search index,
     * according to the current MSSQL server.
     *
     * @return string[] Lower-case stopwords.
     */
    private function get_mssql_stopwords(): array {
        global $DB;

        $tablename = $DB->get_prefix() . 'search_simpledb_index';
        $stoplistid = $DB->get_field_sql(
            'SELECT fi.stoplist_id FROM sys.fulltext_indexes fi WHERE fi.object_id = OBJECT_ID(?)',
            [$tablename]
        );

        if ($stoplistid === false || $stoplistid === null) {
            // No fulltext index found, or it has STOPLIST = OFF (nothing is excluded from the
            // index, so there is nothing to filter out of the query either).
            return [];
        }

        if ((int) $stoplistid === 0) {
            // Stoplist id 0 is SQL Server's built-in system stoplist, which is what a fulltext
            // index gets by default when no STOPLIST clause is given (as is the case for the
            // search_simpledb index, see db/install.php). Its words are not listed in
            // sys.fulltext_stopwords -- that view only covers stoplists created via CREATE
            // FULLTEXT STOPLIST -- so look them up in sys.fulltext_system_stopwords instead,
            // keyed by the language(s) configured on the index's columns.
            $sql = "SELECT DISTINCT sw.stopword
                      FROM sys.fulltext_index_columns fic
                      JOIN sys.fulltext_system_stopwords sw ON sw.language_id = fic.language_id
                     WHERE fic.object_id = OBJECT_ID(?)";
            $words = $DB->get_fieldset_sql($sql, [$tablename]);
        } else {
            $sql = 'SELECT stopword FROM sys.fulltext_stopwords WHERE stoplist_id = ?';
            $words = $DB->get_fieldset_sql($sql, [$stoplistid]);
        }

        return array_map('core_text::strtolower', $words);
    }

    /**
     * Simpledb supports deleting the index for a context.
     *
     * @param int $oldcontextid Context that has been deleted
     * @return bool True to indicate that any data was actually deleted
     * @throws \core_search\engine_exception
     */
    public function delete_index_for_context(int $oldcontextid) {
        global $DB;
        try {
            $DB->delete_records('search_simpledb_index', ['contextid' => $oldcontextid]);
        } catch (\dml_exception $e) {
            throw new \core_search\engine_exception('dbupdatefailed');
        }
        return true;
    }

    /**
     * Simpledb supports deleting the index for a course.
     *
     * @param int $oldcourseid
     * @return bool True to indicate that any data was actually deleted
     * @throws \core_search\engine_exception
     */
    public function delete_index_for_course(int $oldcourseid) {
        global $DB;
        try {
            $DB->delete_records('search_simpledb_index', ['courseid' => $oldcourseid]);
        } catch (\dml_exception $e) {
            throw new \core_search\engine_exception('dbupdatefailed');
        }
        return true;
    }
}
