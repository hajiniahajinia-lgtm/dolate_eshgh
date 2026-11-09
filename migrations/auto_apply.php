<?php
declare(strict_types=1);

/*
 * Automatic migration execution is intentionally disabled.
 *
 * MySQL DDL may partially commit before a later statement fails. Migrations
 * must now be reviewed with migration_runner.php in read-only plan mode and
 * executed only through its authenticated, explicit {"mode":"apply"} request.
 * This compatibility file remains so legacy db.php includes cannot silently
 * execute pending migrations during ordinary page requests.
 */
return;
