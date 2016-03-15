CREATE TABLE _navegar_searchs(
	search_source varchar(10),
	search_query varchar(255),
	last_usage timestamp DEFAULT CURRENT_TIMESTAMP,
	usage_count int(11) DEFAULT 1,
	PRIMARY KEY (search_source, search_query)
);

CREATE TABLE _navegar_visits(
	site varchar(255) PRIMARY KEY,
	last_usage timestamp DEFAULT CURRENT_TIMESTAMP,
	usage_count int(11) DEFAULT 1
);