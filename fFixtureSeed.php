<?php

/**
* Provides a way to spicify and build fixtures with both simple and complex structures and values. One or more fFixtureSeed object can be used with
* an instance of fFixture to create rich data for unit tests. fFixtureSeed objects will are used by the fFixture instance in a similar way to the
* tables that are white listed. This means, that when using seeds only the seeds and the dependent classes, as needed, are instansiated.
*
* The fFixtureSeed is build for chaining:
*
* <code>
* 	$seed = new fFixtureSeed('products', 10);
*   $seed->type('movie')
*        ->genre('Thriller', 'Drama', 'Sci-Fi', 'Romance')->random()
*        ->rating(1,2,3,4,5)-random()
*        ->child('product_descriptions', 2)
*            ->description('Lorem ipsum')
*            ->locale('da', 'se')
*            ->up('products')
*        ->includeAll();
*
*  $fixture->addSeed($seed);
*  $fixture->build();
* </code>
*
* This example will create 10 movies with a random genre, a random rating. For each movie two description will be created with
* locale `da` and `se` respectfully. Lastly the include all flag is raised meaning, that for every one-to-many relationship of 'products'
* one record of each will be build recursivly.
*
* When invoking child() a sub-seed is automatically created.
*
* Values can be functions with this signature:
*
* <code>
*   function ($seed, number) { }
* </code>
*
*/
class fFixtureSeed
{
	private $db;
	
	private $schema;
	
	private $column_info;
	
	private $table_name;
	
	private $number;
	
	private $children;
	
	private $include_all;
	
	private $include_relations;
	
	private $exclude_relations;
	
	private $parent;
	
	private $specification;
	
	private $object;
	
	private $last_property;
	
	private $open_value;
	
	/**
	 * Constructs a new seed that will produce $number records of the class identified by $table_name.
	 *
	 * The number of records created dependes of the level of the seed. A seed having a number of 3 with a parent having 2 will in total
	 * produce 6 records of $table_name.
	 *
	 * @param $db
	 *  The database to retrieve the schema information from.
	 * @param $table_name
	 *  The table_name to use as the base for the seed.
	 * @param $number
	 *  The number of records to produce.
	 * @param $parent
	 *  A parent seed.
	 */
	function __construct($db, $table_name, $number = 1, $parent = NULL)
	{
		$this->db                = $db;
		$this->schema            = new fSchema($db);
		$this->children          = array();
		$this->table_name        = $table_name;
		$this->exclude_relations = array();
		$this->include_all       = FALSE;
		$this->include_relations = array();
		$this->number            = $number;
		$this->parent            = $parent;
		$this->specifications    = array();
		$this->last_property     = NULL;
		$this->open_value        = FALSE;
	}
	
	/**
	 * Magic function the looks up all property and modifier assignments.
	 */
	public function __call($function, $args)
	{
		if ($this->hasProperty($function) === FALSE) {
			throw new fValidationException("Unknown property name, %s", $function);
		}
		
		$specs = array(
			'property' => $function
		);
		
		if (empty($args)) {
			$this->open_value = TRUE;
		} else if ($args[0] instanceof Closure) {
			$specs['function'] = $args[0];
		} else if (count($args) === 1) {
			$specs['value'] = $args[0];
		} else {
			$specs['value'] = $args;
		}

		$this->specifications[$specs['property']] = $specs;
		
		$this->last_property = $specs['property'];
		
		return $this;
	}
	
	/**
	 * A modifier that raises the random flag. Random can be used on array values or together with min and max.
	 */
	public function random()
	{
		$specs = $this->specifications[$this->last_property];
		
		if (isset($specs)) {
			
			if (isset($specs['function']) || FALSE === (is_array($specs['value']) || isset($specs['min']) || isset($specs['max']))) {
				throw new fProgrammerException("Random function can only be applied to properties with array values or intervals");
			}
			
			$this->specifications[$this->last_property]['random'] = TRUE;
		}
		
		return $this;
	}
	
	public function min($value)
	{
		$this->specifications[$this->last_property]['min'] = $value;
		unset($this->specifications[$this->last_property]['value']);
		
		$this->open_value = FALSE;
		return $this;
	}
	
	public function max($value)
	{
		$this->specifications[$this->last_property]['max'] = $value;
		unset($this->specifications[$this->last_property]['value']);
		
		$this->open_value = FALSE;
		return $this;
	}
	
	public function value($value)
	{
		if ($value instanceof Closure) {
			$this->specifications[$this->last_property]['function'] = $value;
		} else {
			$this->specifications[$this->last_property]['value'] = $value;
		}
		
		unset($this->specifications[$this->last_property]['random']);
		unset($this->specifications[$this->last_property]['min']);
		unset($this->specifications[$this->last_property]['max']);
				
		$this->open_value = FALSE;
		return $this;
	}
	
	public function build($object)
	{
	}
	
	public function getTableName()
	{
		return $this->table_name;
	}
		
	public function child($table_name, $number = 1, $route = NULL)
	{
		$this->tossIfOpenValue();
		
		// TODO check if the record exists following $route if not throw exception
		
		foreach ($this->children as $seed) {
			if ($seed->is($table_name)) {
				return $seed;
			}
		}
		
		$child = new fFixtureSeed($this->db, $table_name, $number, $this);
		
		$this->children[] = $child;
		
		if ($this->include_all !== TRUE) {
			$this->include_relations[] = $table_name;
		}
		
		return $child;
	}
	
	public function is($table_name)
	{
		return $this->table_name === $table_name;
	}
	
	public function up($parent_name = NULL)
	{
		$this->tossIfOpenValue();
		
		if ($this->parent === NULL) {
			// throw exception
		}

		if (is_null($parent_name) || $this->parent->is($parent_name)) {
			return $this->parent;
		}
		
		$pointer = $this->parent;
		
		while ($pointer->parent !== NULL) {
			
			if ($pointer->parent->is($parent_name)) {
				return $pointer->parent;
			}
			
			$pointer = $pointer->parent;
		}
		
		// throw exception
	}
	
	public function specs()
	{
		return $this->specifications;
	}
	
	public function object()
	{
		return $this->object;
	}
	
	public function includeAll()
	{
		$this->tossIfOpenValue();
		
		$this->include_all = TRUE;
		$this->include_relations = array();
		$this->exclude_relations = array();
		return $this;
	}
	
	public function shouldIncludeAll()
	{
		return $this->include_all;
	}
	
	public function includeNone()
	{
		$this->tossIfOpenValue();
		
		$this->include_all = FALSE;
		$this->include_relations = array();
		$this->exclude_relations = array();
		return $this;
	}
	
	public function shouldIncludeNone()
	{
		return $this->include_all && empty($this->include_relations) && empty($this->exclude_relations);
	}
	
	public function includeRelation($relation)
	{
		$this->tossIfOpenValue();
		
		// TODO check if relation exists
		
		if (is_array($relation)) {
			$this->include_relations = $relation;
		} else {
			$this->include_relations[] = $relation;
		}
		
		$this->exclude_relations = array();
		$this->include_all = FALSE;
		return $this;
	}
	
	public function excludeRelation($relation)
	{
		$this->tossIfOpenValue();
		
		// TODO check if relation exists
		
		if (is_array($relation)) {
			$this->exclude_relations = $relation;
		} else {
			$this->exclude_relations[] = $relation;
		}
		$this->include_relations = array();
		$this->include_all = FALSE;
		return $this;
	}
	
	/**
	 * Creates a build queue for the seed.
	 */
	public function buildQueue($table_name = NULL, $depedencies_only = FALSE, $seed = NULL)
	{
		// echo ">> $table_name of seed " . (isset($seed) ? $seed->table_name : 'none') . " ($depedencies_only)\n";
		
		$this->tossIfOpenValue();
		
		if (is_null($table_name)) {
			$table_name = $this->table_name;
		}
		
		if (is_null($seed)) {
			$seed = $this;
		}
		
		$column_info = $this->schema->getColumnInfo($table_name);
		
		$queue = array();
		
		// many-to-one (dependencies)
		
		foreach ($this->schema->getRelationships($table_name, 'many-to-one') as $relationship) {
				
			$key = $relationship['column'];
			$foreign_table = $relationship['related_table'];
						
			if ($table_name === $foreign_table) {
				continue;
			}
			
			if ($column_info[$key]['not_null'] === TRUE) {
				// echo "Going up $foreign_table ^ from $table_name\n";
				$old_seed = $seed;
				$queue = array_merge($queue, $this->buildQueue($foreign_table, TRUE));
				$seed = $old_seed;
			}
		}
		
		$queue[] = $table_name;
		
		// one-to-many (children)
		
		if ($depedencies_only === FALSE) {
			
			$one_to_many = $this->schema->getRelationships($table_name, 'one-to-many');
			$i = 1;
			foreach ($one_to_many as $relationship) {
				
				$foreign_table = $relationship['related_table'];
			
				if ($table_name === $foreign_table) {
					continue;
				}
				
				$child_seed = $this->childWithName($foreign_table);
				
				if ($seed->shouldInclude($foreign_table) !== TRUE && is_null($child_seed)) {
					// echo "skipping $foreign_table says $table_name, $this->table_name or $seed->table_name\n";
					
					continue;
				}
												
				// Build partial queue going both up and down 
				// echo "Going down $foreign_table v from $table_name\n";
				
				$queue = array_merge($queue, $this->buildQueue($foreign_table, FALSE, $child_seed));
				
			}
						
		}
		
		// Making unique to remove duplicate dependiences. array_values() is used to reindex the queue.
		
		$queue = array_values(array_unique($queue));
		
		return $queue;
	}
	
	private function tossIfOpenValue()
	{
		if ($this->open_value === TRUE) {
			throw new fProgrammerException("Property assignment to, %s, was begun but never finished", $this->last_property);
		}
	}
	
	/**
	 * Determins whether a table should be included or not.
	 */
	private function shouldInclude($table_name)
	{
		// var_dump(__FUNCTION__, $this->table_name, $table_name, "all?", $this->include_all, "incl", $this->include_relations, "excl", $this->exclude_relations);
		
		// If the 'include all' flag is set
		
		if ($this->include_all === TRUE) {
				
			return TRUE;
				
		}
		
		// If a white list of relations to include is used
		
		if (empty($this->include_relations) !== TRUE) {
			
			return in_array($table_name, $this->include_relations);
			
		}

		// If a black list of relations to exclude is used
				
		if (empty($this->exclude_relations) !== TRUE) {
						
			return in_array($table_name, $this->exclude_relations) === FALSE;
			
		}
		
		return FALSE;
		
	}
	
	/**
	 * Returns are child seed with a specific name.
	 */
	private function childWithName($table_name)
	{
		foreach ($this->children as $seed) {
			if ($seed->is($table_name)) {
				return $seed;
			}
		}
		
		return NULL;
	}
	
	private function hasProperty($property_name)
	{
		if (is_null($this->column_info)) {
			$this->column_info = $this->schema->getColumnInfo($this->table_name);
		}
		
		return isset($this->column_info[fGrammar::underscorize($property_name)]);
	}
}

