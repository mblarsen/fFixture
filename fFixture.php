<?php

/**
 * Provides an easy way of creating sample data (fixtures) for unit testing.
 *
 * The class is intened to use as an easy way to build test data for unit testing. Data that is created and torn down after testing is complete.
 * In other words, it does not handle updates or in any other way handle situations where the database has existing data.
 *
 * The fixtures, though JSON, will first be evalutated as PHP scripts, so you can included <?php echo data('Y-m-d H:i:s); ?> into you json files.
 *
 * If you use namespaces, either make sure that you have included the namespaced classes before running fFixture::create(). Alternativly you can use
 * the fORM::mapClassToTable() method:
 *
 * fORM::mapClassToTable('My\Namespaced\Class', 'classes');
 *
 * Limitations:
 *  - fFixture does not handle multiplie cases where you have records split over several database. It will either use the default ORM database or the
 *    one you supply using setDatabase().
 *  - Only table dependency is supported and only at one level.
 *  - Only relational validation (foreign key checks) is done when invoking validate(). The rest is up to Flourish at build time.
 *
 * Future:
 *  - Implement auto-fill mode (missing properties, dependencies, etc.)
 *  - Create records using partial specifications together with or in place of fixture files.
 *  - Handle selfreferencing patterns like, trees.
 *  - Support for complex primary keys
 *  - Helper method to easily work references in relationships. (eg. page uses [column layout])
 *  - Other input source (eg. string, array)
 *
 * @author Michael Bøcker-Larsen mbl@codeboutiuqe.com
 *
 */
class fFixture
{
	const PreSetBuildHook = 'pre-set::build()';

	static private $database;

	static private $verbose;

	static private $glabal_hook_callbacks = array();

	/**
	 * Return a new fFixture instance after parsing the json files found in either $root or $replacements_root.
	 *
	 * Only fixtures in $white_list and their dependencies will be created. Leave $white_list empty to build everything.
	 *
	 * Replacements root is useful in cases where you need very specific records. You could include these fixtures with the
	 * unit tests.
	 *
	 * @param $root
	 *   The root of the fixture directory.
	 * @param $white_list
	 *   An array of fixture names to include when building. NULL or empty means that every fixture found will be build.
	 * @param $replacements_root
	 *
	 */
	static public function create($root, $white_list = NULL, $replacements_root = NULL)
	{
		$db = self::$database;

		if (is_null($db)) {
			throw new fEnvironmentException('Database not set');
		}

		$fixture = new self($root, $db, $white_list, $replacements_root);

		return $fixture;
	}

	/**
	* Sets the database to use for ORM operations.
	*/
	static public function setDatabase(fDatabase $database)
	{
		self::$database = $database;
		fORMDatabase::attach($database);
	}

	/**
	 * Registers a global hook callback. Global hooks are registered with the instance fixtures upon creation.
	 *
	 * @param $hook_name
	 *   The name of the hook.
	 * @param $fixture_name
	 *   The fixture for which to register the hook.
	 * @param $callback
	 *   The callback function
	 */
	static public function registerGlobalHookCallback($hook_name, $fixture_name, $callback)
	{
		if (isset(self::$glabal_hook_callbacks) === FALSE) {
			self::$glabal_hook_callbacks = array(
				self::PreSetBuildHook => array()
			);
		}

		if (in_array($hook_name, array(self::PreSetBuildHook)) === FALSE) {
			throw new fValidationException('Invalid hook name, %s', $hook_name);
		}

		if (isset(self::$glabal_hook_callbacks[$hook_name][$fixture_name]) === FALSE) {
			self::$glabal_hook_callbacks[$hook_name][$fixture_name] = array();
		}

		self::$glabal_hook_callbacks[$hook_name][$fixture_name][] = $callback;
	}

	static public function setVerbose($value)
	{
		self::$verbose = $value;
	}

	// ----------------------------- Instance code -----------------------------

	private $db;
	private $schema;

	private $root;
	private $replacements_root;

	private $ancestor_mode;
	private $fill_mode;

	private $fixture_sources;
	private $fixture_data;

	private $white_list;
	private $hook_callbacks;

	private $tables_to_tear_down;

	private $column_info;
	private $foreign_keys;

	/**
	 * Create a new instance. Use fFixture::create()
	 */
	public function __construct($root, $db, $white_list = NULL, $replacements_root = NULL)
	{
		// Ready meta data arrays

		$this->column_info = array();
		$this->foreign_keys = array();

		$this->ancestor_mode = FALSE;
		$this->fill_mode = FALSE;
		$this->white_list = is_null($white_list) ? array() : $white_list;

		// Create fDirectory object if root isn't already.

		if ($root instanceof fDirectory) {
			$this->root = $root;
		} else {
			$this->root = new fDirectory($root);
		}

        // Scan root for fixtures

        $fixture_files = $this->root->scan('*.json');

        if (empty($fixture_files)) {
			throw new fEnvironmentException(
				'The root specified, %s, does not contain any fixtures',
				$this->root
			);
        }

		// Create map fixture name (key) => file (value)

		foreach ($fixture_files as $file) {

			$fixture_name = $file->getName(TRUE);
			$this->fixture_sources[$fixture_name] = $file;

		}

		// Check for replacements

		if ($replacements_root) {

			// Create fDirectory object if replacements root isn't already

			if ($root instanceof fDirectory) {
				$this->replacements_root = $replacements_root;
			} else {
				$this->replacements_root = new fDirectory($replacements_root);
			}

	        // If replacements has been specified use those

	        if ($this->replacements_root) {

	            $replacements_fixture_files = $this->replacements_root->scan('*.json');

	            if (empty($replacements_fixture_files)) {
	    			throw new fEnvironmentException(
	    				'The replacements root specified, %s, does not contain any fixtures',
	    				$this->replacements_root
	    			);
	            }

				// Create map fixture name (key) => file (value)

				foreach ($replacements_fixture_files as $file) {

					$fixture_name = $file->getName(TRUE);
					$this->fixture_sources[$fixture_name] = $file;

				}
			}
		}

		// Setup db and schema

		$this->db = $db;
		$this->schema = new fSchema($db);
		$this->fixture_data = array();

		// Merge globally registred hooks

		if (isset(self::$glabal_hook_callbacks)) {
			$this->hook_callbacks = self::$glabal_hook_callbacks;
		} else {
			$this->hook_callbacks[self::PreSetBuildHook] = array();
		}

	}

	/**
	 * Validates fixtures buy loading and checking each record for required keys.
	 *
	 * @throws fValidationException In case of missing dependency or if a record is missing a required foreign key.
	 */
	public function validate()
	{
		$queue = $this->buildQueue();

		foreach ($queue as $fixture_name) {
			$this->validateFixture($fixture_name);
		}
	}
	
    /**
     * Build the records in order of dependency.
     *
     * @throws fProgrammerException if table does not exists
     * @throws fValidationException if keys are missing or if non-existing properties is specified
	 *
	 * @return Returns an associative array of fRecordSet objects where the fixture names are the keys.
     */
    public function build()
    {
        try {

			// Build queue

			$build_queue = $this->buildQueue();

			$this->tables_to_tear_down = $build_queue;

			// Build records

			$completed_fixtures = array();

			foreach ($build_queue as $fixture_name) {

				$this->buildRecords($completed_fixtures, $fixture_name);
			}

			return $completed_fixtures;

		} catch (Exception $e) {

			// Tear down tables in case of failure

			$this->tearDown();
			throw $e;

		}

	}
	
	// ----------------------------- Private methods -----------------------------
	
	/**
	 * Validates a fixture data set.
	 *
	 * @param string $fixture_name
	 *   Name of the fixture to validate
	 *
	 * @throws fValidationException In case of missing dependency or if a record is missing a required foreign key.
	 */
	private function validateFixture(/* string */ $fixture_name)
	{
		if (isset($this->fixture_data[$fixture_name]) === FALSE) {
			$this->loadFixture($fixture_name);
		}

		$schema = $this->schema;

		$column_info  = $schema->getColumnInfo($fixture_name);
        $many_to_one  = $schema->getRelationships($fixture_name, 'many-to-one');
		$number_of_relationships = array_reduce($schema->getRelationships($fixture_name), function($number, $relationships) { return $number + count($relationships); }, 0);

		$dependencies = array();

		if ($number_of_relationships === 0) {

			// Join-table like tables

			$keys_found = 0;
			foreach ($schema->getKeys($fixture_name, 'foreign') as $key_data) {
				if ($column_info[$key_data['column']]['not_null'] === TRUE) {
					$dependencies[] = $key_data['foreign_table'];
					$keys_found += 1;
				}
			}

			if ($keys_found !== 2) {
				throw new fValidationException("This fixture, %1$s, has no relationship and doesn't look like a join as it has more than two keys", $fixture_name);
			}

		} else {

			// Many-to-one

			foreach ($many_to_one as $relationship) {

				$key = $relationship['column'];
				$foreign_table = $relationship['related_table'];

				if ($fixture_name === $foreign_table) {
					continue;
				}

				$required = $column_info[$key]['not_null'] === TRUE;

				// Throw exception if foreign_table is not known, unless fill mode is on.

				if ($required && $this->fill_mode === FALSE && isset($this->fixture_data[$foreign_table]) === FALSE) {
					throw new fValidationException('Unable to create fixture for, %1$s, due to missing dependency %2$s',
						$fixture_name,
						$foreign_table
					);
				}

				$records = $this->fixture_data[$fixture_name];

				foreach ($records as $record) {

					if (isset($this->fixture_data[$foreign_table])) {
						continue;
					}

					$key_set = isset($record->$key);

					// Throw exception if a required key is missing, unless fill mode is on.

					if ($this->fill_mode === FALSE && $key_set === TRUE) {
						if ($this->fixture_sources[$foreign_table]) {
							$this->loadFixture($foreign_table);
						} else {
							throw new fValidationException('Unable to create fixture for %1$s with foreign key, %2$s to dependency %3$s',
							$fixture_name,
							$record->$key,
							$foreign_table);
						}
					}

					if ($this->fill_mode === FALSE && $required && $key_set === FALSE) {
						throw new fValidationException('Invalid fixture, %1$s, record requires foreign key %2$s',
						$fixture_name,
						$key);
					}
				}
			}
		}
	}
	
	/**
	 * Loads a fixture and makes the fixture data accessible through $this->fixture_data.
	 *
	 * @param string $fixture_name
	 *   Name of the fixture to load.
	 * @return Returns the loaded fixture data or NULL in the case that there was no source for the requested fixture. Note that if fill mode is off exceptions are thrown in stead of returning NULL.
	 */
	private function loadFixture(/* string */ $fixture_name)
	{
		if (isset($this->fixture_data[$fixture_name])) {
			return $this->fixture_data[$fixture_name];
		}

		if (isset($this->fixture_sources[$fixture_name]) === FALSE) {
			//if ($this->fill_mode === FALSE) {
				throw new fValidationException("There exists no fixtures for, %s", $fixture_name);
				//}
			return NULL;
		}

		$fixture_source = $this->fixture_sources[$fixture_name];

		// Helper vars

		$now             = date('Y-m-d H:i:s');
		$a_moment_ago    = date('Y-m-d H:i:s', strtotime('-5 minutes'));
		$in_a_moment_ago = date('Y-m-d H:i:s', strtotime('+5 minutes'));

		// Source is a JSON fixture file

		if ($fixture_source instanceof fFile) {

			// Grab the content from buffer

			ob_start();
			require $fixture_source->getPath();
			$json_data = ob_get_clean();

			// Decode

			$fixture_data = fJSON::decode($json_data);

			if (empty($fixture_data)) {
				throw new fValidationException("Invalid fixture file, %s", $fixture_source->getPath());
			}

			$this->fixture_data[$fixture_name] = $fixture_data;


		} else if ($fixture_source instanceof fFixtureSeed) {

			// Source is a fFixtureSeed

		}
	}
	

    /**
     * Build a queue that is ordered according to dependencies.
     *
     * @param $fixture_name
     *   Fixture to build queue for.
     * @return
     *   Returns an array of table names with dependencies first and table in question last.
     */
    private function buildQueue($fixture_name = NULL)
    {
		$queue = array();

		// If no specific fixture is specified start from the root

		if (is_null($fixture_name)) {

			foreach ($this->fixture_sources as $fixture_name => $fixture_source) {
				if ($this->isWhiteListed($fixture_name)) {
					$queue = array_merge($queue, $this->buildQueue($fixture_name));
				}
			}

			if (self::$verbose) {
				echo "\nwhite list   : " . join(', ', $this->white_list) . "\n";
				echo "initial queue : " . join(', ', array_unique($queue)) . "\n";
			}

			return array_unique($queue);

		}

		// Ready meta data

		$schema = $this->schema;

		$column_info  = $schema->getColumnInfo($fixture_name);
        $many_to_one  = $schema->getRelationships($fixture_name, 'many-to-one');
		$number_of_relationships = array_reduce($schema->getRelationships($fixture_name), function($number, $relationships) { return $number + count($relationships); }, 0);

		$dependencies = array();

		if ($number_of_relationships === 0) {

			// Join-table like tables

			$keys_found = 0;
			foreach ($schema->getKeys($fixture_name, 'foreign') as $key_data) {
				if ($column_info[$key_data['column']]['not_null'] === TRUE) {
					$dependencies[] = $key_data['foreign_table'];
					$keys_found += 1;
				}
			}

			if ($keys_found !== 2) {
				// throw new excption with message: Flourish joins only have keys.
			}

		} else {

			// Many-to-one

			foreach ($many_to_one as $relationship) {

				$key = $relationship['column'];
				$foreign_table = $relationship['related_table'];

				if ($fixture_name === $foreign_table) {
					continue;
				}

				if ($this->ancestor_mode === TRUE || $column_info[$key]['not_null'] === TRUE) {
					$dependencies[] = $foreign_table;
				}
			}
		}

		foreach ($dependencies as $foreign_table) {

			// If the dependency is not found in any source throw exception, unless fill mode is on.

			if ($this->fill_mode === FALSE && isset($this->fixture_sources[$foreign_table]) === FALSE) {
				throw new fProgrammerException('Unable to create fixture for, %1$s, due to missing data for dependency %2$s',
					$fixture_name,
					$foreign_table
				);
			}

			$queue = array_merge($queue, $this->buildQueue($foreign_table));
		}

		$queue[] = $fixture_name;

		return array_unique($queue);
    }

	/**
	 * Recursivly builds records.
	 * 
	 * @param array* $completed_fixtures
	 *   Completed records is stored in this array
	 * @param $fixture_data
	 *   Build records of this fixture
	 */
	private function buildRecords(&$completed_fixtures, $fixture_name)
	{
		if (array_key_exists($fixture_name, $completed_fixtures)) {
			return;
		}

		// Load data

		if (isset($this->fixture_data[$fixture_name]) === FALSE) {
			$this->loadFixture($fixture_name);
		}

		$class_name = fORM::classize($fixture_name);

		// If the class does not exists created it

		if (class_exists($class_name) === FALSE) {
			fORM::defineActiveRecordClass($class_name);
		}

		// Create the records

		$method_name = NULL;
		$record = NULL;

		$records = array();

		foreach ($this->fixture_data[$fixture_name] as $record_data) {

			$record = new $class_name();

			foreach ($record_data as $key => $value) {

				$method_name = 'set' . fGrammar::camelize($key, $upper = TRUE);
				$value = $this->applyHookCallbacks(self::PreSetBuildHook, $fixture_name, $key, $value);


				if ($this->isRelationshipKey($fixture_name, $key)) {

					$related_table = $this->getRelatedTable($fixture_name, $key);
					$required = $this->isRequiredKey($fixture_name, $key);


					if (array_key_exists($related_table, $completed_fixtures) === FALSE && $fixture_name !== $related_table) {

						if (isset($value) && array_key_exists($related_table, $this->fixture_sources)) {
							$this->buildRecords($completed_fixtures, $related_table);
							array_unshift($this->tables_to_tear_down, $related_table);
						}

					}
				}

				$record->$method_name($value);
			}

			$record->store();
			$records[] = $record;
		}

		$completed_fixtures[$fixture_name] = fRecordSet::buildFromArray($class_name, $records);
	}

	/**
	 * Is the fixture white listed.
	 */
	private function isWhiteListed($fixture_name)
	{
		return empty($this->white_list) || in_array($fixture_name, $this->white_list);
	}

	/**
	 * Empty tables in the reverse order of their creation.
	 */
	public function tearDown()
	{

		if (is_array($this->tables_to_tear_down)) {
			$tables = array_reverse(array_unique($this->tables_to_tear_down));

			if (self::$verbose) {
				echo "tear down plan: " . join(', ', $tables) . "\n";
			}

			foreach ($tables as $fixture_name) {
				$this->db->execute("DELETE FROM $fixture_name");
			}
		}
	}

	/**
	 * Registers a hook callback. Global hooks are registered with the instance fixtures upon creation.
	 *
	 * @param $hook_name
	 *   The name of the hook.
	 * @param $fixture_name
	 *   The fixture for which to register the hook.
	 * @param $callback
	 *   The callback function
	 */
	public function registerHookCallback($hook_name, $fixture_name, $callback)
	{
		if (in_array($hook_name, array(self::PreSetBuildHook)) === FALSE) {
			throw new fValidationException('Invalid hook name, %s', $hook_name);
		}

		if (isset($this->hook_callbacks[$hook_name][$fixture_name]) === FALSE) {
			$this->hook_callbacks[$hook_name][$fixture_name] = array();
		}

		$this->hook_callbacks[$hook_name][$fixture_name][] = $callback;
	}

    private function applyHookCallbacks($hook_name, $fixture_name, $key, $value)
	{
		if (isset($this->hook_callbacks[$hook_name][$fixture_name]) === FALSE) {
			return $value;
		}

		$original_value = $value;

		foreach ($this->hook_callbacks[$hook_name][$fixture_name] as $callback) {
			$value = $callback($key, $value, $original_value);
		}

		return $value;
	}
	
	/**
	 * Is the key of fixture a relationship key or not
	 */
	private function isRelationshipKey($fixture_name, $key)
	{
		$many_to_one = $this->schema->getRelationships($fixture_name, 'many-to-one');

		foreach ($many_to_one as $relationship) {

			$foreign_table = $relationship['related_table'];

			if ($key === $relationship['column']) {
				return TRUE; //return $fixture_name !== $foreign_table;
			}

		}

		return FALSE;
	}

	/**
	 * Return related table based on key
	 */
	private function getRelatedTable($fixture_name, $foreign_key)
	{
		$many_to_one = $this->schema->getRelationships($fixture_name, 'many-to-one');

		foreach ($many_to_one as $relationship) {

			$key = $relationship['column'];
			$foreign_table = $relationship['related_table'];

			if ($foreign_key === $key) {
				return $foreign_table; //return $fixture_name === $foreign_table ? NULL : $foreign_table;
			}

		}

		return NULL;
	}
	
	/**
	 * Is the key of a fixture a required key or not.
	 */
	private function isRequiredKey($fixture_name, $key)
	{
		$column_info = $this->schema->getColumnInfo($fixture_name);
		return $column_info[$key]['not_null'] === TRUE;
	}
}