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
 * Note: This class does not handle multiplie cases where you have records split over several database. It will either use the default ORM database or the
 * one you supply using setDatabase().
 *
 * Note: Only table dependency is supported and only at one level.
 *
 * Future:
 *  - Implement auto-fill mode (missing properties, dependencies, etc.)
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
	 *   An array of fixture table names to include when building. NULL or empty means that every fixture found will be build.
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

		$fixture->load();

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
	 * @param $table_name
	 *   The table for which to register the hook.
	 * @param $callback
	 *   The callback function
	 */
	static public function registerGlobalHookCallback($hook_name, $table_name, $callback)
	{
		if (isset(self::$glabal_hook_callbacks) === FALSE) {
			self::$glabal_hook_callbacks = array(
				self::PreSetBuildHook => array()
			);
		}
		
		if (in_array($hook_name, array(self::PreSetBuildHook)) === FALSE) {
			throw new fValidationException('Invalid hook name, %s', $hook_name);
		}
		
		if (isset(self::$glabal_hook_callbacks[$hook_name][$table_name]) === FALSE) {
			self::$glabal_hook_callbacks[$hook_name][$table_name] = array();
		}
		
		self::$glabal_hook_callbacks[$hook_name][$table_name][] = $callback;
	}
	
	// ----------------------------- Instance code -----------------------------

	private $root;
    
	private $replacements_root;
    
	private $db;
    
	private $schema;
    
	private $data;
        
	private $white_list;
    
	private $tables_to_tear_down;
	
	private $hook_callbacks;
    
	/**
	 * Create a new instance
	 */
	public function __construct($root, $db, $white_list = NULL, $replacements_root = NULL)
	{
		if ($root instanceof fDirectory) {
			$this->root = $root;
		} else {
			$this->root = new fDirectory($root);
		}

		if ($replacements_root) {
			if ($root instanceof fDirectory) {
				$this->replacements_root = $replacements_root;
			} else {
				$this->replacements_root = new fDirectory($replacements_root);
			}
		}
                
		$this->db = $db;
		$this->schema = new fSchema($db);
		$this->data = array();
		$this->white_list = $white_list;
		
		if (isset(self::$glabal_hook_callbacks)) {
			$this->hook_callbacks  = self::$glabal_hook_callbacks;
		} else {
			$this->hook_callbacks[self::PreSetBuildHook] = array();
		}
	}
    
    /**
     * Scan for json files and prepare them for record building.
     *
     * If a root of replacement fixtures is specified these will have priority to files found in the root directory.
     *
     * All files are read and parsed, even though not all are used for record building.
     */
    public function load()
    {
        // If replacements has been specified use those
        
        if ($this->replacements_root) {
            $replacements_fixture_files = $this->replacements_root->scan('*.json');

            if (empty($replacements_fixture_files)) {
    			throw new fEnvironmentException(
    				'The replacements root specified, %s, does not contain any fixtures',
    				$this->replacements_root
    			);
            }

            foreach ($replacements_fixture_files as $fixture_file) {
                $fixture_name = $fixture_file->getName(TRUE);
                ob_start();
                include $fixture_file->getPath();
                $json_data = ob_get_clean();
                $fixture_data = fJSON::decode($json_data);
                if (empty($fixture_data)) {
                    throw new fValidationException("Invalid fixture file, %s", $fixture_file->getPath());
                }
                $this->data[$fixture_name] = $fixture_data;
            }
        }

        // Scan root for fixtures
        
        $fixture_files = $this->root->scan('*.json');

        if (empty($fixture_files)) {
			throw new fEnvironmentException(
				'The root specified, %s, does not contain any fixtures',
				$this->root
			);
        }
        
		foreach ($fixture_files as $fixture_file) {
			$fixture_name = $fixture_file->getName(TRUE);
            
			// Skip if replacement was added
            
			if (isset($this->data[$fixture_name])) {
				continue;
			} 
            
			ob_start();
			include $fixture_file->getPath();
			$json_data = ob_get_clean();
			$fixture_data = fJSON::decode($json_data);
			if (empty($fixture_data)) {
				throw new fValidationException("Invalid fixture file, %s", $fixture_file->getPath());
			}
			$this->data[$fixture_name] = $fixture_data;
		}
    }
    
    /**
     * Build a queue that is ordered according to dependencies.
     * 
     * @param $tables_name
     *   Table to build queue for.
     * @param $records
     *   Records for that table.
     * @return
     *   Returns an array of table names with dependencies first and table in question last.
     */
    private function buildQueue($table_name, $records)
    {
		$schema = $this->schema;
        
		$column_info  = $schema->getColumnInfo($table_name);
        $many_to_one  = $schema->getRelationships($table_name, 'many-to-one');
		$number_of_relationships = array_reduce($schema->getRelationships($table_name), function($number, $relationships) { return $number + count($relationships); }, 0);
				
		$dependencies = array();

		if ($number_of_relationships === 0) {
						
			// Join-table like tables
			
			foreach ($schema->getKeys($table_name, 'foreign') as $key_data) {
				if ($column_info[$key_data['column']]['not_null'] === TRUE) {
					$dependencies[] = $key_data['foreign_table'];
				}
			}
			
		} else {
			
			// Many-to-one
			
			foreach ($many_to_one as $relationship) {
			
				$key = $relationship['column'];
				$foreign_table = $relationship['related_table'];
			
				if ($table_name === $foreign_table) {
					continue;
				}
			
				$required = $column_info[$key]['not_null'] === TRUE;
			
				foreach ($records as $record) {
				
					if ($required && isset($record->$key) === FALSE) {
						throw new fProgrammerException('Invalid fixture, %1$s, record requires foreign key %2$s',
						$table_name,
						$key);
					}
				
					if (isset($record->$key)) {
						$dependencies[] = $foreign_table;
						break;
					}
				}
			}
		}

		$queue = array();
        
		foreach ($dependencies as $foreign_table) {
            
			if (isset($this->data[$foreign_table]) === FALSE) {
				throw new fProgrammerException('Unable to create fixture for, %1$s, due to missing data for dependency %2$s',
					$table_name,
					$foreign_table
				);
			}
            
			$queue = array_merge($queue, $this->buildQueue($foreign_table, $this->data[$foreign_table]));
		}
        
		$queue[] = $table_name;
        
		return array_unique($queue);
    }
        
    /**
     * Build the records in order of dependency.
     *
     * @throws fProgrammerException if table does not exists
     * @throws fValidationException if keys are missing or if non-existing properties is specified
     */
    public function build()
    {
        try {
            
			// Build queue
        
			$build_queue = array();
			
			// $seed_table_names = 
			
			foreach ($this->data as $table_name => $records) {
				if (empty($this->white_list) || (in_array($table_name, $this->white_list))) {
					$build_queue = array_merge($build_queue, $this->buildQueue($table_name, $records));
				} 
			}
			
			// if (empty($this->seeds) === FALSE) {
			// 	// Adding seeds corresponds to setting fixtures tables
			// }

			$build_queue = array_unique($build_queue);
			$this->tables_to_tear_down = $build_queue;
        
			// Build records
        
			$tables_completed = array();
			foreach ($build_queue as $table_name) {
            
				if (in_array($table_name, $tables_completed)) {
					continue;
				}
                
				$class_name = fORM::classize($table_name);

				// If the class does not exists created it
                
				if (class_exists($class_name) === FALSE) {
					fORM::defineActiveRecordClass($class_name);
				}
                
				// Create the records
                
				$method_name = NULL;

				try {

					foreach ($this->data[$table_name] as $record_data) {
						$record = new $class_name();
						foreach ($record_data as $key => $value) {
							$method_name = 'set' . fGrammar::camelize($key, $upper = TRUE);
							$value = $this->applyHookCallbacks(self::PreSetBuildHook, $table_name, $key, $value);
							$record->$method_name($value);
						}
                
						$record->store();
					}

				} catch (Exception $e) {
					echo "$class_name->$method_name($value)\n";
					throw $e;
				}
            
				$tables_completed[] = $table_name;
			}
        
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
			foreach ($tables as $table_name) {
				$this->db->execute("DELETE FROM $table_name");
			}
		}
	}
	
	/**
	 * Registers a hook callback. Global hooks are registered with the instance fixtures upon creation.
	 *
	 * @param $hook_name
	 *   The name of the hook.
	 * @param $table_name
	 *   The table for which to register the hook.
	 * @param $callback
	 *   The callback function
	 */
	public function registerHookCallback($hook_name, $table_name, $callback)
	{
		if (in_array($hook_name, array(self::PreSetBuildHook)) === FALSE) {
			throw new fValidationException('Invalid hook name, %s', $hook_name);
		}
		
		if (isset($this->hook_callbacks[$hook_name][$table_name]) === FALSE) {
			$this->hook_callbacks[$hook_name][$table_name] = array();
		}
		
		$this->hook_callbacks[$hook_name][$table_name][] = $callback;
	}
	
    private function applyHookCallbacks($hook_name, $table_name, $key, $value)
	{
		if (isset($this->hook_callbacks[$hook_name][$table_name]) === FALSE) {
			return $value;
		}
		
		$original_value = $value;
		
		foreach ($this->hook_callbacks[$hook_name][$table_name] as $callback) {
			$value = $callback($key, $value, $original_value);
		}
				
		return $value;
	}
}