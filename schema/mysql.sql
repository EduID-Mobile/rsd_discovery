
create table if not exists services (
    service_id INTEGER PRIMARY KEY AUTO_INCREMENT,
    uri TEXT NOT NULL,
    rsd LONGTEXT,
    ttl INTEGER DEFAULT 86400,
    last_update INTEGER DEFAULT 0,
    checksum varchar(64)
);

create table if not exists protocols (
    service_id INTEGER not null,
    rsd_name VARCHAR(255) NOT NULL
);

create table if not exists service_keys (
    kid varchar(255),
    jku TEXT,
    service_key longtext not null
);
