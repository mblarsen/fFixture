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

	// ----------------------------- Instance code -----------------------------

	private $db;
	private $schema;

	private $root;
	private $replacements_root;

	private $fixture_sources;
	private $fixture_data;

	private $white_list;
	private $hook_callbacks;

	private $tables_to_tear_down;

	/**
	 * Create a new instance. Use fFixture::create()
	 */
	public function __construct($root, $db, $white_list = NULL, $replacements_root = NULL)
	{
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
		$this->white_list = $white_list;

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
	 * Validates a fixture data set.
	 *
	 * @param $fixture_name
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

			foreach ($schema->getKeys($fixture_name, 'foreign') as $key_data) {
				if ($column_info[$key_data['column']]['not_null'] === TRUE) {
					$dependencies[] = $key_data['foreign_table'];
				}
			}

		} else {

			// Many-to-one

			foreach ($many_to_one as $relationship) {

				$key = $relationship['column'];
				$foreign_table = $relationship['related_table'];

				if ($fixture_name === $foreign_table) {
					continue;
				}

				if (isset($this->fixture_data[$foreign_table]) === FALSE) {
					throw new fValidationException('Unable to create fixture for, %1$s, due to missing dependency %2$s',
						$fixture_name,
						$foreign_table
					);
				}

				$required = $column_info[$key]['not_null'] === TRUE;

				$records = $this->fixture_data[$fixture_name];

				foreach ($records as $record) {

					if ($required && isset($record->$key) === FALSE) {
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
	 * @param $fixture_name
	 *   Name of the fixture to load
	 */
	private function loadFixture(/* string */ $fixture_name)
	{
		if (isset($this->fixture_data[$fixture_name])) {
			return $this->fixture_data[$fixture_name];
		}

		if (isset($this->fixture_sources[$fixture_name]) === FALSE) {
			throw new fValidationException("There exists no fixtures for, %s", $fixture_file);
		}

		$fixture_source = $this->fixture_sources[$fixture_name];

		// Helper vars

		$now = date('Y:m:i H:i:s', time());

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
				if (empty($this->white_list) || in_array($fixture_name, $this->white_list)) {
					$queue = array_merge($queue, $this->buildQueue($fixture_name));
				}
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

			foreach ($schema->getKeys($fixture_name, 'foreign') as $key_data) {
				if ($column_info[$key_data['column']]['not_null'] === TRUE) {
					$dependencies[] = $key_data['foreign_table'];
				}
			}

		} else {

			// Many-to-one

			foreach ($many_to_one as $relationship) {

				$key = $relationship['column'];
				$foreign_table = $relationship['related_table'];

				if ($fixture_name === $foreign_table) {
					continue;
				}

				if ($column_info[$key]['not_null'] === TRUE) {
					$dependencies[] = $foreign_table;
				}
			}
		}

		foreach ($dependencies as $foreign_table) {

			if (isset($this->fixture_sources[$foreign_table]) === FALSE) {
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

				if (array_key_exists($fixture_name, $completed_fixtures)) {
					continue;
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

				try {

					$records = array();

					foreach ($this->fixture_data[$fixture_name] as $record_data) {
						$record = new $class_name();
						foreach ($record_data as $key => $value) {
							$method_name = 'set' . fGrammar::camelize($key, $upper = TRUE);
							$value = $this->applyHookCallbacks(self::PreSetBuildHook, $fixture_name, $key, $value);
							$record->$method_name($value);
						}

						$record->store();
						$records[] = $record;
					}

					$completed_fixtures[$fixture_name] = fRecordSet::buildFromArray($class_name, $records);

				} catch (Exception $e) {
					//+ Fixgure out the right exceptions to catch and how to attach the relevant data before throwing again.
					echo "$class_name->$method_name($value)\n";
					throw $e;
				}
			}

			return $completed_fixtures;

		} catch (Exception $e) {

			// Tear down tables in case of failure

			$this->tearDown();
			throw $e;

		}

	}

	public function addSeed(fFixtureSeed $seed)
	{
		// TODO sort out dependencies
	}

	private function getSeedTableNames()
	{
		foreach ($this->seeds as $seed) {
			# code...
		}
	}

	/**
	 * Empty tables in the reverse order of their creation.
	 */
	public function tearDown()
	{
		if (is_array($this->tables_to_tear_down)) {
			$tables = array_reverse($this->tables_to_tear_down);
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
}