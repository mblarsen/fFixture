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

    static private $database;
    
    /**
     * Will return a new fFixture instance after parsing the json files found in either $root or $replacements_root.
     *
     * Only fixtures in $fixture_tables and their dependencies will be created. Leave $fixture_tables empty to build everything.
     *
     * Replacements root is useful in cases where you need very specific records. You could include these fixtures with the
     * unit tests.
     *
     * @param $root
     *   The root of the fixture directory.
     * @param $fixture_tables
     *   An array of fixture table names to include when building. NULL or empty means that every fixture found will be build.
     * @param $replacements_root
     *   
     */
    static public function create($root, $fixture_tables = NULL, $replacements_root = NULL)
    {
        $db = self::$database;
        
        if (is_null($db)) {
            throw new fEnvironmentException('Database not set');
        }
                
        $fixture = new self($root, $db, $fixture_tables, $replacements_root);
        
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
    
    private $root;
    
    private $replacements_root;
    
    private $db;
    
    private $schema;
    
    private $data;
        
    private $fixture_tables;
    
    private $tables_to_tear_down;
    
    /**
     * Will create a new instance
     */
    public function __construct($root, $db, $fixture_tables = NULL, $replacements_root = NULL)
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
        $this->fixture_tables = $fixture_tables;
    }
    
    /**
     * Will scan for json files and prepare them for record building.
     *
     * If a root of replacement fixtures is specified these will have priority to files found in the root directory.
     *
     * All files are read and parsed, eventhough not all are used for record building.
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
     * Will build a queue that is order according to dependencies.
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
        
        $column_info = $schema->getColumnInfo($table_name);
            
        $foreign_keys = $schema->getKeys($table_name, 'foreign');
                    
        $null_allowed_foreign_keys = array();
        $not_null_foreign_keys = array();
        $foreign_key_columns = array();
        $foreign_key_tables = array();
        foreach ($foreign_keys as $key_data) {
            $key_column = $key_data['column'];
            $foreign_key_tables[$key_column] = $key_data['foreign_table'];
            if ($column_info[$key_column]['not_null'] === FALSE) {
                $null_allowed_foreign_keys[] = $key_column;
            } else {
                $not_null_foreign_keys[] = $key_column;
            }
        }
        
        $dependencies = array();
         
        // Enqueue tables of fks that have optional dependency

        foreach ($null_allowed_foreign_keys as $key) {
            foreach ($records as $record) {
                if (property_exists($record, $key)) {
                    $dependencies[] = $foreign_key_tables[$key];
                    break;
                }
            }
        }
            
        // Enqueue tables of fks with mandetory dependecy
             
        foreach ($not_null_foreign_keys as $key) {
            foreach ($records as $record) {
                if (property_exists($record, $key) === FALSE) {
                    throw new fProgrammerException('Invalid fixture, %1$s, record requires foreign key %2$s',
                        $table_name,
                        $key);
                }
                
                $dependencies[] = $foreign_key_tables[$key];
                break;
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
     * Will build the records in order of dependency.
     *
     * @throws fProgrammerException if table does not exists
     * @throws fValidationException if keys are missing or if non-existing properties is specified
     */
    public function build()
    {
        try {
            
            // Build queue
        
            $build_queue = array();
         
            foreach ($this->data as $table_name => $records) {
                if (empty($this->fixture_tables) || (in_array($table_name, $this->fixture_tables))) {
                    $build_queue = array_merge($build_queue, $this->buildQueue($table_name, $records));
                } 
            }

            $build_queue = array_unique($build_queue);
            $this->tables_to_tear_down = $build_queue;
        
            // Build records
        
            $tables_completed = array();
            foreach ($build_queue as $table_name) {
            
                if (in_array($table_name, $tables_completed)) {
                    continue;
                }
                
                $class_name = fORM::classize($table_name);

                // If join table define class to reuse creation code belo0w
                
                if ($this->isJoin($table_name)) {
                    fORM::defineActiveRecordClass($class_name);
                }
                
                // Create the records
                
                $method_name = NULL;

                try {

                    foreach ($this->data[$table_name] as $record_data) {
                        $record = new $class_name();
                        foreach ($record_data as $key => $value) {
                            $method_name = 'set' . fGrammar::camelize($key, $upper = TRUE);
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
    
    /**
     * Will empty tables in the reverse order of their creation.
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
     * A bit silly method for identifying join tables
     *
     * TODO find replacement
     */
    private function isJoin($table_name)
    {
        $num_relationships = array_reduce($this->schema->getRelationships($table_name), function ($num, $relation_ship) use ($table_name) { return $num + count($relation_ship); }, 0);
        $foreign_keys = $this->schema->getKeys($table_name, 'foreign');
                
        return count($foreign_keys) === 2 && $num_relationships === 0;
    }        
}