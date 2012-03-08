<?php

/**
* A set of unit tests testing the behaviour of fFixtureSeed in various situations.
*/
class fFixtureSeedTest extends PHPUnit_Framework_TestCase
{
	private $db;
	
	public function setup()
	{
		$this->db = fORMDatabase::retrieve();
	}
	
	/**
	 * Validates a simple specification.
	 */
	public function testSimpleSpec()
	{
		$seed = new fFixtureSeed($this->db, 'products');
			
		$seed->catalog('movie')
			->version('DVD', 'Blu-Ray')
			->name('Jimmy', 'Alen', 'Judy')->random()
			->shopId(1);
			
		$specs = $seed->specs();
			
		$this->assertTrue(isset($specs['catalog']));
		$this->assertEquals('movie', $specs['catalog']['value']);
		$this->assertEquals('catalog', $specs['catalog']['property']);
	
		$this->assertTrue(isset($specs['version']));
		$this->assertEquals(array('DVD', 'Blu-Ray'), $specs['version']['value']);
		$this->assertEquals(FALSE, $specs['version']['random']);
		$this->assertEquals('version', $specs['version']['property']);
			
		$this->assertTrue(isset($specs['name']));
		$this->assertEquals(array('Jimmy', 'Alen', 'Judy'), $specs['name']['value']);
		$this->assertEquals(TRUE, $specs['name']['random']);
		$this->assertEquals('name', $specs['name']['property']);
			
	}
	
	/**
	 * Test that an exception is thrown when trying to access unknown property.
	 */
	public function testInvalidProperty()
	{
		$this->setExpectedException('fValidationException', 'Unknown property name, foo');
		$seed = new fFixtureSeed($this->db, 'products');
		$seed->foo("bar");
	}
	
	/**
	 * Tests min, max, and random modifiers.
	 */
	public function testModifiers()
	{
		$seed = new fFixtureSeed($this->db, 'products');

		$seed->price()->max(30);
		$this->assertEquals(array('price' => array('property' => 'price', 'max' => 30)), $seed->specs());
		$seed->price()->max(30)->random();		
		$this->assertEquals(array('price' => array('property' => 'price', 'max' => 30, 'random' => TRUE)), $seed->specs());
		$seed->price()->max(30)->random()->min(10);		
		$this->assertEquals(array('price' => array('property' => 'price', 'max' => 30, 'random' => TRUE, 'min' => 10)), $seed->specs());
	}
	
	/**
	 * Tests that exception is thrown if an assignment to a property was begun, but never completed.
	 */
	public function testMissingValue()
	{
		$this->setExpectedException('fProgrammerException', 'Property assignment to, price, was begun but never finished');
		$seed = new fFixtureSeed($this->db, 'products');
		$seed->price()->child('having a child before defining value');
	}
	
	/**
	 * Tests basic usage of up().
	 */
	public function testChildUp()
	{
		$seed = new fFixtureSeed($this->db, 'products');
			
		$child = $seed->child('offerings');
			
		// Assert that the child is a fFixtureSeed
			
		$this->assertTrue($child instanceof fFixtureSeed);
			
		// Assert that the main seed is returned with up()
			
		$this->assertEquals($seed, $child->up());
			
		// Assert that the same child is returned when using child() method with same fixture name
			
		$this->assertEquals($child, $seed->child('offerings'));
			
		// Assert that up() jumps from 2nd level child back to the main seed;
			
		$this->assertEquals($seed, $child->child('price_tiers')->up('products'));
	}
	
	/**
	 * Test the correct include, exclude behaviour - as well as the defaults.
	 */
	public function testBuildQueue()
	{
		$seed = new fFixtureSeed($this->db, 'products');
		
		// Price tiers are left out since offering child has the default include none.
		
		$seed->includeAll()->child('offerings');
		$this->assertEquals(array('shops', 'products', 'offerings', 'product_descriptions'), $seed->buildQueue());
			
		// This time price tiers are included.
		
		$seed->includeAll()->child('offerings')->includeAll();
		$this->assertEquals(array('shops', 'products', 'offerings', 'price_tiers', 'product_descriptions'), $seed->buildQueue());
		
		// In this case only products offerings and offerings to-many relations are included.
		
		$seed->includeRelation(array('offerings'))->child('offerings')->includeAll();
		$this->assertEquals(array('shops', 'products', 'offerings', 'price_tiers'), $seed->buildQueue());
	}
	
	/**
	 * Test the correct include, exclude behaviour when using up().
	 */
	public function testBuildQueueMultiLevel()
	{
		$seed = new fFixtureSeed($this->db, 'products', 100);
		
		$seed->catalog('movie')
			->child('offerings', 2)
				->child('price_tiers', 3)
					->up('products')
			->child('product_descriptions', 2);
				
		$this->assertEquals(array('shops', 'products', 'offerings', 'price_tiers', 'product_descriptions'), $seed->buildQueue());
	}
	
	/**
	 * Testing a complex example
	 */
	public function testComplex()
	{
		$seed = new fFixtureSeed($this->db, 'products', 100);
		
		$seed->catalog('movie')
			->version('DVD', 'Blu-Ray')
			->shopId(1)
			->name(function ($seed, $number) { return 'Cool Product #' . $number; })
			->child('offerings', 2)->price(49.95, 69.95, 79.95, 119.95, 249.95)->random()
				->version(function ($seed, $num) { return $seed->up('product')->object()->getVersion(); })
				->child('price_tiers', 3)
					->minUnits(3,5,10)
					->price(function ($seed, $num) {})
					->up('products')
			->child('product_descriptions', 2)
		    	->description('Lorem ipsum dolor sit amet');
		
		$this->assertEquals(array('shops', 'products', 'offerings', 'price_tiers', 'product_descriptions'), $seed->buildQueue());
	}
}