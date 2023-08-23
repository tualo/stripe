create table stripe_environment(
    id varchar(36) not null primary key,
    val longtext not null
);


create table stripe_webhook(
    id varchar(36) not null primary key default uuid(),
    createdatetime datetime DEFAULT current_timestamp,
    eventtype varchar(100) not null,
    eventdata json not null
);


create table stripe_webhook_errors(
    id varchar(36) not null primary key  default uuid(),
    createdatetime datetime DEFAULT current_timestamp,
    errordata longtext not null
);
