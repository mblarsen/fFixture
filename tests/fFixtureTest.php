<?php

/**
 * A set of unit tests testing the behaviour of fFixture in various situations.
 */
class fFxitureTest extends PHPUnit_Framework_TestCase
{
	static private $db;
	
	static public function setupBeforeClass()
	{
		self::$db = fORMDatabase::retrieve();
	}
	
	static public function tearDownAfterClass()
	{
		self::reset();
	}
	
	static private function reset()
	{
		self::$db->execute(RESET_DATABASE_SQL);
	}
	
	/**
	 * Test that create will fail in case that the datbase has not been set.
	 */
	public function testMissingDatabase()
	{
		$this->setExpectedException("fEnvironmentException", "Database not set");
		$fixture = fFixture::create(FIXTURES_ROOT);
	}
	
	/**
	 * Test that create will fail in case of corrupt JSON files.
	 */
	public function testBadFixtures()
	{
		$this->setExpectedException("fValidationException");
		fFixture::setDatabase(self::$db);
		$fixture = fFixture::create(FIXTURES_ROOT . "/bad/");
	}
	
	/**
	 * Test that every fixture is read - we are ready to build.
	 */
	public function testReadAll()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT);
	}

	/**
	 * Will test the build of simple one-to-many relationship.
	 */
	public function testSimpleBuild()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT . "/simple/");
		
		// includes: users.json and shops.json
				
		$fixture->build();
		
		$shop = new Shop(1);
		$user = new User(1);
		
		$this->assertTrue($shop->exists());
		$this->assertTrue($user->exists());

		$this->assertEquals(1, $shop->buildUsers()->count());
		$this->assertTrue($user->createShop()->exists());
		
		$this->assertEquals("Palle Pallesen", $user->getName());
	}
	
	/**
	 * Will test the build of the more comples many-to-many and the case where there is more than one relationships.
	 */
	public function testManyToMany()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT, array("categories_products"));
		
		// includes: categories.json, products.json, and categories_products.json
		
		$fixture->build();
	}
	
	/**
	 * Will test the build of the more comples many-to-many and the case where there is more than one relationships.
	 */
	public function testComplexBuild()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT);
		
		// includes: categories.json, products.json, users.json, shops.json and the join table categories_products.json
		
		$fixture->build();
		
		$shop = new Shop(1);
		$user = new User(1);
		$category = new Category(1);
		$product = new Product(1);
		
		$this->assertTrue($shop->exists());
		$this->assertTrue($user->exists());
		$this->assertTrue($category->exists());
		$this->assertTrue($product->exists());
		
		// Shop has manu users and products
		
		$this->assertEquals(1, $shop->buildUsers()->count());
		$this->assertEquals(1, $shop->buildProducts()->count());
		
		// User and product has a shop
		
		$this->assertTrue($user->createShop()->exists());
		$this->assertTrue($product->createShop()->exists());
		
		// Product and category has many of eachother
		
		$this->assertEquals(1, $product->buildCategories()->count());
		$this->assertEquals(1, $category->buildProducts()->count());
	}
	
	public function testTearDown()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT);
		$fixture->build();

		$this->assertEquals(1, self::$db->query("SELECT COUNT(*) FROM users")->fetchScalar());

		$fixture->tearDown();
		
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM users")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM categories")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM products")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM shops")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM categories_products")->fetchScalar());
	}
	
	/**
	 * Test that only specified fixtures are build.
	 */
	public function testPartial()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT, array("users"));
		$fixture->build();

		// Only users and shops by dependency should be build
		
		$this->assertEquals(1, self::$db->query("SELECT COUNT(*) FROM users")->fetchScalar());
		$this->assertEquals(1, self::$db->query("SELECT COUNT(*) FROM shops")->fetchScalar());
		
		// The rest should be empty
		
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM categories")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM products")->fetchScalar());
		$this->assertEquals(0, self::$db->query("SELECT COUNT(*) FROM categories_products")->fetchScalar());
	}
	
	/**
	 * Test that a subset of fixtures is used in stead of those in root. Root is used as fallback.
	 */
	public function testSubset()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT, array("users"), FIXTURES_ROOT . "/subset");
		
		// includes: subset/users.json and shops.json
		
		$fixture->build();
		
		$shop = new Shop(1);
		$user = new User(1);
		
		$this->assertTrue($shop->exists());
		$this->assertTrue($user->exists());

		$this->assertEquals(1, $shop->buildUsers()->count());
		$this->assertTrue($user->createShop()->exists());
		
		// The user should be Lars Larsen and not Palle Pallesen
		
		$this->assertEquals("Lars Larsen", $user->getName());
	}
	
	public function testHookCallback()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		$fixture = fFixture::create(FIXTURES_ROOT, array("products"));
		
		// This callback will change the price property of products to 11.95. The fixture is 9.95
		
		$fixture->registerHookCallback(fFixture::PreSetBuildHook, "products", function($key, $value, $original_value) {
			if ('price' === $key) {
				return 11.95;
			}
			return $value;
		});
			
		$fixture->build();
		
		$product = new Product(1);
		
		$this->assertEquals(11.95, $product->getPrice());
	}

	public function testGlobalHookCallback()
	{
		self::reset();
		fFixture::setDatabase(fORMDatabase::retrieve());
		
		// This callback will change the price property of products to 11.95. The fixture is 9.95
		
		fFixture::registerGlobalHookCallback(fFixture::PreSetBuildHook, "products", function($key, $value, $original_value) {
			if ('price' === $key) {
				return 11.95;
			}
			return $value;
		});
			
		$fixture = fFixture::create(FIXTURES_ROOT, array("products"));
		$fixture->build();
		
		$product = new Product(1);
		
		$this->assertEquals(11.95, $product->getPrice());
	}
}
