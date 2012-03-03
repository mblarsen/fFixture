DROP TABLE IF EXISTS shops;
CREATE TABLE shops (
	shop_id  INTEGER PRIMARY KEY,
	name     TEXT
);

DROP TABLE IF EXISTS users;
CREATE TABLE users (
	user_id INTEGER PRIMARY KEY,
	shop_id INTEGER NOT NULL,
	name    TEXT,
	last_logged_in DATE,
	FOREIGN KEY(shop_id) REFERENCES shops(shop_id)
);

DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
	category_id INTEGER PRIMARY KEY,
	parent_id   INTEGER,
	name        TEXT,
	ordinal     INTEGER,
	FOREIGN KEY(parent_id) REFERENCES categories(category_id)
);

DROP TABLE IF EXISTS products;
CREATE TABLE products (
	product_id  INTEGER PRIMARY KEY,
	shop_id     INTEGER NOT NULL,
	name        TEXT,
	version     TEXT,
	catalog     TEXT,
	price       REAL,
	FOREIGN KEY(shop_id) REFERENCES shops(shop_id)
);

DROP TABLE IF EXISTS product_descriptions;
CREATE TABLE product_descriptions (
	product_description_id INTEGER PRIMARY KEY,
	product_id  INTEGER NOT NULL,
	description TEXT,
	locale      TEXT,
	FOREIGN KEY(product_id) REFERENCES products(product_id)
);

DROP TABLE IF EXISTS offerings;
CREATE TABLE offerings (
	offering_id INTEGER PRIMARY KEY,
	product_id  INTEGER NOT NULL,
	price       REAL,
	valid_from  DATE,
	valid_until DATE,   
	version     TEXT,
	FOREIGN KEY(product_id) REFERENCES products(product_id)
);

DROP TABLE IF EXISTS price_tiers;
CREATE TABLE price_tiers (
	price_tier_id INTEGER PRIMARY KEY,
	offering_id   INTEGER NOT NULL,
	min_units     INTEGER NOT NULL,
	price         REAL,
	FOREIGN KEY(offering_id) REFERENCES offerings(offering_id)
);

DROP TABLE IF EXISTS categories_products;
CREATE TABLE categories_products (
	category_id INTEGER NOT NULL,
	product_id  INTEGER NOT NULL,
	FOREIGN KEY(category_id) REFERENCES categories(category_id),
	FOREIGN KEY(product_id) REFERENCES products(product_id),
    PRIMARY KEY(category_id, product_id)
);