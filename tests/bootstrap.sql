DROP TABLE IF EXISTS shops;
CREATE TABLE shops (
	shop_id  INTEGER PRIMARY KEY,
	name     TEXT
);

DROP TABLE IF EXISTS users;
CREATE TABLE users (
	user_id INTEGER PRIMARY KEY,
	shop_id INTEGER,
	name    TEXT,
	last_logged_in DATE,
	FOREIGN KEY(shop_id) REFERENCES shops(shop_id)
);

DROP TABLE IF EXISTS categories;
CREATE TABLE categories (
	category_id INTEGER PRIMARY KEY,
	name        TEXT,
	ordinal     INTEGER
);

DROP TABLE IF EXISTS products;
CREATE TABLE products (
	product_id  INTEGER PRIMARY KEY,
	shop_id     INTEGER,
	name        TEXT,
	price       REAL,
	FOREIGN KEY(shop_id) REFERENCES shops(shop_id)
);

DROP TABLE IF EXISTS categories_products;
CREATE TABLE categories_products (
	category_id INTEGER,
	product_id  INTEGER,
	FOREIGN KEY(category_id) REFERENCES categories(category_id),
	FOREIGN KEY(product_id) REFERENCES products(product_id),
    PRIMARY KEY(category_id, product_id)
);